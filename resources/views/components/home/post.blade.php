@props(['post'])
@php
    $authorName = $post['author']['display_name'];
    $authorInitial = mb_strtoupper(mb_substr($authorName, 0, 1));
    $publishedAt = $post['published_at'] ? Illuminate\Support\Carbon::parse($post['published_at']) : null;
@endphp
<article class="home-card home-post" data-home-post="{{ $post['id'] }}">
    <a class="home-post-stretched-link" href="{{ route('community.show', $post['id']) }}" aria-label="Abrir publicación de {{ $authorName }}"></a>
    <header class="home-post-header">
        <span class="home-avatar" aria-hidden="true">{{ $authorInitial }}</span>
        <div class="home-post-author">
            <h3>
                <span>{{ $authorName }}</span>
                @if ($post['author']['professional_verified'] ?? false)
                    <x-lucide-badge-check class="home-verified-icon" aria-label="Perfil verificado" title="Revisión documental interna vigente" />
                @endif
            </h3>
            <p class="home-meta">
                @if ($post['author']['professional_public'] ?? false)
                    <a class="home-author-role" href="{{ route('professionals.show', $post['author']['id']) }}">Perfil profesional</a>
                    <span aria-hidden="true">·</span>
                @endif
                @if ($publishedAt)
                    <time datetime="{{ $publishedAt->toIso8601String() }}" title="{{ $post['published_at'] }}">{{ $publishedAt->locale('es')->diffForHumans() }}</time>
                @endif
            </p>
        </div>
        @if ($post['category'])
            <span class="home-post-badge">{{ $post['category']['name'] }}</span>
        @endif
    </header>
    <p class="home-post-excerpt">{{ Illuminate\Support\Str::limit($post['content'] ?: 'Archivo compartido', 240) }}</p>

    @if (!empty($post['attachments']))
        @foreach ($post['attachments'] as $file)
            @php
                $mediaUrl = route('community.media', ['post' => $post['id'], 'attachment' => $file['id']]);
                $ext = strtolower($file['extension'] ?? '');
                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg']);
                $isVideo = in_array($ext, ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv']);
            @endphp

            @if ($isImage)
                <div class="community-media-embed community-media-embed--image">
                    <img
                        src="{{ $mediaUrl }}"
                        alt="{{ $file['name'] }}"
                        loading="lazy"
                        class="community-media-embed__img"
                    >
                    <a
                        class="community-media-embed__download"
                        href="{{ $mediaUrl }}"
                        download="{{ $file['name'] }}"
                        data-turbo="false"
                        aria-label="Descargar {{ $file['name'] }}"
                        title="Descargar"
                    >
                        <x-lucide-download aria-hidden="true" />
                    </a>
                </div>
            @elseif ($isVideo)
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
                        class="community-media-embed__download"
                        href="{{ $mediaUrl }}"
                        download="{{ $file['name'] }}"
                        data-turbo="false"
                        aria-label="Descargar {{ $file['name'] }}"
                        title="Descargar"
                    >
                        <x-lucide-download aria-hidden="true" />
                    </a>
                </div>
            @endif
        @endforeach
    @endif

    <footer class="home-post-footer">
        <div class="home-post-actions">
            <span class="home-post-stat"><x-lucide-message-circle aria-hidden="true" /><span>{{ $post['comments_count'] }} {{ $post['comments_count'] === 1 ? 'comentario' : 'comentarios' }}</span></span>
            <x-content.save type="post" :id="$post['id']" :saved="$post['saved']" />
        </div>
        <span class="home-post-open" aria-hidden="true"><x-lucide-arrow-up-right /></span>
    </footer>
</article>
