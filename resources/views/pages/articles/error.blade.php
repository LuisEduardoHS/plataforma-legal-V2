@extends('layouts.app')
@section('title', 'Biblioteca | Plataforma Legal')
@section('page-assets') @vite(['resources/css/articles.css']) @endsection
@section('content')
<section class="articles-page content-page"><div class="content-wrap content-empty" role="alert"><h1>{{ $status === 409 ? 'Conflicto de versión' : 'No se completó la operación' }}</h1><p>{{ $message }}</p><div class="content-actions"><a class="content-secondary" href="{{ route('articles') }}">Volver a artículos</a>@auth<a class="content-secondary" href="{{ route('articles.workspace') }}">Mi espacio editorial</a><a class="content-secondary" href="{{ route('saved') }}">Mis guardados</a>@endauth</div></div></section>
@endsection
