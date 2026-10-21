
{{--
    Layout principal de la Plataforma Legal.

    Proporciona la estructura HTML compartida por las páginas
    internas, como Inicio, Comunidad, Profesionales, Mensajes,
    Guardados, Perfil y Configuración.

    Integra los componentes de navegación reutilizables.
--}}

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>@yield('title', 'Plataforma Legal')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-gray-50 text-gray-900">

    <div id="app" class="min-h-screen">

        {{-- Encabezado compartido de las páginas internas --}}
        @include('layouts.partials.header')

        {{-- Contenido particular de cada página --}}
        <main id="main-content">
            @yield('content')
        </main>

    </div>

</body>
</html>
