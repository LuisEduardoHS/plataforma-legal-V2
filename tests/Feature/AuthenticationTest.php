<?php

namespace Tests\Feature;

use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Models\UserProfile;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\EnforcedCsrfToken;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        DB::beginTransaction();
        $this->seed(RolePermissionSeeder::class);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        try {
            Event::forget('eloquent.created: '.User::class);
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function registration(array $changes = []): array
    {
        return array_replace([
            'name' => 'María Ejemplo', 'email' => 'maria@example.com',
            'password' => 'ContraseñaSegura42', 'password_confirmation' => 'ContraseñaSegura42',
        ], $changes);
    }

    public function test_registration_creates_only_an_active_conventional_account_and_rotates_the_session(): void
    {
        $this->withSession(['_token' => 'before-registration']);
        $before = session()->getId();
        $response = $this->post(route('register.store'), $this->registration(['email' => ' MARIA@EXAMPLE.COM ']));
        $response->assertRedirect(route('home'))->assertSessionHasNoErrors();
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('maria@example.com', $user->email);
        $this->assertSame('active', $user->status);
        $this->assertTrue(Hash::check('ContraseñaSegura42', $user->password));
        $this->assertNotSame('ContraseñaSegura42', $user->getRawOriginal('password'));
        $this->assertSame(['user'], $user->getRoleNames()->all());
        $this->assertFalse($user->can('users.manage'));
        $this->assertSame(0, $user->permissions()->count());
        $this->assertNull($user->email_verified_at);
        $this->assertSame(0, UserProfile::count());
        $this->assertSame(0, ProfessionalProfile::count());
        $this->assertNotSame($before, session()->getId());
        $this->assertNotSame('before-registration', session()->token());
        $this->get(route('home'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function invalidRegistrations(): array
    {
        return [
            'empty name' => [['name' => ''], 'name'],
            'bad email' => [['email' => 'not-an-email'], 'email'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'mismatch' => [['password_confirmation' => 'AnotherPassword42'], 'password'],
            'missing confirmation' => [['password_confirmation' => null], 'password_confirmation'],
            'bcrypt byte boundary' => [['password' => str_repeat('á', 37), 'password_confirmation' => str_repeat('á', 37)], 'password'],
            'null byte' => [['password' => "LongPassword\0Secret42", 'password_confirmation' => "LongPassword\0Secret42"], 'password'],
        ];
    }

    #[DataProvider('invalidRegistrations')]
    public function test_invalid_registration_never_creates_a_user_or_flashes_passwords(array $changes, string $field): void
    {
        $this->from(route('register'))->post(route('register.store'), $this->registration($changes))
            ->assertRedirect(route('register'))->assertSessionHasErrors($field)
            ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_email_is_unique_after_case_and_space_normalization(): void
    {
        User::factory()->create(['email' => 'maria@example.com']);
        $this->post(route('register.store'), $this->registration(['email' => ' MARIA@EXAMPLE.COM ']))
            ->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
        $this->assertGuest();
    }

    public function test_old_input_is_escaped_and_credentials_are_not_rendered_after_validation(): void
    {
        $name = '<img src=x onerror=alert(1)>';
        $this->from(route('register'))->post(route('register.store'), $this->registration(['name' => $name, 'email' => 'invalid']))
            ->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password_confirmation');
        $this->get(route('register'))->assertOk()->assertSee(e($name), escape: false)
            ->assertDontSee($name, escape: false)->assertDontSee('ContraseñaSegura42');
    }

    public function test_rejected_arrays_and_oversized_values_cannot_break_the_next_form_render(): void
    {
        foreach (['login' => ['email' => [], 'password' => [], 'remember' => []],
            'register' => ['name' => [], 'email' => [], 'password' => [], 'password_confirmation' => []]] as $name => $data) {
            $this->from(route($name))->post(route($name.'.store'), $data)->assertSessionHasErrors();
            $this->assertSame([], session('_old_input'));
            $this->get(route($name))->assertOk();
        }
        $this->from(route('register'))->post(route('register.store'), $this->registration(['name' => str_repeat('a', 1000)]))
            ->assertSessionHasErrors('name');
        $this->assertSame(255, mb_strlen(session('_old_input.name')));
        $this->get(route('register'))->assertOk();
        $this->assertSame(0, User::count());
    }

    public static function internalFields(): array
    {
        return [
            [['roles' => ['admin']]], [['role' => 'admin']], [['permissions' => ['roles.manage']]],
            [['status' => 'verified']], [['email_verified_at' => '2026-11-14']],
            [['professional_profile' => ['verification_status' => 'verified']]], [['user_id' => 1]],
        ];
    }

    #[DataProvider('internalFields')]
    public function test_registration_rejects_internal_fields_instead_of_assigning_them(array $extra): void
    {
        $this->post(route('register.store'), $this->registration($extra))->assertSessionHasErrors('form');
        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('model_has_roles')->count());
        foreach (array_keys($extra) as $key) {
            $this->assertNull(session('_old_input.'.$key));
        }
    }

    public function test_missing_conventional_role_does_not_create_an_orphan_or_bootstrap_privileges(): void
    {
        config(['app.debug' => false]);
        Role::findByName('user')->delete();
        $this->post(route('register.store'), $this->registration())->assertStatus(503);
        $this->assertSame(0, User::count());
        $this->assertNull(Role::where('name', 'user')->first());
        $this->assertGuest();
    }

    public function test_database_failure_after_account_insertion_rolls_back_registration(): void
    {
        config(['app.debug' => false]);
        Event::listen('eloquent.created: '.User::class, function (): void {
            $previous = new \PDOException('private SQL diagnostic');
            $previous->errorInfo = ['HY000', 9999, 'private SQL diagnostic'];
            throw new QueryException('mysql', 'insert into users values (?)', ['private credential'], $previous);
        });
        $this->post(route('register.store'), $this->registration())->assertStatus(503)
            ->assertDontSee('private credential')->assertDontSee('private SQL diagnostic');
        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('model_has_roles')->count());
        $this->assertGuest();
    }

    public function test_login_authenticates_real_credentials_and_rotates_session_and_csrf(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com']);
        $this->withSession(['_token' => 'before-login', 'harmless' => 'kept']);
        $before = session()->getId();
        $this->post(route('login.store'), ['email' => ' MARIA@EXAMPLE.COM ', 'password' => 'password'])
            ->assertRedirect(route('home'))->assertSessionHasNoErrors()->assertSessionHas('harmless', 'kept');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
        $this->assertNotSame('before-login', session()->token());
        Auth::forgetGuards();
        $this->get(route('home'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_and_unknown_credentials_have_the_same_generic_error_and_safe_old_input(): void
    {
        User::factory()->create(['email' => 'maria@example.com']);
        $messages = [];
        foreach (['maria@example.com', 'unknown@example.com'] as $email) {
            $this->from(route('login'))->post(route('login.store'), ['email' => $email, 'password' => 'wrong-password'])
                ->assertRedirect(route('login'))->assertSessionHasErrors('email')
                ->assertSessionMissing('_old_input.password');
            $messages[] = session('errors')->first('email');
            $this->assertGuest();
        }
        $this->assertSame($messages[0], $messages[1]);
        $this->get(route('login'))->assertOk()->assertSee('unknown@example.com')->assertDontSee('wrong-password');
    }

    public function test_logout_invalidates_session_and_expires_remember_cookie_using_post(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com']);
        $login = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
        $recaller = Auth::guard('web')->getRecallerName();
        $login->assertCookie($recaller);
        $this->withCookie($recaller, $login->getCookie($recaller)->getValue());
        $this->withCookie(config('session.cookie'), $login->getCookie(config('session.cookie'))->getValue());
        $oldRemember = $user->fresh()->remember_token;
        $this->assertNotEmpty($oldRemember);
        $this->withSession(['private-marker' => 'discard']);
        $before = session()->getId();
        $token = session()->token();
        $this->post(route('logout'))->assertRedirect(route('landing'))
            ->assertSessionMissing('private-marker')->assertCookieExpired(Auth::guard('web')->getRecallerName());
        $this->assertGuest();
        $this->assertNotSame($before, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->assertNotSame($oldRemember, $user->fresh()->remember_token);
        $this->get(route('logout'))->assertStatus(405);
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_session_intended_destination_and_guest_only_pages_are_coherent(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com']);
        $this->get(route('messages'))->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('messages'));
        $this->get(route('login'))->assertRedirect(route('home'));
        $this->get(route('register'))->assertRedirect(route('home'));
        $this->post(route('register.store'), $this->registration())->assertRedirect(route('home'));
        $this->assertSame(1, User::count());
        $this->get(route('landing'))->assertRedirect(route('home'))->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('/dashboard');
    }

    public function test_remember_cookie_restores_an_active_account_but_cannot_bypass_later_suspension(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com']);
        $login = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
        $cookie = Auth::guard('web')->getRecallerName();
        $this->withCookie($cookie, $login->getCookie($cookie)->getValue());
        session()->invalidate();
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->get(route('home'))->assertOk();
        $this->assertTrue(Auth::guard('web')->viaRemember());
        $this->assertAuthenticatedAs($user);

        DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']);
        session()->invalidate();
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->get(route('home'))->assertRedirect(route('login'))->assertCookieExpired($cookie);
        $this->assertGuest();
    }

    public function test_a_deleted_account_cannot_continue_using_a_cached_authenticated_model(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->delete();
        $this->getJson(route('home'))->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_public_routes_remain_open_while_private_routes_require_authentication(): void
    {
        foreach (['landing', 'community', 'professionals', 'articles', 'help'] as $name) {
            $this->get(route($name))->assertOk();
        }
        foreach (['home', 'messages', 'lexa', 'saved', 'profile', 'settings'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
        $this->getJson(route('messages'))->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach (['home', 'messages', 'lexa', 'saved', 'profile', 'settings'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public static function inactiveStates(): array
    {
        return [['suspended'], ['disabled'], ['pending_deletion']];
    }

    #[DataProvider('inactiveStates')]
    public function test_inactive_accounts_cannot_login_even_with_an_admin_role(string $status): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com', 'status' => $status]);
        $user->assignRole('admin');
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[DataProvider('inactiveStates')]
    public function test_a_session_is_revoked_using_persisted_state_and_stale_admin_cannot_bypass_it(string $status): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user)->withSession(['private-marker' => 'discard']);
        $before = session()->getId();
        DB::table('users')->where('id', $user->id)->update(['status' => $status]);
        $this->assertTrue($user->isActive());
        $this->get(route('home'))->assertRedirect(route('login'))->assertSessionHasErrors('email')
            ->assertSessionMissing('private-marker')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertGuest();
        $this->assertNotSame($before, session()->getId());
        $this->get(route('login'))->assertOk();
    }

    public function test_inactive_session_on_public_or_guest_routes_is_cleared_without_a_redirect_loop(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']);
        $this->get(route('login'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();
        $this->assertGuest();
        $this->actingAs($user);
        $this->getJson(route('community'))->assertUnauthorized()->assertJsonMissing(['email' => $user->email]);
        $this->assertGuest();
        $this->get(route('community'))->assertOk();
    }

    public function test_login_failures_are_limited_per_normalized_identity_and_ip_then_expire(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('login.store'), ['email' => 'MARIA@EXAMPLE.COM', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_clears_the_identity_failure_counter(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com']);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $key = 'auth.login:'.hash('sha256', $user->email.'|127.0.0.1');
        $this->assertSame(1, RateLimiter::attempts($key));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('home'));
        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_ip_limits_also_bound_malformed_login_and_registration_requests(): void
    {
        foreach (['login.store' => 30, 'register.store' => 10] as $name => $limit) {
            for ($i = 0; $i < $limit; $i++) {
                $this->postJson(route($name), [])->assertUnprocessable();
            }
            $this->postJson(route($name), [])->assertStatus(429)->assertHeader('Retry-After');
        }
        $this->assertSame(0, User::count());
    }

    public function test_real_csrf_middleware_rejects_missing_or_wrong_tokens_and_accepts_matching_tokens(): void
    {
        // Laravel normally bypasses CSRF in tests. This binding exercises the actual check.
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $user = User::factory()->create(['email' => 'maria@example.com']);
        foreach (['login.store', 'register.store'] as $name) {
            $this->withSession(['_token' => 'csrf-token']);
            $data = $name === 'register.store' ? $this->registration() : ['email' => $user->email, 'password' => 'password'];
            $this->post(route($name), $data)->assertStatus(419);
            $this->post(route($name), $data + ['_token' => 'wrong'])->assertStatus(419);
        }
        $this->withSession(['_token' => 'csrf-token']);
        $this->post(route('login.store'), ['_token' => 'csrf-token', 'email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('home'));
        $token = session()->token();
        $this->post(route('logout'))->assertStatus(419);
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'), ['_token' => $token])->assertRedirect(route('landing'));
        $this->assertGuest();
        $token = session()->token();
        $this->post(route('register.store'), $this->registration(['email' => 'new@example.com']) + ['_token' => $token])
            ->assertRedirect(route('home'));
        $this->assertSame(2, User::count());
    }

    public function test_database_sessions_survive_rehydration_and_old_session_ids_are_destroyed(): void
    {
        config(['session.driver' => 'database', 'session.connection' => DB::getDefaultConnection(), 'session.lottery' => [0, 100]]);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth.driver');
        Auth::forgetGuards();
        $user = User::factory()->create(['email' => 'maria@example.com']);
        $cookie = config('session.cookie');
        $loginPage = $this->get(route('login'))->assertOk()->assertCookie($cookie);
        $oldId = session()->getId();
        $this->assertDatabaseHas('sessions', ['id' => $oldId]);
        $this->withCookie($cookie, $loginPage->getCookie($cookie)->getValue());
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth.driver');
        Auth::forgetGuards();
        $login = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('home'));
        $authenticatedId = session()->getId();
        $this->assertNotSame($oldId, $authenticatedId);
        $this->assertDatabaseMissing('sessions', ['id' => $oldId]);
        $this->assertDatabaseHas('sessions', ['id' => $authenticatedId, 'user_id' => $user->id]);
        $this->withCookie($cookie, $login->getCookie($cookie)->getValue());
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth.driver');
        Auth::forgetGuards();
        $this->get(route('home'))->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'))->assertRedirect(route('landing'));
        $this->assertDatabaseMissing('sessions', ['id' => $authenticatedId]);
        $this->assertGuest();
    }
}
