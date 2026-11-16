@extends('layouts.operations')
@section('operations-title', 'Historial de operaciones')
@section('operations-content')
<form method="GET" action="{{ route('operations.audit') }}" class="profile-panel space-y-3"><div class="profile-field"><label for="audit-target">Cuenta destinataria (ID)</label><input id="audit-target" name="target_id" type="number" min="1" value="{{ request('target_id') }}"></div><button class="profile-button">Filtrar</button></form>
<section class="profile-panel space-y-4" aria-label="Eventos">
@forelse ($events as $event)<article class="border-b border-gray-200 dark:border-border-dark pb-4 space-y-2 break-words"><h2 class="font-semibold">#{{ $event->id }} · {{ $event->action }}</h2><p>{{ $event->created_at->format('Y-m-d H:i:s') }} UTC · {{ $event->source }}</p><p>Actor: {{ $event->actor_id ?? 'Consola confiable' }} · Destinatario: {{ $event->target_user_id ?? '—' }}</p><p>Recurso: {{ $event->resource_type }} #{{ $event->resource_id }} @if ($event->permission_name) · {{ $event->permission_name }}@endif</p><p>Motivo: {{ $event->reason }}</p><p>Resultado: <code class="break-all">{{ json_encode($event->after_state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></p></article>@empty<p>No hay operaciones registradas para este filtro.</p>@endforelse
{{ $events->links() }}</section>
@endsection
