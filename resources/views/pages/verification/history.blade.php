@extends('layouts.verification')
@section('verification-title', 'Historial del expediente '.$dossierId)
@section('verification-content')
<section class="profile-panel space-y-4"><h2 class="font-semibold text-lg">Registro de acciones y observaciones comunicables</h2>
    @php($actions = ['created' => 'Borrador creado', 'evidence_added' => 'Evidencia asociada', 'evidence_removed' => 'Evidencia retirada del borrador', 'submitted' => 'Solicitud presentada', 'review_opened' => 'Revisión consultada', 'evidence_read' => 'Evidencia descargada', 'approved' => 'Solicitud aprobada', 'rejected' => 'Solicitud rechazada', 'revoked' => 'Verificación revocada', 'invalidated' => 'Invalidada por cambio material del perfil'])
    <ol class="space-y-4">@forelse ($events as $event)<li class="border-b border-gray-200 dark:border-border-dark pb-4 space-y-2"><h3 class="font-semibold">{{ $actions[$event['action']] ?? 'Acción registrada' }}</h3><p class="text-xs break-words">{{ $event['created_at'] }} UTC · {{ $event['actor'] }}</p><x-verification.status :status="$event['to_status']" />@if ($event['reason'])<p class="whitespace-pre-wrap break-words text-sm">{{ $event['reason'] }}</p>@endif</li>@empty<li>No hay acciones disponibles.</li>@endforelse</ol>
</section>
@endsection
