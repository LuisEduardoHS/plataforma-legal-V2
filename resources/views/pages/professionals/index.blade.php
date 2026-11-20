@extends('layouts.app')
@section('title', 'Profesionales | Plataforma Legal')
@section('page-assets')
    @vite(['resources/css/professionals.css'])
@endsection
@section('content')
<section class="professionals" data-professional-directory aria-label="Directorio profesional">
    <div class="professionals-container">
        <header class="professionals-heading">
            @if (! $unavailable)
                <form method="GET" action="{{ route('professionals') }}" class="professionals-search-panel">
                    <h2>Busca según tu situación</h2>
                    @if ($errors->any())<p role="alert" class="professionals-error">Revisa los filtros de búsqueda.</p>@endif
                    <div class="professionals-search"><x-lucide-search aria-hidden="true" /><label class="professionals-sr" for="professional-search">Buscar por nombre público, especialidad o ubicación</label><input type="search" id="professional-search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Nombre público, especialidad o ciudad" maxlength="160"></div>
                    <div class="professionals-location-filters">
                        <label>Estado<select name="state_id" data-directory-state><option value="">Todos los estados</option>@foreach ($states as $state)<option value="{{ $state->id }}" @selected((string) ($filters['state_id'] ?? '') === (string) $state->id)>{{ $state->name }}</option>@endforeach</select></label>
                        <label>Municipio<select name="municipality_id" data-directory-municipality><option value="">Todos los municipios</option>@foreach ($municipalities as $municipality)<option value="{{ $municipality->id }}" @selected((string) ($filters['municipality_id'] ?? '') === (string) $municipality->id)>{{ $municipality->name }}</option>@endforeach</select></label>
                        <button class="professionals-primary">Buscar / aplicar ubicación</button><a class="professionals-secondary" href="{{ route('professionals') }}">Limpiar filtros</a>
                    </div>
                    <p class="professionals-results">Aplica un estado para cargar sus municipios. La búsqueda usa únicamente información pública.</p>
                    @if (! empty($filters['specialty_id']))<input type="hidden" name="specialty_id" value="{{ $filters['specialty_id'] }}" data-selected-specialty>@endif
                    <div class="professionals-filters" role="group" aria-label="Filtrar por especialidad">
                        <button name="specialty_id" value="" aria-pressed="{{ empty($filters['specialty_id']) ? 'true' : 'false' }}">Todas</button>
                        @foreach ($specialties as $specialty)<button name="specialty_id" value="{{ $specialty->id }}" aria-pressed="{{ (string) ($filters['specialty_id'] ?? '') === (string) $specialty->id ? 'true' : 'false' }}">{{ $specialty->name }}</button>@endforeach
                    </div>
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
