@extends('layouts.operations')
@section('operations-title', 'Administrar cuenta')
@section('operations-content')
<section class="profile-panel space-y-3 break-words"><h2 class="text-lg font-semibold">{{ $account['name'] }}</h2><p>{{ $account['email'] }}</p><p>Cuenta #{{ $account['id'] }} · {{ $account['status'] }}</p><a class="underline" href="{{ route('operations.users') }}">Volver a usuarios</a></section>
@if ($can_change_status)
<form method="POST" action="{{ route('operations.state', $account['id']) }}" class="profile-panel space-y-4">@csrf
    <h2 class="text-lg font-semibold">Estado de cuenta</h2><input type="hidden" name="version" value="{{ $account['version'] }}">
    <div class="profile-field">
        <label for="next-status">Estado solicitado</label>
        <select id="next-status" name="status" required>
            @foreach (['active' => 'Activa', 'suspended' => 'Suspendida', 'disabled' => 'Desactivada'] as $value => $label)
                @if (\App\Enums\UserStatus::from($account['status'])->canTransitionTo(\App\Enums\UserStatus::from($value)))
                    <option value="{{ $value }}" @selected($account['status'] === $value)>{{ $label }}</option>
                @endif
            @endforeach
        </select>
    </div>
    <div class="profile-field"><label for="state-reason">Motivo del cambio</label><textarea id="state-reason" name="reason" required minlength="10" maxlength="2000" aria-describedby="state-help">{{ old('reason') }}</textarea><p id="state-help" class="text-sm">De 10 a 2,000 caracteres. Evita incluir datos personales innecesarios.</p></div>
    <button class="profile-button">Guardar estado</button>
</form>
@else<p class="profile-panel">El estado de esta cuenta no se puede modificar desde tu acceso actual.</p>@endif
@if ($permission_options)
<section class="profile-panel space-y-4"><h2 class="text-lg font-semibold">Permisos administrables</h2><p class="text-sm">Solo se modifican concesiones directas. Los permisos heredados requieren mantenimiento confiable del rol. No se sustituyen roles ni otras concesiones.</p>
    <ul class="space-y-2">@foreach ($permission_options as $option)<li class="break-all">{{ $option['name'] }} · {{ $option['inherited'] ? 'Heredado de rol' : ($option['direct'] ? 'Concesión directa' : 'Sin concesión') }}</li>@endforeach</ul>
    @if ($can_change_permissions)<form method="POST" action="{{ route('operations.permission', $account['id']) }}" class="space-y-4">@csrf<input type="hidden" name="version" value="{{ $account['version'] }}">
        <div class="profile-field"><label for="permission-name">Permiso</label><select id="permission-name" name="permission" required>@foreach ($permission_options as $option)<option value="{{ $option['name'] }}">{{ $option['name'] }}</option>@endforeach</select></div>
        <div class="profile-field"><label for="permission-action">Acción</label><select id="permission-action" name="action" required><option value="grant">Conceder</option><option value="revoke">Revocar concesión directa</option></select></div>
        <div class="profile-field"><label for="permission-reason">Motivo de la concesión o revocación</label><textarea id="permission-reason" name="reason" required minlength="10" maxlength="2000">{{ old('reason') }}</textarea></div>
        <button class="profile-button">Guardar permiso</button>
    </form>@else<p>No se permite modificar tus propias concesiones.</p>@endif
</section>
@endif
@endsection
