@extends('layouts.app')
@section('title', 'Operación no disponible | Plataforma Legal')
@section('content')
<section class="max-w-2xl mx-auto px-4 py-12 space-y-4"><h1 class="text-2xl font-semibold">Operación no disponible ({{ $status }})</h1><p role="alert">{{ $message }}</p><a class="underline" href="{{ route('home') }}">Volver a Inicio</a></section>
@endsection
