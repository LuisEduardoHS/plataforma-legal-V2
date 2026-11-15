@extends('pages.articles.layout')
@section('library-title', $kind->value === 'article' ? 'Artículo' : 'Recurso jurídico')
@section('library-content')
<article class="content-reading">
    <div class="content-heading"><a class="content-link" href="{{ route($kind->permission()) }}">Volver al listado</a><x-content.save :type="$kind->value" :id="$item['id']" :saved="$item['saved']" :can-save="$item['can_save'] || (!auth()->check() && $item['status'] === 'published')" /></div>
    <h2>{{ $item['title'] }}</h2>
    <p class="content-muted">{{ $item['author']['display_name'] }} · {{ $item['published_at'] ?? 'Sin publicar' }}@if ($item['status'] !== 'published') · {{ __('content.statuses.'.$item['status'], [], 'es') }}@endif</p>
    @if ($item['author']['username'])<a class="content-link" href="{{ route('profiles.show', $item['author']['id']) }}">Ver perfil público</a>@endif
    <div class="content-chips">@if ($item['category'])<a href="{{ route($kind->permission(), ['category_id' => $item['category']['id']]) }}">{{ $item['category']['name'] }}</a>@endif @foreach ($item['specialties'] as $specialty)<a href="{{ route($kind->permission(), ['specialty_id' => $specialty['id']]) }}">{{ $specialty['name'] }}</a>@endforeach @foreach ($item['tags'] as $tag)<a href="{{ route($kind->permission(), ['tag_id' => $tag['id']]) }}">#{{ $tag['name'] }}</a>@endforeach</div>
    @if ($kind->value === 'article')
        @if ($item['summary'])<p class="content-summary">{{ $item['summary'] }}</p>@endif
        <div class="resource-detail-content">{{ $item['body'] }}</div>
    @else
        <p class="content-muted">{{ $item['source_label'] }} · {{ __('content.resource_types.'.$item['resource_type'], [], 'es') }}</p>
        <div class="resource-detail-content">{{ $item['description'] }}</div>
        @if ($item['external_url'])<p class="content-external"><a class="content-link" href="{{ $item['external_url'] }}" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer">Consultar enlace externo <x-lucide-external-link aria-hidden="true" /></a><span>Este enlace no certifica la vigencia ni el carácter oficial del contenido.</span></p>@endif
    @endif
    @foreach ($item['attachments'] as $file)<div class="content-document"><x-lucide-file-text aria-hidden="true" /><div><strong>{{ $file['name'] }}</strong><span>{{ strtoupper($file['extension']) }} · {{ max(1, (int) ceil($file['size_bytes'] / 1024)) }} KiB</span><a class="content-link" href="{{ route($kind->permission().'.media', ['entry' => $item['id'], 'attachment' => $file['id']]) }}" data-turbo="false">Descargar archivo</a></div></div>@endforeach
    @if ($item['can_edit'] || $item['can_audit'] || $item['transitions'])
        <details class="content-management"><summary>Administrar contenido</summary><div class="content-actions">
            @if ($item['can_edit'])<a class="content-secondary" href="{{ route($kind->permission().'.edit', $item['id']) }}">Editar contenido</a>@endif
            @if ($item['can_audit'])<a class="content-secondary" href="{{ route($kind->permission().'.history', $item['id']) }}">Historial de revisiones</a>@endif
            @foreach ($item['transitions'] as $target)<form method="POST" action="{{ route($kind->permission().'.transition', $item['id']) }}">@csrf<input type="hidden" name="version" value="{{ $item['version'] }}"><input type="hidden" name="status" value="{{ $target }}"><button class="content-secondary">{{ __('content.transitions.'.$target, [], 'es') }}</button></form>@endforeach
        </div><p class="content-muted">Versión {{ $item['version'] }}. Los cambios y retiradas conservan las revisiones y sus archivos históricos.</p></details>
    @endif
</article>
@endsection
