
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
    - Menú responsive para dispositivos móviles.
    - Avatar real del usuario autenticado.
    - Notificaciones y menú de cuenta.
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

<header class="bg-white w-full py-4 lg:px-6 px-3 lg:h-18 sm:h-16 h-12 flex items-center justify-between">

    {{-- Desktop Logo --}}
    <div class="lg:inline hidden bg-linear-to-l from-dark-accent to-light-accent lg:size-11 size-8 rounded-full"></div>

    {{-- Mobile Profile --}}
    <a href="{{ route('profile') }}" aria-label="Ver mi perfil">
        <div class="bg-linear-to-br from-[#E33F3F] to-[#D98040] sm:size-9 size-8 rounded-full lg:hidden flex items-end justify-end cursor-pointer">
            <div class="bg-accent-bg sm:size-4 size-3 rounded-full flex items-center justify-center">
                <x-lucide-chevron-down class="w-3 h-3 text-black" stroke-width="2" />
            </div>
        </div>
    </a>

    {{-- Desktop NavBar --}}
    <nav class="lg:flex hidden space-x-2 absolute left-1/2 transform -translate-x-1/2">
        @foreach ($navItems as $item)
            @php
                $active = request()->routeIs($item['route']);
            @endphp
            <a href="{{ route($item['route']) }}" 
               @class([
                   'headerNav-button',
                   'selected' => $active
               ])
            >
                <x-dynamic-component 
                    :component="'lucide-' . $item['icon']" 
                    class="w-6 h-6 text-center {{ $active ? 'text-[#7F1842]' : 'text-[#1A1A1A]' }}" 
                    stroke-width="2" 
                />
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    {{-- Mobile Logo --}}
    <div class="lg:hidden space-x-2 absolute left-1/2 transform -translate-x-1/2 bg-linear-to-l from-dark-accent to-light-accent sm:size-9 size-8 rounded-lg"></div>

    {{-- Desktop LEXA and User Profile --}}
    <div class="lg:flex hidden items-center space-x-4">
        <button class="bg-accent-bg-hover text-md font-semibold sm:px-12 px-8 py-1 tracking-[0.4rem] rounded-lg hover:bg-accent-bg cursor-pointer transition-colors duration-200">
            <span class="text-transparent text-center bg-clip-text bg-linear-to-l from-lexa via-accent to-dark-accent">
                LEX<span class="tracking-normal">A</span>
            </span>
        </button>

        <a href="{{ route('profile') }}" aria-label="Ver mi perfil">
            <div class="bg-linear-to-br from-[#E33F3F] to-[#D98040] lg:size-11 size-8 rounded-full flex items-end justify-end cursor-pointer">
                <div class="bg-accent-bg lg:size-4 size-3 rounded-full flex items-center justify-center">
                    <x-lucide-chevron-down class="w-3 h-3 text-black" stroke-width="2" />
                </div>
            </div>
        </a>
    </div>

</header>

{{-- Mobile NavBar --}}
<div class="bg-white lg:hidden flex items-center justify-center rounded-t-xl fixed bottom-0 sm:h-18 h-14 w-full py-1 px-4">
    <nav class="flex w-full space-x-2 absolute left-1/2 transform -translate-x-1/2">
        @foreach ($navItems as $item)
            @php
                $active = request()->routeIs($item['route']);
            @endphp
            <a href="{{ route($item['route']) }}" 
               @class([
                   'headerNav-button mobile',
                   'selected' => $active
               ])
            >
                <x-dynamic-component 
                    :component="'lucide-' . $item['icon']" 
                    class="w-6 h-6 text-center {{ $active ? 'text-[#7F1842]' : 'text-[#1A1A1A]' }}" 
                    stroke-width="2" 
                />
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>
</div>