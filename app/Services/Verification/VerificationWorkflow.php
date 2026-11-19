<?php

namespace App\Services\Verification;

use App\Enums\VerificationEvidenceType;
use App\Enums\VerificationRequestStatus as Status;
use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\AttachmentValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/** HTTP coordination and explicit projections; all domain writes stay in BACK-04. */
class VerificationWorkflow
{
    public function __construct(
        private VerificationService $verification,
        private VerificationIntegrity $integrity,
        private AttachmentService $attachments,
        private AttachmentValidator $validator,
    ) {}

    public function own(User $actor, ?int $page = null): array
    {
        return DB::transaction(function () use ($actor, $page) {
            $actor = $this->current($actor);
            $profile = ProfessionalProfile::where('user_id', $actor->id)->lockForUpdate()->first();
            $requests = $profile?->verificationRequests()->orderByDesc('id')->paginate(15, page: $page)
                ->through(fn ($request) => $this->summary($request));

            return [
                'professional' => $profile ? [
                    'headline' => $profile->headline,
                    'status' => $profile->verification_status->value,
                    'has_license' => (bool) $profile->license_number,
                    'has_specialties' => $profile->specialties()->active()->exists(),
                ] : null,
                'requests' => $requests,
                'can_create' => $profile && ! $profile->verificationRequests()->whereIn('status', ['draft', 'pending', 'approved'])->exists()
                    && ! in_array($profile->verification_status->value, ['verified', 'pending'], true),
                'can_review' => $actor->can('verification.review'),
            ];
        });
    }

    public function create(User $actor): int
    {
        $profileId = ProfessionalProfile::where('user_id', $actor->id)->value('id');
        if (! $profileId) {
            throw ValidationException::withMessages(['verification' => 'Crea primero tu presentación profesional.']);
        }

        return $this->verification->createDraft($actor, $profileId)->id;
    }

    public function queue(User $actor, string $status): array
    {
        return DB::transaction(function () use ($actor, $status) {
            $actor = $this->current($actor);
            abort_unless($actor->can('verification.review'), 403);
            // Bounded HTTP projection of submitted requests; each row also passes policy.
            $requests = VerificationRequest::where('status', $status)->whereNotNull('submitted_at')
                ->whereHas('profile', fn ($query) => $query->where('user_id', '<>', $actor->id))
                ->with('profile')->orderBy('submitted_at')->orderBy('id')->paginate(15)
                ->through(function ($request) use ($actor) {
                    Gate::forUser($actor)->authorize('review', $request);

                    return $this->summary($request);
                })->appends(['status' => $status]);

            return compact('requests', 'status');
        });
    }

    public function detail(User $actor, int $id, bool $review = false): array
    {
        return $this->locked($actor, $id, $review ? 'review' : 'owner', function ($actor, $profile, $request) use ($review) {
            $reviewData = $review ? $this->verification->review($actor, $request->id) : null;
            if (! $review) {
                $this->verification->view($actor, $request->id);
            }
            $evidences = $request->evidences()->with('attachment')->orderBy('id')->get()->map(fn ($evidence) => [
                'id' => $evidence->id, 'type' => $evidence->type->value,
                'name' => $evidence->attachment->original_name, 'size_bytes' => $evidence->size_bytes,
            ])->all();
            $gate = Gate::forUser($actor);

            return [
                'dossier' => $this->summary($request) + ['version' => $this->version($request, $profile)],
                'evidences' => $evidences, 'review' => $review,
                'profile_matches' => $reviewData['profile_matches_submission'] ?? null,
                // Only the explicitly authorized reviewer receives these material fields.
                'material' => $reviewData['profile'] ?? null,
                'specialties' => $reviewData && $reviewData['profile_matches_submission'] ? $profile->specialties()->pluck('name')->all() : [],
                'can_edit' => ! $review && $request->status === Status::Draft,
                'can_download' => $gate->allows('readEvidence', $request),
                'can_audit' => $gate->allows('audit', $request),
                'can_decide' => $review && $request->status === Status::Pending && $gate->allows('decide', $request),
                'can_approve' => $review && $request->status === Status::Pending && $gate->allows('decide', $request)
                    && $profile->user->isActive() && ($reviewData['profile_matches_submission'] ?? false),
                'can_revoke' => $review && $request->status === Status::Approved && $gate->allows('revoke', $request),
            ];
        });
    }

    public function authorize(User $actor, int $id, string $operation): void
    {
        $this->locked($actor, $id, $operation, fn () => null);
    }

    public function change(User $actor, int $id, string $version, string $action, array $data = []): void
    {
        $permission = match ($action) {
            'approve', 'reject' => 'decide', 'revoke' => 'revoke', default => 'owner',
        };
        $this->locked($actor, $id, $permission, function ($actor, $profile, $request) use ($version, $action, $data) {
            $this->checkVersion($request, $profile, $version);
            match ($action) {
                'submit' => $this->verification->submit($actor, $request->id),
                'remove' => $this->verification->removeEvidence($actor, $request->id, $data['evidence']),
                'add' => $this->verification->addEvidence($actor, $request->id, $data['attachment'], VerificationEvidenceType::from($data['type'])),
                'approve' => $this->verification->approve($actor, $request->id, $data['reason']),
                'reject' => $this->verification->reject($actor, $request->id, $data['reason']),
                'revoke' => $this->verification->revoke($actor, $request->id, $data['reason']),
                default => throw new \LogicException('Unknown verification operation.'),
            };
        });
    }

    public function upload(User $actor, int $id, string $version, UploadedFile $file, string $type): void
    {
        $this->locked($actor, $id, 'owner', function ($actor, $profile, $request) use ($version) {
            $this->checkVersion($request, $profile, $version);
            if ($request->status !== Status::Draft || $request->evidences()->count() >= config('verification.max_evidences')) {
                throw ValidationException::withMessages(['file' => 'Sólo puedes añadir documentos a un borrador con espacio disponible.']);
            }
        });
        $metadata = $this->validator->validate($file);
        if (! in_array($metadata['category'], ['image', 'document'], true)
            || $metadata['size_bytes'] > config('verification.max_evidence_bytes')) {
            throw ValidationException::withMessages(['file' => 'Selecciona una imagen o documento dentro del límite de evidencia.']);
        }
        // Durable reservation and physical upload must finish outside the association transaction.
        $attachment = $this->attachments->store($actor, $file);
        try {
            $this->change($actor, $id, $version, 'add', ['attachment' => $attachment->id, 'type' => $type]);
        } catch (Throwable $error) {
            try {
                $this->attachments->requestDeletion($actor, $attachment->id);
            } catch (Throwable $cleanupError) {
                report($cleanupError);
            }
            throw $error;
        }
    }

    public function history(User $actor, int $id): array
    {
        return $this->locked($actor, $id, 'audit', function ($actor, $profile, $request) {
            $events = array_map(fn ($event) => [
                'action' => $event['action'], 'from_status' => $event['from_status'], 'to_status' => $event['to_status'],
                'reason' => $event['reason'], 'created_at' => $event['created_at'],
                'actor' => (int) $event['actor_id'] === (int) $actor->id ? 'Tú'
                    : ((int) $event['actor_id'] === (int) $profile->user_id ? 'Solicitante' : 'Personal autorizado'),
            ], $this->verification->history($actor, $request->id));

            return ['dossierId' => $request->id, 'events' => $events];
        });
    }

    public function download(User $actor, int $id, int $evidence)
    {
        return $this->locked($actor, $id, 'readEvidence', fn ($actor) => $this->verification->downloadEvidence($actor, $id, $evidence));
    }

    private function locked(User $actor, int $id, string $permission, callable $operation): mixed
    {
        return DB::transaction(function () use ($actor, $id, $permission, $operation) {
            $profileId = VerificationRequest::whereKey($id)->value('professional_profile_id');
            $ownerId = ProfessionalProfile::whereKey($profileId)->value('user_id');
            $users = User::whereKey([$actor->id, $ownerId])->orderBy('id')->lockForUpdate()->get();
            $actor = $users->firstWhere('id', $actor->id);
            $profile = ProfessionalProfile::whereKey($profileId)->lockForUpdate()->first();
            $request = VerificationRequest::whereKey($id)->lockForUpdate()->first();
            if (! $actor || ! $actor->isActive() || ! $profile || ! $request) {
                throw new AuthorizationException;
            }
            $profile->setRelation('user', $users->firstWhere('id', $ownerId));
            $request->setRelation('profile', $profile);
            if ($permission === 'owner') {
                if ((int) $profile->user_id !== (int) $actor->id) {
                    throw new AuthorizationException;
                }
                Gate::forUser($actor)->authorize('view', $request);
            } else {
                Gate::forUser($actor)->authorize($permission, $request);
            }

            return $operation($actor, $profile, $request);
        });
    }

    private function current(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->first();
        if (! $current || ! $current->isActive()) {
            throw new AuthorizationException;
        }

        return $current;
    }

    private function summary(VerificationRequest $request): array
    {
        return [
            'id' => $request->id, 'status' => $request->status->value, 'reason' => $request->reason,
            'created_at' => $request->created_at?->toIso8601String(),
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'resolved_at' => $request->resolved_at?->toIso8601String(),
        ];
    }

    private function version(VerificationRequest $request, ProfessionalProfile $profile): string
    {
        return hash_hmac('sha256', json_encode([
            'verification-http-v1', $request->id, $request->status->value,
            $request->getRawOriginal('updated_at'), $this->integrity->profileFingerprint($profile),
            $this->integrity->evidenceFingerprint($request),
        ], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function checkVersion(VerificationRequest $request, ProfessionalProfile $profile, string $version): void
    {
        if (! hash_equals($this->version($request, $profile), $version)) {
            abort(409, 'El expediente cambió. Abre su estado actual antes de volver a enviar.');
        }
    }
}
