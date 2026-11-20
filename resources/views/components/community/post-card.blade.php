@props(['post', 'detail' => false])
@inject('cardIcons', 'App\Support\CommunityCardIcons')
@php
    $authorName = $post['author']['display_name'];
    $authorInitial = mb_strtoupper(mb_substr($authorName, 0, 1));
    $publishedAt = $post['published_at'] ? Illuminate\Support\Carbon::parse($post['published_at']) : null;
    $statusLabels = ['draft' => 'Borrador', 'hidden' => 'Oculta', 'published' => 'Publicada'];
@endphp
<article class="community-post community-surface {{ $detail ? 'community-post--detail' : '' }}" id="post-{{ $post['id'] }}" aria-labelledby="title-{{ $post['id'] }}">
    @if (! $detail)
        <a class="community-post-stretched-link" href="{{ route('community.show', $post['id']) }}" aria-label="Abrir publicación de {{ $authorName }}"></a>
    @endif
    <header class="community-post-header">
        <div class="community-author">
            <x-community.avatar :initials="$authorInitial" :src="$post['author']['photo_url'] ?? null" :alt="$authorName" />
            <div class="community-author-info">
                <div class="community-author-name">
                    @if ($post['author']['profile_public'])
                        <a href="{{ route('profiles.show', $post['author']['id']) }}">{{ $authorName }}</a>
                    @else
                        <span>{{ $authorName }}</span>
                    @endif
                    @if ($post['author']['professional_verified'] ?? false)
                        {{ $cardIcons->svg('badge-check') }}
                    @endif
                </div>
                <div class="community-meta">
                    @if ($post['author']['professional_public'] ?? false)
                        <a class="community-author-role" href="{{ route('professionals.show', $post['author']['id']) }}">Perfil profesional</a>
                        <span aria-hidden="true">·</span>
                    @endif
                    <time datetime="{{ $publishedAt ? $publishedAt->toIso8601String() : '' }}" title="{{ $post['published_at'] ?? 'Borrador sin publicar' }}">
                        {{ $publishedAt ? $publishedAt->locale('es')->diffForHumans() : 'Borrador' }}
                    </time>
                </div>
            </div>
        </div>
        <div class="community-post-header-actions">
            @if ($post['status'] !== 'published' || $post['visibility'] !== 'public')
                <span class="community-status-pill">
                    {{ $statusLabels[$post['status']] ?? $post['status'] }}@if ($post['visibility'] === 'private') · Privada @endif
                </span>
            @endif
            @if ($post['can_follow'])
                <form method="POST" action="{{ route($post['following'] ? 'community.unfollow' : 'community.follow', $post['author']['id']) }}">
                    @csrf @method($post['following'] ? 'DELETE' : 'PUT')
                    <button class="community-follow-btn {{ $post['following'] ? 'is-following' : '' }}" aria-label="{{ $post['following'] ? 'Dejar de seguir a ' . $authorName : 'Seguir a ' . $authorName }}">
                        @if ($post['following'])
                            {{ $cardIcons->svg('check') }}
                            <span>Siguiendo</span>
                        @else
                            {{ $cardIcons->svg('plus') }}
                            <span>Seguir</span>
                        @endif
                    </button>
                </form>
            @endif
        </div>
    </header>

    <h2 id="title-{{ $post['id'] }}" class="community-sr-only">Publicación {{ $post['id'] }} de {{ $authorName }}</h2>

    @if ($post['category'] || $post['specialty'])
        <div class="community-post-topics">
            @if ($post['category'])
                <span class="community-badge">{{ $post['category']['name'] }}</span>
            @endif
            @if ($post['specialty'])
                <span class="community-badge community-badge--soft">{{ $post['specialty']['name'] }}</span>
            @endif
        </div>
    @endif

    <p class="community-post-body">{{ $post['content'] }}</p>

    @if ($post['tags'])
        <div class="community-post-tags">
            @foreach ($post['tags'] as $tag)
                <a href="{{ route('community', ['tag_id' => $tag['id']]) }}">#{{ $tag['name'] }}</a>
            @endforeach
        </div>
    @endif

    @foreach ($post['attachments'] as $file)
        @php
            $mediaUrl = route('community.media', ['post' => $post['id'], 'attachment' => $file['id']]);
            $ext = strtolower($file['extension'] ?? '');
            $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg']);
            $isVideo = in_array($ext, ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv']);
        @endphp

        @if ($isImage)
            {{-- Imagen embebida con botón de descarga superpuesto --}}
            <div class="community-media-embed community-media-embed--image">
                <img
                    src="{{ $mediaUrl }}"
                    alt="{{ $file['name'] }}"
                    loading="lazy"
                    class="community-media-embed__img"
                >
                <a
                    class="community-media-embed__download community-document-download"
                    href="{{ $mediaUrl }}"
                    download="{{ $file['name'] }}"
                    data-turbo="false"
                    aria-label="Descargar {{ $file['name'] }}"
                    title="Descargar"
                >
                    {{ $cardIcons->svg('download') }}
                </a>
            </div>
        @elseif ($isVideo)
            {{-- Video embebido con botón de descarga superpuesto --}}
            <div class="community-media-embed community-media-embed--video">
                <video
                    controls
                    preload="metadata"
                    class="community-media-embed__video"
                    aria-label="{{ $file['name'] }}"
                >
                    <source src="{{ $mediaUrl }}" type="video/{{ $ext === 'mov' ? 'quicktime' : ($ext === 'mkv' ? 'x-matroska' : $ext) }}">
                    Tu navegador no soporta la reproducción de video.
                </video>
                <a
                    class="community-media-embed__download community-document-download"
                    href="{{ $mediaUrl }}"
                    download="{{ $file['name'] }}"
                    data-turbo="false"
                    aria-label="Descargar {{ $file['name'] }}"
                    title="Descargar"
                >
                    {{ $cardIcons->svg('download') }}
                </a>
            </div>
        @else
            {{-- Documento descargable (comportamiento original) --}}
            <div class="community-document">
                <span class="community-document-icon" aria-hidden="true">{{ $cardIcons->svg('file-text') }}</span>
                <div class="community-document-info">
                    <strong>{{ $file['name'] }}</strong>
                    <span>{{ strtoupper($file['extension']) }} · {{ max(1, (int) ceil($file['size_bytes'] / 1024)) }} KiB</span>
                </div>
                <a class="community-document-download" href="{{ $mediaUrl }}" data-turbo="false">
                    {{ $cardIcons->svg('download') }}
                    <span>Descargar</span>
                </a>
            </div>
        @endif
    @endforeach

    <footer class="community-post-footer">
        <div class="community-post-actions">
            @if ($post['can_interact'])
                <form method="POST" action="{{ route($post['liked'] ? 'community.unreact' : 'community.react', $post['id']) }}">
                    @csrf @method($post['liked'] ? 'DELETE' : 'PUT')
                    <button aria-label="{{ $post['liked'] ? 'Retirar Me gusta' : 'Me gusta' }}" aria-pressed="{{ $post['liked'] ? 'true' : 'false' }}">
                        {{ $cardIcons->svg('thumbs-up') }}
                        <span>{{ $post['likes_count'] }}</span>
                    </button>
                </form>
            @else
                <span class="community-action-static" aria-label="{{ $post['likes_count'] }} Me gusta">
                    {{ $cardIcons->svg('thumbs-up') }}
                    <span>{{ $post['likes_count'] }}</span>
                </span>
            @endif
            <a href="{{ route('community.show', $post['id']) }}#comentarios" aria-label="Ver {{ $post['comments_count'] }} comentarios">
                {{ $cardIcons->svg('message-circle') }}
                <span>{{ $post['comments_count'] }}</span>
            </a>
            <x-content.save type="post" :id="$post['id']" :saved="$post['saved']" :can-save="$post['can_save'] || (!auth()->check() && $post['status'] === 'published')" />
            <button type="button" data-share-post="{{ route('community.show', $post['id']) }}" aria-label="Copiar enlace" title="Copiar enlace">
                {{ $cardIcons->svg('share-2') }}
            </button>
        </div>
    </footer>

    <p class="community-action-status" data-action-status role="status" aria-live="polite"></p>

    @if ($detail && ($post['can_edit'] || $post['can_delete'] || $post['can_moderate']))
        <details class="community-manage" @if (session('community.moderation_error') === 'post-'.$post['id']) open @endif>
            <summary>Administrar publicación</summary>
            <div class="community-form-actions">
                @if ($post['can_edit'])<a class="community-secondary" href="{{ route('community.edit', $post['id']) }}">Editar</a>@endif
                @if ($post['can_edit'] && $post['status'] === 'draft' && ($post['author']['id'] !== auth()->id() || auth()->user()->can('posts.create')))
                    <form method="POST" action="{{ route('community.posts.publish', $post['id']) }}">@csrf<input type="hidden" name="version" value="{{ $post['version'] }}"><button class="community-primary">Publicar borrador</button></form>
                @endif
                @if ($post['can_delete'])
                    <form method="POST" action="{{ route('community.posts.destroy', $post['id']) }}">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $post['version'] }}"><button class="community-secondary">Eliminar</button></form>
                @endif
                @if ($post['can_moderate'] && in_array($post['status'], ['published', 'hidden'], true))
                    <form method="POST" action="{{ route('community.posts.moderate', $post['id']) }}">@csrf<input type="hidden" name="version" value="{{ $post['version'] }}"><input type="hidden" name="action" value="{{ $post['status'] === 'hidden' ? 'show' : 'hide' }}"><x-community.moderation-reason :suffix="'post-'.$post['id']" /><button class="community-secondary">{{ $post['status'] === 'hidden' ? 'Volver a mostrar' : 'Ocultar' }}</button></form>
                @endif
            </div>
        </details>
    @endif
</article>
