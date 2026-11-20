@php
    $status = (int) ($status ?? 503);
    $title = match ($status) {
        401 => 'Inicia sesión para continuar',
        403 => 'No puedes realizar esta acción',
        404 => 'No encontramos una página disponible',
        409 => 'Conflicto de versión o estado',
        419 => 'Tu sesión o formulario caducó',
        422 => 'Revisa los datos de la solicitud',
        429 => 'Necesitas esperar un momento',
        default => 'El servicio no está disponible por ahora',
    };
    $description = $message ?? match ($status) {
        401 => 'Abre una sesión vigente para continuar. El acceso se comprobará de nuevo.',
        403 => 'No tienes acceso a esta acción. Puedes continuar desde otra sección de la plataforma.',
        404 => 'La página o el contenido no están disponibles. Puedes continuar desde una sección de la plataforma.',
        409 => 'Consulta los datos actuales antes de decidir qué cambios volver a enviar.',
        419 => 'Por seguridad, esta solicitud no puede continuar. Abre una pantalla actual antes de volver a usar el formulario.',
        429 => 'Se alcanzó el límite de solicitudes. Espera antes de volver a consultar o realizar una acción.',
        default => 'No pudimos completar la solicitud. Puedes volver a consultar una pantalla de lectura más tarde.',
    };
    [$recoveryRoute, $recoveryLabel] = match (true) {
        request()->routeIs('messages', 'messages.*') => ['messages', 'Volver a Mensajes'],
        request()->routeIs('profile.professional.*') => ['profile.professional.edit', 'Abrir mis datos profesionales actuales'],
        request()->routeIs('profile', 'profile.*') => ['profile', 'Volver a mi perfil'],
        request()->routeIs('professionals', 'professionals.*', 'profiles.show') => ['professionals', 'Volver al directorio'],
        request()->routeIs('community', 'community.*') => ['community', 'Volver a Comunidad'],
        request()->routeIs('resources', 'resources.*') => ['resources', 'Volver a recursos jurídicos'],
        request()->routeIs('articles', 'articles.*') => ['articles', 'Volver a artículos'],
        request()->routeIs('saved', 'saved.*') => ['saved', 'Consultar mis guardados'],
        request()->routeIs('verification', 'verification.*') => ['verification', 'Consultar mi estado actual'],
        default => ['landing', 'Volver a la plataforma'],
    };
@endphp
<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="turbo-visit-control" content="reload">
    <meta name="turbo-cache-control" content="no-cache">
    <link rel="icon" type="image/svg+xml" href="{{ asset('lefenda_icon.svg') }}">
    <title>{{ $title }} | Lefenda</title>
    @vite(['resources/css/app.css'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body id="app" class="public-shell min-h-screen bg-bg dark:bg-bg-dark text-text dark:text-text-dark font-sans">
    <a class="ui-skip-link" href="#main-content">Saltar al contenido</a>
    <header class="public-header"><div class="public-header-inner"><a class="public-brand" href="{{ route('landing') }}" data-turbo="false" aria-label="Lefenda, portada"><span class="public-brand-mark" aria-hidden="true"></span>Lefenda</a></div></header>
    <main id="main-content" tabindex="-1" class="max-w-3xl mx-auto px-[min(1.25rem,5vw)] py-12 break-words">
        <x-ui.card :padding="false" class="p-[min(1.25rem,5vw)] dark:bg-surface-dark dark:border-border-dark space-y-5">
            <p class="text-sm text-light-text dark:text-light-text-dark">Error {{ $status }}</p>
            <h1 class="text-2xl sm:text-3xl font-semibold">{{ $title }}</h1>
            <p role="alert">{{ $description }}</p>
            @if (in_array($status, [409, 419, 503]))<p>No repetimos la operación automáticamente. Antes de repetir un envío, consulta si ya aparece guardado.</p>@endif
            <nav class="flex flex-wrap gap-4" aria-label="Opciones de recuperación">
                <a class="inline-flex items-center min-h-11 px-4 py-3 rounded-ui bg-dark-accent text-white hover:bg-accent" href="{{ route($recoveryRoute) }}" data-turbo="false">{{ $recoveryLabel }}</a>
                @if (in_array($status, [401, 419]))<a class="inline-flex items-center min-h-11 underline" href="{{ route('login') }}" data-turbo="false">Iniciar sesión</a>@endif
                @if ($recoveryRoute !== 'landing')<a class="inline-flex items-center min-h-11 underline" href="{{ route('landing') }}" data-turbo="false">Volver a la plataforma</a>@endif
                <a class="inline-flex items-center min-h-11 underline" href="{{ route('help') }}" data-turbo="false">Consultar Ayuda</a>
            </nav>
        </x-ui.card>
    </main>
</body>
</html>
