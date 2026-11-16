@props(['status'])
@php($labels = ['unverified' => 'Sin verificar', 'pending' => 'Verificación pendiente', 'verified' => 'Perfil verificado', 'rejected' => 'Verificación rechazada', 'revoked' => 'Verificación revocada'])
<span class="inline-flex items-center gap-1 text-sm" @if ($status === 'verified') title="Revisión documental interna vigente; no es una consulta oficial automática ni garantía de calidad." @endif>@if ($status === 'verified')<x-lucide-badge-check class="size-4" aria-hidden="true" />@endif{{ $labels[$status] ?? 'Sin verificar' }}</span>
