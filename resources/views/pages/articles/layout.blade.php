@extends('layouts.app')
@section('title', ($kind->value === 'article' ? 'Artículos' : 'Recursos jurídicos').' | Plataforma Legal')
@section('page-assets') @vite(['resources/css/articles.css']) @endsection
@section('content')
<section class="articles-page content-page">
    <div class="content-wrap">
        <header class="resources-intro"><span class="resources-eyebrow">Biblioteca legal</span><h1>@yield('library-title', 'Aprende a defenderte')</h1><p>Documentación y recursos para todos.</p></header>
        <nav class="content-tabs" aria-label="Biblioteca legal">
            <a href="{{ route('articles') }}" @if ($kind->value === 'article') aria-current="page" @endif>Artículos</a>
            <a href="{{ route('resources') }}" @if ($kind->value === 'resource') aria-current="page" @endif>Recursos jurídicos</a>
            @auth<a href="{{ route('saved') }}">Mis guardados</a><a href="{{ route($kind->permission().'.workspace') }}">Mi espacio editorial</a>@endauth
        </nav>
        @if (session('status'))<p class="content-notice" role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())<div class="content-error" role="alert"><h2>Revisa el formulario</h2><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('library-content')
    </div>
</section>
@endsection
