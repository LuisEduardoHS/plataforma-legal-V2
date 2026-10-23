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
    <meta name="turbo-visit-control" content="reload">

    <title>@yield('title', 'Plataforma Legal')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/css/views/login.css'])

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&display=swap" rel="stylesheet">
</head>
<body data-turbo="false" class="min-h-screen bg-white dark:bg-bg-dark text-gray-900 dark:text-text-dark">

    <main>
        @yield('content')
    </main>

</body>
</html>