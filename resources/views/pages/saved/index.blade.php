@extends('layouts.app')
@section('title', 'Guardados | Plataforma Legal')
@section('page-assets')
    @vite(['resources/css/library-help.css'])
@endsection
@section('content')
<section class="personal-page" data-saved aria-labelledby="saved-title">
    <div class="personal-wrap">
        <header class="personal-intro"><div><span class="personal-eyebrow">Tu biblioteca</span><h1 id="saved-title">Tus guardados</h1><p>Publicaciones y lecturas para volver cuando las necesites.</p></div><x-lucide-bookmark class="personal-intro-icon" aria-hidden="true" /></header>
        <div class="personal-layout">
            <div>
                <p class="personal-error" data-saved-error role="alert" hidden></p>
                <div class="personal-notice" data-saved-undo hidden><span>Contenido retirado de Guardados.</span><button class="personal-button" type="button" data-undo>Deshacer</button></div>
                <label class="personal-search"><x-lucide-search aria-hidden="true" /><span class="personal-sr">Buscar en Guardados</span><input type="search" data-saved-search placeholder="Busca por título, tema o autor…" autocomplete="off"></label>
                <div class="personal-filters" role="group" aria-label="Tipo de contenido guardado">
                    @foreach (['all' => 'Todo', 'post' => 'Publicaciones', 'article' => 'Artículos'] as $key => $label)
                        <button type="button" data-saved-kind="{{ $key }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="personal-toolbar"><span class="personal-muted" data-saved-result role="status" aria-live="polite"></span><label class="personal-muted">Ordenar <select data-saved-order><option value="recent">Más recientes</option><option value="oldest">Más antiguos</option></select></label></div>
                <div class="saved-list" data-saved-list></div>
                <div class="personal-empty" data-saved-empty><x-lucide-bookmark aria-hidden="true" /><h2>Tu próxima buena lectura, aquí</h2><p>Pulsa el marcador de una publicación o un artículo para encontrarlo después en este espacio.</p><div class="personal-empty-actions"><a class="personal-button personal-button-primary" href="{{ route('community') }}">Explorar Comunidad</a><a class="personal-button" href="{{ route('articles') }}">Descubrir recursos</a></div></div>
                <div class="personal-empty" data-saved-no-results hidden><x-lucide-search-x aria-hidden="true" /><h2>No hay coincidencias</h2><p>Prueba con otra palabra o cambia el tipo de contenido.</p><button class="personal-button" type="button" data-saved-reset>Limpiar filtros</button></div>
            </div>
            <aside class="personal-sidebar" aria-label="Tu biblioteca en detalle">
                <section class="personal-card"><h2>En tu biblioteca</h2><div class="saved-count-row"><span>Total de guardados</span><strong data-saved-total>0</strong></div><div class="saved-count-row"><span>Publicaciones</span><strong data-count-post>0</strong></div><div class="saved-count-row"><span>Artículos</span><strong data-count-article>0</strong></div></section>
                <section class="personal-card"><h2>En este dispositivo</h2><p class="personal-muted">Tus guardados se conservan en este navegador. Por ahora no se sincronizan entre equipos.</p><a class="personal-button" href="{{ route('help') }}">Cómo funciona<x-lucide-arrow-right aria-hidden="true" /></a></section>
            </aside>
        </div>
    </div>
    <template data-saved-template><article class="saved-item"><span class="saved-item-icon" aria-hidden="true"><x-lucide-bookmark /></span><div class="saved-item-body"><div class="saved-item-meta"><span data-item-type></span><span aria-hidden="true">·</span><span data-item-category></span></div><h3><a data-item-link></a></h3><p class="saved-item-summary" data-item-summary></p><div class="saved-item-footer"><span class="personal-muted" data-item-author></span><time data-item-time></time></div></div><button class="personal-icon-button" type="button" data-remove-saved><x-lucide-bookmark-minus aria-hidden="true" /></button></article></template>
</section>
@endsection
