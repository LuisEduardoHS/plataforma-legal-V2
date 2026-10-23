@extends('layouts.app')

@section('title', 'Comunidad | Plataforma Legal')

@section('page-assets')
    @vite(['resources/css/community.css'])
@endsection
@section('content')
    @php
        $community = $community ?? require resource_path('views/pages/community/demo.php');
        $state = $community['state'] ?? 'ready';
    @endphp
    <section class="community" aria-labelledby="community-title" data-community>
        <div class="community-grid">
            <div class="community-main">
                <div class="community-heading"><h1 id="community-title">Comunidad</h1></div>
                <div class="community-tabs community-surface" role="tablist" aria-label="Filtrar publicaciones">
                    @foreach (['para-ti' => 'Para ti', 'recientes' => 'Recientes', 'sin-respuesta' => 'Sin respuesta'] as $key => $label)
                        <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="community-feed" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}" data-filter="{{ $key }}">{{ $label }}</button>
                    @endforeach
                    <span class="community-tab-indicator" aria-hidden="true"></span>
                </div>
                <x-community.composer />
                <section class="community-feed" id="community-feed" role="tabpanel" aria-labelledby="tab-para-ti" @if ($state === 'loading') aria-busy="true" @endif>
                    @if ($state === 'loading')
                        <div class="community-state community-surface" role="status"><x-lucide-loader-circle /> Cargando publicaciones…</div>
                    @elseif ($state === 'error')
                        <div class="community-state community-surface" role="alert"><x-lucide-circle-alert /><p>No pudimos cargar la comunidad. Inténtalo de nuevo más tarde.</p></div>
                    @else
                        @foreach ($community['posts'] as $post)
                            <x-community.post-card :post="$post" />
                        @endforeach
                        <div class="community-state community-surface" data-feed-empty @if (count($community['posts'])) hidden @endif>
                            <x-lucide-messages-square /><h2>No hay publicaciones en esta selección</h2>
                            <button type="button" class="community-secondary" data-reset-filter>Ver todas</button>
                        </div>
                    @endif
                </section>
                <p class="community-sr-only" role="status" aria-live="polite" data-filter-status></p>
            </div>
            <x-community.sidebar :topics="$community['topics']" :questions="$community['questions']" :professionals="$community['professionals']" />
        </div>
        <x-community.create-modal :categories="$community['topics']" />
        <dialog class="community-dialog" data-community-dialog aria-labelledby="community-dialog-title">
            <div class="community-dialog-heading">
                <h2 id="community-dialog-title">Publicación</h2>
                <button type="button" class="community-icon-button" data-close-dialog aria-label="Cerrar publicación"><x-lucide-x aria-hidden="true" /></button>
            </div>
            <div data-community-detail></div>
            <p class="community-muted">Las respuestas estarán disponibles cuando se habiliten las conversaciones.</p>
        </dialog>
    </section>
@endsection
