@extends('pages.articles.layout')
@section('library-title', 'Mi espacio editorial')
@section('library-content')
<div class="content-heading"><h2>{{ $kind->value === 'article' ? 'Artículos' : 'Recursos jurídicos' }}</h2>@if ($canCreate)<a class="content-primary" href="{{ route($kind->permission().'.create') }}">Crear borrador</a>@endif</div>
<form class="content-filters" method="GET" action="{{ route($kind->permission().'.workspace') }}">
    <label>Estado<select name="status"><option value="">Todos los disponibles</option>@foreach (App\Enums\EditorialStatus::cases() as $status)@if ($status->value !== 'deleted')<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ __('content.statuses.'.$status->value, [], 'es') }}</option>@endif @endforeach</select></label>
    @if ($canManage)<label class="content-checkbox"><input type="checkbox" name="all" value="1" @checked($filters['all'] ?? false)> Revisar todos los autores</label>@endif
    <button class="content-secondary">Filtrar</button>
</form>
<div class="content-workspace">
    @forelse ($page as $item)<article class="content-reading"><h2><a href="{{ route($kind->permission().'.show', $item['id']) }}">{{ $item['title'] }}</a></h2><p class="content-muted">{{ __('content.statuses.'.$item['status'], [], 'es') }} · Versión {{ $item['version'] }} · {{ $item['author']['display_name'] }}</p><div class="content-actions">@if ($item['can_edit'])<a class="content-link" href="{{ route($kind->permission().'.edit', $item['id']) }}">Editar</a>@endif @if ($item['can_audit'])<a class="content-link" href="{{ route($kind->permission().'.history', $item['id']) }}">Historial</a>@endif</div></article>
    @empty<div class="content-empty"><h2>Sin contenido editorial disponible</h2><p>Crear, editar, publicar y moderar requiere permisos explícitos. El rol de administrador o profesional no los concede automáticamente.</p></div>@endforelse
</div>
<x-content.pagination :page="$page" />
@endsection
