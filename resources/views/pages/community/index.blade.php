@extends('layouts.app')
@section('title', 'Comunidad | Plataforma Legal')
@section('page-assets') @vite(['resources/css/community.css']) @endsection
@section('content')
<section class="community" aria-label="Comunidad" data-community>
    <div class="community-grid">
        <div class="community-main">
            <x-community.feedback />
            <nav class="community-tabs community-surface" aria-label="Filtrar publicaciones">
                @foreach (['recent' => 'Recientes', 'following' => 'Siguiendo', 'mine' => 'Mis publicaciones'] as $key => $label)<a href="{{ route('community', ['feed' => $key]) }}" @if (! $profileFeed && $feed === $key) aria-current="page" @endif>{{ $label }}</a>@endforeach
            </nav>
            @can('posts.moderate')<a class="community-text-link" href="{{ route('community', ['feed' => 'moderation']) }}">Revisión de publicaciones</a>@endcan
            @if (! $profileFeed)
                <form method="GET" action="{{ route('community') }}" class="!items-end community-filter-form community-surface">
                    <input type="hidden" name="feed" value="{{ $feed }}">
                    @foreach (['category_id' => ['Categoría', $categories], 'specialty_id' => ['Especialidad', $specialties], 'tag_id' => ['Etiqueta', $tags]] as $name => [$label, $options])
                        <label for="filter-{{ $name }}" class="!flex !min-w-0 !flex-1 !basis-40 !flex-col text-xs font-medium text-light-text dark:text-light-text-dark">
                            {{ $label }}
                            <span class="relative !mt-2 block w-full">
                                <select name="{{ $name }}" id="filter-{{ $name }}" class="!mt-0 !h-9 !w-full appearance-none rounded-lg border border-gray-200/70 bg-white px-3! pr-10! text-sm text-text dark:border-border-dark dark:bg-surface-dark dark:text-text-dark">
                                    <option value="">Todas</option>
                                    @foreach ($options as $option)
                                        <option value="{{ $option->id }}" @selected((string) ($filters[$name] ?? '') === (string) $option->id)>{{ $option->name }}{{ $option->is_active ? '' : ' (histórica)' }}</option>
                                    @endforeach
                                </select>
                                <x-lucide-chevron-down class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-light-text dark:text-light-text-dark" aria-hidden="true" />
                            </span>
                        </label>
                    @endforeach
                    <button class="!h-9 !min-h-9 community-secondary">Aplicar filtros</button>
                    <a class="inline-grid !size-9 shrink-0 place-items-center rounded-lg text-light-text transition-colors hover:bg-light-hover hover:text-accent dark:text-light-text-dark dark:hover:bg-accent-bg-dark dark:hover:text-accent-text-dark" href="{{ route('community', ['feed' => $feed]) }}" aria-label="Limpiar filtros" title="Limpiar filtros"><x-lucide-x aria-hidden="true" class="size-4" /></a>
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
