@extends('layouts.app')
@section('title', 'Operaciones | Plataforma Legal')
@section('page-assets') @vite(['resources/css/professionals.css']) @endsection
@section('content')
<div class="profile-editor min-h-screen bg-bg dark:bg-bg-dark text-text dark:text-text-dark pb-24">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8 space-y-6">
        <nav aria-label="Operaciones" class="flex flex-wrap gap-4 text-sm"><a class="underline" href="{{ route('operations.index') }}">Resumen operativo</a><a class="underline" href="{{ route('home') }}">Volver a Inicio</a></nav>
        <h1 class="text-2xl font-bold">@yield('operations-title', 'Operaciones')</h1>
        @if (session('status'))<p class="profile-panel" role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())<div class="profile-panel" role="alert" tabindex="-1"><h2 class="font-semibold">Revisa la operación</h2><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('operations-content')
        <p class="text-sm">Los cambios sensibles requieren un motivo y quedan registrados. Esta sección no concede acceso a conversaciones ni documentos privados.</p>
    </div>
</div>
@endsection
