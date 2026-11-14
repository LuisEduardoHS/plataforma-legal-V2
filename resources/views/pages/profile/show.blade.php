@extends('layouts.app')
@section('title', 'Mi perfil | Plataforma Legal')
@section('content')
<div class="min-h-screen bg-bg dark:bg-bg-dark font-sans pb-24 text-text dark:text-text-dark">
    <div class="w-full max-w-[1080px] mx-auto px-4 sm:px-6 pt-6 space-y-5">
        @if (session('status'))<p class="rounded-xl bg-white dark:bg-surface-dark p-4" role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())<div class="rounded-xl bg-white dark:bg-surface-dark p-4" role="alert"><p>No se completó la operación.</p><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <x-profiles.card :name="$profile['name'] ?? auth()->user()->name" :username="$profile['username'] ?? null" :biography="$profile['biography'] ?? null" :location="$profile['location'] ?? null" :website="$profile['website'] ?? null">
            <div class="flex flex-wrap gap-4 items-center mt-5 text-sm">
                <a class="bg-dark-accent text-white rounded-full px-6 py-2 hover:opacity-90 transition-opacity" href="{{ route('profile.edit') }}">{{ $profile ? 'Editar perfil' : 'Completar mi perfil' }}</a>
                <span>{{ ($profile['visibility'] ?? 'private') === 'public' ? 'Perfil personal público' : 'Perfil personal privado' }}</span>
                @if (($profile['visibility'] ?? null) === 'public')<a class="underline" href="{{ route('profiles.show', auth()->id()) }}">Ver perfil público</a>@endif
                <a class="underline" href="{{ route('community.user', auth()->id()) }}">Mis publicaciones en Comunidad</a>
                <a class="underline" href="{{ route('community.connections', auth()->id()) }}">Mis conexiones</a>
            </div>
            @if (! $profile)<p class="text-sm mt-4">Tu perfil es opcional. Completa los datos que quieras compartir y elige su visibilidad.</p>@endif
            @if ($profile['has_photo'] ?? false)<p class="text-sm mt-4"><a class="underline" href="{{ route('profile.photo') }}" data-turbo="false">Descargar mi fotografía privada</a></p>@endif
        </x-profiles.card>
        <section class="bg-white dark:bg-surface-dark rounded-2xl p-6 shadow-xs border border-gray-100/50 dark:border-border-dark/50 space-y-4">
            <h2 class="text-lg font-bold">Información profesional</h2>
            @if ($professional)
                <h3 class="font-semibold">{{ $professional['headline'] ?? 'Perfil profesional' }}</h3>
                <x-profiles.verification :status="$professional['verification_status']" />
                <p class="text-sm">{{ implode(' · ', $professional['specialties']) ?: 'Sin especialidades indicadas' }}</p>
                <p class="whitespace-pre-line break-words text-sm">{{ $professional['description'] }}</p>
                <p class="text-sm">{{ $professional['visibility'] === 'public' ? 'Visible en el directorio' : 'Perfil profesional privado' }}</p>
                @if ($professional['visibility'] === 'public')<a class="inline-block underline mr-4" href="{{ route('professionals.show', auth()->id()) }}">Ver presentación pública</a>@endif
            @else
                <p class="text-sm">Si ofreces servicios jurídicos, puedes completar una presentación profesional. Esto no te otorga una verificación ni permisos adicionales.</p>
            @endif
            <a class="inline-block text-dark-accent dark:text-accent-text-dark underline" href="{{ route('profile.professional.edit') }}">{{ $professional ? 'Editar información profesional' : 'Crear presentación profesional' }}</a>
        </section>
    </div>
</div>
@endsection
