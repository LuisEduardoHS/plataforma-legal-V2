@props(['categories'])
@php
    $activeCategoryId = request('category_id');
    $featuredCategories = $categories->take(5);
    $extraCategories = $categories->slice(5);
@endphp
<aside class="community-sidebar" aria-label="Explorar la comunidad">
    <form method="GET" action="{{ route('community') }}" class="community-search" role="search" aria-label="Buscar publicaciones y perfiles">
        @if (request('feed'))
            <input type="hidden" name="feed" value="{{ request('feed') }}">
        @endif
        <label for="community-search-input" class="community-sr-only">Buscar publicaciones y perfiles</label>
        <div class="community-search-box community-surface">
            <x-lucide-search aria-hidden="true" />
            <input id="community-search-input" type="search" name="q" value="{{ request('q') }}" placeholder="Buscar" autocomplete="off">
            @if (filled(request('q')))
                <a class="community-search-clear" href="{{ route('community', request('feed') ? ['feed' => request('feed')] : []) }}" aria-label="Limpiar búsqueda" title="Limpiar búsqueda"><x-lucide-x aria-hidden="true" /></a>
            @endif
        </div>
    </form>
    <section>
        <h2>Categorías</h2>
        <div class="community-sidebar-card community-surface">
            <div class="community-chips">
                <a class="community-chip {{ blank($activeCategoryId) ? 'is-active' : '' }}" href="{{ route('community') }}">Todas</a>
                @forelse ($featuredCategories as $category)
                    <a class="community-chip {{ (string) $activeCategoryId === (string) $category->id ? 'is-active' : '' }}" href="{{ route('community', ['category_id' => $category->id]) }}" @if (! $category->is_active) title="Categoría histórica" @endif>{{ $category->name }}</a>
                @empty
                    <p class="community-muted">Aún no hay categorías disponibles.</p>
                @endforelse
            </div>
            @if ($extraCategories->isNotEmpty())
                <details class="community-more-topics">
                    <summary>
                        <span class="community-more-closed">Ver {{ $extraCategories->count() }} más</span>
                        <span class="community-more-open">Ver menos</span>
                    </summary>
                    <div class="community-chips community-chips--extra">
                        @foreach ($extraCategories as $category)
                            <a class="community-chip {{ (string) $activeCategoryId === (string) $category->id ? 'is-active' : '' }}" href="{{ route('community', ['category_id' => $category->id]) }}" @if (! $category->is_active) title="Categoría histórica" @endif>{{ $category->name }}</a>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
    </section>

    <section>
        <h2>Mi comunidad</h2>
        <div class="community-sidebar-card community-surface">
            @auth
                <a class="community-topic community-topic--icon" href="{{ route('community.connections', auth()->id()) }}">
                    <x-lucide-users aria-hidden="true" />
                    <strong>Mis conexiones</strong>
                </a>
                <a class="community-topic community-topic--icon" href="{{ route('community', ['feed' => 'mine']) }}">
                    <x-lucide-file-text aria-hidden="true" />
                    <strong>Mis publicaciones</strong>
                </a>
            @else
                <a class="community-topic community-topic--icon" href="{{ route('login') }}">
                    <x-lucide-log-in aria-hidden="true" />
                    <strong>Iniciar sesión para participar</strong>
                </a>
            @endauth
        </div>
    </section>

    <section>
        <h2>Profesionales</h2>
        <div class="community-sidebar-card community-surface">
            <p class="community-muted community-sidebar-copy">Consulta especialidades y perfiles públicos.</p>
            <a class="community-see-all" href="{{ route('professionals') }}">Explorar directorio <x-lucide-arrow-right aria-hidden="true" /></a>
        </div>
    </section>
</aside>
