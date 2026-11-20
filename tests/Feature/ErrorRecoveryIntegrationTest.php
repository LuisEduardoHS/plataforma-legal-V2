<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use App\Services\Profiles\ProfessionalDirectory;
use Closure;
use DOMDocument;
use DOMXPath;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\EnforcedCsrfToken;
use Tests\Support\TestingIsolation;
use Tests\TestCase;

class ErrorRecoveryIsolationProbe
{
    public function handle(Request $request, Closure $next): Response
    {
        $db = DB::connection();
        $storage = str_replace('\\', '/', storage_path());
        $private = str_replace('\\', '/', config('filesystems.disks.attachments.root'));
        if (! app()->environment('testing') || $db->getDriverName() !== 'mysql'
            || $db->getDatabaseName() !== TestingIsolation::database()
            || $db->selectOne('SELECT DATABASE() db')->db !== TestingIsolation::database()
            || config('session.driver') !== 'array' || config('cache.default') !== 'array'
            || str_starts_with($storage, str_replace('\\', '/', base_path()).'/')
            || ! str_starts_with($private, $storage.'/framework/testing/disks/error-recovery')) {
            throw new \RuntimeException('La recuperación requiere MySQL testing y almacenamiento externo aislado.');
        }

        return $next($request)->header('X-Error-Recovery-Isolation', 'testing-mysql-external-array');
    }
}

class ErrorRecoveryIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['filesystems.disks.attachments.root' => storage_path('framework/testing/disks/error-recovery')]);
        $this->app->make(Kernel::class)->prependMiddlewareToGroup('web', ErrorRecoveryIsolationProbe::class);
        // This real HTTP preflight precedes every fixture and transaction.
        $this->get('/ayuda')->assertOk()->assertHeader('X-Error-Recovery-Isolation', 'testing-mysql-external-array');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);

        return new DOMXPath($document);
    }

    private function safe(Response $response): void
    {
        foreach (['PRIVATE_EXCEPTION_CANARY', 'PRIVATE_SQL_CANARY', 'PRIVATE_PATH_CANARY', 'trace', 'exception', 'bindings', 'private@example.invalid', 'protected-file.pdf'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    private function recovery(string $html, string $destination): void
    {
        $dom = $this->dom($html);
        $this->assertSame(1, $dom->query('//main[@id="main-content"]//h1')->length);
        $this->assertSame(1, $dom->query('//nav[@aria-label="Opciones de recuperación"]//a[@href="'.$destination.'"]')->length);
        $this->assertSame(0, $dom->query('//form | //script | //*[@data-account-menu] | //iframe')->length);
        $this->assertGreaterThanOrEqual(2, $dom->query('//nav[@aria-label="Opciones de recuperación"]//a')->length);
        foreach ($dom->query('//nav[@aria-label="Opciones de recuperación"]//a') as $link) {
            $this->assertSame('false', $link->getAttribute('data-turbo'));
            $this->assertStringStartsWith(url('/'), $link->getAttribute('href'));
            $this->assertStringNotContainsString('?', $link->getAttribute('href'));
        }
        $this->assertSame(1, $dom->query('//meta[@name="turbo-cache-control" and @content="no-cache"]')->length);
        $ids = [];
        foreach ($dom->query('//*[@id]') as $element) {
            $ids[] = $element->getAttribute('id');
        }
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public static function errorCases(): array
    {
        $cases = [];
        foreach ([403, 404, 409, 419, 429, 503] as $status) {
            foreach ([false, true] as $active) {
                foreach ([false, true] as $json) {
                    $cases[$status.($active ? '-active' : '-guest').($json ? '-json' : '-html')] = [$status, $active, $json];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('errorCases')]
    public function test_framework_errors_keep_status_headers_and_safe_html_or_json(int $status, bool $active, bool $json): void
    {
        config(['app.debug' => true]);
        if ($active) {
            $this->actingAs(User::factory()->create(['name' => 'PRIVATE_PATH_CANARY']));
        }
        Route::middleware('web')->get('/__testing/recovery', fn () => throw new HttpException($status, 'PRIVATE_EXCEPTION_CANARY private@example.invalid protected-file.pdf', null, $status === 429 ? ['Retry-After' => '60'] : []));
        $response = $json ? $this->getJson('/__testing/recovery') : $this->get('/__testing/recovery');
        $response->assertStatus($status);
        $this->safe($response->baseResponse);
        if ($status === 429) {
            $response->assertHeader('Retry-After', '60');
        }
        if ($json) {
            $response->assertHeader('Content-Type', 'application/json');
            $this->assertSame(['message'], array_keys($response->json()));
        } else {
            $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
            $this->recovery($response->getContent(), route('landing'));
            $response->assertSee('Error '.$status);
        }
    }

    public function test_real_csrf_rejection_is_419_and_never_replays_logout(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $response = $this->post('/cerrar-sesion')->assertStatus(419);
        $this->recovery($response->getContent(), route('landing'));
        $response->assertSee(route('login'), false);
        $this->assertAuthenticatedAs($actor);
        $this->postJson('/cerrar-sesion')->assertStatus(419)->assertJsonStructure(['message']);
        $this->assertAuthenticatedAs($actor);
    }

    public function test_real_rate_limit_keeps_429_retry_headers_and_json_contract(): void
    {
        RateLimiter::for('recovery-test', fn () => Limit::perMinute(1)->by('recovery'));
        Route::middleware(['web', 'throttle:recovery-test'])->get('/__testing/limited', fn () => 'lectura');
        $this->get('/__testing/limited')->assertOk();
        $response = $this->get('/__testing/limited')->assertStatus(429)->assertHeader('X-RateLimit-Limit', '1');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->recovery($response->getContent(), route('landing'));
        $this->getJson('/__testing/limited')->assertStatus(429)->assertHeader('Content-Type', 'application/json')->assertJsonStructure(['message']);
    }

    public function test_sql_failures_are_safe_even_in_debug_and_generic_error_views_query_no_private_services(): void
    {
        config(['app.debug' => true]);
        Route::middleware('web')->get('/__testing/sql', fn () => throw new QueryException('mysql', 'SELECT PRIVATE_SQL_CANARY', [], new \PDOException('PRIVATE_PATH_CANARY')));
        $response = $this->get('/__testing/sql')->assertStatus(503);
        $this->safe($response->baseResponse);
        $this->recovery($response->getContent(), route('landing'));
        $json = $this->getJson('/__testing/sql')->assertStatus(503)->assertHeader('Content-Type', 'application/json');
        $this->safe($json->baseResponse);
        $this->assertSame(['message'], array_keys($json->json()));
        $actor = User::factory()->create();
        $this->actingAs($actor);
        DB::enableQueryLog();
        foreach ([403, 404, 409, 419, 429, 503] as $status) {
            DB::flushQueryLog();
            $html = view('errors.'.$status)->render();
            $this->assertSame([], DB::getQueryLog());
            $this->recovery($html, route('landing'));
        }
    }

    public function test_caught_directory_sql_failure_has_contextual_recovery_without_catalog_or_account_queries(): void
    {
        $this->mock(ProfessionalDirectory::class, function ($mock) {
            $mock->shouldReceive('search')->andThrow(new QueryException('mysql', 'SELECT PRIVATE_SQL_CANARY', [], new \PDOException('PRIVATE_PATH_CANARY')));
        });
        $response = $this->get('/profesionales')->assertStatus(503);
        $this->safe($response->baseResponse);
        $this->recovery($response->getContent(), route('professionals'));
        $this->getJson('/profesionales')->assertStatus(503)->assertJsonStructure(['message']);
    }

    public function test_session_expiry_is_json_401_and_html_auth_redirect_without_fetch_redirect(): void
    {
        $this->getJson('/mensajes/999999999/actualizaciones')->assertUnauthorized()->assertHeader('Content-Type', 'application/json')->assertJsonStructure(['message']);
        $this->get('/mensajes/999999999')->assertRedirect(route('login'));
        $actor = User::factory()->create();
        $this->actingAs($actor);
        DB::table('users')->where('id', $actor->id)->update(['status' => 'suspended']);
        $this->getJson('/mensajes/999999999/actualizaciones')->assertUnauthorized()->assertJsonStructure(['message']);
        $this->assertGuest();
    }

    public function test_foreign_missing_and_deleted_conversations_are_indistinguishable_and_never_expose_private_data(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $chat = app(ConversationService::class)->start($a, $b->id);
        $message = app(MessageService::class)->send($a, $chat->id, ['content' => 'PRIVATE_EXCEPTION_CANARY', 'client_token' => (string) Str::uuid()]);
        $this->actingAs(User::factory()->create());
        $foreign = $this->getJson('/mensajes/'.$chat->id.'/actualizaciones')->assertNotFound();
        $missing = $this->getJson('/mensajes/999999999/actualizaciones')->assertNotFound();
        $this->assertSame($foreign->json(), $missing->json());
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['content' => 'no', 'client_token' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame(1, Message::where('conversation_id', $chat->id)->count());
        $html = $this->get('/mensajes/'.$chat->id)->assertNotFound();
        $this->assertSame($html->getContent(), $this->get('/mensajes/999999999')->assertNotFound()->getContent());
        $this->safe($html->baseResponse);
        $this->recovery($html->getContent(), route('messages'));
        foreach ([$a->email, $b->email, $message->client_token, $message->request_hash] as $secret) {
            $html->assertDontSee($secret);
        }
        $this->actingAs($a);
        DB::table('messages')->where('conversation_id', $chat->id)->delete();
        DB::table('conversation_participants')->where('conversation_id', $chat->id)->delete();
        DB::table('conversations')->where('id', $chat->id)->delete();
        $this->assertSame($foreign->json(), $this->getJson('/mensajes/'.$chat->id.'/actualizaciones')->assertNotFound()->json());
    }

    public function test_read_authorization_survives_disabled_sending_and_stale_mutation_recovers_without_replay(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $chat = app(ConversationService::class)->start($a, $b->id);
        $message = app(MessageService::class)->send($a, $chat->id, ['content' => 'Historial autorizado', 'client_token' => (string) Str::uuid()]);
        app(MessageService::class)->edit($a, $message->id, 'Versión actual', 1);
        $this->actingAs($a);
        $response = $this->put('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['content' => 'No sobrescribir', 'version' => 1])->assertStatus(409);
        $this->recovery($response->getContent(), route('messages'));
        $this->assertSame('Versión actual', $message->fresh()->content);
        $this->putJson('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['content' => 'No sobrescribir', 'version' => 1])->assertStatus(409)->assertJsonStructure(['message']);
        $b->forceFill(['status' => 'disabled'])->save();
        $poll = $this->getJson('/mensajes/'.$chat->id.'/actualizaciones')->assertOk()->assertJsonPath('can_send', false);
        $this->assertStringContainsString('Versión actual', $poll->json('history_html'));
        $html = $this->get('/mensajes/'.$chat->id)->assertOk()->assertSee('Versión actual');
        $dom = $this->dom($html->getContent());
        $this->assertSame(1, $dom->query('//*[@data-messaging-recovery and @hidden]')->length);
        $this->assertSame(route('messages'), $dom->evaluate('string(//*[@data-recovery-access]/@href)'));
        $this->assertSame('button', $dom->evaluate('string(//*[@data-read-retry]/@type)'));
        $this->assertSame(1, $dom->query('//form[@data-composer and @method="POST"]//input[@name="_token"]')->length);
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['content' => 'No autorizado', 'client_token' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame(1, Message::where('conversation_id', $chat->id)->count());
        Auth::logout();
    }
}
