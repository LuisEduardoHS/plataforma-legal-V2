@extends('layouts.app')
@section('title', 'Comunidad | Plataforma Legal')
@section('page-assets') @vite(['resources/css/community.css']) @endsection
@section('content')
<section class="community" aria-labelledby="community-title" data-community>
    <div class="community-grid">
        <div class="community-main">
            <div class="community-heading"><h1 id="community-title">{{ $profileFeed ? 'Publicaciones del usuario' : 'Comunidad' }}</h1></div>
            <x-community.feedback />
            <nav class="community-tabs community-surface" aria-label="Filtrar publicaciones">
                @foreach (['recent' => 'Recientes', 'following' => 'Siguiendo', 'mine' => 'Mis publicaciones'] as $key => $label)<a href="{{ route('community', ['feed' => $key]) }}" @if (! $profileFeed && $feed === $key) aria-current="page" @endif>{{ $label }}</a>@endforeach
            </nav>
            @can('posts.moderate')<a class="community-text-link" href="{{ route('community', ['feed' => 'moderation']) }}">Revisión de publicaciones</a>@endcan
            @if (! $profileFeed)
                <form method="GET" action="{{ route('community') }}" class="community-filter-form community-surface">
                    <input type="hidden" name="feed" value="{{ $feed }}">
                    @foreach (['category_id' => ['Categoría', $categories], 'specialty_id' => ['Especialidad', $specialties], 'tag_id' => ['Etiqueta', $tags]] as $name => [$label, $options])<label for="filter-{{ $name }}">{{ $label }}<select name="{{ $name }}" id="filter-{{ $name }}"><option value="">Todas</option>@foreach ($options as $option)<option value="{{ $option->id }}" @selected((string) ($filters[$name] ?? '') === (string) $option->id)>{{ $option->name }}{{ $option->is_active ? '' : ' (histórica)' }}</option>@endforeach</select></label>@endforeach
                    <button class="community-secondary">Aplicar filtros</button><a class="community-text-link" href="{{ route('community', ['feed' => $feed]) }}">Limpiar filtros</a>
                </form>
            @endif
            <x-community.composer />
            @if ($feed === 'mine')<p class="community-muted">Incluye tus borradores, publicaciones privadas y contenido oculto. El feed público solo muestra contenido publicado y público.</p>@elseif ($feed === 'moderation')<p class="community-muted" role="status">Revisión autorizada: incluye borradores, contenido privado y oculto. Editar contenido oculto no lo vuelve a publicar.</p>@endif
            <section class="community-feed" id="community-feed" aria-label="Publicaciones">
                @forelse ($posts as $post)<x-community.post-card :post="$post" />@empty<div class="community-state community-surface"><x-lucide-messages-square /><h2>No hay publicaciones en esta selección</h2><a class="community-secondary" href="{{ route('community') }}">Ver publicaciones recientes</a></div>@endforelse
            </section>
            <x-community.pagination :page="$page" />
        </div>
        <x-community.sidebar :categories="$categories" />
    </div>
    @can('posts.create')<x-community.create-modal :categories="$categories" :specialties="$specialties" :tags="$tags" />@endcan
</section>
@endsection
