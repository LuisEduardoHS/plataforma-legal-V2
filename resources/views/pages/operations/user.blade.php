@extends('layouts.operations')
@section('operations-title', 'Administrar cuenta')
@php
    // Existing route-specific fields identify the submitted form without changing the request contract.
    $stateSubmitted = session()->hasOldInput('status') && !session()->hasOldInput('permission');
    $permissionSubmitted = session()->hasOldInput('permission') && !session()->hasOldInput('status');
    $submittedVersion = is_string(old('version')) ? old('version') : '';
@endphp
@section('operations-errors')
    <x-ui.error-summary :fields="$stateSubmitted && $can_change_status ? ['status' => 'next-status', 'reason' => 'state-reason'] : ($permissionSubmitted && $can_change_permissions ? ['permission' => 'permission-name', 'action' => 'permission-action', 'reason' => 'permission-reason'] : [])" />
@endsection
@section('operations-content')
<section class="profile-panel space-y-3 break-words"><h2 class="text-lg font-semibold">{{ $account['name'] }}</h2><p>{{ $account['email'] }}</p><p>Cuenta #{{ $account['id'] }} · {{ $account['status'] }}</p><a class="underline" href="{{ route('operations.users') }}">Volver a usuarios</a></section>
@if ($can_change_status)
<form method="POST" action="{{ route('operations.state', $account['id']) }}" class="profile-panel space-y-4">@csrf
    <h2 class="text-lg font-semibold">Estado de cuenta</h2><input type="hidden" name="version" value="{{ $stateSubmitted ? $submittedVersion : $account['version'] }}">
    <div class="profile-field">
        <label for="next-status">Estado solicitado</label>
        <select id="next-status" name="status" required aria-invalid="{{ $stateSubmitted && $errors->has('status') ? 'true' : 'false' }}" @if ($stateSubmitted && $errors->has('status')) aria-describedby="next-status-error" @endif>
            @foreach (['active' => 'Activa', 'suspended' => 'Suspendida', 'disabled' => 'Desactivada'] as $value => $label)
                @if (\App\Enums\UserStatus::from($account['status'])->canTransitionTo(\App\Enums\UserStatus::from($value)))
                    <option value="{{ $value }}" @selected(($stateSubmitted ? old('status') : $account['status']) === $value)>{{ $label }}</option>
                @endif
            @endforeach
        </select><x-ui.field-error field="status" id="next-status-error" :active="$stateSubmitted" />
    </div>
    <x-ui.input name="reason" id="state-reason" label="Motivo del cambio" multiline :error-active="$stateSubmitted" :restore-old="$stateSubmitted" required minlength="10" maxlength="2000" rows="4" aria-describedby="state-help" /><p id="state-help" class="text-sm">De 10 a 2,000 caracteres. Evita incluir datos personales innecesarios.</p>
    <button class="profile-button">Guardar estado</button>
</form>
@else<p class="profile-panel">El estado de esta cuenta no se puede modificar desde tu acceso actual.</p>@endif
@if ($permission_options)
<section class="profile-panel space-y-4"><h2 class="text-lg font-semibold">Permisos administrables</h2><p class="text-sm">Solo se modifican concesiones directas. Los permisos heredados requieren mantenimiento confiable del rol. No se sustituyen roles ni otras concesiones.</p>
    <ul class="space-y-2">@foreach ($permission_options as $option)<li class="break-all">{{ $option['name'] }} · {{ $option['inherited'] ? 'Heredado de rol' : ($option['direct'] ? 'Concesión directa' : 'Sin concesión') }}</li>@endforeach</ul>
    @if ($can_change_permissions)<form method="POST" action="{{ route('operations.permission', $account['id']) }}" class="space-y-4">@csrf<input type="hidden" name="version" value="{{ $permissionSubmitted ? $submittedVersion : $account['version'] }}">
        <div class="profile-field"><label for="permission-name">Permiso</label><select id="permission-name" name="permission" required aria-invalid="{{ $permissionSubmitted && $errors->has('permission') ? 'true' : 'false' }}" @if ($permissionSubmitted && $errors->has('permission')) aria-describedby="permission-name-error" @endif>@foreach ($permission_options as $option)<option value="{{ $option['name'] }}" @selected($permissionSubmitted && old('permission') === $option['name'])>{{ $option['name'] }}</option>@endforeach</select><x-ui.field-error field="permission" id="permission-name-error" :active="$permissionSubmitted" /></div>
        <div class="profile-field"><label for="permission-action">Acción</label><select id="permission-action" name="action" required aria-invalid="{{ $permissionSubmitted && $errors->has('action') ? 'true' : 'false' }}" @if ($permissionSubmitted && $errors->has('action')) aria-describedby="permission-action-error" @endif><option value="grant" @selected(!$permissionSubmitted || old('action') === 'grant')>Conceder</option><option value="revoke" @selected($permissionSubmitted && old('action') === 'revoke')>Revocar concesión directa</option></select><x-ui.field-error field="action" id="permission-action-error" :active="$permissionSubmitted" /></div>
        <x-ui.input name="reason" id="permission-reason" label="Motivo de la concesión o revocación" multiline :error-active="$permissionSubmitted" :restore-old="$permissionSubmitted" required minlength="10" maxlength="2000" rows="4" />
        <button class="profile-button">Guardar permiso</button>
    </form>@else<p>No se permite modificar tus propias concesiones.</p>@endif
</section>
@endif
@endsection
