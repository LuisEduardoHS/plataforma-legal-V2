@props(['item'])
<article class="home-card home-continuation" data-pending-item>
    <div class="home-continuation-top"><span class="home-kind-icon home-kind-{{ $item['kind'] }}"><x-dynamic-component :component="'lucide-' . $item['icon']" aria-hidden="true" /></span><span>{{ ['lexa' => 'LEXA', 'messages' => 'Conversación', 'draft' => 'Borrador'][$item['kind']] }}</span><button type="button" class="home-icon" data-dismiss-pending aria-label="Ocultar recordatorio: {{ $item['title'] }}"><x-lucide-x aria-hidden="true" /></button></div>
    <h3>{{ $item['title'] }}</h3>
    <p class="home-meta">{{ $item['meta'] }}</p>
    <a href="{{ $item['url'] }}" class="home-action" data-continue="{{ $item['id'] }}">{{ ['lexa' => 'Retomar consulta', 'messages' => 'Abrir conversación', 'draft' => 'Editar borrador'][$item['kind']] }}</a>
</article>
