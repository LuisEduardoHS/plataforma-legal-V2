<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
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

        $response = $next($request);
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
