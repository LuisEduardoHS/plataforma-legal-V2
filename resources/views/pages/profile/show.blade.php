@extends('layouts.app')
@section('title', 'Mi perfil | Plataforma Legal')
@section('content')
<div class="min-h-screen bg-bg dark:bg-bg-dark font-sans pb-16 sm:pb-24 text-text dark:text-text-dark">
    <div class="w-full max-w-[1280px] mx-auto px-4 sm:px-6 pt-6">
        @if (app(\App\Services\Operations\OperationsAccess::class)->enter(auth()->user()))
            <a class="mb-5 inline-flex min-h-[44px] items-center gap-2 rounded-[10px] border border-accent/20 bg-white px-3 py-2 text-xs font-semibold text-dark-accent shadow-xs transition-colors hover:bg-accent-bg-hover active:bg-accent-bg dark:border-border-dark dark:bg-surface-dark dark:text-accent-text-dark dark:hover:bg-accent-bg-hover-dark dark:active:bg-accent-bg-dark sm:min-h-[38px]" href="{{ route('operations.index') }}">
                <x-lucide-shield-check class="size-4" />
                Acceder a Operaciones
            </a>
        @endif
        @if (session('status'))<p class="mb-5 rounded-[10px] border border-gray-100/50 bg-white p-4 dark:border-border-dark dark:bg-surface-dark" role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())<div class="mb-5 rounded-[10px] border border-gray-100/50 bg-white p-4 dark:border-border-dark dark:bg-surface-dark" role="alert"><p class="font-semibold">No se completó la operación.</p><ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <x-profiles.card :name="$profile['name'] ?? auth()->user()->name" :username="$profile['username'] ?? null" :biography="$profile['biography'] ?? null" :location="$profile['location'] ?? null" :website="$profile['website'] ?? null" :visibility="$profile['visibility'] ?? 'private'">
            <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                <a class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-[10px] bg-dark-accent px-3 py-2 text-xs font-semibold text-white shadow-xs transition-colors hover:bg-accent active:bg-light-accent sm:min-h-[38px]" href="{{ route('profile.edit') }}">
                    <x-lucide-pencil class="size-4" />
                    {{ $profile ? 'Editar perfil' : 'Completar mi perfil' }}
                </a>
                @if (($profile['visibility'] ?? null) === 'public')
                    <a class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-[10px] border border-gray-100/50 bg-bg px-3 py-2 text-xs font-semibold text-dark-accent transition-colors hover:bg-accent-bg-hover active:bg-accent-bg dark:border-border-dark dark:bg-bg-dark dark:text-accent-text-dark dark:hover:bg-accent-bg-hover-dark dark:active:bg-accent-bg-dark sm:min-h-[38px]" href="{{ route('profiles.show', auth()->id()) }}">
                        <x-lucide-eye class="size-4" />Ver perfil público
                    </a>
                @endif
                <a class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-[10px] px-3 py-2 text-xs font-semibold text-light-text transition-colors hover:bg-accent-bg-hover active:bg-accent-bg dark:text-light-text-dark dark:hover:bg-accent-bg-hover-dark dark:active:bg-accent-bg-dark sm:min-h-[38px]" href="{{ route('community.connections', auth()->id()) }}">
                    <x-lucide-users class="size-4" />Mis conexiones
                </a>
            </div>
            @if (! $profile)<p class="mt-4 text-sm leading-6 text-light-text dark:text-light-text-dark">Tu perfil es opcional. Completa los datos que quieras compartir y elige su visibilidad.</p>@endif
            @if ($profile['has_photo'] ?? false)<a class="mt-4 inline-flex min-h-[44px] items-center gap-2 rounded-[10px] px-3 py-2 text-xs font-semibold text-dark-accent transition-colors hover:bg-accent-bg-hover active:bg-accent-bg dark:text-accent-text-dark dark:hover:bg-accent-bg-hover-dark dark:active:bg-accent-bg-dark sm:min-h-[38px]" href="{{ route('profile.photo') }}" data-turbo="false"><x-lucide-download class="size-4" />Descargar mi fotografía privada</a>@endif
        </x-profiles.card>

        <div class="mt-5 grid items-start gap-5 lg:grid-cols-[minmax(280px,360px)_minmax(0,1fr)]">
            <section class="min-w-0 rounded-[10px] border border-gray-100/50 bg-white p-5 shadow-xs dark:border-border-dark dark:bg-surface-dark">
                    <div class="mb-4 flex items-center gap-2">
                        <x-lucide-briefcase-business class="size-5 text-dark-accent dark:text-accent-text-dark" />
                        <h2 class="text-lg font-bold">Información profesional</h2>
                    </div>
                    <div class="space-y-3 break-words" data-professional-hub data-professional-state="{{ $journey['state'] }}">
                        <p class="text-sm">{{ $journey['label'] }}</p>
                        @if ($journey['missing'])<p class="text-sm text-light-text dark:text-light-text-dark">Para presentar una solicitud, completa:</p><ul class="list-disc pl-5 text-sm">@foreach ($journey['missing'] as $missing)<li>{{ $missing }}</li>@endforeach</ul>@endif
                        <a class="inline-flex min-h-[44px] items-center gap-2 rounded-[10px] bg-dark-accent px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-accent active:bg-light-accent sm:min-h-[38px]" href="{{ route($journey['route'], $journey['id'] ? [$journey['id']] : []) }}"><x-lucide-arrow-right class="size-4" />{{ $journey['next'] }}</a>
                        @if (in_array($journey['state'], ['ready', 'draft', 'incomplete']))<p class="text-xs leading-5 text-light-text dark:text-light-text-dark">El expediente necesita evidencia privada de la cédula. Puedes preparar un borrador antes de completar los requisitos. La revisión es humana, sin plazo ni aprobación garantizados.</p>@endif
                    </div>
                    @if ($professional)
                        <div class="mt-5 border-t border-gray-100/70 pt-5 dark:border-border-dark">
                            <h3 class="font-semibold">{{ $professional['headline'] ?? 'Perfil profesional' }}</h3>
                            <div class="mt-2"><x-profiles.verification :status="$professional['verification_status']" /></div>
                            <p class="mt-3 text-sm text-light-text dark:text-light-text-dark">{{ implode(' · ', $professional['specialties']) ?: 'Sin especialidades indicadas' }}</p>
                            <p class="mt-2 whitespace-pre-line break-words text-sm leading-6">{{ $professional['description'] }}</p>
                            <p class="mt-3 inline-flex items-center gap-2 text-xs font-semibold text-light-text dark:text-light-text-dark"><x-lucide-eye class="size-4" />{{ $professional['visibility'] === 'public' ? 'Visible en el directorio' : 'Perfil profesional privado' }}</p>
                            @if ($professional['visibility'] === 'public')<a class="mt-3 inline-flex min-h-[44px] items-center gap-2 rounded-[10px] px-3 py-2 text-xs font-semibold text-dark-accent transition-colors hover:bg-accent-bg-hover active:bg-accent-bg dark:text-accent-text-dark dark:hover:bg-accent-bg-hover-dark dark:active:bg-accent-bg-dark sm:min-h-[38px]" href="{{ route('professionals.show', auth()->id()) }}"><x-lucide-eye class="size-4" />Ver presentación pública</a>@endif
                        </div>
                    @else
                        <p class="mt-5 border-t border-gray-100/70 pt-5 text-sm leading-6 text-light-text dark:border-border-dark dark:text-light-text-dark">Si ofreces servicios jurídicos, puedes completar una presentación profesional.</p>
                    @endif
                    <div class="mt-5 grid gap-2">
                        <a class="inline-flex min-h-[44px] items-center gap-2 rounded-[10px] bg-bg px-3 py-2 text-xs font-semibold text-dark-accent transition-colors hover:bg-accent-bg-hover active:bg-accent-bg dark:bg-bg-dark dark:text-accent-text-dark dark:hover:bg-accent-bg-hover-dark dark:active:bg-accent-bg-dark sm:min-h-[38px]" href="{{ route('verification') }}"><x-lucide-badge-check class="size-4" />Mi verificación profesional</a>
                        @if ($professional)<a class="inline-flex min-h-[44px] items-center gap-2 rounded-[10px] px-3 py-2 text-xs font-semibold text-light-text transition-colors hover:bg-accent-bg-hover active:bg-accent-bg dark:text-light-text-dark dark:hover:bg-accent-bg-hover-dark dark:active:bg-accent-bg-dark sm:min-h-[38px]" href="{{ route('profile.professional.edit') }}"><x-lucide-pencil class="size-4" />Editar información profesional</a>@endif
                        @if (auth()->user()->can('verification.review'))<a class="inline-flex min-h-[44px] items-center gap-2 rounded-[10px] px-3 py-2 text-xs font-semibold text-light-text transition-colors hover:bg-accent-bg-hover active:bg-accent-bg dark:text-light-text-dark dark:hover:bg-accent-bg-hover-dark dark:active:bg-accent-bg-dark sm:min-h-[38px]" href="{{ route('verification.review.index') }}"><x-lucide-clipboard-check class="size-4" />Bandeja de revisión profesional</a>@endif
                    </div>
            </section>

            <section class="min-w-0 rounded-[10px] border border-gray-100/50 bg-white p-5 shadow-xs dark:border-border-dark dark:bg-surface-dark" aria-label="Publicaciones del usuario">
                <div class="flex min-h-64 flex-col items-center justify-center rounded-[10px] bg-bg-dark px-6 py-10 text-center text-text-dark dark:bg-bg-dark">
                    <span class="mb-4 grid size-14 place-items-center rounded-[10px] bg-accent-bg-hover-dark text-accent-text-dark"><x-lucide-newspaper class="size-7" /></span>
                    <h3 class="font-bold">Tu actividad aparecerá aquí</h3>
                    <p class="mt-2 max-w-md text-sm leading-6 text-light-text-dark">Consulta tus publicaciones y participa en las conversaciones de la comunidad.</p>
                </div>
            </section>
        </div>
    </div>
</div>
@endsection
