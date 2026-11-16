@extends('layouts.operations')
@section('operations-title', 'Resumen operativo')
@section('operations-content')
<section class="profile-panel space-y-4"><h2 class="text-lg font-semibold">Herramientas autorizadas</h2>
    @if ($capabilities['accounts'] || $capabilities['permissions'])<p><a class="underline" href="{{ route('operations.users') }}">Usuarios y permisos</a></p>@endif
    @if ($capabilities['accounts'])<p>Consulta de cuentas, suspensión, desactivación y reactivación.</p>@endif
    @foreach ($capabilities['permissions'] as $group)<p>Administración de permisos: {{ ['editorial' => 'Artículos y recursos jurídicos', 'verification' => 'Revisión profesional', 'moderation' => 'Moderación de Comunidad'][$group] }}.</p>@endforeach
    @if ($capabilities['posts'] || $capabilities['comments'])<p><a class="underline" href="{{ route('operations.moderation') }}">Moderación de Comunidad</a></p>@endif
    @if ($capabilities['audit'])<p><a class="underline" href="{{ route('operations.audit') }}">Historial de operaciones</a></p>@endif
    @if ($capabilities['articles'])<p><a class="underline" href="{{ route('articles.workspace') }}">Gestión de artículos</a></p>@endif
    @if ($capabilities['resources'])<p><a class="underline" href="{{ route('resources.workspace') }}">Gestión de recursos jurídicos</a></p>@endif
    <p class="text-sm">La revisión profesional conserva su entrada independiente para revisores autorizados. Administrar permisos de revisión no permite consultar evidencias ni resolver solicitudes.</p>
</section>
@endsection
