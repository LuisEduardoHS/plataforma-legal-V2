<?php

use App\Exceptions\AttachmentException;
use App\Exceptions\CommunityException;
use App\Exceptions\ContentLibraryException;
use App\Exceptions\MessagingException;
use App\Http\Middleware\EnsureActiveAccount;
use App\Support\DatabaseFailureReporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
        // Render SQL failures before route-specific errors, including validation
        // and session middleware. Keep programming exceptions on Laravel's path.
        $exceptions->render(function (QueryException $error, Request $request) {
            $message = $request->routeIs('profile*', 'professionals*')
                ? 'Los perfiles no están disponibles temporalmente.'
                : 'El servicio no está disponible temporalmente. Inténtalo nuevamente.';

            return ($request->expectsJson() ? response()->json(['message' => $message], 503)
                : response($message, 503)->header('Content-Type', 'text/plain; charset=UTF-8'))
                ->header('Cache-Control', 'private, no-store');
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->routeIs('messages', 'messages.*')) {
                return null;
            }
            $status = null;
            if ($error instanceof MessagingException || $error instanceof AttachmentException || $error instanceof LockTimeoutException) {
                $status = 503;
                $message = 'No pudimos completar la operación de Mensajes. Inténtalo nuevamente con el mismo envío.';
            } elseif ($error instanceof AuthorizationException || ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 403)) {
                $status = 404;
                $message = 'La conversación, mensaje o acción no está disponible para tu cuenta.';
            } elseif ($error instanceof ValidationException && ($request->isMethodSafe() || isset($error->errors()['version']) || isset($error->errors()['client_token']))) {
                $status = isset($error->errors()['version']) && $request->attributes->get('messaging.input_validated') ? 409 : 422;
                $message = $status === 409 ? 'El mensaje cambió. Tus cambios no se guardaron. Abre su versión actual antes de editar.'
                    : 'Revisa los datos o el cursor. Conserva el identificador y el contenido original al reintentar un envío.';
            }
            if ($status === null) {
                return null;
            }

            return ($request->expectsJson() ? response()->json(['message' => $message], $status)
                : response()->view('pages.messages.error', compact('status', 'message'), $status))->header('Cache-Control', 'private, no-store');
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->routeIs('articles', 'articles.*', 'resources', 'resources.*', 'saved', 'saved.*', 'home')) {
                return null;
            }
            $status = null;
            if ($error instanceof ContentLibraryException || $error instanceof AttachmentException || $error instanceof CommunityException) {
                $status = 503;
                $message = 'La biblioteca no está disponible temporalmente. Inténtalo nuevamente.';
            } elseif ($error instanceof AuthorizationException || ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 403)) {
                $status = $request->routeIs('articles.show', 'resources.show', 'articles.media', 'resources.media', 'articles.history', 'resources.history', 'saved.store') ? 404 : 403;
                $message = 'El contenido no está disponible o no tienes permiso para esta operación.';
            } elseif ($error instanceof ValidationException && ($request->isMethodSafe() || isset($error->errors()['version']))) {
                $status = isset($error->errors()['version']) && $request->attributes->get('content.input_validated') ? 409 : 422;
                $message = $status === 409 ? 'El contenido cambió. Tus cambios no se guardaron. Abre la versión actual antes de editar.' : 'Revisa los datos, filtros o el cursor. Abre de nuevo el formulario si su versión no es válida.';
            }
            if ($status === null) {
                return null;
            }

            return ($request->expectsJson() ? response()->json(['message' => $message], $status)
                : response()->view('pages.articles.error', compact('status', 'message'), $status))
                ->header('Cache-Control', 'private, no-store');
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->routeIs('community', 'community.*')) {
                return null;
            }
            $status = null;
            if ($error instanceof CommunityException || $error instanceof AttachmentException) {
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
        // Covers handled report($error) calls and unhandled QueryExceptions alike.
        $exceptions->report(function (QueryException $error): void {
            (new DatabaseFailureReporter)($error);
        })->stop();
    })->create();
