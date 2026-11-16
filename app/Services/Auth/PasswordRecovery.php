<?php

namespace App\Services\Auth;

use App\Jobs\SendPasswordRecoveryLink;
use App\Models\User;
use App\Notifications\AccountPasswordReset;
use App\Services\Operations\OperationsAudit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class PasswordRecovery
{
    public function __construct(private RecoveryMailer $mailer, private OperationsAudit $audit) {}

    public function request(string $email): void
    {
        $this->mailer->assertAvailable();
        // Same encrypted job for every syntactically valid email. No public lookup.
        SendPasswordRecoveryLink::dispatch($email);
    }

    public function deliver(#[\SensitiveParameter] string $email): void
    {
        try {
            $this->mailer->assertAvailable();
            Password::broker()->sendResetLink(['email' => $email, 'status' => 'active'], function ($user, $token) {
                $user->notify(new AccountPasswordReset($token));
                event(new PasswordResetLinkSent($user));
            });
        } catch (QueryException $error) {
            report($error); // Existing terminal SQL sanitizer, never bindings.
        } catch (Throwable $error) {
            // SMTP exceptions can contain credentials, message bodies and reset links.
            Log::warning('password_recovery.delivery_failed', ['exception_class' => get_class($error)]);
        }
    }

    public function reset(#[\SensitiveParameter] array $credentials): bool
    {
        return DB::transaction(function () use ($credentials) {
            // Serialize token consumption with password/state changes. The broker
            // still owns token generation, hashing, expiry and one-use deletion.
            User::where('email', $credentials['email'])->lockForUpdate()->first();
            $table = config('auth.passwords.'.config('auth.defaults.passwords').'.table');
            DB::table($table)->where('email', $credentials['email'])->lockForUpdate()->first();
            $status = Password::broker()->reset(array_replace($credentials, ['status' => 'active']), function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                $this->audit->record($user->id, $user->id, 'password_reset', 'user', $user->id, [],
                    ['credential_changed' => true, 'remember_rotated' => true], 'El titular utilizó un token válido de recuperación.', source: 'recovery');
                event(new PasswordReset($user));
            });

            return $status === Password::PASSWORD_RESET;
        });
    }
}
