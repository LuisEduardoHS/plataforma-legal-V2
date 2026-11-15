@extends('layouts.app')
@section('title', ($profile['display_name'] ?? 'Profesional').' | Plataforma Legal')
@section('content')
@php
    $name = $profile['display_name'] ?? 'Profesional';
    $location = $professional['location'] ?? $profile['location'] ?? null;
    $place = $location ? implode(', ', [$location['municipality']['name'], $location['state']['name'], $location['country']['name']]) : null;
    $modes = ['in_person' => 'Presencial', 'remote' => 'En línea', 'hybrid' => 'Presencial y en línea'];
@endphp
<div class="min-h-screen bg-bg dark:bg-bg-dark text-text dark:text-text-dark pb-24">
    <div class="max-w-[1080px] mx-auto px-4 sm:px-6 pt-6 space-y-5">
        <a class="inline-block underline" href="{{ route('professionals') }}">← Directorio de profesionales</a>
        <x-profiles.card :name="$name" :username="$profile['username'] ?? null" :biography="$profile['biography'] ?? null" :location="$place" :website="$profile['website'] ?? null" />
        <x-messages.start :recipient="$publicUserId" />
        @if ($profile)<nav class="flex flex-wrap gap-4" aria-label="Actividad pública"><a class="underline" href="{{ route('community.user', $publicUserId) }}">Publicaciones en Comunidad</a><a class="underline" href="{{ route('community.connections', $publicUserId) }}">Conexiones y seguimiento</a></nav>@endif
        @if ($professional)
            <section class="bg-white dark:bg-surface-dark rounded-2xl p-6 border border-gray-100/50 dark:border-border-dark/50 space-y-4">
                <h2 class="text-xl font-bold break-words">{{ $professional['headline'] ?? 'Presentación profesional' }}</h2>
                <x-profiles.verification :status="$professional['verification_status']" />
                <p class="whitespace-pre-line break-words text-sm">{{ $professional['description'] }}</p>
                <h3 class="font-semibold">Especialidades</h3><p>{{ implode(' · ', array_column($professional['specialties'], 'name')) ?: 'Sin especialidades indicadas' }}</p>
                <dl class="grid sm:grid-cols-2 gap-4"><div><dt class="font-semibold">Experiencia</dt><dd>{{ $professional['years_experience'] !== null ? $professional['years_experience'].' años' : 'Sin indicar' }}</dd></div><div><dt class="font-semibold">Modalidad</dt><dd>{{ $modes[$professional['attention_mode']] ?? 'Sin indicar' }}</dd></div></dl>
                @if ($professional['website'])<a class="underline break-all" href="{{ $professional['website'] }}" rel="nofollow noopener noreferrer" target="_blank">Sitio web profesional</a>@endif
                @if ($professional['contact'] && ($professional['contact']['email'] || $professional['contact']['phone']))<div class="space-y-2"><h3 class="font-semibold">Contacto publicado por el profesional</h3>@if ($professional['contact']['email'])<p class="break-all">{{ $professional['contact']['email'] }}</p>@endif @if ($professional['contact']['phone'])<p>{{ $professional['contact']['phone'] }}</p>@endif</div>@endif
            </section>
        @endif
    </div>
</div>
@endsection
