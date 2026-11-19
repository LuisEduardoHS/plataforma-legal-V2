<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Conversation;
use App\Models\LegalResource;
use App\Models\Post;
use App\Models\SavedItem;
use App\Models\User;
use App\Services\Auth\ReadingReturn;
use App\Services\Operations\OperationsAccess;
use App\Services\Profiles\ProfileService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\EnforcedCsrfToken;
use Tests\TestCase;

class AuthenticationContextIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        DB::beginTransaction();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        try {
            DB::disableQueryLog();
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } finally {
            parent::tearDown();
        }
    }

    public static function types(): array
    {
        return array_map(fn ($type) => [$type], ['professional', 'user', 'post', 'article', 'resource']);
    }

    private function target(string $type): array
    {
        $author = User::factory()->create();
        if ($type === 'professional' || $type === 'user') {
            $service = app(ProfileService::class);
            $personal = $service->saveUserProfile($author, $author->id, ['visibility' => 'public', 'display_name' => 'Perfil público sintético', 'biography' => 'Texto público']);
            $profile = $type === 'professional'
                ? $service->saveProfessionalProfile($author, $author->id, ['visibility' => 'public', 'headline' => 'Profesional sintético', 'description' => 'Presentación pública', 'license_number' => '1234567'])
                : $personal;

            return [$author, $profile, route($type === 'professional' ? 'professionals.show' : 'profiles.show', $author->id), $author->id, 'professionals'];
        }
        $model = match ($type) {
            'post' => Post::class, 'article' => Article::class, 'resource' => LegalResource::class
        };
        $entry = new $model;
        $fields = $type === 'post' ? ['content' => 'Publicación sintética', 'visibility' => 'public']
            : ['title' => 'Contenido sintético', 'slug' => fake()->uuid()] + ($type === 'article' ? ['summary' => 'Resumen público', 'body' => 'Cuerpo público'] : ['resource_type' => 'guide', 'description' => 'Recurso público']);
        $entry->forceFill($fields + ['user_id' => $author->id, 'status' => 'published', 'published_at' => now()])->save();
        $entry->refresh();
        $route = match ($type) {
            'post' => 'community', 'article' => 'articles', 'resource' => 'resources'
        };

        return [$author, $entry, route($route.'.show', $entry->id), $entry->id, $route];
    }

    private function readingLink(string $html, string $type, int $target): string
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        foreach ((new \DOMXPath($document))->query('//a[@href]') as $link) {
            $href = $link->getAttribute('href');
            parse_str(parse_url($href, PHP_URL_QUERY) ?? '', $query);
            if (($query['read'] ?? null) === $type && ($query['target'] ?? null) === (string) $target) {
                $this->assertSame(['expires', 'flow', 'read', 'target', 'signature'], array_keys($query));
                $this->assertSame('/iniciar-sesion', parse_url($href, PHP_URL_PATH));

                return $href;
            }
        }
        $this->fail('No se renderizó el enlace de lectura firmado esperado.');
    }

    private function login(User $user)
    {
        return $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    }

    private function assertNoAutomaticActions(): void
    {
        $this->assertSame(0, SavedItem::count());
        $this->assertSame(0, Conversation::count());
    }

    #[DataProvider('types')]
    public function test_public_reading_login_returns_to_the_selected_target_without_replaying_actions(string $type): void
    {
        [, , $detail, $id] = $this->target($type);
        $reader = User::factory()->create();
        $link = $this->readingLink($this->get($detail)->assertOk()->getContent(), $type, $id);
        $this->get($link)->assertOk()->assertSee('esa acción todavía no se ha realizado')->assertSessionHas('auth.reading_return.type', $type)->assertSessionMissing('url.intended');
        $this->login($reader)->assertRedirect($detail)->assertSessionMissing('auth.reading_return')->assertSessionMissing('url.intended');
        $this->get($detail)->assertOk()->assertDontSee('esa acción todavía no se ha realizado');
        $this->assertNoAutomaticActions();
    }

    #[DataProvider('types')]
    public function test_registration_preserves_reading_context_without_changing_new_account_privileges(string $type): void
    {
        [, , $detail, $id] = $this->target($type);
        Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
        $link = $this->readingLink($this->get($detail)->assertOk()->getContent(), $type, $id);
        $this->get($link)->assertOk();
        $this->get(route('register'))->assertOk()->assertSee('esa acción todavía no se ha realizado');
        $this->post(route('register.store'), ['name' => 'Registro sintético', 'email' => 'context-registration@example.invalid',
            'password' => 'ContextoSeguro42!', 'password_confirmation' => 'ContextoSeguro42!'])->assertRedirect($detail)->assertSessionMissing('auth.reading_return');
        $user = User::where('email', 'context-registration@example.invalid')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['user'], $user->getRoleNames()->all());
        $this->assertSame(0, $user->permissions()->count());
        foreach ([...OperationsAccess::CAPABILITIES, ...OperationsAccess::VERIFICATION, ...OperationsAccess::EDITORIAL, ...OperationsAccess::MODERATION] as $permission) {
            $this->assertFalse($user->can($permission));
        }
        $this->assertSame('active', $user->status);
        $this->assertNoAutomaticActions();
        $this->get($detail)->assertOk();
    }

    public function test_catalog_cards_link_to_the_particular_content_rather_than_only_the_listing(): void
    {
        foreach (['professional' => 'professionals', 'post' => 'community', 'article' => 'articles', 'resource' => 'resources'] as $type => $listing) {
            [, , , $id] = $this->target($type);
            $this->readingLink($this->get(route($listing))->assertOk()->getContent(), $type, $id);
        }
    }

    public function test_direct_login_keeps_home_and_the_real_lexa_intended_flow_keeps_precedence(): void
    {
        $reader = User::factory()->create();
        $this->get(route('login'))->assertOk()->assertDontSee('esa acción todavía no se ha realizado');
        $this->login($reader)->assertRedirect(route('home'));
        $this->post(route('logout'))->assertRedirect(route('landing'));
        $this->get(route('lexa'))->assertRedirect(route('login'))->assertSessionHas('url.intended', route('lexa'));
        [, , $detail, $id] = $this->target('professional');
        $link = $this->readingLink($this->get($detail)->assertOk()->getContent(), 'professional', $id);
        $this->get($link)->assertOk()->assertSessionHas('url.intended', route('lexa'))->assertDontSee('esa acción todavía no se ha realizado');
        $this->login($reader)->assertRedirect(route('lexa'))->assertSessionMissing('auth.reading_return')->assertSessionMissing('url.intended');
        $this->get(route('lexa'))->assertOk();
        $this->assertNoAutomaticActions();
    }

    public function test_existing_protected_intended_filters_are_preserved_and_win_over_new_context(): void
    {
        $reader = User::factory()->create();
        [, , , $id] = $this->target('post');
        $intended = route('messages', ['archived' => 1]);
        $this->withSession(['url.intended' => $intended])->get(ReadingReturn::loginUrl('post', $id))->assertSessionHas('url.intended', $intended);
        $this->login($reader)->assertRedirect($intended)->assertSessionMissing('auth.reading_return');
    }

    public function test_latest_explicit_valid_public_link_replaces_only_the_public_context(): void
    {
        $reader = User::factory()->create();
        [, , , $first] = $this->target('post');
        [, , $detail, $second] = $this->target('article');
        $this->get(ReadingReturn::loginUrl('post', $first));
        $this->get(ReadingReturn::loginUrl('article', $second));
        $this->login($reader)->assertRedirect($detail);
    }

    public function test_failed_credentials_keep_context_and_plain_login_register_navigation_does_not_extend_expiry(): void
    {
        [, , $detail, $id] = $this->target('article');
        $reader = User::factory()->create();
        $link = ReadingReturn::loginUrl('article', $id);
        $this->get($link);
        $expires = session('auth.reading_return.expires');
        $this->from($link)->post(route('login.store'), ['email' => $reader->email, 'password' => 'incorrect'])->assertSessionHasErrors('email')->assertSessionHas('auth.reading_return.expires', $expires);
        $this->travel(2)->minutes();
        $this->get(route('register'))->assertOk()->assertSessionHas('auth.reading_return.expires', $expires);
        $this->get(route('login'))->assertOk()->assertSessionHas('auth.reading_return.expires', $expires);
        $this->login($reader)->assertRedirect($detail);
    }

    public function test_expired_link_and_expired_pending_context_fall_back_to_home(): void
    {
        [, , , $id] = $this->target('post');
        $reader = User::factory()->create();
        $expired = URL::temporarySignedRoute('login', now()->subMinute(), ['read' => 'post', 'target' => $id], absolute: false);
        $this->get($expired)->assertOk()->assertSessionMissing('auth.reading_return');
        $this->get(ReadingReturn::loginUrl('post', $id));
        $this->travel(11)->minutes();
        $this->login($reader)->assertRedirect(route('home'))->assertSessionMissing('auth.reading_return');
        $this->assertNoAutomaticActions();
    }

    public static function unsafeIntended(): array
    {
        return array_map(fn ($value) => [$value], [
            'https://evil.example/lexa', '//evil.example/inicio', 'javascript:alert(1)', '/\\evil.example',
            'http://localhost@evil.example/inicio', 'http://user@localhost/inicio', '/iniciar-sesion', '/registro',
            '/cerrar-sesion', '/guardados/post/1', '/mensajes/conversaciones/1', '/restablecer-contrasena?token=PRIVATE',
            '/missing-route', '/%2f%2fevil.example', '/lexa#PRIVATE', '/lexa%0d%0aLocation:evil', ['external'],
        ]);
    }

    #[DataProvider('unsafeIntended')]
    public function test_untrusted_or_mutating_intended_destinations_cannot_drive_redirects(mixed $intended): void
    {
        $reader = User::factory()->create();
        $this->withSession(['url.intended' => $intended]);
        $this->login($reader)->assertRedirect(route('home'))->assertSessionMissing('url.intended');
        $this->assertNoAutomaticActions();
    }

    public function test_signed_link_cannot_be_tampered_or_replace_a_verified_pending_target(): void
    {
        [, , $detail, $id] = $this->target('resource');
        $reader = User::factory()->create();
        $link = ReadingReturn::loginUrl('resource', $id);
        $this->get($link)->assertOk();
        foreach ([str_replace('target='.$id, 'target='.($id + 1), $link), $link.'&return=https://evil.example',
            '/iniciar-sesion?read[]=post&target=1&signature[]=bad&expires[]=bad', '/iniciar-sesion?read=post&target=https://evil.example',
            '/iniciar-sesion?return=//evil.example', '/iniciar-sesion?read=messages.start&target=1'] as $invalid) {
            $this->get($invalid)->assertOk()->assertSessionHas('auth.reading_return.id', (string) $id);
        }
        $this->login($reader)->assertRedirect($detail);
    }

    public function test_unsigned_context_and_arbitrary_return_parameters_do_not_create_destinations(): void
    {
        $reader = User::factory()->create();
        foreach (['/iniciar-sesion?read=article&target=1', '/iniciar-sesion?return=https://evil.example', '/registro?next=/cerrar-sesion'] as $url) {
            $this->get($url)->assertOk()->assertSessionMissing('auth.reading_return')->assertSessionMissing('url.intended');
        }
        $this->login($reader)->assertRedirect(route('home'));
    }

    public static function unavailable(): array
    {
        $cases = [];
        foreach (['professional', 'user', 'post', 'article', 'resource'] as $type) {
            foreach (['hidden', 'deleted', 'inactive-author'] as $state) {
                $cases[] = [$type, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('unavailable')]
    public function test_inaccessible_content_returns_to_a_safe_listing_without_disclosing_it(string $type, string $state): void
    {
        [$author, $target, , $id, $listing] = $this->target($type);
        $reader = User::factory()->create();
        $this->get(ReadingReturn::loginUrl($type, $id))->assertOk();
        if ($state === 'inactive-author') {
            $author->status = 'suspended';
            $author->save();
        } elseif ($type === 'professional' || $type === 'user') {
            if ($state === 'deleted') {
                $target->delete();
            } else {
                $target->forceFill(['visibility' => 'private'])->save();
            }
        } else {
            $target->forceFill($state === 'deleted' ? ['status' => 'deleted', 'deleted_at' => now()] : ['status' => 'hidden'])->save();
        }
        $response = $this->login($reader)->assertRedirect(route($listing))->assertSessionHas('auth_reading_notice')->assertSessionMissing('auth.reading_return');
        $this->assertStringNotContainsString((string) $id, parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $this->get(route($listing))->assertOk()->assertSee('No pudimos volver al contenido solicitado.');
        $this->assertNoAutomaticActions();
    }

    public function test_logout_consumption_and_account_switch_cannot_restore_another_identity_context(): void
    {
        [, , $detail, $id] = $this->target('professional');
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->get(ReadingReturn::loginUrl('professional', $id));
        $this->login($first)->assertRedirect($detail);
        $this->get(ReadingReturn::loginUrl('professional', $id))->assertRedirect(route('home'))->assertSessionMissing('auth.reading_return');
        $this->post(route('logout'))->assertRedirect(route('landing'))->assertSessionMissing('auth.reading_return')->assertSessionMissing('url.intended');
        $this->login($second)->assertRedirect(route('home'));
        $this->assertNoAutomaticActions();
    }

    public function test_old_signed_link_cannot_restore_context_after_logout_or_in_a_different_session(): void
    {
        [, , $detail, $id] = $this->target('post');
        $first = User::factory()->create();
        $second = User::factory()->create();
        $link = ReadingReturn::loginUrl('post', $id);
        $this->get($link)->assertSessionHas('auth.reading_return');
        $this->login($first)->assertRedirect($detail)->assertSessionMissing('auth.reading_flow');
        $this->post(route('logout'));
        $this->get($link)->assertOk()->assertSessionMissing('auth.reading_return');
        // Even rendering fresh links for the new guest must not revive the previous flow.
        $this->get($detail)->assertOk();
        $this->get($link)->assertOk()->assertSessionMissing('auth.reading_return');
        $this->login($second)->assertRedirect(route('home'));
        $this->assertNoAutomaticActions();
    }

    public function test_signed_reading_link_does_not_bypass_native_csrf_on_login(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        [, , $detail, $id] = $this->target('user');
        $reader = User::factory()->create();
        $this->get(ReadingReturn::loginUrl('user', $id))->assertOk();
        $this->login($reader)->assertStatus(419)->assertSessionHas('auth.reading_return');
        $this->assertGuest();
        $this->post(route('login.store'), ['_token' => session()->token(), 'email' => $reader->email, 'password' => 'password'])->assertRedirect($detail);
        $this->assertNoAutomaticActions();
    }

    public function test_inactive_login_does_not_consume_or_authorize_the_pending_action(): void
    {
        [, , , $id] = $this->target('post');
        $reader = User::factory()->create();
        $reader->status = 'suspended';
        $reader->save();
        $this->get(ReadingReturn::loginUrl('post', $id));
        $this->login($reader)->assertSessionHasErrors('email')->assertSessionHas('auth.reading_return');
        $this->assertGuest();
        $this->assertNoAutomaticActions();
    }

    public function test_manual_save_and_contact_still_require_current_authorization(): void
    {
        [$author, $post, $detail, $id] = $this->target('post');
        $reader = User::factory()->create();
        $this->get(ReadingReturn::loginUrl('post', $id));
        $this->login($reader)->assertRedirect($detail);
        $this->assertNoAutomaticActions();
        $post->forceFill(['visibility' => 'private'])->save();
        $this->put(route('saved.store', ['type' => 'post', 'target' => $id]))->assertNotFound();
        $author->status = 'suspended';
        $author->save();
        $this->post(route('messages.start', $author->id))->assertNotFound();
        $this->assertNoAutomaticActions();
    }

    public function test_context_capture_has_no_database_lookup_and_keeps_native_forms_without_javascript(): void
    {
        [, , , $id] = $this->target('article');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->get(ReadingReturn::loginUrl('article', $id))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $queries);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//form[@method="POST" and @data-turbo="false"]//input[@name="_token"]')->length);
        $this->assertSame(0, $xpath->query('//form//input[@name="return" or @name="read" or @name="target"]')->length);
        $this->assertSame(1, $xpath->query('//a[@href="'.route('register').'"]')->length);
    }
}
