@extends('layouts.app')
@section('title', 'Profesionales | Plataforma Legal')
@section('page-assets')
    @vite(['resources/css/professionals.css'])
@endsection
@section('content')
<section class="professionals" data-professional-directory aria-labelledby="professionals-title">
    <div class="professionals-container">
        <header class="professionals-heading">
            <div class="professionals-intro"><div><span class="professionals-eyebrow">Directorio profesional</span><h1 id="professionals-title">Encuentra el apoyo legal que necesitas.</h1><p>Conoce sus áreas de atención y elige con quién dar tu siguiente paso.</p></div></div>
            @if (! $unavailable)
                <form method="GET" action="{{ route('professionals') }}" class="professionals-search-panel" x-data="{ filtersOpen: false }">
                    <h2>Busca según tu situación</h2>
                    @if ($errors->any())<p role="alert" class="professionals-error">Revisa los filtros de búsqueda.</p>@endif
                    <div class="flex items-center gap-2 rounded-[10px] bg-[var(--professionals-soft)] p-2">
                        <div class="flex min-w-0 flex-1 items-center gap-2 px-2"><x-lucide-search class="size-5 shrink-0 text-light-text dark:text-light-text-dark" aria-hidden="true" /><label class="sr-only" for="professional-search">Buscar por nombre público, especialidad o ubicación</label><input class="min-h-11 min-w-0 flex-1 border-0 bg-transparent text-[15px] text-text outline-none placeholder:text-light-text dark:text-text-dark dark:placeholder:text-light-text-dark" type="search" id="professional-search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Nombre público, especialidad o ciudad" maxlength="160"></div>
                        <button type="button" class="grid size-11 shrink-0 place-items-center rounded-[10px] border-0 bg-white text-dark-accent shadow-xs transition-colors hover:bg-accent-bg-hover active:bg-accent-bg dark:bg-accent-bg-dark dark:text-accent-text-dark dark:hover:bg-accent-bg-hover-dark dark:active:bg-accent-bg-hover-dark" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen.toString()" aria-controls="professional-directory-filters" title="Mostrar u ocultar filtros" aria-label="Mostrar u ocultar filtros">
                            <x-lucide-sliders-horizontal class="size-5" aria-hidden="true" />
                        </button>
                    </div>
                    <div id="professional-directory-filters"
                        x-show="filtersOpen"
                        x-cloak
                        x-transition:enter="transition ease-out duration-180"
                        x-transition:enter-start="opacity-0 -translate-y-2 scale-[0.99]"
                        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                        x-transition:leave="transition ease-in duration-120"
                        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-1 scale-[0.99]"
                        class="mt-3 origin-top rounded-[10px] border border-gray-100/50 bg-bg p-4 dark:border-border-dark dark:bg-bg-dark">
                        @php($selectedSpecialties = collect($filters['specialty_ids'] ?? (! empty($filters['specialty_id']) ? [$filters['specialty_id']] : []))->map(fn ($id) => (string) $id)->all())
                        <div class="flex flex-wrap items-center gap-2" x-data="{ open: false }" data-category-row>
                            <details class="relative" @toggle="open = $el.open">
                                <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-[10px] border border-control-border/40 bg-white px-3 py-2 text-sm font-semibold text-text transition-colors hover:bg-accent-bg-hover dark:border-border-dark dark:bg-surface-dark dark:text-text-dark dark:hover:bg-accent-bg-hover-dark">
                                    <span>Categorías</span><span class="grid size-6 place-items-center rounded-full ring-1 ring-inset ring-control-border/60 dark:ring-focus-dark"><x-lucide-plus class="size-4" aria-hidden="true" /></span>
                                </summary>
                                <div class="absolute left-0 z-30 mt-2 max-h-64 w-72 max-w-[calc(100vw-48px)] overflow-y-auto rounded-[10px] border border-gray-100/50 bg-white p-2 shadow-lg dark:border-border-dark dark:bg-surface-dark">
                                    @foreach ($specialties as $specialty)
                                        <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-[10px] px-3 py-2 text-sm text-text transition-colors hover:bg-accent-bg-hover dark:text-text-dark dark:hover:bg-accent-bg-hover-dark">
                                            <input type="checkbox" name="specialty_ids[]" value="{{ $specialty->id }}" @checked(in_array((string) $specialty->id, $selectedSpecialties, true)) class="size-4 rounded border-control-border text-dark-accent focus:ring-dark-accent dark:border-focus-dark dark:bg-bg-dark dark:focus:ring-light-accent">
                                            <span>{{ $specialty->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </details>
                            <div class="flex flex-wrap items-center gap-2" data-selected-categories>
                                @foreach ($specialties as $specialty)
                                    @if (in_array((string) $specialty->id, $selectedSpecialties, true))
                                        <span class="inline-flex min-h-11 items-center rounded-[10px] bg-[var(--professionals-tint)] px-3 py-2 text-xs font-semibold text-dark-accent transition-colors dark:text-accent-text-dark">{{ $specialty->name }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_44px_44px] sm:items-end">
                            <label class="relative grid min-w-0 gap-1 text-xs font-semibold text-light-text dark:text-light-text-dark">Estado
                                <select name="state_id" data-directory-state class="min-h-11 w-full appearance-none rounded-[10px] border border-control-border/40 bg-white px-3 pr-10 text-sm font-semibold text-text outline-none transition-colors hover:bg-accent-bg-hover focus:border-dark-accent dark:border-border-dark dark:bg-surface-dark dark:text-text-dark dark:hover:bg-accent-bg-hover-dark dark:focus:border-light-accent"><option value="">Todos los estados</option>@foreach ($states as $state)<option value="{{ $state->id }}" @selected((string) ($filters['state_id'] ?? '') === (string) $state->id)>{{ $state->name }}</option>@endforeach</select>
                                <x-lucide-chevron-down class="pointer-events-none absolute right-3 bottom-[11px] size-4 text-light-text dark:text-light-text-dark" aria-hidden="true" />
                            </label>
                            <label class="relative grid min-w-0 gap-1 text-xs font-semibold text-light-text dark:text-light-text-dark">Municipio
                                <select name="municipality_id" data-directory-municipality class="min-h-11 w-full appearance-none rounded-[10px] border border-control-border/40 bg-white px-3 pr-10 text-sm font-semibold text-text outline-none transition-colors hover:bg-accent-bg-hover focus:border-dark-accent dark:border-border-dark dark:bg-surface-dark dark:text-text-dark dark:hover:bg-accent-bg-hover-dark dark:focus:border-light-accent"><option value="">Todos los municipios</option>@foreach ($municipalities as $municipality)<option value="{{ $municipality->id }}" @selected((string) ($filters['municipality_id'] ?? '') === (string) $municipality->id)>{{ $municipality->name }}</option>@endforeach</select>
                                <x-lucide-chevron-down class="pointer-events-none absolute right-3 bottom-[11px] size-4 text-light-text dark:text-light-text-dark" aria-hidden="true" />
                            </label>
                            <a href="{{ route('professionals') }}" class="grid size-11 justify-self-start place-items-center rounded-[10px] text-light-text transition-colors hover:bg-accent-bg-hover active:bg-accent-bg sm:justify-self-end dark:text-light-text-dark dark:hover:bg-accent-bg-hover-dark dark:active:bg-accent-bg-dark" title="Limpiar filtros" aria-label="Limpiar filtros"><x-lucide-eraser class="size-5" aria-hidden="true" /></a>
                            <button type="submit" class="grid size-11 justify-self-start place-items-center rounded-[10px] bg-dark-accent text-white transition-colors hover:bg-accent active:bg-light-accent sm:justify-self-end" title="Aplicar filtros" aria-label="Aplicar filtros"><x-lucide-search class="size-5" aria-hidden="true" /></button>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-light-text dark:text-light-text-dark">Aplica un estado para cargar sus municipios. La búsqueda usa únicamente información pública.</p>
                </form>
                <p class="professionals-results" role="status">{{ $profiles->total() }} {{ $profiles->total() === 1 ? 'perfil público' : 'perfiles públicos' }} · Página {{ $profiles->currentPage() }} de {{ $profiles->lastPage() }}</p>
            @endif
        </header>
        @if ($unavailable)
            <div class="professionals-empty" role="alert"><x-lucide-cloud-off aria-hidden="true" /><h2>No pudimos cargar los perfiles</h2><p>Intenta de nuevo para explorar el directorio.</p><a class="professionals-primary" href="{{ route('professionals') }}">Volver a intentar</a></div>
        @elseif ($profiles->isEmpty())
            <div class="professionals-empty"><x-lucide-search-x aria-hidden="true" /><h2>No encontramos perfiles</h2><p>Prueba con otro nombre público, especialidad o ciudad.</p><a class="professionals-primary" href="{{ route('professionals') }}">Limpiar filtros</a></div>
        @else
            <section aria-labelledby="directory-results"><div class="professionals-section-heading"><h2 id="directory-results">Profesionales</h2></div><div class="professionals-grid">@foreach ($profiles as $profile)<x-professionals.card :profile="$profile" />@endforeach</div></section>
            <div class="professionals-pagination">{{ $profiles->links() }}</div>
        @endif
    </div>
</section>
@endsection
