@props(['status'])
@php($labels = ['draft' => 'Borrador', 'pending' => 'En revisión', 'approved' => 'Aprobada', 'rejected' => 'Rechazada', 'revoked' => 'Revocada', 'invalidated' => 'Invalidada por cambios del perfil'])
<span class="inline-flex rounded-full px-3 py-1 text-sm border border-current" data-verification-status="{{ $status }}">{{ $labels[$status] ?? 'Estado no disponible' }}</span>
