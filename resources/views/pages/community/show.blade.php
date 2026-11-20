@extends('layouts.app')
@section('title', 'Publicación | Lefenda')
@section('page-assets') @vite(['resources/css/community.css']) @endsection
@section('content')
<section class="community" data-community><div class="community-single">
    <h1 class="community-sr-only">Publicación de Comunidad</h1>
    <div class="community-single-nav">
        <a class="community-back-link" href="{{ route('community') }}"><x-lucide-arrow-left aria-hidden="true" /><span>Volver a Comunidad</span></a>
    </div>
    <x-community.feedback />
    <x-community.post-card :post="$post" :detail="true" />
    <section id="comentarios" class="community-comments" aria-labelledby="comments-title">
        <div class="community-comments-header">
            <h2 id="comments-title">Comentarios <span class="community-comments-count">{{ $post['comments_count'] }}</span></h2>
            @can('comments.moderate')
                <a class="community-moderation-toggle" href="{{ route('community.show', ['post' => $post['id'], 'review' => $review ? 0 : 1]) }}">
                    {{ $review ? 'Ver comentarios públicos' : 'Revisar ocultos' }}
                </a>
            @endcan
        </div>
        @if ($review)
            <p class="community-muted">Modo revisión: incluye comentarios ocultos. Ocultar un comentario raíz oculta sus respuestas en el hilo público.</p>
        @endif
        @if ($post['can_comment'])
            <div class="community-comment-composer community-surface">
                <x-community.comment-form :post-id="$post['id']" />
            </div>
        @elseif (! auth()->check())
            <div class="community-comment-login community-surface">
                <span>¿Quieres participar en la conversación?</span>
                <a class="community-primary" href="{{ route('login') }}">Iniciar sesión</a>
            </div>
        @endif
        @forelse ($comments ?? [] as $comment)
            @php
                $commentAuthor = $comment['author']['display_name'];
                $commentInitial = mb_strtoupper(mb_substr($commentAuthor, 0, 1));
                $commentAt = $comment['created_at'] ? Illuminate\Support\Carbon::parse($comment['created_at']) : null;
            @endphp
            <article class="community-comment community-surface {{ $comment['parent_id'] ? 'community-comment-reply' : '' }}" id="comentario-{{ $comment['id'] }}">
                <header class="community-comment-header">
                    <x-community.avatar :initials="$commentInitial" :src="$comment['author']['photo_url'] ?? null" :alt="$commentAuthor" />
                    <div class="community-comment-author">
                        <div class="community-author-name">
                            @if ($comment['author']['profile_public'])
                                <a href="{{ route('profiles.show', $comment['author']['id']) }}">{{ $commentAuthor }}</a>
                            @else
                                <span>{{ $commentAuthor }}</span>
                            @endif
                            @if ($review)
                                <span class="community-status-pill">{{ $comment['status'] === 'hidden' ? 'Oculto' : 'Visible' }}</span>
                            @endif
                        </div>
                        <time class="community-muted" datetime="{{ $commentAt ? $commentAt->toIso8601String() : '' }}" title="{{ $comment['created_at'] }}">
                            {{ $commentAt ? $commentAt->locale('es')->diffForHumans() : $comment['created_at'] }}
                        </time>
                    </div>
                </header>
                @if ($comment['parent_id'])
                    <p class="community-reply-ref">En respuesta al comentario #{{ $comment['parent_id'] }}</p>
                @endif
                <p class="community-comment-body">{{ $comment['content'] }}</p>
                <div class="community-comment-actions">
                    @if ($comment['can_edit'])<a class="community-comment-action" href="{{ route('community.comments.edit', $comment['id']) }}">Editar</a>@endif
                    @if ($comment['can_delete'])<form method="POST" action="{{ route('community.comments.destroy', $comment['id']) }}">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $comment['version'] }}"><button class="community-comment-action community-comment-action--danger">Eliminar</button></form>@endif
                    @if ($comment['can_moderate'])<form method="POST" action="{{ route('community.comments.moderate', $comment['id']) }}">@csrf<input type="hidden" name="version" value="{{ $comment['version'] }}"><input type="hidden" name="action" value="{{ $comment['status'] === 'hidden' ? 'show' : 'hide' }}"><x-community.moderation-reason :suffix="'comment-'.$comment['id']" /><button class="community-comment-action">{{ $comment['status'] === 'hidden' ? 'Mostrar' : 'Ocultar' }}</button></form>@endif
                </div>
                @if ($comment['can_reply'])
                    <details class="community-reply-details">
                        <summary class="community-reply-trigger"><x-lucide-corner-down-right aria-hidden="true" /><span>Responder</span></summary>
                        <x-community.comment-form :post-id="$post['id']" :parent-id="$comment['id']" />
                    </details>
                @endif
            </article>
        @empty
            <div class="community-state community-surface">
                <x-lucide-message-circle aria-hidden="true" />
                <p>Aún no hay comentarios. Sé el primero en participar.</p>
            </div>
        @endforelse
        <x-community.pagination :page="$comments" />
    </section>
</div></section>
@endsection
