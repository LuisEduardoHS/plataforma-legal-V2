<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Explora Comunidad, profesionales, artículos y recursos jurídicos en México. Conoce la demostración de LEXA en Plataforma Legal.">
    <meta name="turbo-visit-control" content="reload">
    <title>@yield('title', 'Plataforma Legal | Un punto de partida para tus dudas legales')</title>
    @vite(['resources/css/app.css', 'resources/css/landing.css', 'resources/js/public.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="landing-page" data-turbo="false">
    <a class="public-skip" href="#main-content">Saltar al contenido</a>
    <x-public.header />
    <main id="main-content" tabindex="-1">@yield('content')</main>
    <x-public.footer />
</body>
</html>
