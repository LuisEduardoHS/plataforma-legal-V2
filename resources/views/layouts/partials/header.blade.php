
{{--
    Header principal de la Plataforma Legal.

    Se utiliza en las páginas internas de la aplicación.

    Estructura:
    - Logotipo de la plataforma.
    - Navegación principal con iconos.
    - Acceso a LEXA.
    - Acceso al perfil del usuario.

    Incluye indicadores de la página activa mediante
    el sistema de rutas de Laravel.

    Posteriormente incorporaremos:
    - Logotipo definitivo.
    - Avatar real del usuario autenticado.
    - Notificaciones.
--}}
@php
    $navItems = [
        ['route' => 'home', 'label' => 'Inicio', 'icon' => 'home'],
        ['route' => 'community', 'label' => 'Comunidad', 'icon' => 'users'],
        ['route' => 'professionals', 'label' => 'Profesionales', 'icon' => 'briefcase-business'],
        ['route' => 'articles', 'label' => 'Recursos', 'icon' => 'archive'],
        ['route' => 'messages', 'label' => 'Mensajes', 'icon' => 'message-circle'],
    ];
@endphp

<header class="platform-header bg-white dark:bg-surface-dark w-full py-0.5 sm:py-2 lg:px-6 px-3 lg:h-18 sm:h-16 h-12 flex items-center justify-between">

    {{-- Desktop Logo --}}
    <div class="lg:inline hidden bg-linear-to-l from-dark-accent to-light-accent lg:size-11 size-8 rounded-full"></div>

    {{-- Desktop NavBar --}}
    <nav aria-label="Navegación principal" class="lg:flex hidden space-x-2 absolute left-1/2 transform -translate-x-1/2">
        @foreach ($navItems as $item)
            @php
                $active = request()->routeIs($item['route']) || ($item['route'] === 'community' && request()->routeIs('community.*'))
                    || ($item['route'] === 'articles' && request()->routeIs('articles.*', 'resources', 'resources.*'))
                    || ($item['route'] === 'messages' && request()->routeIs('messages.*'));
            @endphp
            <a href="{{ route($item['route']) }}" @if ($active) aria-current="page" @endif
               @class([
                   'headerNav-button',
                   'selected' => $active
               ])
            >
                <x-dynamic-component 
                    :component="'lucide-' . $item['icon']" 
                    class="w-6 h-6 text-center {{ $active ? 'header-active-icon text-accent dark:text-light-accent' : 'text-text dark:text-text-dark' }}"
                    stroke-width="2" 
                />
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    {{-- Desktop LEXA; account menu is shared above. --}}
    <div class="desktop-account-actions lg:flex hidden items-center">
        <a href="{{ route('lexa') }}" @if (request()->routeIs('lexa')) aria-current="page" @endif class="lexa-header-btn inline-flex items-center text-md font-bold sm:px-12 px-8 py-1.5 rounded-ui cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2">
            <span class="landing-lexa-wordmark !text-base">LEXA</span>
        </a>

    </div>

    {{-- One native account disclosure for desktop, tablet and mobile. --}}
    <x-header.account-menu />

    {{-- Mobile Logo --}}
    <div class="lg:hidden space-x-2 absolute left-1/2 transform -translate-x-1/2 bg-linear-to-l from-dark-accent to-light-accent sm:size-9 size-8 rounded-lg"></div>

    <a href="{{ route('lexa') }}" @if (request()->routeIs('lexa')) aria-current="page" @endif
       class="lexa-header-btn lg:hidden inline-flex min-h-11 items-center gap-1.5 rounded-ui px-3 py-2 text-xs font-semibold text-lexa dark:text-lexa-dark focus-visible:outline-2 focus-visible:outline-offset-2"
       aria-label="Abrir LEXA">
        <span class="landing-lexa-wordmark">LEXA</span>
    </a>

</header>

{{-- Mobile NavBar --}}
<div class="platform-mobile-nav bg-white dark:bg-surface-dark lg:hidden flex items-center justify-center rounded-t-xl fixed bottom-0 min-h-14 sm:min-h-18 h-auto w-full py-1 px-2">
    <nav aria-label="Navegación principal móvil" class="flex flex-wrap items-stretch justify-center w-full">
        @foreach ($navItems as $item)
            @php
                $active = request()->routeIs($item['route']) || ($item['route'] === 'community' && request()->routeIs('community.*'))
                    || ($item['route'] === 'articles' && request()->routeIs('articles.*', 'resources', 'resources.*'))
                    || ($item['route'] === 'messages' && request()->routeIs('messages.*'));
            @endphp
            <a href="{{ route($item['route']) }}" @if ($active) aria-current="page" @endif
               @class([
                   'headerNav-button mobile',
                   'selected' => $active
               ])
            >
                <x-dynamic-component 
                    :component="'lucide-' . $item['icon']" 
                    class="w-6 h-6 shrink-0 text-center {{ $active ? 'text-dark-accent dark:text-accent-text-dark' : 'text-text dark:text-text-dark' }}"
                    stroke-width="2" 
                />
                <span class="headerNav-label">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
</div>
