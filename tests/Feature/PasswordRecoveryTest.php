<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordRecoveryLink;
use App\Models\OperationsEvent;
use App\Models\User;
use App\Notifications\AccountPasswordReset;
use App\Services\Auth\PasswordRecovery;
use App\Services\Auth\RecoveryMailer;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\EnforcedCsrfToken;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments');
        $this->assertStringContainsString('framework/testing/disks/attachments', str_replace('\\', '/', Storage::disk('attachments')->path('')));
        DB::beginTransaction();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Event::forget('eloquent.creating: '.OperationsEvent::class);
        Event::forget(MessageLogged::class);
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function credentials(User $user, string $token, array $overrides = []): array
    {
        return $overrides + ['email' => $user->email, 'token' => $token, 'password' => 'NuevaContraseña42', 'password_confirmation' => 'NuevaContraseña42'];
    }

    public function test_existing_missing_and_inactive_addresses_get_the_same_public_acknowledgement(): void
    {
        $active = User::factory()->create();
        $inactive = User::factory()->create(['status' => 'suspended']);
        $a = $this->postJson(route('password.email'), ['email' => $active->email])->assertOk();
        $b = $this->postJson(route('password.email'), ['email' => 'missing@example.test'])->assertOk();
        $c = $this->postJson(route('password.email'), ['email' => $inactive->email])->assertOk();
        $this->assertSame($a->json(), $b->json());
        $this->assertSame($a->json(), $c->json());
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $active->email]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $inactive->email]);
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $body = $messages->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('/restablecer-contrasena#token=', html_entity_decode($body));
        $this->assertStringNotContainsString('/restablecer-contrasena?token=', $body);
        preg_match('/#token=([a-f0-9]{64})/', html_entity_decode($body), $matches);
        $this->assertTrue(Hash::check($matches[1], DB::table('password_reset_tokens')->where('email', $active->email)->value('token')));
        $this->assertStringNotContainsString($matches[1], $a->getContent());
    }

    public function test_valid_reset_is_atomic_audited_and_single_use(): void
    {
        $user = User::factory()->create(['remember_token' => 'remember-before']);
        $token = Password::broker()->createToken($user);
        $this->postJson(route('password.update'), $this->credentials($user, $token))->assertOk();
        $this->assertTrue(Hash::check('NuevaContraseña42', $user->fresh()->password));
        $this->assertNotSame('remember-before', $user->fresh()->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $event = OperationsEvent::where('target_user_id', $user->id)->sole();
        $this->assertSame('password_reset', $event->action);
        $this->assertSame('recovery', $event->source);
        $this->assertEquals(['credential_changed' => true, 'remember_rotated' => true], $event->after_state);
        $this->assertStringNotContainsString($token, $event->toJson());
        $this->postJson(route('password.update'), $this->credentials($user, $token))->assertUnprocessable();
        $this->assertSame(1, OperationsEvent::where('target_user_id', $user->id)->count());
    }

    public function test_expired_wrong_and_inactive_tokens_are_rejected_uniformly(): void
    {
        $user = User::factory()->create();
        $before = $user->password;
        $token = Password::broker()->createToken($user);
        $wrong = $this->postJson(route('password.update'), $this->credentials($user, str_repeat('a', 64)))->assertUnprocessable();
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(61)]);
        $expired = $this->postJson(route('password.update'), $this->credentials($user, $token))->assertUnprocessable();
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()]);
        $user->forceFill(['status' => 'suspended'])->save();
        $inactive = $this->postJson(route('password.update'), $this->credentials($user, $token))->assertUnprocessable();
        $this->assertSame($wrong->json(), $expired->json());
        $this->assertSame($wrong->json(), $inactive->json());
        $this->assertFalse(app(PasswordRecovery::class)->reset($this->credentials($user, $token, ['status' => 'suspended'])));
        $this->assertSame($before, $user->fresh()->password);
        $this->assertSame(0, OperationsEvent::where('target_user_id', $user->id)->count());
    }

    public function test_policy_and_unexpected_fields_do_not_consume_the_token(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        foreach ([['password' => 'short', 'password_confirmation' => 'short'], ['password' => str_repeat('é', 40), 'password_confirmation' => str_repeat('é', 40)], ['password_confirmation' => 'different'], ['role' => 'admin'], ['token' => 'invalid']] as $bad) {
            $this->postJson(route('password.update'), $this->credentials($user, $token, $bad))->assertUnprocessable();
        }
        $this->assertTrue(Password::broker()->tokenExists($user, $token));
        $this->travel(61)->seconds();
        $this->post(route('password.update'), $this->credentials($user, $token, ['password_confirmation' => 'different']))->assertRedirect(route('password.reset'));
        $this->assertNull(session('_old_input.token'));
        $this->assertNull(session('_old_input.password'));
        $this->assertNull(session('_old_input.password_confirmation'));
    }

    public function test_reset_rollback_preserves_password_and_token_when_audit_sql_fails(): void
    {
        $user = User::factory()->create();
        $before = $user->password;
        $token = Password::broker()->createToken($user);
        Event::listen('eloquent.creating: '.OperationsEvent::class, function () {
            throw new QueryException('mysql', 'INSERT secret password token', ['private-binding'], new \RuntimeException('synthetic'));
        });
        $this->postJson(route('password.update'), $this->credentials($user, $token))->assertStatus(503)->assertDontSee('private-binding')->assertDontSee($token);
        $this->assertSame($before, $user->fresh()->password);
        $this->assertTrue(Password::broker()->tokenExists($user, $token));
    }

    public function test_unexpected_http_and_runtime_errors_never_reflect_recovery_secrets_even_in_debug(): void
    {
        config(['app.debug' => true]);
        $logs = [];
        Log::listen(function (MessageLogged $event) use (&$logs) {
            $logs[] = json_encode([$event->message, $event->context], JSON_PARTIAL_OUTPUT_ON_ERROR);
        });
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $before = $user->password;
        foreach ([new HttpException(500, 'synthetic-sensitive-error '.$token), new \RuntimeException('synthetic-sensitive-error '.$token)] as $error) {
            Event::forget('eloquent.creating: '.OperationsEvent::class);
            Event::listen('eloquent.creating: '.OperationsEvent::class, fn () => throw $error);
            foreach ([true, false] as $json) {
                $response = $json ? $this->postJson(route('password.update'), $this->credentials($user, $token))
                    : $this->post(route('password.update'), $this->credentials($user, $token));
                $response->assertStatus(500)->assertDontSee('synthetic-sensitive-error')->assertDontSee($token)->assertDontSee('NuevaContraseña42')->assertHeader('Cache-Control', 'no-store, private');
            }
        }
        $this->assertSame($before, $user->fresh()->password);
        $this->assertTrue(Password::broker()->tokenExists($user, $token));
        $this->assertNotEmpty($logs);
        $this->assertStringNotContainsString($token, implode('\n', $logs));
        $this->assertStringNotContainsString('NuevaContraseña42', implode('\n', $logs));
        $this->assertStringNotContainsString('synthetic-sensitive-error', implode('\n', $logs));
    }

    public function test_native_password_stamp_rejects_an_existing_session_after_recovery(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/inicio')->assertOk();
        $oldStamp = Auth::guard('web')->hashPasswordForCookie($user->password);
        $token = Password::broker()->createToken($user);
        $this->assertTrue(app(PasswordRecovery::class)->reset($this->credentials($user, $token)));
        $this->withSession(['password_hash_web' => $oldStamp])->getJson('/inicio')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_recovery_rejects_old_remember_cookie_and_hashes_even_hash_shaped_passwords(): void
    {
        $user = User::factory()->create();
        $login = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => '1'])->assertRedirect(route('home'));
        $cookie = Auth::guard('web')->getRecallerName();
        $this->withCookie($cookie, $login->getCookie($cookie)->getValue());
        $token = Password::broker()->createToken($user);
        $hashShapedPassword = Hash::make('AnotherPassword42');
        $this->assertTrue(app(PasswordRecovery::class)->reset($this->credentials($user, $token, ['password' => $hashShapedPassword, 'password_confirmation' => $hashShapedPassword])));
        $this->assertTrue(Hash::check($hashShapedPassword, $user->fresh()->password));
        $this->assertFalse(Hash::check('AnotherPassword42', $user->fresh()->password));
        session()->invalidate();
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->get(route('home'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_csrf_and_rate_limits_apply_without_account_enumeration(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $this->postJson(route('password.email'), ['email' => 'missing@example.test'])->assertStatus(419);
        $this->withSession(['_token' => 'synthetic-csrf']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('password.email'), ['_token' => 'synthetic-csrf', 'email' => 'missing@example.test'])->assertOk();
        }
        $this->postJson(route('password.email'), ['_token' => 'synthetic-csrf', 'email' => 'missing@example.test'])->assertStatus(429);
    }

    public function test_forms_never_echo_query_tokens_and_use_no_store_and_no_referrer(): void
    {
        $this->get(route('password.request'))->assertOk()->assertSee('sin entrega externa')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('password.reset', ['token' => 'sensitive-query']))->assertOk()->assertDontSee('sensitive-query')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_every_address_dispatches_the_same_encrypted_job_without_token_creation_in_http(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
        $this->postJson(route('password.email'), ['email' => 'missing@example.test'])->assertOk();
        Queue::assertPushed(SendPasswordRecoveryLink::class, 2);
        $this->assertInstanceOf(ShouldBeEncrypted::class, new SendPasswordRecoveryLink($user->email));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_testing_cannot_select_a_real_mail_transport_or_log_link(): void
    {
        config(['mail.default' => 'smtp']);
        $this->assertSame('array', app(RecoveryMailer::class)->name());
        $user = User::factory()->create();
        $message = (new AccountPasswordReset(str_repeat('a', 64)))->toMail($user);
        $this->assertSame('array', $message->mailer);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/'), $message->actionUrl);
    }

    public function test_production_without_a_safe_transport_or_async_queue_fails_before_lookup(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->withSession(['_token' => 'production-synthetic-csrf']);
            config(['mail.default' => 'log']);
            $this->postJson(route('password.email'), ['_token' => 'production-synthetic-csrf', 'email' => 'missing@example.test'])->assertStatus(503)->assertDontSee('trace')->assertDontSee('exception');
            config(['mail.default' => 'smtp', 'queue.default' => 'sync']);
            $this->postJson(route('password.email'), ['_token' => 'production-synthetic-csrf', 'email' => 'missing@example.test'])->assertStatus(503);
            $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_database_queue_payload_encrypts_email_before_persistence(): void
    {
        $email = 'encrypted-address@example.test';
        $id = app('queue')->connection('database')->push(new SendPasswordRecoveryLink($email));
        $payload = DB::table('jobs')->where('id', $id)->value('payload');
        $this->assertStringNotContainsString($email, $payload);
        $command = json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data']['command'];
        $this->assertStringContainsString($email, app('encrypter')->decrypt($command));
        $this->assertDatabaseHas('jobs', ['id' => $id]);
    }
}
