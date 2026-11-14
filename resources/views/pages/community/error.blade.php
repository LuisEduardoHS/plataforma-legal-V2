@extends('layouts.app')
@section('title', 'Comunidad | Plataforma Legal')
@section('page-assets') @vite(['resources/css/community.css']) @endsection
@section('content')
<section class="community"><div class="community-single community-state community-surface" role="alert"><h1>{{ $status === 409 ? 'Conflicto de versión' : 'No se completó la operación' }}</h1><p>{{ $message }}</p><a class="community-primary" href="{{ route('community') }}">Volver a Comunidad</a>@auth<a class="community-secondary" href="{{ route('community', ['feed' => 'mine']) }}">Consultar mis publicaciones</a>@endauth</div></section>
@endsection
