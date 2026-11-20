<?php

use App\Exceptions\AttachmentException;
use App\Exceptions\CommunityException;
use App\Exceptions\ContentLibraryException;
use App\Exceptions\MessagingException;
use App\Exceptions\PasswordRecoveryUnavailable;
use App\Exceptions\VerificationException;
use App\Http\Middleware\EnsureActiveAccount;
use App\Support\DatabaseFailureReporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->routeIs('operations.*')) {
                return null;
            }
            $status = match (true) {
                $error instanceof AuthorizationException => 403,
                $error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 404, 409], true) => $error->getStatusCode(),
                $error instanceof ValidationException && $request->isMethodSafe() => 422,
                default => null,
            };
            if ($status === null) {
                return null;
            }
            $message = $status === 409 ? 'La cuenta o sus concesiones cambiaron. Consulta su estado actual antes de reintentar.'
                : ($status === 422 ? 'Revisa los filtros de consulta.' : 'La operación no está disponible para tu cuenta.');

            return ($request->expectsJson() ? response()->json(['message' => $message], $status)
                : response()->view('pages.operations.error', compact('message', 'status'), $status))->header('Cache-Control', 'private, no-store');
        });
        $exceptions->dontFlash(['license_number', 'token']);
        // Render SQL failures before route-specific errors, including validation
        // and session middleware. Keep programming exceptions on Laravel's path.
        $exceptions->render(function (QueryException $error, Request $request) {
            $message = $request->routeIs('profile*', 'professionals*')
                ? 'Los perfiles no están disponibles temporalmente.'
                : 'El servicio no está disponible temporalmente. Inténtalo nuevamente.';

            return ($request->expectsJson() ? response()->json(['message' => $message], 503)
                : response()->view('errors.503', compact('message'), 503))
                ->header('Cache-Control', 'private, no-store');
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->routeIs('password.*') || $error instanceof ValidationException
                || $error instanceof AuthenticationException) {
                return null;
            }
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof PasswordRecoveryUnavailable ? 503 : 500);
            $message = match ($status) {
                419 => 'La sesión del formulario caducó. Abre el formulario nuevamente.',
                429 => 'Se alcanzó el límite de intentos. Espera antes de volver a solicitarlo.',
                default => 'La recuperación no está disponible temporalmente. Inténtalo más tarde.',
            };
            $headers = $error instanceof HttpExceptionInterface ? $error->getHeaders() : [];

            return ($request->expectsJson() ? response()->json(['message' => $message], $status, $headers)
                : response($message, $status, $headers)->header('Content-Type', 'text/plain; charset=UTF-8'))
                ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->routeIs('verification', 'verification.*')) {
                return null;
            }
            $status = null;
            if ($error instanceof VerificationException || $error instanceof AttachmentException || $error instanceof LockTimeoutException) {
                $status = 503;
                $message = 'La verificación no está disponible temporalmente. Consulta el estado actual antes de reintentar.';
            } elseif ($error instanceof AuthorizationException || ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 404], true))) {
                $status = $request->route('dossier') ? 404 : 403;
                $message = 'El expediente o la operación no están disponibles para tu cuenta.';
            } elseif ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 409) {
                $status = 409;
                $message = 'El expediente cambió. Tus cambios no se guardaron. Abre su estado actual antes de volver a enviar.';
            } elseif ($error instanceof ValidationException && $request->isMethodSafe()) {
                $status = 422;
                $message = 'Revisa los filtros o los datos del formulario.';
            }
            if ($status === null) {
                return null;
            }

            return ($request->expectsJson() ? response()->json(['message' => $message], $status)
                : response()->view('pages.verification.error', compact('status', 'message'), $status))
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
        // Keep framework JSON errors minimal even with local debug enabled. Module contracts above take precedence.
        $exceptions->render(function (HttpExceptionInterface $error, Request $request) {
            $status = $error->getStatusCode();
            if (! $request->expectsJson() || ! in_array($status, [401, 403, 404, 409, 419, 429, 503], true)) {
                return null;
            }
            $message = match ($status) {
                401 => 'Inicia sesión para continuar.',
                403 => 'La acción no está disponible para tu cuenta.',
                404 => 'La página o el contenido no están disponibles.',
                409 => 'El estado cambió. Consulta los datos actuales antes de volver a enviar.',
                419 => 'La sesión o el formulario caducó. Abre una pantalla actual.',
                429 => 'Se alcanzó el límite de solicitudes. Espera antes de volver a intentarlo.',
                default => 'El servicio no está disponible temporalmente.',
            };

            return response()->json(['message' => $message], $status, $error->getHeaders());
        });
        $exceptions->respond(function ($response) {
            if (in_array($response->getStatusCode(), [401, 403, 404, 409, 419, 429, 503], true)) {
                $response->headers->set('Cache-Control', 'private, no-store');
            }

            return $response;
        });
        // Covers handled report($error) calls and unhandled QueryExceptions alike.
        $exceptions->report(function (QueryException $error): void {
            (new DatabaseFailureReporter)($error);
        })->stop();
        $exceptions->report(function (Throwable $error) {
            if (request()->routeIs('password.*')) {
                Log::error('password_recovery.request_failed', ['exception_class' => get_class($error)]);

                return false;
            }

            return null;
        });
    })->create();
