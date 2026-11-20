@extends('layouts.app')
@section('title', 'Guardados | Lefenda')
@section('page-assets') @vite(['resources/css/library-help.css', 'resources/css/articles.css']) @endsection
@section('content')
<section class="personal-page" aria-label="Tus guardados">
    <div class="personal-wrap">
        @if (session('status'))<p class="content-notice mb-3 rounded-[var(--radius-ui)] bg-white px-4 py-2.5 text-xs text-light-text dark:bg-surface-dark dark:text-light-text-dark" role="status">{{ session('status') }}</p>@endif
        <div class="personal-layout">
            <div class="min-w-0">
                <nav class="mb-3 flex rounded-[var(--radius-ui)] bg-white px-3 dark:bg-surface-dark" aria-label="Tipo de contenido guardado">
                    @foreach (['' => 'Todo', 'post' => 'Publicaciones', 'article' => 'Artículos', 'resource' => 'Recursos jurídicos'] as $key => $label)
                        @php($activeTab = ($type?->value ?? '') === $key)
                        <a href="{{ route('saved', $key ? ['type' => $key] : []) }}"
                           class="flex min-h-11 flex-1 items-center justify-center border-b-2 px-2 text-center text-sm transition-colors {{ $activeTab ? 'border-accent font-semibold text-accent dark:border-accent-text-dark dark:text-accent-text-dark' : 'border-transparent text-light-text hover:text-text dark:text-light-text-dark dark:hover:text-text-dark' }}"
                           @if ($activeTab) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>

                <div class="mb-3 flex items-center justify-between px-1">
                    <span class="text-xs text-light-text dark:text-light-text-dark">Más recientes primero · {{ count($page) }} en esta página</span>
                </div>

                <div class="grid gap-3" data-library-list>
                    @foreach ($page as $item)
                        @php($targetRoute = match ($item['type']) { 'post' => 'community.show', 'article' => 'articles.show', 'resource' => 'resources.show' })
                        @php($typeLabel = match ($item['type']) { 'post' => 'Publicación', 'article' => 'Artículo', 'resource' => 'Recurso jurídico' })
                        @php($savedAt = Illuminate\Support\Carbon::parse($item['saved_at']))
                        <article class="relative flex items-start gap-3.5 rounded-[var(--radius-ui)] bg-white p-5 transition-shadow hover:shadow-[0_4px_16px_color-mix(in_srgb,var(--color-text)_7%,transparent)] dark:bg-surface-dark" data-library-item>
                            <span class="mt-0.5 inline-flex shrink-0 items-center justify-center text-accent dark:text-accent-text-dark" aria-hidden="true">
                                @if ($item['type'] === 'post')
                                    <x-lucide-message-square-text class="!size-5" />
                                @elseif ($item['type'] === 'article')
                                    <x-lucide-book-open class="!size-5" />
                                @else
                                    <x-lucide-scale class="!size-5" />
                                @endif
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="mb-1.5 flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-full bg-accent/10 px-2.5 py-0.5 text-[11px] font-semibold text-accent dark:bg-selected-dark dark:text-accent-text-dark">{{ $typeLabel }}</span>
                                    <time class="text-xs text-light-text dark:text-light-text-dark" datetime="{{ $savedAt->toIso8601String() }}" title="{{ $item['saved_at'] }}">Guardado {{ $savedAt->locale('es')->diffForHumans() }}</time>
                                </div>
                                <h3 class="text-[15.5px] font-semibold leading-snug text-text dark:text-text-dark">
                                    <a href="{{ route($targetRoute, $item['target_id']) }}" class="after:absolute after:inset-0 after:z-0 after:rounded-[var(--radius-ui)] hover:text-accent dark:hover:text-accent-text-dark">{{ $item['title'] ?? $item['preview'] }}</a>
                                </h3>
                            </div>
                            <div class="relative z-10 shrink-0">
                                <x-content.save :type="$item['type']" :id="$item['target_id']" :saved="true" />
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="personal-empty" data-library-empty @if (count($page)) hidden @endif>
                    <x-lucide-bookmark aria-hidden="true" />
                    <h2>No hay guardados disponibles en esta página</h2>
                    <p>Guarda contenido desde Comunidad, Artículos o Recursos. El contenido que deja de ser accesible no aparece en tu biblioteca.</p>
                    <div class="personal-empty-actions">
                        <a class="inline-flex min-h-9 items-center justify-center rounded-[var(--radius-ui)] bg-accent px-4 text-xs font-semibold text-white transition-colors hover:bg-dark-accent" href="{{ route('community') }}">Explorar Comunidad</a>
                        <a class="inline-flex min-h-9 items-center justify-center rounded-[var(--radius-ui)] bg-light-hover px-4 text-xs font-semibold text-accent transition-colors hover:bg-accent/10 dark:bg-accent-bg-dark dark:text-accent-text-dark" href="{{ route('articles') }}">Descubrir recursos</a>
                    </div>
                </div>
                <x-content.pagination :page="$page" />
            </div>

            <aside class="personal-sidebar" aria-label="Tu biblioteca en detalle">
                <section>
                    <h2 class="mb-2 text-sm font-semibold text-text dark:text-text-dark">Vinculada a tu cuenta</h2>
                    <div class="rounded-[var(--radius-ui)] bg-white p-3.5 dark:bg-surface-dark">
                        <p class="mb-3 text-xs leading-relaxed text-light-text dark:text-light-text-dark">Tus guardados son privados y se conservan entre sesiones. Si cambia la visibilidad de un contenido, dejará de mostrarse.</p>
                        <a class="inline-flex min-h-9 w-full items-center justify-center gap-1.5 rounded-[var(--radius-ui)] bg-light-hover px-3 text-xs font-semibold text-accent transition-colors hover:bg-accent/10 dark:bg-accent-bg-dark dark:text-accent-text-dark dark:hover:bg-selected-dark" href="{{ route('help') }}">Cómo funciona <x-lucide-arrow-right class="!size-3.5" aria-hidden="true" /></a>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</section>
@endsection
