@extends('layouts.app')
@section('title', 'Verificación profesional | Plataforma Legal')
@section('page-assets') @vite(['resources/css/professionals.css']) @endsection
@section('content')
<div class="profile-editor min-h-screen bg-bg dark:bg-bg-dark text-text dark:text-text-dark pb-24" data-verification>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8 space-y-6">
        <nav class="flex flex-wrap gap-4 text-sm" aria-label="Verificación"><a class="underline" href="{{ route('profile') }}">Mi perfil</a><a class="underline" href="{{ route('verification') }}">Mi verificación</a>@if (auth()->user()->can('verification.review'))<a class="underline" href="{{ route('verification.review.index') }}">Bandeja de revisión</a>@endif</nav>
        <h1 class="text-2xl font-bold">@yield('verification-title', 'Verificación profesional')</h1>
        @if (session('status'))<p class="profile-panel" role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())<div class="profile-panel" role="alert" tabindex="-1" aria-labelledby="verification-errors-title"><h2 id="verification-errors-title" class="font-semibold">Revisa el formulario</h2><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul><p class="mt-3 text-sm">Los archivos seleccionados deben elegirse de nuevo. No se ha presentado una solicitud por mostrar este error.</p></div>@endif
        @yield('verification-content')
        <p class="text-xs leading-relaxed text-light-text dark:text-light-text-dark">La verificación es una revisión humana interna de la documentación presentada. No es una consulta automática oficial de cédulas ni garantiza la calidad de los servicios profesionales.</p>
    </div>
</div>
@endsection
