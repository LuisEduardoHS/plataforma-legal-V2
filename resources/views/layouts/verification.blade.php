@extends('layouts.app')
@section('title', 'Verificación profesional | Lefenda')
@section('page-assets') @vite(['resources/css/professionals.css']) @endsection
@section('content')
<div class="profile-editor min-h-screen bg-bg pb-20 text-text dark:bg-bg-dark dark:text-text-dark" data-verification>
    <div class="mx-auto max-w-[1160px] px-4 py-6 sm:px-6 sm:py-8">
        <nav class="mb-5 flex min-h-11 flex-wrap items-center gap-1 rounded-[10px] bg-white p-1.5 shadow-xs dark:bg-surface-dark" aria-label="Verificación">
            <a class="inline-flex min-h-9 items-center gap-2 rounded-[10px] px-3 text-sm font-semibold text-light-text transition-colors hover:bg-accent-bg-hover dark:text-light-text-dark dark:hover:bg-accent-bg-hover-dark" href="{{ route('profile') }}"><x-lucide-arrow-left class="size-4" />Mi perfil</a>
            <a class="inline-flex min-h-9 items-center gap-2 rounded-[10px] px-3 text-sm font-semibold text-dark-accent hover:bg-accent-bg-hover dark:text-accent-text-dark dark:hover:bg-accent-bg-hover-dark" href="{{ route('verification') }}"><x-lucide-badge-check class="size-4" />Mi verificación</a>
            @if (auth()->user()->can('verification.review'))<a class="inline-flex min-h-9 items-center gap-2 rounded-[10px] px-3 text-sm font-semibold text-light-text hover:bg-accent-bg-hover dark:text-light-text-dark dark:hover:bg-accent-bg-hover-dark" href="{{ route('verification.review.index') }}"><x-lucide-clipboard-check class="size-4" />Bandeja de revisión</a>@endif
        </nav>
        <header class="mb-5 rounded-[10px] bg-white p-5 shadow-xs dark:bg-surface-dark sm:p-6">
            <div class="flex items-start gap-3">
                <span class="grid size-11 shrink-0 place-items-center rounded-[10px] bg-accent-bg-hover text-dark-accent dark:bg-accent-bg-hover-dark dark:text-accent-text-dark"><x-lucide-shield-check class="size-5" /></span>
                <div><p class="text-xs font-semibold uppercase tracking-[0.12em] text-dark-accent dark:text-accent-text-dark">Verificación profesional</p><h1 class="mt-1 text-2xl font-bold sm:text-[28px]">@yield('verification-title', 'Verificación profesional')</h1></div>
            </div>
        </header>
        @if (session('status'))<p class="profile-panel mb-5 !rounded-[10px] border-0 shadow-xs" role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())<div class="profile-panel mb-5 !rounded-[10px] border-0 shadow-xs" role="alert" tabindex="-1" aria-labelledby="verification-errors-title"><h2 id="verification-errors-title" class="font-semibold">Revisa el formulario</h2><ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul><p class="mt-3 text-sm">Los archivos seleccionados deben elegirse de nuevo. No se ha presentado una solicitud por mostrar este error.</p></div>@endif
        @yield('verification-content')
        <p class="mt-5 px-1 text-xs leading-relaxed text-light-text dark:text-light-text-dark">La verificación es una revisión humana interna de la documentación presentada. No es una consulta automática oficial de cédulas ni garantiza la calidad de los servicios profesionales.</p>
    </div>
</div>
@endsection
