@props(['article'])
@php($prefix = $article['type'] === 'article' ? 'articles' : 'resources')
<article class="resource-card animate-card-fade-in">
    <div class="resource-card-cover"><x-articles.card-banner /><span class="resource-cover-category">{{ $article['category']['name'] ?? 'Sin categoría' }}</span></div>
    <div class="content-card-body">
        <h3><a href="{{ route($prefix.'.show', $article['id']) }}">{{ $article['title'] }}</a></h3>
        <p>{{ Illuminate\Support\Str::limit($article['summary'] ?? $article['description'] ?? '', 240) }}</p>
        <p class="content-muted">{{ $article['author']['display_name'] }} · {{ $article['published_at'] ?? 'Sin publicar' }}</p>
        @if ($article['type'] === 'resource')<p class="content-muted">{{ $article['source_label'] }} · {{ __('content.resource_types.'.$article['resource_type'], [], 'es') }}</p>@endif
        <div class="content-card-footer"><a class="content-link" href="{{ route($prefix.'.show', $article['id']) }}">Ver más <x-lucide-arrow-right aria-hidden="true" /></a><x-content.save :type="$article['type']" :id="$article['id']" :saved="$article['saved']" :can-save="$article['can_save'] || !auth()->check()" /></div>
    </div>
</article>
