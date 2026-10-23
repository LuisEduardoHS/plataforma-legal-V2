@props(['item'])
<article class="home-card home-continuation">
    <h3>{{ $item['title'] }}</h3>
    <p class="home-meta"><x-dynamic-component :component="'lucide-' . $item['icon']" aria-hidden="true" />{{ $item['meta'] }}</p>
    <p @class(['home-continuation-note', 'home-waiting' => $item['kind'] === 'messages'])>{{ $item['text'] }}</p>
    <a href="{{ $item['url'] }}" class="home-action" data-continue="{{ $item['id'] }}">Continuar</a>
</article>