@extends('layouts.app')
@section('title', 'Guardados | Plataforma Legal')
@section('page-assets') @vite(['resources/css/library-help.css', 'resources/css/articles.css']) @endsection
@section('content')
<section class="personal-page" aria-labelledby="saved-title">
    <div class="personal-wrap">
        <header class="personal-intro"><div><span class="personal-eyebrow">Tu biblioteca</span><h1 id="saved-title">Tus guardados</h1><p>Publicaciones, artículos y recursos para volver cuando los necesites.</p></div><x-lucide-bookmark class="personal-intro-icon" aria-hidden="true" /></header>
        @if (session('status'))<p class="content-notice" role="status">{{ session('status') }}</p>@endif
        <div class="personal-layout"><div>
            <nav class="content-tabs" aria-label="Tipo de contenido guardado">@foreach (['' => 'Todo', 'post' => 'Publicaciones', 'article' => 'Artículos', 'resource' => 'Recursos jurídicos'] as $key => $label)<a href="{{ route('saved', $key ? ['type' => $key] : []) }}" @if (($type?->value ?? '') === $key) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>
            <div class="personal-toolbar"><span class="personal-muted">Más recientes primero · {{ count($page) }} en esta página</span></div>
            <div class="saved-list" data-library-list>
                @foreach ($page as $item)
                    @php($targetRoute = match ($item['type']) { 'post' => 'community.show', 'article' => 'articles.show', 'resource' => 'resources.show' })
                    <article class="saved-item" data-library-item><span class="saved-item-icon" aria-hidden="true"><x-lucide-bookmark /></span><div class="saved-item-body"><div class="saved-item-meta">{{ match ($item['type']) { 'post' => 'Publicación', 'article' => 'Artículo', 'resource' => 'Recurso jurídico' } }}</div><h3><a href="{{ route($targetRoute, $item['target_id']) }}">{{ $item['title'] ?? $item['preview'] }}</a></h3><div class="saved-item-footer"><time datetime="{{ Illuminate\Support\Carbon::parse($item['saved_at'])->toIso8601String() }}">Guardado el {{ $item['saved_at'] }}</time></div></div><x-content.save :type="$item['type']" :id="$item['target_id']" :saved="true" /></article>
                @endforeach
            </div>
            <div class="personal-empty" data-library-empty @if (count($page)) hidden @endif><x-lucide-bookmark aria-hidden="true" /><h2>No hay guardados disponibles en esta página</h2><p>Guarda contenido desde Comunidad, Artículos o Recursos. El contenido que deja de ser accesible no aparece en tu biblioteca.</p><div class="personal-empty-actions"><a class="personal-button personal-button-primary" href="{{ route('community') }}">Explorar Comunidad</a><a class="personal-button" href="{{ route('articles') }}">Descubrir recursos</a></div></div>
            <x-content.pagination :page="$page" />
        </div><aside class="personal-sidebar" aria-label="Tu biblioteca en detalle"><section class="personal-card"><h2>Vinculada a tu cuenta</h2><p class="personal-muted">Tus guardados son privados y se conservan entre sesiones y equipos. Guardar un contenido no concede acceso cuando cambia su visibilidad.</p><a class="personal-button" href="{{ route('help') }}">Cómo funciona <x-lucide-arrow-right aria-hidden="true" /></a></section></aside></div>
    </div>
</section>
@endsection
