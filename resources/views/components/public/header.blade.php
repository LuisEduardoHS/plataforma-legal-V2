@php
    $publicLinks = [
        ['route' => 'community', 'label' => 'Comunidad', 'current' => request()->routeIs('community', 'community.*')],
        ['route' => 'professionals', 'label' => 'Profesionales', 'current' => request()->routeIs('professionals', 'professionals.*', 'profiles.show')],
        ['route' => 'articles', 'label' => 'Artículos', 'current' => request()->routeIs('articles', 'articles.*')],
        ['route' => 'resources', 'label' => 'Recursos', 'current' => request()->routeIs('resources', 'resources.*')],
        ['route' => 'help', 'label' => 'Ayuda', 'current' => request()->routeIs('help')],
    ];
    $readingTarget = match (request()->route()?->getName()) {
        'professionals.show' => ['professional', request()->route('userId')],
        'profiles.show' => ['user', request()->route('userId')],
        'community.show' => ['post', request()->route('post')],
        'articles.show' => ['article', request()->route('entry')],
        'resources.show' => ['resource', request()->route('entry')],
        default => null,
    };
    $publicLogin = $readingTarget ? \App\Services\Auth\ReadingReturn::loginUrl(...$readingTarget) : route('login');
@endphp
<header class="public-header">
    <div class="public-header-inner">
        <a class="public-brand" href="{{ route('landing') }}" aria-label="Plataforma Legal, portada" @if (request()->routeIs('landing')) aria-current="page" @endif><span class="public-brand-mark" aria-hidden="true"></span>Plataforma Legal</a>
        <nav class="public-navigation" aria-label="Navegación pública">
            <div class="public-desktop-links">
                @foreach ($publicLinks as $link)<a href="{{ route($link['route']) }}" @if ($link['current']) aria-current="page" @endif>{{ $link['label'] }}</a>@endforeach
            </div>
            <details class="public-disclosure" data-public-navigation>
                <summary aria-controls="public-navigation-panel" data-account-trigger>Explorar <x-lucide-chevron-down aria-hidden="true" /></summary>
                <div id="public-navigation-panel" class="public-dropdown" data-account-panel>
                    @foreach ($publicLinks as $link)<a href="{{ route($link['route']) }}" @if ($link['current']) aria-current="page" @endif>{{ $link['label'] }}</a>@endforeach
                    <div class="public-dropdown-account"><a href="{{ $publicLogin }}">Iniciar sesión</a><a href="{{ route('register') }}">Crear cuenta</a></div>
                </div>
            </details>
        </nav>
        <div class="public-account-actions"><a href="{{ $publicLogin }}">Iniciar sesión</a><a class="public-register" href="{{ route('register') }}">Crear cuenta</a></div>
    </div>
</header>
