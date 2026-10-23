
@extends('layouts.app')
@section('title', 'Profesionales | Plataforma Legal')
@section('page-assets')
    @vite(['resources/css/professionals.css'])
@endsection
@section('content')
    @php($directory = $directory ?? require resource_path('views/pages/professionals/demo.php'))
    <section class="professionals" data-professionals aria-labelledby="professionals-title">
        <script type="application/json" data-directory-data>{!! Illuminate\Support\Js::encode($directory) !!}</script>
        <div class="professionals-container">
            <header class="professionals-heading">
                <div class="professionals-intro"><div><span class="professionals-eyebrow">Directorio profesional</span><h1 id="professionals-title">Encuentra el apoyo legal que necesitas.</h1><p>Conoce sus áreas de atención y elige con quién dar tu siguiente paso.</p></div><aside class="professionals-guide"><x-lucide-compass aria-hidden="true" /><div><h2>¿No sabes por dónde empezar?</h2><p>Ordena tu situación y tus preguntas antes de buscar apoyo.</p><a href="{{ route('lexa') }}">Orientarme con LEXA <x-lucide-arrow-up-right aria-hidden="true" /></a></div></aside></div>
                <div class="professionals-search-panel"><h2>Busca según tu situación</h2>
                <div class="professionals-search">
                    <x-lucide-search aria-hidden="true" />
                    <label class="professionals-sr" for="professional-search">Buscar por nombre, especialidad o ubicación</label>
                    <input type="search" id="professional-search" data-professional-search placeholder="Nombre, especialidad o ciudad" autocomplete="off" maxlength="160" aria-controls="professional-sections">
                    <button type="button" class="professionals-icon" data-clear-search aria-label="Limpiar búsqueda" hidden><x-lucide-x aria-hidden="true" /></button>
                </div>
                <div class="professionals-filters" role="group" aria-label="Filtrar por especialidad">
                    <button type="button" data-specialty="all" aria-pressed="true">Todas</button>
                    @foreach ($directory['specialties'] as $specialty)
                        <button type="button" data-specialty="{{ $specialty['id'] }}" aria-pressed="false">{{ $specialty['label'] }}</button>
                    @endforeach
                </div>
                </div>
                <div class="professionals-feedback" data-dismiss-feedback hidden>
                    <p data-dismiss-message></p><button type="button" data-undo-dismiss>Deshacer</button><button type="button" data-restore-dismissed>Restaurar perfiles</button>
                </div>
                <p class="professionals-results" data-result-summary hidden></p>
            </header>
            @if (($directory['state'] ?? 'ready') === 'loading')
                <div class="professionals-grid" aria-busy="true" aria-label="Cargando profesionales">
                    @for ($i = 0; $i < 6; $i++)<div class="professionals-skeleton" aria-hidden="true"><span></span><span></span><span></span></div>@endfor
                </div>
            @elseif (($directory['state'] ?? 'ready') === 'error')
                <div class="professionals-empty" role="alert"><x-lucide-cloud-off aria-hidden="true" /><h2>No pudimos cargar los perfiles</h2><p>Intenta de nuevo para explorar el directorio.</p><button type="button" class="professionals-primary" data-retry>Volver a intentar</button></div>
            @else
                <div class="professionals-sections" id="professional-sections">
                    @foreach ($directory['sections'] as $section)
                        <section data-professional-section="{{ $section['id'] }}" aria-labelledby="professionals-{{ $section['id'] }}">
                            <div class="professionals-section-heading">
                                <h2 id="professionals-{{ $section['id'] }}" tabindex="-1">{{ $section['title'] }}</h2>
                                <button type="button" data-expand-section aria-expanded="false" aria-controls="professionals-grid-{{ $section['id'] }}"><span>Ver todo</span><x-lucide-arrow-right aria-hidden="true" /><span class="professionals-sr">: {{ $section['title'] }}</span></button>
                            </div>
                            <div class="professionals-grid {{ $section['id'] === 'popular' ? 'professionals-grid-featured' : '' }}" id="professionals-grid-{{ $section['id'] }}">
                                @foreach ($section['profile_ids'] as $id)
                                    @if (isset($directory['profiles'][$id]))
                                        <x-professionals.card :profile="$directory['profiles'][$id]" :featured="$section['id'] === 'popular'" :hidden="$loop->index >= $section['limit']" />
                                    @endif
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
                <div class="professionals-empty" data-directory-empty hidden>
                    <x-lucide-search-x aria-hidden="true" /><h2 data-empty-title>No encontramos perfiles</h2><p data-empty-message>Prueba con otro nombre, especialidad o ciudad.</p>
                    <div><button type="button" class="professionals-primary" data-reset-filters>Limpiar filtros</button><button type="button" class="professionals-secondary" data-restore-empty hidden>Restaurar perfiles ocultos</button></div>
                </div>
            @endif
            <noscript><p class="professionals-results">Activa JavaScript para buscar, explorar todos los perfiles y abrir sus detalles.</p></noscript>
        </div>
        <x-professionals.profile-dialog />
        <p class="professionals-sr" data-directory-status role="status" aria-live="polite" aria-atomic="true"></p>
    </section>
@endsection
