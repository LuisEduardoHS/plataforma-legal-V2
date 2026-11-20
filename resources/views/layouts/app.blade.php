
{{--
    Layout principal de la Plataforma Legal.

    Proporciona la estructura HTML compartida por las páginas
    internas, como Inicio, Comunidad, Profesionales, Mensajes,
    Guardados, Perfil y Configuración.

    Integra los componentes de navegación reutilizables.
--}}

<!DOCTYPE html>
@php($internalNavigation = auth()->user()?->isActive() ?? false)
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="turbo-cache-control" content="no-cache">
    <meta name="turbo-prefetch" content="false">
    <link rel="icon" type="image/svg+xml" href="{{ asset('lefenda_icon.svg') }}">

    <title>@yield('title', 'Lefenda')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if ($internalNavigation) @vite(['resources/css/components/userHeader.css']) @endif
    @yield('page-assets')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&display=swap" rel="stylesheet">
</head>

<body class="min-h-screen bg-gray-50 dark:bg-bg-dark text-gray-900 dark:text-text-dark">
    <a class="ui-skip-link" href="#main-content">Saltar al contenido</a>

    <div id="app" @class(['min-h-screen', 'public-shell' => ! $internalNavigation])>

        {{-- Encabezado compartido de las páginas internas --}}
        @if ($internalNavigation) @include('layouts.partials.header') @else <x-public.header /> @endif

        {{-- Contenido particular de cada página --}}
        <main id="main-content" tabindex="-1">
            @if (session('auth_reading_notice'))<p role="status" class="max-w-3xl mx-auto px-4 py-4 text-sm">{{ session('auth_reading_notice') }}</p>@endif
            @yield('content')
        </main>

    </div>

</body>
</html>
