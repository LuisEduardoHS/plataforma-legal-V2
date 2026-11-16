@extends('layouts.verification')
@section('verification-title', 'Revisión profesional')
@section('verification-content')
<section class="profile-panel space-y-4">
    <p class="text-sm">Acceso exclusivo para revisores con permisos explícitos. Tus solicitudes propias no aparecen en esta bandeja.</p>
    <form method="GET" action="{{ route('verification.review.index') }}" class="flex flex-wrap items-end gap-4"><div class="profile-field grow"><label for="status">Estado del expediente</label><select name="status" id="status">@foreach (['pending' => 'En revisión', 'approved' => 'Aprobadas', 'rejected' => 'Rechazadas', 'revoked' => 'Revocadas', 'invalidated' => 'Invalidadas'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select></div><button class="profile-button-secondary mb-4">Consultar</button></form>
    @forelse ($requests as $item)<article class="border-b border-gray-200 dark:border-border-dark py-4 space-y-3"><h2 class="font-semibold"><a class="underline" href="{{ route('verification.review.show', $item['id']) }}">Revisar expediente {{ $item['id'] }}</a></h2><x-verification.status :status="$item['status']" /><p class="text-xs break-words">Presentado: {{ $item['submitted_at'] }} UTC</p></article>@empty<p>No hay solicitudes disponibles en este estado.</p>@endforelse
    {{ $requests->links() }}
</section>
@endsection
