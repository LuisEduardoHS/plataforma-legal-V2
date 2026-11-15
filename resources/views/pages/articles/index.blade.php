@extends('pages.articles.layout')
@section('library-content')
<form method="GET" action="{{ route($kind->permission()) }}" class="content-filters">
    <label class="content-search">Buscar por inicio del título<input type="search" name="title" value="{{ $filters['title'] ?? '' }}" maxlength="100" placeholder="Buscar" autocomplete="off"></label>
    <label>Categoría<select name="category_id"><option value="">Todas</option>@foreach ($categories as $category)<option value="{{ $category['id'] }}" @selected(($filters['category_id'] ?? '') == $category['id'])>{{ $category['name'] }}{{ $category['is_active'] ? '' : ' (inactiva)' }}</option>@endforeach</select></label>
    <label>Especialidad<select name="specialty_id"><option value="">Todas</option>@foreach ($specialties as $specialty)<option value="{{ $specialty['id'] }}" @selected(($filters['specialty_id'] ?? '') == $specialty['id'])>{{ $specialty['name'] }}{{ $specialty['is_active'] ? '' : ' (inactiva)' }}</option>@endforeach</select></label>
    <label>Etiqueta<select name="tag_id"><option value="">Todas</option>@foreach ($tags as $tag)<option value="{{ $tag['id'] }}" @selected(($filters['tag_id'] ?? '') == $tag['id'])>{{ $tag['name'] }}{{ $tag['is_active'] ? '' : ' (inactiva)' }}</option>@endforeach</select></label>
    @if ($kind->value === 'resource')<label>Tipo de recurso<select name="resource_type"><option value="">Todos</option>@foreach (App\Enums\LegalResourceType::cases() as $type)<option value="{{ $type->value }}" @selected(($filters['resource_type'] ?? '') === $type->value)>{{ __('content.resource_types.'.$type->value, [], 'es') }}</option>@endforeach</select></label>@endif
    @if (isset($filters['author_id']))<input type="hidden" name="author_id" value="{{ $filters['author_id'] }}">@endif
    <button class="content-primary">Buscar</button><a class="content-secondary" href="{{ route($kind->permission()) }}">Limpiar filtros</a>
</form>
<div class="content-heading"><h2>{{ $kind->value === 'article' ? 'Artículos publicados' : 'Recursos jurídicos publicados' }}</h2>@if ($canCreate)<a class="content-secondary" href="{{ route($kind->permission().'.create') }}">Crear borrador</a>@endif</div>
@if (count($page))
    <div class="content-grid">@foreach ($page as $article)<x-articles.card :article="$article" />@endforeach</div>
    <x-content.pagination :page="$page" />
@else
    <div class="content-empty"><x-lucide-book-open aria-hidden="true" /><h2>No hay {{ $kind->value === 'article' ? 'artículos' : 'recursos' }} disponibles</h2><p>Cuando haya contenido publicado que coincida con estos filtros, lo encontrarás aquí.</p><a class="content-secondary" href="{{ route($kind->permission()) }}">Restablecer búsqueda</a></div>
@endif
@endsection
