@props(['post'])
<article class="home-card home-post" data-home-post="{{ $post['id'] }}">
    <header><span class="home-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($post['author']['display_name'], 0, 1)) }}</span><div><h3>{{ $post['author']['display_name'] }}</h3><p class="home-meta">{{ $post['category']['name'] ?? 'Sin categoría' }} <span aria-hidden="true">·</span> {{ $post['published_at'] }} UTC</p></div><x-content.save type="post" :id="$post['id']" :saved="$post['saved']" /></header>
    <h4 class="whitespace-pre-wrap break-words">{{ Illuminate\Support\Str::limit($post['content'] ?: 'Archivo compartido', 250) }}</h4>
    <x-profiles.public-professional :author="$post['author']" />
    <footer><span><x-lucide-message-circle aria-hidden="true" />{{ $post['comments_count'] }} {{ $post['comments_count'] === 1 ? 'comentario' : 'comentarios' }}</span><a href="{{ route('community.show', $post['id']) }}">Ver publicación <x-lucide-arrow-up-right aria-hidden="true" /></a></footer>
</article>
