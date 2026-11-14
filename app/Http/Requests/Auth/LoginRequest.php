<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => $this->passwordRules(),
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function authenticate(): void
    {
        $key = 'auth.login:'.hash('sha256', $this->input('email').'|'.$this->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            event(new Lockout($this));
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => "Demasiados intentos. Inténtalo de nuevo en {$seconds} segundos.",
            ])->status(429);
        }

        $authenticated = Auth::guard('web')->attemptWhen(
            $this->only('email', 'password'),
            fn (User $user) => User::whereKey($user->id)->sharedLock()->first()?->isActive() === true,
            $this->boolean('remember'),
        );

        if (! $authenticated) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                'email' => 'El correo o la contraseña no son correctos, o la cuenta no está disponible.',
            ]);
        }

        RateLimiter::clear($key);
    }
}
