@extends('layouts.app')
@section('title', 'Publicación | Plataforma Legal')
@section('page-assets') @vite(['resources/css/community.css']) @endsection
@section('content')
<section class="community" data-community><div class="community-single"><h1 class="community-sr-only">Publicación de Comunidad</h1><a class="community-text-link" href="{{ route('community') }}">← Volver a Comunidad</a><x-community.feedback /><x-community.post-card :post="$post" :detail="true" />
    <section id="comentarios" class="community-comments" aria-labelledby="comments-title"><h2 id="comments-title">Comentarios ({{ $post['comments_count'] }})</h2>
        @can('comments.moderate')<a class="community-text-link" href="{{ route('community.show', ['post' => $post['id'], 'review' => $review ? 0 : 1]) }}">{{ $review ? 'Volver a comentarios visibles' : 'Revisar comentarios ocultos' }}</a>@endcan
        @if ($review)<p class="community-muted">Revisión de moderación: incluye comentarios ocultos. El contador corresponde a comentarios visibles. Eliminar u ocultar una raíz oculta sus respuestas del hilo público.</p>@endif
        @forelse ($comments ?? [] as $comment)
            <article class="community-comment community-surface {{ $comment['parent_id'] ? 'community-comment-reply' : '' }}" id="comentario-{{ $comment['id'] }}">
                <header class="community-author-name">@if ($comment['author']['profile_public'])<a href="{{ route('profiles.show', $comment['author']['id']) }}">{{ $comment['author']['display_name'] }}</a>@else{{ $comment['author']['display_name'] }}@endif <time class="community-muted">{{ $comment['created_at'] }}</time></header>
                @if ($comment['parent_id'])<p class="community-muted">Respuesta al comentario #{{ $comment['parent_id'] }} (puede aparecer en otra página).</p>@endif
                @if ($review)<p class="community-muted">{{ $comment['status'] === 'hidden' ? 'Oculto' : 'Visible' }}</p>@endif
                <p class="community-post-body">{{ $comment['content'] }}</p><div class="community-form-actions">
                    @if ($comment['can_edit'])<a class="community-text-link" href="{{ route('community.comments.edit', $comment['id']) }}">Editar comentario</a>@endif
                    @if ($comment['can_delete'])<form method="POST" action="{{ route('community.comments.destroy', $comment['id']) }}">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $comment['version'] }}"><button class="community-text-link">Eliminar comentario</button></form>@endif
                    @if ($comment['can_moderate'])<form method="POST" action="{{ route('community.comments.moderate', $comment['id']) }}">@csrf<input type="hidden" name="version" value="{{ $comment['version'] }}"><input type="hidden" name="action" value="{{ $comment['status'] === 'hidden' ? 'show' : 'hide' }}"><button class="community-text-link">{{ $comment['status'] === 'hidden' ? 'Mostrar comentario' : 'Ocultar comentario' }}</button></form>@endif
                </div>
                @if ($comment['can_reply'])<details><summary>Responder a este comentario</summary><x-community.comment-form :post-id="$post['id']" :parent-id="$comment['id']" /></details>@endif
            </article>
        @empty<p class="community-state community-surface">No hay comentarios disponibles en esta página.</p>@endforelse
        <x-community.pagination :page="$comments" />
        @if ($post['can_comment'])<div class="community-comment community-surface"><x-community.comment-form :post-id="$post['id']" /></div>@elseif (! auth()->check())<p><a class="community-text-link" href="{{ route('login') }}">Inicia sesión para comentar o reaccionar</a></p>@endif
    </section>
</div></section>
@endsection
