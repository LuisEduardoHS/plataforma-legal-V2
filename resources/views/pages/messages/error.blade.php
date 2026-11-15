@extends('layouts.app')
@section('title', 'Mensajes | Plataforma Legal')
@section('page-assets') @vite(['resources/css/messages.css']) @endsection
@section('content')
<section class="messages-edit" role="alert"><h1>{{ $status === 409 ? 'Conflicto de versión' : 'No se completó la operación' }}</h1><p>{{ $message }}</p><a class="messages-action" href="{{ route('messages') }}">Volver a Mensajes</a></section>
@endsection
