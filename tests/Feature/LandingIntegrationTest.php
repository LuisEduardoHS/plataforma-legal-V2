<?php

namespace Tests\Feature;

use App\Models\User;
use Closure;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class LandingIsolationProbe
{
    public function handle(Request $request, Closure $next): Response
    {
        $db = DB::connection();
        $root = str_replace('\\', '/', config('filesystems.disks.attachments.root'));
        $storage = str_replace('\\', '/', storage_path());
        if (! app()->environment('testing') || $db->getDriverName() !== 'mysql'
            || $db->getDatabaseName() !== 'plataforma_legal_v2_test'
            || $db->selectOne('SELECT DATABASE() db')->db !== 'plataforma_legal_v2_test'
            || config('cache.default') !== 'array' || config('session.driver') !== 'array'
            || ! str_starts_with($root, $storage.'/framework/testing/disks/09c-private')
            || str_starts_with($storage, str_replace('\\', '/', base_path()).'/')) {
            throw new \RuntimeException('Landing requiere MySQL testing y almacenamiento externo aislado.');
        }

        return $next($request)->header('X-Landing-Isolation', 'testing-mysql-private-array');
    }
}

class LandingIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['filesystems.disks.attachments.root' => storage_path('framework/testing/disks/09c-private')]);
        $this->app->make(Kernel::class)->prependMiddlewareToGroup('web', LandingIsolationProbe::class);
        // A real HTTP request verifies every isolation boundary before fixtures.
        $this->get('/')->assertOk()->assertHeader('X-Landing-Isolation', 'testing-mysql-private-array');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function dom(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function test_guest_receives_the_product_document_without_shared_caching(): void
    {
        $response = $this->get('/')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        $dom = $this->dom($response->getContent());
        $this->assertSame('es-MX', $dom->evaluate('string(/html/@lang)'));
        $this->assertSame(1, $dom->query('//h1')->length);
        $this->assertSame('Un punto de partida para tus dudas legales.', trim($dom->evaluate('string(//h1)')));
        $this->assertSame(1, $dom->query('//main[@id="main-content" and @tabindex="-1"]')->length);
        $this->assertSame('#main-content', $dom->evaluate('string(//a[contains(@class,"public-skip")]/@href)'));
        $this->assertSame(1, $dom->query('//header/nav | //header/div/nav')->length);
        $this->assertSame(1, $dom->query('//details/summary')->length);
        $response->assertDontSee("Let's get started")->assertDontSee('laravel.com/docs');
    }

    public function test_header_and_footer_offer_real_public_destinations_without_private_navigation(): void
    {
        $dom = $this->dom($this->get('/')->getContent());
        foreach (['community', 'professionals', 'articles', 'resources', 'help', 'login', 'register'] as $route) {
            foreach (['//header//a', '//footer//a'] as $selector) {
                $hrefs = [];
                foreach ($dom->query($selector) as $node) {
                    $hrefs[] = $node->getAttribute('href');
                }
                $this->assertContains(route($route), $hrefs, $selector.' '.$route);
            }
        }
        foreach ($dom->query('//header//a | //footer//a') as $node) {
            $this->assertNotContains($node->getAttribute('href'), array_map(fn ($name) => route($name), ['home', 'messages', 'settings', 'profile']));
        }
        $this->assertNotSame(route('articles'), route('resources'));
    }

    public function test_hero_is_an_explicit_example_and_never_a_fake_ai_form(): void
    {
        $dom = $this->dom($this->get('/')->getContent());
        $hero = $dom->query('//section[@aria-labelledby="landing-title"]')->item(0);
        $this->assertStringContainsString('Pregunta de ejemplo', $hero->textContent);
        $this->assertStringContainsString('¿Qué información debería reunir antes de consultar a un profesional?', $hero->textContent);
        $this->assertStringContainsString('IA aún no conectada. Las respuestas actuales son ejemplos y no analizan tu caso.', $hero->textContent);
        $this->assertStringContainsString('La demostración requiere iniciar sesión.', $hero->textContent);
        $this->assertSame(0, $dom->query('//form | //textarea | //input | //*[@contenteditable="true"]')->length);
        $this->assertSame(0, $dom->query('//*[@data-lexa] | //*[@data-composer]')->length);
        $this->assertSame(route('lexa'), $dom->evaluate('string(//a[contains(@class,"landing-button-lexa")]/@href)'));
    }

    public function test_document_has_unique_ids_valid_fragments_and_no_unpublished_legal_destinations(): void
    {
        $dom = $this->dom($this->get('/')->getContent());
        $ids = [];
        foreach ($dom->query('//*[@id]') as $node) {
            $ids[] = $node->getAttribute('id');
        }
        $this->assertSame(count($ids), count(array_unique($ids)));
        foreach ($dom->query('//a') as $node) {
            $href = $node->getAttribute('href');
            $this->assertNotSame('#', $href);
            if (str_starts_with($href, '#')) {
                $this->assertContains(substr($href, 1), $ids);
            }
            $this->assertDoesNotMatchRegularExpression('/(?:terminos|privacidad|contacto-institucional)/', $href);
        }
    }

    public function test_editorial_landing_never_queries_content_or_exposes_private_session_data(): void
    {
        $user = User::factory()->create(['name' => 'Identidad privada de prueba', 'email' => 'private-landing@example.invalid']);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $response = $this->withSession(['private-marker' => 'session-private-content'])->get('/')->assertOk();
        $response->assertDontSee($user->name)->assertDontSee($user->email)->assertDontSee('session-private-content');
        $response->assertSessionMissing('legal.ui.handoff.lexa');
        $this->assertGuest();
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:from|join)\s+`?(?:posts|articles|legal_resources|professional_profiles|messages|attachments|saved_items)\b/i', $sql);
        }
        $this->assertNotEmpty($queries); // The HTTP isolation probe actually ran.
    }

    public function test_build_document_loads_css_and_fonts_without_any_application_javascript(): void
    {
        $this->withVite();
        $this->app->make(Vite::class)->useHotFile(storage_path('absent.hot'));
        $dom = $this->dom($this->get('/')->assertOk()->getContent());
        $this->assertSame(0, $dom->query('//script')->length);
        $this->assertSame(0, $dom->query('//link[@rel="modulepreload"]')->length);
        $styles = [];
        foreach ($dom->query('//link[@rel="stylesheet"]') as $node) {
            $styles[] = $node->getAttribute('href');
        }
        $this->assertCount(3, $styles); // Shared tokens/reset, Landing, DM Sans.
        $this->assertSame(1, count(array_filter($styles, fn ($href) => str_contains($href, '/assets/landing-'))));
        $this->assertSame(1, count(array_filter($styles, fn ($href) => str_contains($href, 'family=DM+Sans'))));
        $this->assertSame('reload', $dom->evaluate('string(//meta[@name="turbo-visit-control"]/@content)'));
    }

    public function test_guest_head_has_no_body_and_the_same_cache_policy(): void
    {
        $this->head('/')->assertOk()->assertContent('')->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function rootMethods(): array
    {
        return [['GET'], ['HEAD']];
    }

    #[DataProvider('rootMethods')]
    public function test_active_account_redirects_temporarily_to_existing_home_without_consuming_intended(string $method): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->withSession(['url.intended' => route('lexa')])->call($method, '/');
        $response->assertStatus(302)->assertRedirect(route('home'))->assertHeader('Cache-Control', 'no-store, private')
            ->assertSessionHas('url.intended', route('lexa'));
        $this->get(route('home'))->assertOk()->assertSee('data-home', escape: false);
    }

    public static function inactiveStates(): array
    {
        return [['suspended'], ['disabled'], ['pending_deletion']];
    }

    #[DataProvider('inactiveStates')]
    public function test_root_honors_persisted_account_state_without_redirect_loops(string $status): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['private-marker' => 'discard']);
        DB::table('users')->where('id', $user->id)->update(['status' => $status]);
        $this->get('/')->assertRedirect(route('login'))->assertSessionMissing('private-marker')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertGuest();
        $this->get(route('login'))->assertOk();
        $this->get('/')->assertOk();
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_revoked_credential_stamp_cannot_restore_access_through_root(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['private-marker' => 'discard']);
        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('ChangedCredential42')]);
        $this->get('/')->assertRedirect(route('login'))->assertSessionMissing('private-marker');
        $this->assertGuest();
        $this->get('/')->assertOk();
        $this->get(route('lexa'))->assertRedirect(route('login'));
    }

    public function test_demo_link_preserves_login_intended_and_existing_lexa_without_a_question_payload(): void
    {
        $user = User::factory()->create();
        $this->get('/')->assertOk()->assertSessionMissing('url.intended');
        $this->get(route('lexa'))->assertRedirect(route('login'))->assertSessionHas('url.intended', route('lexa'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('lexa'));
        $this->get(route('lexa'))->assertOk()->assertSee('data-lexa', escape: false)->assertSee('Demostración local');
        $this->get('/')->assertRedirect(route('home'));
    }

    public function test_public_destination_pages_still_render_for_a_guest(): void
    {
        foreach (['community', 'professionals', 'articles', 'resources', 'help', 'login', 'register'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }
}
