@props(['post'])
<article class="community-post community-surface" id="post-{{ $post['id'] }}" aria-labelledby="title-{{ $post['id'] }}" data-post data-replies="{{ $post['replies'] }}" data-date="{{ $post['published_at'] }}" data-category="{{ $post['category'] }}">
    <div data-post-content>
        <header class="community-author">
            <x-community.avatar :initials="$post['initials']" />
            <div>
                <div class="community-author-name">{{ $post['author'] }} @if ($post['verified'])<x-lucide-badge-check aria-label="Profesional verificado" role="img" />@endif</div>
                <div class="community-meta"><span class="community-badge">{{ $post['category'] }}</span><span aria-hidden="true">·</span><span>{{ $post['time'] }}</span></div>
            </div>
        </header>
        <h2 id="title-{{ $post['id'] }}">{{ $post['title'] }}</h2>
        <p class="community-post-body">{{ $post['body'] }}</p>
        @if ($post['type'] === 'image')
            <figure class="community-post-image"><img src="{{ asset('images/community/demo-sketch.svg') }}" alt="Una cara sonriente con un pulgar arriba" width="280" height="266" loading="lazy"></figure>
        @elseif ($post['type'] === 'document')
            <div class="community-document"><x-lucide-file-text aria-hidden="true" /><div><strong>Contrato_arrendamiento.pdf</strong><span>PDF · Vista de referencia</span></div></div>
        @endif
    </div>
    <footer class="community-post-footer">
        <div class="community-post-actions">
            <button type="button" data-open-post aria-label="Ver {{ $post['replies'] }} respuestas a {{ $post['title'] }}"><x-lucide-message-circle aria-hidden="true" /><span>{{ $post['replies'] }}</span></button>
            <button type="button" aria-label="Guardar publicación" title="Guardar publicación" aria-pressed="false" data-save-post><x-lucide-bookmark aria-hidden="true" /></button>
            <button type="button" aria-label="Copiar enlace" title="Copiar enlace" data-share-post><x-lucide-share-2 aria-hidden="true" /></button>
        </div>
        <button class="community-detail-link" type="button" data-open-post>Ver publicación <x-lucide-arrow-up-right aria-hidden="true" /></button>
    </footer>
    <p class="community-action-status" data-action-status role="status" aria-live="polite"></p>
    @if ($post['type'] === 'question')
        <button type="button" class="community-reply" data-open-post><x-lucide-message-circle aria-hidden="true" /> Ver conversación</button>
    @endif
</article>
