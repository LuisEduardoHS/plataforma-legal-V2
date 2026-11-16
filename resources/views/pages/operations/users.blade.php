@extends('layouts.operations')
@section('operations-title', 'Usuarios y permisos')
@section('operations-content')
<form method="GET" action="{{ route('operations.users') }}" class="profile-panel space-y-4">
    <div class="profile-field"><label for="account-query">Nombre o correo</label><input id="account-query" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></div>
    <div class="profile-field"><label for="account-status">Estado</label><select id="account-status" name="status"><option value="">Todos</option>@foreach (['active' => 'Activa', 'suspended' => 'Suspendida', 'disabled' => 'Desactivada', 'pending_deletion' => 'Pendiente de eliminación'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
    <button class="profile-button">Buscar</button>
</form>
<section class="profile-panel space-y-4" aria-label="Resultados">
    @forelse ($users as $account)<article class="border-b border-gray-200 dark:border-border-dark pb-4 break-words"><h2 class="font-semibold"><a class="underline" href="{{ route('operations.user', $account['id']) }}">{{ $account['name'] }}</a></h2><p>{{ $account['email'] }}</p><p>Cuenta #{{ $account['id'] }} · {{ $account['status'] }}</p></article>@empty<p>No hay cuentas que coincidan con los filtros.</p>@endforelse
    {{ $users->links() }}
</section>
@endsection
