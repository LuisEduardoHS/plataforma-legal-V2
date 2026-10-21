
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

<header class="sticky top-0 z-50 border-b border-gray-200/80 bg-white/90 backdrop-blur-md">

    <div class="mx-auto flex h-20 max-w-7xl items-center justify-between gap-5 px-6">

        {{-- Logotipo --}}
        <a
            href="{{ route('home') }}"
            aria-label="Ir al inicio"
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#7F1842]"
        >
            <span class="text-xl font-bold text-white">P</span>
        </a>

        {{-- Navegación de escritorio --}}
        <nav
            aria-label="Navegación principal"
            class="hidden items-center gap-2 md:flex"
        >
            @php
                $navItems = [
                    ['route' => 'home', 'label' => 'Inicio', 'icon' => 'home'],
                    ['route' => 'community', 'label' => 'Comunidad', 'icon' => 'users'],
                    ['route' => 'professionals', 'label' => 'Profesionales', 'icon' => 'briefcase'],
                    ['route' => 'articles', 'label' => 'Recursos', 'icon' => 'book'],
                    ['route' => 'messages', 'label' => 'Mensajes', 'icon' => 'message'],
                ];
            @endphp

            @foreach ($navItems as $item)
                @php
                    $active = request()->routeIs($item['route']);
                @endphp

                <a
                    href="{{ route($item['route']) }}"
                    @if ($active) aria-current="page" @endif
                    @class([
                        'flex min-w-20 flex-col items-center justify-center gap-1 rounded-xl px-3 py-2 transition-colors',
                        'bg-[#7F1842]/10 text-[#7F1842]' => $active,
                        'text-gray-600 hover:bg-gray-100 hover:text-gray-900' => ! $active,
                    ])
                >

                    {{-- Iconos SVG temporales --}}
                    @switch($item['icon'])

                        @case('home')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor"
                                 stroke-width="1.8" viewBox="0 0 24 24"
                                 aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V10Z"/>
                            </svg>
                            @break

                        @case('users')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor"
                                 stroke-width="1.8" viewBox="0 0 24 24"
                                 aria-hidden="true">
                                <circle cx="9" cy="8" r="3"/>
                                <path stroke-linecap="round" d="M3 20v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 5"/>
                            </svg>
                            @break

                        @case('briefcase')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor"
                                 stroke-width="1.8" viewBox="0 0 24 24"
                                 aria-hidden="true">
                                <rect x="3" y="7" width="18" height="14" rx="2"/>
                                <path stroke-linecap="round" d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/>
                            </svg>
                            @break

                        @case('book')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor"
                                 stroke-width="1.8" viewBox="0 0 24 24"
                                 aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M4 5h16v15H4zM4 9h16M9 13h6"/>
                            </svg>
                            @break

                        @case('message')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor"
                                 stroke-width="1.8" viewBox="0 0 24 24"
                                 aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M20 11a8 8 0 0 1-8 8 9 9 0 0 1-4-.9L3 20l1.9-5A8 8 0 1 1 20 11Z"/>
                            </svg>
                            @break

                    @endswitch

                    <span class="text-xs font-medium">
                        {{ $item['label'] }}
                    </span>
                </a>
            @endforeach
        </nav>

        {{-- Accesos de la derecha --}}
        <div class="flex shrink-0 items-center gap-3">

            {{-- Acceso visual a LEXA; su funcionalidad se agregará después --}}
            <span
                class="rounded-full bg-gray-100 px-5 py-2 text-xs font-semibold tracking-[0.3em] text-[#7F1842]"
            >
                LEXA
            </span>

            {{-- Perfil temporal --}}
            <a
                href="{{ route('profile') }}"
                aria-label="Ver mi perfil"
                class="flex h-10 w-10 items-center justify-center rounded-full bg-[#E65B42] text-sm font-semibold text-white"
            >
                U
            </a>

        </div>

    </div>

</header>
