<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        if ($actor = $guard->user()) {
            $current = User::whereKey($actor->getAuthIdentifier())->sharedLock()->first();
            if (! $current || ! $current->isActive()) {
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                $response = $request->expectsJson()
                    ? response()->json(['message' => 'La sesión ya no está disponible. Inicia sesión de nuevo.'], 401)
                    : redirect()->route('login')->withErrors(['email' => 'La sesión ya no está disponible. Inicia sesión de nuevo.']);

                return $this->uncached($response);
            }
            $guard->setUser($current);
        }

        try {
            // Compare Laravel's credential stamp only after reloading the account.
            $response = app(AuthenticateSession::class)->handle($request, $next);
        } catch (AuthenticationException) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->uncached($request->expectsJson()
                ? response()->json(['message' => 'La sesión ya no está disponible. Inicia sesión de nuevo.'], 401)
                : redirect()->route('login'));
        }
        if ($guard->check() || $request->routeIs('login', 'login.store', 'register', 'register.store', 'logout')) {
            return $this->uncached($response);
        }

        return $response;
    }

    private function uncached(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
