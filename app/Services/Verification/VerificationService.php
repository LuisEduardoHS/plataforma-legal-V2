<?php

namespace App\Services\Verification;

use App\Enums\ProfessionalVerificationStatus as ProfileStatus;
use App\Enums\VerificationAction as Action;
use App\Enums\VerificationEvidenceType as EvidenceType;
use App\Enums\VerificationRequestStatus as Status;
use App\Exceptions\VerificationException;
use App\Models\Attachment;
use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Models\VerificationEvent;
use App\Models\VerificationEvidence;
use App\Models\VerificationRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VerificationService
{
    public function __construct(private VerificationIntegrity $integrity) {}

    public function createDraft(User $actor, int $profileId): VerificationRequest
    {
        return $this->withProfile($actor, $profileId, function (User $actor, ProfessionalProfile $profile): VerificationRequest {
            $this->owner($actor, $profile);
            if ($profile->verificationRequests()->whereIn('status', [Status::Draft->value, Status::Pending->value, Status::Approved->value])->exists()
                || in_array($profile->verification_status, [ProfileStatus::Verified, ProfileStatus::Pending], true)) {
                $this->invalid('Ya existe un expediente activo o una verificación vigente.');
            }
            $request = new VerificationRequest;
            $request->forceFill(['professional_profile_id' => $profile->id, 'status' => Status::Draft])->save();
            $this->event($request, $actor, Action::Created);

            return $request->fresh();
        });
    }

    public function addEvidence(User $actor, int $requestId, int $attachmentId, EvidenceType $type): VerificationEvidence
    {
        return $this->withRequest($actor, $requestId, function (User $actor, ProfessionalProfile $profile, VerificationRequest $request) use ($attachmentId, $type): VerificationEvidence {
            $this->owner($actor, $profile);
            $this->status($request, Status::Draft);
            if ($request->evidences()->count() >= config('verification.max_evidences')
                || $request->evidences()->where('attachment_id', $attachmentId)->exists()) {
                $this->invalid('La evidencia está repetida o se alcanzó el límite de documentos.');
            }
            $attachment = Attachment::whereKey($attachmentId)->lockForUpdate()->first();
            if (! $attachment || (int) $attachment->user_id !== (int) $profile->user_id) {
                throw new AuthorizationException;
            }
            if ($attachment->community_published_at !== null
                || DB::table('post_attachments')->where('attachment_id', $attachment->id)->lockForUpdate()->first() !== null) {
                $this->invalid('Un archivo de Comunidad no puede reutilizarse como evidencia privada.');
            }
            if ($attachment->messaging_shared_at !== null
                || DB::table('message_attachments')->where('attachment_id', $attachment->id)->lockForUpdate()->first() !== null) {
                $this->invalid('Un archivo compartido por mensajería no puede reutilizarse como evidencia privada.');
            }
            $stream = $this->integrity->verifiedStream($attachment);
            fclose($stream);
            if ($request->evidences()->where('content_sha256', $attachment->sha256)->exists()) {
                $this->invalid('El contenido del documento ya está asociado a este expediente.');
            }
            $evidence = new VerificationEvidence;
            $evidence->forceFill([
                'verification_request_id' => $request->id, 'attachment_id' => $attachment->id,
                'type' => $type, 'content_sha256' => $attachment->sha256,
                'size_bytes' => $attachment->size_bytes, 'mime_type' => $attachment->mime_type,
                'attachment_fingerprint' => $this->integrity->attachmentFingerprint($attachment),
            ])->save();
            $this->event($request, $actor, Action::EvidenceAdded, attachmentId: $attachment->id);

            return $evidence;
        });
    }

    public function removeEvidence(User $actor, int $requestId, int $evidenceId): void
    {
        $this->withRequest($actor, $requestId, function (User $actor, ProfessionalProfile $profile, VerificationRequest $request) use ($evidenceId): void {
            $this->owner($actor, $profile);
            $this->status($request, Status::Draft);
            $evidence = $request->evidences()->whereKey($evidenceId)->lockForUpdate()->first();
            if (! $evidence) {
                throw new AuthorizationException;
            }
            $this->event($request, $actor, Action::EvidenceRemoved, attachmentId: $evidence->attachment_id);
            $evidence->delete();
        });
    }

    public function submit(User $actor, int $requestId): VerificationRequest
    {
        return $this->withRequest($actor, $requestId, function (User $actor, ProfessionalProfile $profile, VerificationRequest $request): VerificationRequest {
            $this->owner($actor, $profile);
            $this->status($request, Status::Draft);
            if (! $profile->license_number || ! $profile->headline || ! $profile->specialties()->active()->exists()
                || ! $request->evidences()->where('type', EvidenceType::ProfessionalLicense->value)->exists()) {
                $this->invalid('Completa el título, cédula declarada, especialidades activas y evidencia de la cédula.');
            }
            $this->checkEvidences($request, $profile, true);
            $request->forceFill([
                'submitted_at' => now(),
                'profile_fingerprint' => $this->integrity->profileFingerprint($profile),
                'evidence_fingerprint' => $this->integrity->evidenceFingerprint($request),
            ]);
            $this->transition($request, $actor, Status::Pending, Action::Submitted);
            $profile->forceFill(['verification_status' => ProfileStatus::Pending, 'verified_at' => null])->save();

            return $request->fresh();
        });
    }

    public function view(User $actor, int $requestId): array
    {
        return $this->withRequest($actor, $requestId, function (User $actor, ProfessionalProfile $profile, VerificationRequest $request): array {
            Gate::forUser($actor)->authorize('view', $request);

            return $request->toArray();
        });
    }

    public function pending(User $actor): array
    {
        $actor = $this->current($actor);
        if (! $actor->can('verification.review')) {
            throw new AuthorizationException;
        }

        return VerificationRequest::where('status', Status::Pending->value)
            ->whereHas('profile', fn ($query) => $query->where('user_id', '<>', $actor->id))
            ->orderBy('submitted_at')->orderBy('id')->get()->toArray();
    }

    public function review(User $actor, int $requestId): array
    {
        return $this->withRequest($actor, $requestId, function (User $actor, ProfessionalProfile $profile, VerificationRequest $request): array {
            Gate::forUser($actor)->authorize('review', $request);
            $this->assertEvidenceManifest($request);
            $this->event($request, $actor, Action::ReviewOpened);
            $currentData = $request->profile_fingerprint !== null
                && hash_equals($request->profile_fingerprint, $this->integrity->profileFingerprint($profile));

            return [
                'request' => $request->toArray(),
                'profile_matches_submission' => $currentData,
                'profile' => $currentData ? $profile->only(VerificationIntegrity::MATERIAL_FIELDS) : null,
                'evidences' => $request->evidences()->orderBy('id')->get()->toArray(),
            ];
        });
    }

    public function approve(User $actor, int $requestId, string $reason): VerificationRequest
    {
        return $this->decide($actor, $requestId, Status::Approved, $reason);
    }

    public function reject(User $actor, int $requestId, string $reason): VerificationRequest
    {
        return $this->decide($actor, $requestId, Status::Rejected, $reason);
    }

    public function revoke(User $actor, int $requestId, string $reason): VerificationRequest
    {
        return $this->withRequest($actor, $requestId, function (User $actor, ProfessionalProfile $profile, VerificationRequest $request) use ($reason): VerificationRequest {
            Gate::forUser($actor)->authorize('revoke', $request);
            $this->status($request, Status::Approved);
            $this->reason($reason);
            $request->forceFill(['reviewed_by' => $actor->id, 'resolved_at' => now(), 'reason' => trim($reason)]);
            $this->transition($request, $actor, Status::Revoked, Action::Revoked, trim($reason));
            $profile->forceFill(['verification_status' => ProfileStatus::Revoked, 'verified_at' => null])->save();

            return $request->fresh();
        });
    }

    public function history(User $actor, int $requestId): array
    {
        return $this->withRequest($actor, $requestId, function (User $actor, ProfessionalProfile $profile, VerificationRequest $request): array {
            Gate::forUser($actor)->authorize('audit', $request);

            return $request->events()->orderBy('id')->get()->toArray();
        });
    }

    public function downloadEvidence(User $actor, int $requestId, int $evidenceId): StreamedResponse
    {
        [$stream, $extension] = $this->withRequest($actor, $requestId, function (User $actor, ProfessionalProfile $profile, VerificationRequest $request) use ($evidenceId): array {
            Gate::forUser($actor)->authorize('readEvidence', $request);
            if ($request->submitted_at !== null) {
                $this->assertEvidenceManifest($request);
            }
            $evidence = $request->evidences()->whereKey($evidenceId)->lockForUpdate()->first();
            if (! $evidence) {
                throw new AuthorizationException;
            }
            $attachment = Attachment::whereKey($evidence->attachment_id)->lockForUpdate()->firstOrFail();
            $stream = $this->integrity->verifiedStream($attachment, $evidence);
            try {
                $this->event($request, $actor, Action::EvidenceRead, attachmentId: $attachment->id);
            } catch (\Throwable $error) {
                fclose($stream);
                throw $error;
            }

            return [$stream, $attachment->extension];
        });

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 'evidencia-'.$evidenceId.'.'.$extension, [
            'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    // Called only inside ProfileService's transaction, before saving material changes.
    public function invalidateForProfileChange(ProfessionalProfile $profile, User $actor): void
    {
        if (DB::transactionLevel() === 0) {
            throw new VerificationException;
        }
        $this->withProfile($actor, $profile->id, function (User $current, ProfessionalProfile $persisted): void {
            Gate::forUser($current)->authorize('update', $persisted);
            $request = $persisted->verificationRequests()->whereIn('status', [Status::Pending->value, Status::Approved->value])
                ->lockForUpdate()->first();
            if ($request) {
                $reason = 'El perfil profesional cambió después de presentar el expediente.';
                $request->forceFill(['reason' => $reason, 'resolved_at' => now()]);
                $this->transition($request, $current, Status::Invalidated, Action::Invalidated, $reason);
                $persisted->forceFill(['verification_status' => ProfileStatus::Unverified, 'verified_at' => null])->save();
            }
        });
    }

    private function decide(User $actor, int $requestId, Status $status, string $reason): VerificationRequest
    {
        return $this->withRequest($actor, $requestId, function (User $actor, ProfessionalProfile $profile, VerificationRequest $request) use ($status, $reason): VerificationRequest {
            Gate::forUser($actor)->authorize('decide', $request);
            $this->status($request, Status::Pending);
            $this->reason($reason);
            if ($status === Status::Approved) {
                if ($profile->verification_status !== ProfileStatus::Pending || ! $profile->user->isActive()
                    || ! hash_equals($request->profile_fingerprint, $this->integrity->profileFingerprint($profile))
                    || ! $request->evidences()->where('type', EvidenceType::ProfessionalLicense->value)->exists()) {
                    $this->invalid('Los datos del expediente ya no corresponden al perfil activo.');
                }
                $this->checkEvidences($request, $profile);
            }
            $request->forceFill(['reason' => trim($reason), 'reviewed_by' => $actor->id, 'resolved_at' => now()]);
            $this->transition($request, $actor, $status, $status === Status::Approved ? Action::Approved : Action::Rejected, trim($reason));
            $profile->forceFill([
                'verification_status' => $status === Status::Approved ? ProfileStatus::Verified : ProfileStatus::Rejected,
                'verified_at' => $status === Status::Approved ? now() : null,
            ])->save();

            return $request->fresh();
        });
    }

    private function checkEvidences(VerificationRequest $request, ProfessionalProfile $profile, bool $retain = false): void
    {
        if (! $retain) {
            $this->assertEvidenceManifest($request);
        }
        $evidences = $request->evidences()->orderBy('attachment_id')->lockForUpdate()->get();
        if ($evidences->isEmpty()) {
            $this->invalid('El expediente no tiene evidencias.');
        }
        foreach ($evidences as $evidence) {
            $attachment = Attachment::whereKey($evidence->attachment_id)->lockForUpdate()->firstOrFail();
            if ((int) $attachment->user_id !== (int) $profile->user_id) {
                throw new AuthorizationException;
            }
            $stream = $this->integrity->verifiedStream($attachment, $evidence);
            fclose($stream);
            if ($retain && $attachment->verification_retained_at === null) {
                $attachment->forceFill(['verification_retained_at' => now()])->save();
            }
        }
    }

    private function assertEvidenceManifest(VerificationRequest $request): void
    {
        if ($request->evidence_fingerprint === null
            || ! hash_equals($request->evidence_fingerprint, $this->integrity->evidenceFingerprint($request))) {
            $this->invalid('El conjunto de evidencias cambió o no tiene un sello de presentación válido.');
        }
    }

    private function withRequest(User $actor, int $id, callable $operation): mixed
    {
        $profileId = VerificationRequest::whereKey($id)->value('professional_profile_id');
        if (! $profileId) {
            throw new AuthorizationException;
        }

        return $this->withProfile($actor, $profileId, function (User $actor, ProfessionalProfile $profile) use ($id, $operation) {
            $request = $profile->verificationRequests()->whereKey($id)->lockForUpdate()->first();
            if (! $request) {
                throw new AuthorizationException;
            }
            $request->setRelation('profile', $profile);

            return $operation($actor, $profile, $request);
        });
    }

    private function withProfile(User $actor, int $profileId, callable $operation): mixed
    {
        try {
            return DB::transaction(function () use ($actor, $profileId, $operation) {
                $ownerId = ProfessionalProfile::whereKey($profileId)->value('user_id');
                $users = User::whereKey([$actor->id, $ownerId])->orderBy('id')->lockForUpdate()->get();
                $current = $users->firstWhere('id', $actor->id);
                $profile = ProfessionalProfile::whereKey($profileId)->lockForUpdate()->first();
                if (! $current || ! $current->isActive() || ! $profile) {
                    throw new AuthorizationException;
                }
                $profile->setRelation('user', $users->firstWhere('id', $ownerId));

                return $operation($current, $profile);
            });
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                $this->invalid('Ya existe un expediente activo o una evidencia repetida.');
            }
            report($error);
            throw new VerificationException;
        }
    }

    private function current(User $actor): User
    {
        $current = User::find($actor->id);
        if (! $current || ! $current->isActive()) {
            throw new AuthorizationException;
        }

        return $current;
    }

    private function owner(User $actor, ProfessionalProfile $profile): void
    {
        if ((int) $actor->id !== (int) $profile->user_id) {
            throw new AuthorizationException;
        }
    }

    private function status(VerificationRequest $request, Status $expected): void
    {
        if ($request->status !== $expected) {
            $this->invalid('La operación no está permitida en el estado actual del expediente.');
        }
    }

    private function transition(VerificationRequest $request, User $actor, Status $next, Action $action, ?string $reason = null): void
    {
        $previous = $request->status;
        if (! $previous->canTransitionTo($next)) {
            $this->invalid('La transición del expediente no está permitida.');
        }
        $request->forceFill(['status' => $next])->save();
        $this->event($request, $actor, $action, $previous, $reason);
    }

    private function event(VerificationRequest $request, User $actor, Action $action, ?Status $previous = null, ?string $reason = null, ?int $attachmentId = null): void
    {
        $event = new VerificationEvent;
        $event->forceFill([
            'verification_request_id' => $request->id, 'actor_id' => $actor->id,
            'action' => $action, 'from_status' => $previous ?? ($action === Action::Created ? null : $request->status),
            'to_status' => $request->status, 'reason' => $reason, 'attachment_id' => $attachmentId, 'created_at' => now(),
        ])->save();
    }

    private function reason(string $reason): void
    {
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'min:10', 'max:2000']])->validate();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['verification' => $message]);
    }
}
