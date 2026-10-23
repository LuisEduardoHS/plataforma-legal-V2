@props(['post'])
<article class="home-card home-post" data-home-post="{{ $post['id'] }}">
    <header><span class="home-avatar" aria-hidden="true">{{ $post['initials'] }}</span><div><h3>{{ $post['author'] }} @if ($post['verified'])<x-lucide-badge-check aria-label="Profesional verificado" role="img" />@endif</h3><p class="home-meta">{{ $post['category'] }} <span aria-hidden="true">·</span> {{ $post['time'] }}</p></div><x-home.bookmark :label="$post['title']" /></header>
    <h4>{{ $post['title'] }}</h4>
    <p>{{ $post['body'] }}</p>
    <footer><span><x-lucide-message-circle aria-hidden="true" />{{ $post['replies'] }} respuestas</span><a href="{{ route('community') }}#post-{{ $post['id'] }}">Ver publicación <x-lucide-arrow-up-right aria-hidden="true" /></a></footer>
</article>
