<?php

namespace Tests\Feature;

use App\Enums\EditorialKind;
use App\Models\User;
use App\Services\ContentLibrary\EditorialService;
use App\Services\Profiles\ProfileService;
use Closure;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\EnforcedCsrfToken;
use Tests\TestCase;

class NavigationIsolationProbe
{
    public function handle(Request $request, Closure $next): Response
    {
        $db = DB::connection();
        $root = str_replace('\\', '/', config('filesystems.disks.attachments.root'));
        if (! app()->environment('testing') || $db->getDriverName() !== 'mysql'
            || $db->getDatabaseName() !== 'plataforma_legal_v2_test'
            || $db->selectOne('SELECT DATABASE() db')->db !== 'plataforma_legal_v2_test'
            || $root !== str_replace('\\', '/', storage_path('framework/testing/disks/09b-private'))
            || config('cache.default') !== 'array' || config('session.driver') !== 'array') {
            throw new \RuntimeException('Navegación 09B requiere SQL y almacenamiento aislados.');
        }

        return $next($request)->header('X-09B-Isolation', 'testing-mysql-private-array');
    }
}

class AccountNavigationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['filesystems.disks.attachments.root' => storage_path('framework/testing/disks/09b-private')]);
        $this->app->make(Kernel::class)->prependMiddlewareToGroup('web', NavigationIsolationProbe::class);
        // A real HTTP request confirms isolation before any synthetic account/grant.
        $this->get('/ayuda')->assertOk()->assertHeader('X-09B-Isolation', 'testing-mysql-private-array');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function actor(array $grants = []): User
    {
        $actor = User::factory()->create(['name' => 'Persona de navegación']);
        foreach ($grants as $name) {
            $actor->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        return $actor;
    }

    private function dom(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);

            return new DOMXPath($doc);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function links(DOMXPath $dom): array
    {
        $links = [];
        foreach ($dom->query('//*[@data-account-panel]//a') as $node) {
            $links[] = $node->getAttribute('href');
        }

        return $links;
    }

    public function test_guest_has_public_navigation_with_native_disclosure_and_account_entries(): void
    {
        $dom = $this->dom($this->get('/ayuda')->assertOk()->getContent());
        $this->assertSame(0, $dom->query('//details[@data-account-menu]')->length);
        $this->assertSame(1, $dom->query('//details[@data-public-navigation]/summary[@aria-controls="public-navigation-panel"]')->length);
        $this->assertSame(array_map(fn ($name) => route($name), ['community', 'professionals', 'articles', 'resources', 'help', 'login', 'register']), $this->links($dom));
        $this->assertSame(0, $dom->query('//*[@data-account-panel]//form')->length);
    }

    public function test_member_has_one_shared_uncloaked_menu_and_post_logout_with_csrf(): void
    {
        $actor = $this->actor();
        $dom = $this->dom($this->actingAs($actor)->get('/perfil')->assertOk()->getContent());
        $this->assertSame([route('profile'), route('saved'), route('settings'), route('help')], $this->links($dom));
        $this->assertSame(1, $dom->query('//*[@data-account-menu]')->length);
        $this->assertSame(1, $dom->query('//*[@data-account-menu]/preceding-sibling::nav[@aria-label="Navegación principal"]')->length);
        $this->assertSame(1, $dom->query('//*[@data-account-menu]/preceding-sibling::*[contains(@class,"desktop-account-actions")]')->length);
        $this->assertSame(1, $dom->query('//*[@data-account-menu]/following-sibling::a[@aria-label="Abrir LEXA"]')->length);
        $this->assertSame(0, $dom->query('//*[@data-account-menu]/ancestor::*[@hidden or contains(@class,"hidden")]')->length);
        $this->assertSame(0, $dom->query('//*[@data-account-menu]//*[@x-cloak or @x-show]')->length);
        $this->assertSame(1, $dom->query('//*[@data-account-panel]//a[@aria-current="page" and @href="'.route('profile').'"]')->length);
        $form = $dom->query('//*[@data-account-panel]//form')->item(0);
        $this->assertSame('POST', $form->getAttribute('method'));
        $this->assertSame(route('logout'), $form->getAttribute('action'));
        $token = $dom->query('.//input[@name="_token"]', $form)->item(0);
        $this->assertNotEmpty($token->getAttribute('value'));
        $this->assertSame(0, $dom->query('//*[@data-account-panel]//a[@href="'.route('logout').'"]')->length);
        foreach (['saved', 'settings', 'help'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_professional_presentation_does_not_grant_editorial_or_staff_options(): void
    {
        $actor = $this->actor();
        app(ProfileService::class)->saveProfessionalProfile($actor, $actor->id, ['headline' => 'Presentación de prueba']);
        $dom = $this->dom($this->actingAs($actor)->get('/ayuda')->assertOk()->getContent());
        $this->assertSame([route('profile'), route('saved'), route('settings'), route('help')], $this->links($dom));
        $this->get('/perfil/profesional/editar')->assertOk();
        $this->get('/verificacion')->assertOk();
        $this->get('/revision-profesional')->assertForbidden();
        $this->get('/articulos/crear')->assertForbidden();
    }

    public static function capabilities(): array
    {
        return [
            'article author' => [['articles.create'], true, false, false, false],
            'article update' => [['articles.update.own'], true, false, false, false],
            'article publisher' => [['articles.publish.own'], true, false, false, false],
            'resource manager' => [['resources.manage'], false, true, false, false],
            'editor both' => [['articles.manage', 'resources.create'], true, true, false, false],
            'operator' => [['operations.accounts', 'operations.audit'], false, false, true, false],
            'catalog operator' => [['operations.permissions.verification'], false, false, true, false],
            'reviewer' => [['verification.review'], false, false, false, true],
            'document only' => [['verification.evidence.read'], false, false, false, false],
            'moderator' => [['posts.moderate'], false, false, true, false],
            'legacy account name' => [['users.manage'], false, false, false, false],
            'incompatible operational grants' => [['operations.accounts', 'verification.review'], false, false, false, true],
        ];
    }

    #[DataProvider('capabilities')]
    public function test_options_follow_effective_capabilities(array $grants, bool $articles, bool $resources, bool $operations, bool $review): void
    {
        $actor = $this->actor($grants);
        $before = $actor->getAllPermissions()->pluck('name')->sort()->values()->all();
        $links = $this->links($this->dom($this->actingAs($actor)->get('/ayuda')->assertOk()->getContent()));
        foreach (['articles.workspace' => $articles, 'resources.workspace' => $resources, 'operations.index' => $operations, 'verification.review.index' => $review] as $route => $expected) {
            $this->assertSame($expected, in_array(route($route), $links, true), $route);
            if ($expected) {
                $this->get(route($route))->assertOk();
            }
        }
        $this->assertSame($before, $actor->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all());
    }

    public function test_own_editorial_workspace_survives_revoked_create_without_broadening_authorization(): void
    {
        $actor = $this->actor(['articles.create']);
        $item = app(EditorialService::class)->create(EditorialKind::Article, $actor, ['title' => 'Borrador propio de prueba']);
        $actor->revokePermissionTo('articles.create');
        $links = $this->links($this->dom($this->actingAs($actor)->get('/ayuda')->assertOk()->getContent()));
        $this->assertContains(route('articles.workspace'), $links);
        $this->assertNotContains(route('resources.workspace'), $links);
        $this->get('/articulos/gestion')->assertOk()->assertSee($item->title);
        $this->get('/articulos/crear')->assertForbidden();
    }

    public function test_admin_role_name_alone_is_not_an_operator_reviewer_or_editor(): void
    {
        $actor = $this->actor();
        $actor->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        $links = $this->links($this->dom($this->actingAs($actor)->get('/ayuda')->assertOk()->getContent()));
        $this->assertSame([route('profile'), route('saved'), route('settings'), route('help')], $links);
        $this->get('/operaciones')->assertForbidden();
        $this->get('/revision-profesional')->assertForbidden();
    }

    public function test_logout_rejects_missing_csrf_and_valid_post_returns_to_guest_navigation(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $actor = $this->actor();
        $dom = $this->dom($this->actingAs($actor)->get('/ayuda')->assertOk()->getContent());
        $token = $dom->query('//*[@data-account-panel]//input[@name="_token"]')->item(0)->getAttribute('value');
        $this->post('/cerrar-sesion')->assertStatus(419);
        $this->assertAuthenticatedAs($actor);
        $this->post('/cerrar-sesion', ['_token' => $token])->assertRedirect('/');
        $this->assertGuest();
        $links = $this->links($this->dom($this->get('/ayuda')->assertOk()->getContent()));
        $this->assertSame(array_map(fn ($name) => route($name), ['community', 'professionals', 'articles', 'resources', 'help', 'login', 'register']), $links);
        $this->get('/guardados')->assertRedirect('/iniciar-sesion');
        $this->get('/cerrar-sesion')->assertStatus(405);
    }

    public function test_revoked_grants_and_suspended_session_do_not_leave_privileged_navigation(): void
    {
        $actor = $this->actor(['operations.accounts']);
        $this->actingAs($actor)->get('/ayuda')->assertSee('Operaciones');
        $actor->revokePermissionTo('operations.accounts');
        $links = $this->links($this->dom($this->get('/ayuda')->assertOk()->getContent()));
        $this->assertNotContains(route('operations.index'), $links);
        $this->get('/operaciones')->assertForbidden();
        $actor->status = 'suspended';
        $actor->save();
        $this->get('/ayuda')->assertRedirect('/iniciar-sesion');
        $this->assertGuest();
        $this->assertSame(array_map(fn ($name) => route($name), ['community', 'professionals', 'articles', 'resources', 'help', 'login', 'register']), $this->links($this->dom($this->get('/ayuda')->assertOk()->getContent())));
    }

    public function test_rendered_help_is_current_categorized_and_readable_without_scripts(): void
    {
        $dom = $this->dom($this->get('/ayuda')->assertOk()->getContent());
        $questions = $dom->query('//details[@data-help-question]');
        $this->assertStringNotContainsString('@guest', $dom->evaluate('string(//body)'));
        $this->assertStringNotContainsString('@endif', $dom->evaluate('string(//body)'));
        $this->assertSame(23, $questions->length);
        $answers = [];
        foreach ($questions as $question) {
            $summary = $dom->query('./summary', $question)->item(0)->textContent;
            $answers[trim($summary)] = $dom->query('.//*[contains(@class,"help-answer")]/p', $question)->item(0)->textContent;
        }
        $this->assertStringContainsString('No es una demostración', $answers['¿Los mensajes llegan a otras cuentas?']);
        $this->assertStringContainsString('No se ofrece cifrado de extremo a extremo', $answers['¿Los mensajes llegan a otras cuentas?']);
        $this->assertStringContainsString('servicio de entrega de correo', $answers['¿Puedo recuperar mi contraseña por correo?']);
        $this->assertStringContainsString('No es una validación oficial automatizada', $answers['¿Qué significa la verificación profesional?']);
        $this->assertStringContainsString('sin historial persistente', $answers['¿LEXA está conectada a una IA real?']);
        $this->assertStringContainsString('Recientes, Siguiendo o Mis publicaciones', $answers['¿Cómo filtro las publicaciones?']);
        $this->assertArrayNotHasKey('¿Los mensajes de demostración llegan a otras personas?', $answers);
        $this->assertArrayNotHasKey('¿Por qué «Cerrar sesión» puede estar deshabilitado?', $answers);
        $this->assertSame(1, $dom->query('//*[@data-help-tools and @hidden]')->length);
        $statuses = [];
        foreach ($dom->query('//*[contains(@class,"help-availability")]') as $node) {
            $statuses[] = trim($node->textContent);
        }
        foreach (['Disponible', 'Requiere configuración de entrega', 'Demostración', 'Funcionalidad futura'] as $status) {
            $this->assertContains($status, $statuses);
        }
        $this->assertSame(0, $dom->query('//*[@data-help-question]//a[contains(@href,"/operaciones") or contains(@href,"/revision-profesional")]')->length);
        $this->assertSame(0, $dom->query('//*[@data-help-question and (@hidden or @x-cloak)]')->length);
    }
}
