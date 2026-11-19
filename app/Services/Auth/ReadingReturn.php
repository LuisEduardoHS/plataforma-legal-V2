<?php

namespace App\Services\Auth;

use App\Models\Article;
use App\Models\LegalResource;
use App\Models\Post;
use App\Models\ProfessionalProfile;
use App\Models\UserProfile;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReadingReturn
{
    private const KEY = 'auth.reading_return';

    private const FLOW = 'auth.reading_flow';

    private const MINUTES = 10;

    private const TARGETS = [
        'professional' => ['professionals.show', 'professionals'],
        'user' => ['profiles.show', 'professionals'],
        'post' => ['community.show', 'community'],
        'article' => ['articles.show', 'articles'],
        'resource' => ['resources.show', 'resources'],
    ];

    public static function loginUrl(string $type, int|string $id): string
    {
        if (! isset(self::TARGETS[$type]) || ! self::validId($id)) {
            return route('login', absolute: false);
        }

        $flow = session()->get(self::FLOW);
        if (! is_string($flow) || ! preg_match('/\A[a-zA-Z0-9]{32}\z/', $flow)) {
            $flow = Str::random(32);
            session()->put(self::FLOW, $flow);
        }

        // The flow tag is not a session ID or an authentication credential.
        return URL::temporarySignedRoute('login', now()->addMinutes(self::MINUTES), [
            'read' => $type, 'target' => (string) $id, 'flow' => $flow,
        ], absolute: false);
    }

    public function capture(Request $request): void
    {
        if ($request->user()) {
            return;
        }
        $type = $request->query('read');
        $id = $request->query('target');
        $expires = $request->query('expires');
        $signature = $request->query('signature');
        $flow = $request->query('flow');
        $sessionFlow = $request->session()->get(self::FLOW);
        if (! is_string($type) || ! isset(self::TARGETS[$type]) || ! self::validId($id)
            || ! is_string($expires) || ! preg_match('/\A[0-9]{1,10}\z/', $expires)
            || ! is_string($signature) || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)
            || ! is_string($flow) || ! is_string($sessionFlow) || ! hash_equals($sessionFlow, $flow)
            || ! $request->hasValidRelativeSignature()) {
            return;
        }
        $context = ['type' => $type, 'id' => (string) $id, 'expires' => (int) $expires];
        if ($this->validContext($context)) {
            // Do not overwrite Laravel's intended destination with a public reading context.
            $request->session()->put(self::KEY, $context);
        }
    }

    public function hasPending(Request $request): bool
    {
        return $this->validContext($request->session()->get(self::KEY))
            && $this->safeIntended($request, $request->session()->get('url.intended')) === null;
    }

    public function redirect(Request $request): RedirectResponse
    {
        // Consume both once, including when intended takes precedence.
        $context = $request->session()->pull(self::KEY);
        $request->session()->forget(self::FLOW);
        $intended = $this->safeIntended($request, $request->session()->pull('url.intended'));
        if ($intended !== null) {
            return redirect()->to($intended);
        }
        if (! $this->validContext($context)) {
            return redirect()->route('home');
        }

        [$detail, $listing] = self::TARGETS[$context['type']];
        try {
            if ($this->available($request, $context['type'], (int) $context['id'])) {
                return redirect()->route($detail, $context['id']);
            }
        } catch (QueryException $error) {
            report($error);
        }

        return redirect()->route($listing)->with('auth_reading_notice',
            'No pudimos volver al contenido solicitado. Puedes continuar desde este listado. No se realizó ninguna acción de Guardar o Mensajes.');
    }

    private function validContext(mixed $context): bool
    {
        return is_array($context) && count($context) === 3
            && isset($context['type'], $context['id'], $context['expires'])
            && is_string($context['type']) && isset(self::TARGETS[$context['type']])
            && self::validId($context['id']) && is_int($context['expires'])
            && $context['expires'] >= now()->timestamp
            && $context['expires'] <= now()->addMinutes(self::MINUTES)->timestamp;
    }

    private static function validId(mixed $id): bool
    {
        return (is_string($id) || is_int($id)) && preg_match('/\A[1-9][0-9]{0,17}\z/', (string) $id) === 1;
    }

    private function available(Request $request, string $type, int $id): bool
    {
        if (in_array($type, ['professional', 'user'], true)) {
            $model = $type === 'professional' ? ProfessionalProfile::class : UserProfile::class;

            // Same public eligibility as ProfileService, without loading contact or credentials.
            return $model::where('user_id', $id)->where('visibility', 'public')
                ->whereHas('user', fn ($user) => $user->where('status', 'active'))->exists();
        }
        $model = match ($type) {
            'post' => Post::class, 'article' => Article::class, 'resource' => LegalResource::class,
        };
        $columns = ['id', 'user_id', 'status', 'deleted_at'];
        if ($type === 'post') {
            $columns[] = 'visibility';
        }
        // A public context must still be public, even for a privileged new identity.
        $entry = $model::publiclyVisible()->with('author:id,status')->find($id, $columns);

        return $entry !== null && Gate::forUser($request->user())->allows('view', $entry);
    }

    private function safeIntended(Request $request, mixed $destination): ?string
    {
        if (! is_string($destination) || strlen($destination) > 4096
            || preg_match('/[\x00-\x20\x7f\\\\]/', $destination)) {
            return null;
        }
        $parts = parse_url($destination);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || str_starts_with($destination, '//')) {
            return null;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $origin = ($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
            if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || $origin !== $request->getSchemeAndHttpHost()) {
                return null;
            }
        }
        $path = $parts['path'] ?? '';
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')
            || preg_match('/[\x00-\x20\x7f\\\\]/', rawurldecode($path))) {
            return null;
        }
        try {
            $route = Route::getRoutes()->match(Request::create($request->getSchemeAndHttpHost().$path, 'GET'));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException|\InvalidArgumentException) {
            return null;
        }
        $name = $route->getName();
        if (! $name || in_array($name, ['login', 'register', 'logout'], true) || str_starts_with($name, 'password.')) {
            return null;
        }

        // Retain existing filters, but never use a supplied origin for the redirect.
        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
