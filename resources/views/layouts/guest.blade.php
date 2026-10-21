{{--
    Layout público de la Plataforma Legal.

    Se utilizará para las páginas accesibles sin iniciar sesión,
    como la landing page, el registro y el inicio de sesión.

    No incluye la navegación privada de los usuarios.
--}}

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>@yield('title', 'Plataforma Legal')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white text-gray-900">

    <main>
        @yield('content')
    </main>

</body>
</html>