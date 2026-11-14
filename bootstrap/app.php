<?php

use App\Http\Middleware\EnsureActiveAccount;
use App\Support\DatabaseFailureReporter;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [EnsureActiveAccount::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureActiveAccount::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['license_number']);
        $exceptions->render(function (QueryException $error, Request $request) {
            // Includes validation/catalog queries and the persisted-account check.
            if ($request->routeIs('profile*', 'professionals*')) {
                $message = 'Los perfiles no están disponibles temporalmente.';

                return $request->expectsJson()
                    ? response()->json(['message' => $message], 503)
                    : response($message, 503)->header('Content-Type', 'text/plain; charset=UTF-8');
            }
        });
        // Covers handled report($error) calls and unhandled QueryExceptions alike.
        $exceptions->report(function (QueryException $error): void {
            (new DatabaseFailureReporter)($error);
        })->stop();
    })->create();
