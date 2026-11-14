<?php

use App\Exceptions\AttachmentException;
use App\Exceptions\CommunityException;
use App\Http\Middleware\EnsureActiveAccount;
use App\Support\DatabaseFailureReporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->routeIs('community', 'community.*')) {
                return null;
            }
            $status = null;
            if ($error instanceof QueryException || $error instanceof CommunityException || $error instanceof AttachmentException) {
                $status = 503;
                $message = 'No pudimos completar la operación de Comunidad. Inténtalo nuevamente.';
            } elseif ($error instanceof AuthorizationException
                || ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 403)) {
                $status = $request->routeIs('community.show', 'community.media', 'community.connections') ? 404 : 403;
                $message = 'El contenido no está disponible o no tienes permiso para esta operación.';
            } elseif ($error instanceof ValidationException && (isset($error->errors()['version']) || $request->isMethod('get'))) {
                $status = $request->attributes->get('community.input_validated') && isset($error->errors()['version']) ? 409 : 422;
                $message = $status === 409 ? 'El contenido cambió. Tus cambios no se guardaron. Abre de nuevo la publicación antes de editar.'
                    : (isset($error->errors()['version']) ? 'La versión no es válida. Abre de nuevo el contenido antes de realizar esta acción.' : 'Revisa los filtros o el cursor de paginación.');
            }
            if ($status === null) {
                return null;
            }

            return ($request->expectsJson() ? response()->json(['message' => $message], $status)
                : response()->view('pages.community.error', compact('status', 'message'), $status))
                ->header('Cache-Control', 'private, no-store');
        });
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
