<?php

namespace App\Services\Profiles;

use App\Models\User;
use App\Services\Verification\VerificationWorkflow;

class ProfessionalHub
{
    public function __construct(private VerificationWorkflow $verification) {}

    public function project(User $actor): array
    {
        // A profile query string must never page the current dossier out of this projection.
        $own = $this->verification->own($actor, page: 1);
        $professional = $own['professional'];
        if (! $professional) {
            return ['state' => 'absent', 'label' => 'Todavía no tienes una presentación profesional',
                'missing' => [], 'next' => 'Crear presentación profesional', 'route' => 'profile.professional.edit', 'id' => null];
        }
        $requests = collect($own['requests']?->items() ?? []);
        $current = $requests->first(fn ($request) => in_array($request['status'], ['draft', 'pending', 'approved'], true));
        $latest = $requests->first();
        $missing = [];
        if (! $own['professional']['headline']) {
            $missing[] = 'Presentación breve';
        }
        if (! $own['professional']['has_license']) {
            $missing[] = 'Cédula declarada (privada)';
        }
        if (! $own['professional']['has_specialties']) {
            $missing[] = 'Al menos una especialidad activa';
        }
        $state = match (true) {
            $professional['status'] === 'verified' => 'verified',
            $professional['status'] === 'pending' => 'pending',
            ($current['status'] ?? null) === 'draft' => 'draft',
            $current !== null || ! $own['can_create'] => 'historical',
            in_array($latest['status'] ?? '', ['rejected', 'revoked', 'invalidated'], true) => $latest['status'],
            in_array($professional['status'], ['rejected', 'revoked'], true) => $professional['status'],
            $latest !== null => 'historical',
            $missing !== [] => 'incomplete',
            default => 'ready',
        };
        $labels = ['verified' => 'Verificación aprobada y vigente', 'pending' => 'Solicitud presentada: pendiente de revisión',
            'draft' => 'Solicitud en borrador', 'historical' => 'Consulta la situación actual de tu expediente',
            'rejected' => 'Solicitud rechazada', 'revoked' => 'Verificación revocada',
            'invalidated' => 'Solicitud invalidada por cambios materiales', 'incomplete' => 'Completa los datos para presentar una solicitud',
            'ready' => 'Datos del perfil listos; todavía no has iniciado una solicitud'];
        $edit = $missing !== [] && ! in_array($state, ['verified', 'pending'], true);

        return ['state' => $state, 'label' => $labels[$state], 'missing' => $missing,
            'next' => $edit ? 'Completar datos profesionales' : match ($state) {
                'draft' => 'Continuar mi borrador', 'pending' => 'Consultar mi solicitud',
                'verified' => 'Consultar mi verificación vigente', 'ready' => 'Preparar mi solicitud',
                default => 'Revisar mi verificación y próximos pasos',
            },
            'route' => $edit ? 'profile.professional.edit' : (($current && $state !== 'historical') ? 'verification.show' : 'verification'),
            'id' => $edit || $state === 'historical' ? null : ($current['id'] ?? null)];
    }
}
