@extends('layouts.verification')
@section('verification-title', 'Mi verificación profesional')
@section('verification-content')
<div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_260px]">
<section class="profile-panel !rounded-[10px] border-0! shadow-xs space-y-4">
    <div class="flex items-center gap-2"><x-lucide-briefcase-business class="size-5 text-dark-accent dark:text-accent-text-dark" /><h2 class="text-lg font-bold">Estado de tu presentación profesional</h2></div>
    @if ($professional)
        <p class="break-words">{{ $professional['headline'] ?: 'Sin título profesional indicado' }}</p>
        <x-profiles.verification :status="$professional['status']" />
        <p class="text-sm">Para presentar un expediente necesitas un título profesional, cédula declarada, al menos una especialidad activa y evidencia documental de la cédula. Puedes preparar un borrador antes de completar los requisitos.</p>
        @if (! $professional['has_license'] || ! $professional['has_specialties'] || ! $professional['headline'])<p class="text-sm">Todavía faltan datos del perfil para presentar la solicitud.</p>@endif
        <a class="underline inline-block" href="{{ route('profile.professional.edit') }}">Revisar mis datos profesionales</a>
        @if ($can_create)<form method="POST" action="{{ route('verification.store') }}">@csrf<p class="text-sm mb-3">Se abrirá un expediente privado para documentar tu solicitud. La evidencia presentada se conservará para respaldar las decisiones, incluso si la solicitud se rechaza o revoca.</p><button class="profile-button">Iniciar solicitud</button></form>@else<p class="text-sm">Consulta el expediente activo. No puedes abrir otro mientras exista un borrador, revisión pendiente o aprobación vigente.</p>@endif
    @else
        <p>No tienes una presentación profesional. Una cuenta convencional puede crearla sin adquirir una acreditación ni permisos de revisión.</p>
        <a class="profile-button" href="{{ route('profile.professional.edit') }}">Completar presentación profesional</a>
    @endif
</section>
<aside class="profile-panel !rounded-[10px] border-0! shadow-xs space-y-4"><div class="flex items-center gap-2"><x-lucide-files class="size-5 text-dark-accent dark:text-accent-text-dark" /><h2 class="text-lg font-bold">Mis solicitudes</h2></div>
    @forelse ($requests ?? [] as $item)<article class="border-b border-gray-200 dark:border-border-dark py-3 space-y-2"><h3 class="font-semibold"><a class="underline" href="{{ route('verification.show', $item['id']) }}">Expediente {{ $item['id'] }}</a></h3><x-verification.status :status="$item['status']" /><p class="text-xs break-words">Creado: {{ $item['created_at'] }} UTC</p></article>@empty<p>Todavía no has iniciado solicitudes.</p>@endforelse
    @if ($requests){{ $requests->links() }}@endif
</aside>
</div>
@endsection
