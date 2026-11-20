@extends('layouts.app')
@section('title', ($kind->value === 'article' ? 'Artículos' : 'Recursos jurídicos').' | Lefenda')
@section('page-assets') @vite(['resources/css/articles.css']) @endsection
@section('content')
<section class="articles-page content-page">
    <div class="content-wrap">
        <nav class="content-tabs" aria-label="Biblioteca legal">
            <a href="{{ route('articles') }}" @if ($kind->value === 'article') aria-current="page" @endif>Artículos</a>
            <a href="{{ route('resources') }}" @if ($kind->value === 'resource') aria-current="page" @endif>Recursos jurídicos</a>
            @auth<a href="{{ route('saved') }}">Mis guardados</a><a href="{{ route($kind->permission().'.workspace') }}">Mi espacio editorial</a>@endauth
        </nav>
        @hasSection('library-title')<h1 class="content-heading">@yield('library-title')</h1>@endif
        @if (session('status'))<p class="content-notice" role="status">{{ session('status') }}</p>@endif
        @hasSection('library-errors') @yield('library-errors') @else <x-ui.error-summary /> @endif
        @yield('library-content')
    </div>
</section>
@endsection
