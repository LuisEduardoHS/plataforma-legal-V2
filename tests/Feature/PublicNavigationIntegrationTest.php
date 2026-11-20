<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\SavedItem;
use App\Models\User;
use App\Services\Profiles\ProfileService;
use Closure;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\EnforcedCsrfToken;
use Tests\TestCase;

class PublicNavigationIsolationProbe
{
    public function handle(Request $request, Closure $next): Response
    {
        $db = DB::connection();
        $storage = str_replace('\\', '/', storage_path());
        $private = str_replace('\\', '/', config('filesystems.disks.attachments.root'));
        if (! app()->environment('testing') || $db->getDriverName() !== 'mysql'
            || $db->getDatabaseName() !== 'plataforma_legal_v2_test'
            || $db->selectOne('SELECT DATABASE() db')->db !== 'plataforma_legal_v2_test'
            || config('session.driver') !== 'array' || config('cache.default') !== 'array'
            || str_starts_with($storage, str_replace('\\', '/', base_path()).'/')
            || ! str_starts_with($private, $storage.'/framework/testing/disks/public-navigation')) {
            throw new \RuntimeException('La navegación pública requiere MySQL testing y almacenamiento externo aislado.');
        }

        return $next($request)->header('X-Public-Navigation-Isolation', 'testing-mysql-external-array');
    }
}

class PublicNavigationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['filesystems.disks.attachments.root' => storage_path('framework/testing/disks/public-navigation')]);
        $this->app->make(Kernel::class)->prependMiddlewareToGroup('web', PublicNavigationIsolationProbe::class);
        $this->get('/ayuda')->assertOk()->assertHeader('X-Public-Navigation-Isolation', 'testing-mysql-external-array');
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

    public static function publicPages(): array
    {
        return array_map(fn ($name) => [$name], ['landing', 'community', 'professionals', 'articles', 'resources', 'help']);
    }

    public static function internalPages(): array
    {
        return array_map(fn ($name) => [$name], ['community', 'professionals', 'articles', 'resources', 'help']);
    }

    #[DataProvider('publicPages')]
    public function test_guest_receives_consistent_real_destinations_without_private_header(string $page): void
    {
        $response = $this->get(route($page))->assertOk();
        $dom = $this->dom($response->getContent());
        $this->assertSame(1, $dom->query('//header[contains(@class,"public-header")]')->length);
        $this->assertSame(0, $dom->query('//*[@data-account-menu] | //*[contains(@class,"platform-mobile-nav")]')->length);
        $this->assertSame(route('landing'), $dom->evaluate('string(//header[contains(@class,"public-header")]//a[@class="public-brand"]/@href)'));
        foreach (['community', 'professionals', 'articles', 'resources', 'help', 'login', 'register'] as $name) {
            $this->assertSame(2, $dom->query('//header[contains(@class,"public-header")]//a[@href="'.route($name).'"]')->length, $name);
        }
        foreach (['home', 'messages', 'profile', 'settings', 'operations.index', 'verification.review.index', 'lexa'] as $name) {
            $this->assertSame(0, $dom->query('//header[contains(@class,"public-header")]//a[@href="'.route($name).'"]')->length, $name);
        }
        $this->assertSame(0, $dom->query('//header[contains(@class,"public-header")]//a[starts-with(@href,"#")]')->length);
        $this->assertSame($page === 'landing' ? 1 : 2, $dom->query('//header[contains(@class,"public-header")]//a[@aria-current="page"]')->length);
        $this->assertSame(1, $dom->query('//details[@data-public-navigation]/summary[@aria-controls="public-navigation-panel"]')->length);
        $this->assertSame(0, $dom->query('//details[@data-public-navigation and (@hidden or @x-cloak)]')->length);
        $ids = [];
        foreach ($dom->query('//*[@id]') as $node) {
            $this->assertNotContains($node->getAttribute('id'), $ids);
            $ids[] = $node->getAttribute('id');
        }
        $this->get(route('login'))->assertOk();
        $this->get(route('register'))->assertOk();
    }

    #[DataProvider('internalPages')]
    public function test_active_members_keep_internal_navigation_and_existing_capabilities(string $page): void
    {
        $member = User::factory()->create();
        $permissions = $member->getAllPermissions()->pluck('name')->all();
        $dom = $this->dom($this->actingAs($member)->get(route($page))->assertOk()->getContent());
        $this->assertSame(0, $dom->query('//header[contains(@class,"public-header")]')->length);
        $this->assertSame(1, $dom->query('//*[@data-account-menu]')->length);
        foreach (['home', 'messages', 'profile', 'settings'] as $name) {
            $this->assertGreaterThanOrEqual(1, $dom->query('//header[contains(@class,"platform-header")]//a[@href="'.route($name).'"]')->length);
        }
        $this->assertSame(0, $dom->query('//header[contains(@class,"platform-header")]//a[@href="'.route('operations.index').'"]')->length);
        $this->assertSame($permissions, $member->fresh()->getAllPermissions()->pluck('name')->all());
        $this->get(route('landing'))->assertRedirect(route('home'));
    }

    public static function inactiveStatuses(): array
    {
        return [['suspended'], ['disabled'], ['pending_deletion']];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_inactive_session_is_revoked_by_existing_middleware_before_public_render(string $status): void
    {
        $member = User::factory()->create();
        $this->actingAs($member);
        $member->forceFill(['status' => $status])->save();
        $this->get(route('help'))->assertRedirect(route('login'));
        $this->assertGuest();
        $dom = $this->dom($this->get(route('help'))->assertOk()->getContent());
        $this->assertSame(1, $dom->query('//header[contains(@class,"public-header")]')->length);
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public static function intendedCases(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('intendedCases')]
    public function test_header_login_reuses_signed_reading_return_and_keeps_protected_intended_priority(bool $intended): void
    {
        $author = User::factory()->create();
        app(ProfileService::class)->saveProfessionalProfile($author, $author->id, ['visibility' => 'public', 'headline' => 'Presentación pública QA', 'license_number' => '12345678']);
        $reader = User::factory()->create();
        if ($intended) {
            $this->get(route('saved'))->assertRedirect(route('login'));
        }
        $detail = route('professionals.show', $author->id);
        $dom = $this->dom($this->get($detail)->assertOk()->assertDontSee('12345678')->getContent());
        $login = $dom->evaluate('string(//header[contains(@class,"public-header")]//a[normalize-space(.)="Iniciar sesión"]/@href)');
        $this->assertStringContainsString('signature=', $login);
        $this->get($login)->assertOk()->assertSessionHas('auth.reading_return.type', 'professional');
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $this->post(route('login.store'), ['email' => $reader->email, 'password' => 'password', '_token' => session()->token()])
            ->assertRedirect($intended ? route('saved') : $detail)->assertSessionMissing('auth.reading_return');
        $this->assertSame(0, SavedItem::count());
        $this->assertSame(0, Conversation::count());
    }

    public function test_public_header_itself_executes_no_sql_or_private_projection(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $html = Blade::render('<x-public.header />');
        $this->assertSame([], DB::getQueryLog());
        $this->assertStringNotContainsString('data-account-menu', $html);
    }
}
