<?php

namespace Tests\Feature;

use App\Enums\AttachmentStatus;
use App\Enums\ProfessionalVerificationStatus as ProfileStatus;
use App\Enums\VerificationAction as Action;
use App\Enums\VerificationEvidenceType as EvidenceType;
use App\Enums\VerificationRequestStatus as Status;
use App\Exceptions\AttachmentException;
use App\Exceptions\VerificationException;
use App\Models\User;
use App\Models\VerificationEvent;
use App\Models\VerificationRequest;
use App\Services\Attachments\AttachmentService;
use App\Services\Profiles\ProfileService;
use App\Services\Verification\VerificationService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VerificationPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    use VerificationFixtures;

    private bool $transactionStarted = false;

    private VerificationService $workflow;

    private FilesystemAdapter $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('plataforma_legal_v2_test', DB::connection()->getDatabaseName());
        $this->assertSame('plataforma_legal_v2_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->disk = Storage::fake('attachments');
        $this->seed(VerificationPermissionSeeder::class);
        $this->workflow = app(VerificationService::class);
    }

    protected function tearDown(): void
    {
        try {
            Event::forget('eloquent.creating: '.VerificationEvent::class);
            if ($this->transactionStarted) {
                DB::rollBack();
                $this->disk->delete($this->disk->allFiles());
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_draft_belongs_to_profile_is_audited_and_does_not_verify_or_grant_roles(): void
    {
        [$owner, $profile] = $this->professional();
        $request = $this->workflow->createDraft($owner, $profile->id);
        $this->assertSame(Status::Draft, $request->status);
        $this->assertTrue($profile->verificationRequests()->firstOrFail()->is($request));
        $this->assertTrue($request->profile->is($profile));
        $this->assertNull($request->submitted_at);
        $this->assertNull($request->resolved_at);
        $this->assertSame(ProfileStatus::Unverified, $profile->fresh()->verification_status);
        $this->assertSame([], $owner->getRoleNames()->all());
        $history = $this->workflow->history($owner, $request->id);
        $this->assertSame('created', $history[0]['action']);
        $this->assertNull($history[0]['from_status']);
        $this->assertSame('draft', $history[0]['to_status']);
        $this->assertSame($owner->id, $history[0]['actor_id']);
        $this->assertNotNull($history[0]['created_at']);
    }

    public function test_own_evidence_has_integrity_snapshot_can_be_removed_only_in_draft_and_cannot_repeat(): void
    {
        [$owner, $profile] = $this->professional();
        $request = $this->workflow->createDraft($owner, $profile->id);
        $file = $this->evidenceFile($owner);
        $evidence = $this->workflow->addEvidence($owner, $request->id, $file->id, EvidenceType::ProfessionalLicense);
        $this->assertTrue($evidence->request->is($request));
        $this->assertTrue($evidence->attachment->is($file));
        $this->assertSame($file->sha256, $evidence->content_sha256);
        $this->assertSame($file->size_bytes, $evidence->size_bytes);
        $this->assertArrayNotHasKey('attachment', $evidence->toArray());
        try {
            $this->workflow->addEvidence($owner, $request->id, $file->id, EvidenceType::Certificate);
            $this->fail('Se aceptó evidencia repetida.');
        } catch (ValidationException) {
            $this->assertSame(1, $request->evidences()->count());
        }
        $this->workflow->removeEvidence($owner, $request->id, $evidence->id);
        $this->assertSame(0, $request->evidences()->count());
        $this->assertNull($file->fresh()->verification_retained_at);
        $this->assertSame('evidence_removed', $this->workflow->history($owner, $request->id)[2]['action']);
    }

    public function test_complete_submission_sets_pending_preserves_files_and_blocks_ordinary_deletion(): void
    {
        [$owner, $profile, $request, $evidence, $file] = $this->submitted();
        $this->assertSame(Status::Pending, $request->status);
        $this->assertSame(ProfileStatus::Pending, $profile->fresh()->verification_status);
        $this->assertNotNull($request->submitted_at);
        $this->assertNull($profile->fresh()->verified_at);
        $this->assertNotNull($file->fresh()->verification_retained_at);
        foreach (['requestDeletion', 'purge'] as $method) {
            try {
                app(AttachmentService::class)->$method($owner, $file->id);
                $this->fail('Se permitió borrar una evidencia retenida.');
            } catch (AttachmentException) {
                $this->disk->assertExists($file->path);
            }
        }
        foreach ([
            fn () => $this->workflow->submit($owner, $request->id),
            fn () => $this->workflow->addEvidence($owner, $request->id, $file->id, EvidenceType::Certificate),
            fn () => $this->workflow->removeEvidence($owner, $request->id, $evidence->id),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Se modificó una solicitud presentada.');
            } catch (ValidationException) {
                $this->assertSame(Status::Pending, $request->fresh()->status);
            }
        }
    }

    public function test_incomplete_submission_does_not_change_status_or_retain_files(): void
    {
        [$owner, $profile] = $this->professional();
        $request = $this->workflow->createDraft($owner, $profile->id);
        $file = $this->evidenceFile($owner);
        $this->workflow->addEvidence($owner, $request->id, $file->id, EvidenceType::Certificate);
        try {
            $this->workflow->submit($owner, $request->id);
            $this->fail('Se presentó un expediente incompleto.');
        } catch (ValidationException) {
            $this->assertSame(Status::Draft, $request->fresh()->status);
            $this->assertNull($file->fresh()->verification_retained_at);
        }
        $this->workflow->addEvidence($owner, $request->id, $this->evidenceFile($owner)->id, EvidenceType::ProfessionalLicense);
        $profile->forceFill(['license_number' => null])->save();
        $this->expectException(ValidationException::class);
        $this->workflow->submit($owner, $request->id);
    }

    public function test_evidence_limits_and_duplicate_content_are_enforced_even_with_different_attachment_ids(): void
    {
        [$owner, $profile] = $this->professional();
        $request = $this->workflow->createDraft($owner, $profile->id);
        $a = $this->evidenceFile($owner);
        $b = $this->evidenceFile($owner);
        $content = $this->disk->get($a->path);
        $this->disk->put($b->path, $content);
        $b->forceFill(['sha256' => hash('sha256', $content), 'size_bytes' => strlen($content)])->save();
        $this->workflow->addEvidence($owner, $request->id, $a->id, EvidenceType::ProfessionalLicense);
        try {
            $this->workflow->addEvidence($owner, $request->id, $b->id, EvidenceType::Certificate);
            $this->fail('Se duplicó el contenido bajo otro ID.');
        } catch (ValidationException) {
            $this->assertSame(1, $request->evidences()->count());
        }
        config(['verification.max_evidences' => 1]);
        try {
            $this->workflow->addEvidence($owner, $request->id, $this->evidenceFile($owner)->id, EvidenceType::Certificate);
            $this->fail('Se superó el límite de evidencias.');
        } catch (ValidationException) {
            $this->assertSame(1, $request->evidences()->count());
        }
        config(['verification.max_evidences' => 10, 'verification.max_evidence_bytes' => 1]);
        $this->expectException(ValidationException::class);
        $this->workflow->addEvidence($owner, $request->id, $this->evidenceFile($owner)->id, EvidenceType::Certificate);
    }

    public function test_approval_synchronizes_profile_and_preserves_actor_reason_and_event_chain(): void
    {
        [$owner, $profile, $request] = $this->submitted();
        $reviewer = $this->reviewer();
        $context = $this->workflow->review($reviewer, $request->id);
        $this->assertTrue($context['profile_matches_submission']);
        $this->assertSame('12345678', $context['profile']['license_number']);
        $this->assertArrayNotHasKey('license_number', $this->workflow->view($owner, $request->id));
        $approved = $this->workflow->approve($reviewer, $request->id, 'Revisión interna documentada de las evidencias.');
        $this->assertSame(Status::Approved, $approved->status);
        $this->assertSame($reviewer->id, $approved->reviewed_by);
        $this->assertNotNull($approved->resolved_at);
        $this->assertSame(ProfileStatus::Verified, $profile->fresh()->verification_status);
        $this->assertNotNull($profile->fresh()->verified_at);
        $event = $request->events()->orderByDesc('id')->firstOrFail();
        $this->assertSame(Action::Approved, $event->action);
        $this->assertSame(Status::Pending, $event->from_status);
        $this->assertSame(Status::Approved, $event->to_status);
        $this->assertSame($reviewer->id, $event->actor_id);
        $this->assertSame($approved->reason, $event->reason);
    }

    public function test_rejection_allows_new_request_preserves_history_and_never_verifies(): void
    {
        [$owner, $profile, $request] = $this->submitted();
        $reviewer = $this->reviewer();
        $this->workflow->reject($reviewer, $request->id, 'La documentación aportada no es suficiente.');
        $this->assertSame(ProfileStatus::Rejected, $profile->fresh()->verification_status);
        $this->assertNull($profile->fresh()->verified_at);
        $this->assertStringContainsString('no es suficiente', $this->workflow->view($owner, $request->id)['reason']);
        $before = $this->workflow->history($owner, $request->id);
        $new = $this->workflow->createDraft($owner, $profile->id);
        $this->assertNotSame($request->id, $new->id);
        $this->assertSame($before, $this->workflow->history($owner, $request->id));
        $this->assertSame(Status::Rejected, $request->fresh()->status);
    }

    public function test_revocation_removes_verification_preserves_approval_and_allows_resubmission(): void
    {
        [$owner, $profile, $request] = $this->submitted();
        $reviewer = $this->reviewer();
        $this->workflow->approve($reviewer, $request->id, 'Revisión interna documentada de las evidencias.');
        $this->workflow->revoke($reviewer, $request->id, 'La revisión posterior encontró inconsistencias.');
        $this->assertSame(ProfileStatus::Revoked, $profile->fresh()->verification_status);
        $this->assertNull($profile->fresh()->verified_at);
        $this->assertSame(Status::Revoked, $request->fresh()->status);
        $actions = array_column($this->workflow->history($reviewer, $request->id), 'action');
        $this->assertContains('approved', $actions);
        $this->assertContains('revoked', $actions);
        $this->assertSame(Status::Draft, $this->workflow->createDraft($owner, $profile->id)->status);
    }

    public function test_drafts_pending_and_approved_requests_cannot_be_duplicated(): void
    {
        [$owner, $profile] = $this->professional();
        $request = $this->workflow->createDraft($owner, $profile->id);
        foreach ([Status::Draft, Status::Pending, Status::Approved] as $status) {
            if ($status === Status::Pending) {
                $this->workflow->addEvidence($owner, $request->id, $this->evidenceFile($owner)->id, EvidenceType::ProfessionalLicense);
                $this->workflow->submit($owner, $request->id);
            } elseif ($status === Status::Approved) {
                $this->workflow->approve($this->reviewer(), $request->id, 'Revisión interna documentada de las evidencias.');
            }
            try {
                $this->workflow->createDraft($owner, $profile->id);
                $this->fail('Se duplicó el expediente activo.');
            } catch (ValidationException) {
                $this->assertSame(1, $profile->verificationRequests()->count());
            }
        }
    }

    public static function invalidEvidence(): array
    {
        return [
            ['document', AttachmentStatus::PendingUpload], ['document', AttachmentStatus::PendingDeletion],
            ['document', AttachmentStatus::Deleted], ['video', AttachmentStatus::Available], ['other', AttachmentStatus::Available],
        ];
    }

    #[DataProvider('invalidEvidence')]
    public function test_disallowed_or_unavailable_files_cannot_be_evidences(string $category, AttachmentStatus $status): void
    {
        [$owner, $profile] = $this->professional();
        $request = $this->workflow->createDraft($owner, $profile->id);
        $file = $this->evidenceFile($owner, $category, $status);
        $this->expectException(ValidationException::class);
        $this->workflow->addEvidence($owner, $request->id, $file->id, EvidenceType::ProfessionalLicense);
    }

    public function test_foreign_files_other_requests_and_nonowners_are_denied(): void
    {
        [$owner, $profile, $request, $evidence] = $this->submitted();
        [$other, $otherProfile] = $this->professional();
        $otherRequest = $this->workflow->createDraft($other, $otherProfile->id);
        foreach ([
            fn () => $this->workflow->createDraft($other, $profile->id),
            fn () => $this->workflow->addEvidence($other, $otherRequest->id, $this->evidenceFile($owner)->id, EvidenceType::Certificate),
            fn () => $this->workflow->view($other, $request->id),
            fn () => $this->workflow->history($other, $request->id),
            fn () => $this->workflow->downloadEvidence($other, $request->id, $evidence->id),
            fn () => $this->workflow->downloadEvidence($this->reviewer(), $request->id, $this->workflow->addEvidence($other, $otherRequest->id, $this->evidenceFile($other)->id, EvidenceType::Certificate)->id),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Se admitió acceso a datos ajenos.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_reviewer_reads_only_linked_evidence_with_explicit_permission_and_access_is_audited(): void
    {
        [$owner, $profile, $request, $evidence, $file] = $this->submitted();
        $reviewer = $this->reviewer();
        try {
            app(AttachmentService::class)->metadata($reviewer, $file->id);
            $this->fail('AttachmentPolicy fue debilitada.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $response = $this->workflow->downloadEvidence($reviewer, $request->id, $evidence->id);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        ob_start();
        $response->sendContent();
        $this->assertSame($this->disk->get($file->path), ob_get_clean());
        $event = $request->events()->orderByDesc('id')->firstOrFail();
        $this->assertSame(Action::EvidenceRead, $event->action);
        $this->assertSame($reviewer->id, $event->actor_id);
        $this->assertSame($file->id, $event->attachment_id);
        $this->expectException(AuthorizationException::class);
        $this->workflow->downloadEvidence($this->reviewer(['verification.review']), $request->id, $evidence->id);
    }

    public function test_no_self_approval_even_with_all_review_permissions(): void
    {
        [$owner, $profile, $request] = $this->submitted();
        $owner->givePermissionTo(Permission::all());
        foreach (['review', 'approve', 'reject', 'revoke'] as $method) {
            try {
                $this->workflow->$method($owner, $request->id, 'Justificación de prueba suficiente.');
                $this->fail('Se admitió revisión propia.');
            } catch (AuthorizationException) {
                $this->assertSame(Status::Pending, $request->fresh()->status);
            }
        }
    }

    public function test_permissions_are_separate_and_no_existing_role_has_implicit_review_access(): void
    {
        [$owner, $profile, $request] = $this->submitted();
        $this->seed(RolePermissionSeeder::class);
        foreach (['user', 'moderator', 'admin'] as $role) {
            $actor = $this->reviewer([]);
            $actor->assignRole($role);
            try {
                $this->workflow->approve($actor, $request->id, 'Justificación de prueba suficiente.');
                $this->fail('Un rol recibió acceso implícito.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        foreach ([['verification.decide'], ['verification.review'], ['verification.evidence.read']] as $permissions) {
            try {
                $this->workflow->approve($this->reviewer($permissions), $request->id, 'Justificación de prueba suficiente.');
                $this->fail('Se aceptaron permisos incompletos.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public static function inactiveStates(): array
    {
        return [['suspended'], ['disabled'], ['pending_deletion']];
    }

    #[DataProvider('inactiveStates')]
    public function test_stale_inactive_owner_and_reviewer_cannot_operate(string $status): void
    {
        [$owner, $profile, $request, $evidence] = $this->submitted();
        $reviewer = $this->reviewer();
        DB::table('users')->whereIn('id', [$owner->id, $reviewer->id])->update(['status' => $status]);
        foreach ([
            fn () => $this->workflow->view($owner, $request->id),
            fn () => $this->workflow->createDraft($owner, $profile->id),
            fn () => $this->workflow->approve($reviewer, $request->id, 'Justificación de prueba suficiente.'),
            fn () => $this->workflow->downloadEvidence($reviewer, $request->id, $evidence->id),
            fn () => $this->workflow->pending($reviewer),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Una cuenta inactiva realizó una operación.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_inactive_owner_cannot_be_approved_by_an_active_reviewer(): void
    {
        [$owner, $profile, $request] = $this->submitted();
        DB::table('users')->where('id', $owner->id)->update(['status' => 'suspended']);
        $this->expectException(ValidationException::class);
        $this->workflow->approve($this->reviewer(), $request->id, 'Justificación de prueba suficiente.');
    }

    public function test_material_edits_invalidate_pending_and_approved_requests_and_prevent_stale_decisions(): void
    {
        [$owner, $profile, $request] = $this->submitted();
        $reviewer = $this->reviewer();
        app(ProfileService::class)->saveProfessionalProfile($owner, $owner->id, ['headline' => 'Título modificado']);
        $this->assertSame(Status::Invalidated, $request->fresh()->status);
        $this->assertSame(ProfileStatus::Unverified, $profile->fresh()->verification_status);
        try {
            $this->workflow->approve($reviewer, $request->id, 'Justificación de prueba suficiente.');
            $this->fail('Se aprobaron datos obsoletos.');
        } catch (ValidationException) {
            $this->assertFalse($this->workflow->review($reviewer, $request->id)['profile_matches_submission']);
        }
        [$owner2, $profile2, $request2] = $this->submitted();
        $this->workflow->approve($reviewer, $request2->id, 'Justificación de prueba suficiente.');
        app(ProfileService::class)->syncSpecialties($owner2, $owner2->id, []);
        $this->assertSame(Status::Invalidated, $request2->fresh()->status);
        $this->assertSame(ProfileStatus::Unverified, $profile2->fresh()->verification_status);
        $this->assertNull($profile2->fresh()->verified_at);
        $this->assertContains('approved', array_column($this->workflow->history($owner2, $request2->id), 'action'));
        $this->assertSame(Status::Draft, $this->workflow->createDraft($owner2, $profile2->id)->status);
    }

    public function test_noop_and_contact_changes_do_not_invalidate_and_draft_tracks_latest_profile(): void
    {
        [$owner, $profile, $request] = $this->submitted();
        app(ProfileService::class)->saveProfessionalProfile($owner, $owner->id, [
            'headline' => $profile->headline, 'license_number' => '12345678', 'contact_visible' => true,
        ]);
        $this->assertSame(Status::Pending, $request->fresh()->status);
        $this->workflow->approve($this->reviewer(), $request->id, 'Justificación de prueba suficiente.');
        $this->assertSame(ProfileStatus::Verified, $profile->fresh()->verification_status);
        [$owner2, $profile2] = $this->professional();
        $draft = $this->workflow->createDraft($owner2, $profile2->id);
        app(ProfileService::class)->saveProfessionalProfile($owner2, $owner2->id, ['headline' => 'Edición en borrador']);
        $this->assertSame(Status::Draft, $draft->fresh()->status);
    }

    public function test_out_of_band_profile_changes_are_detected_before_approval(): void
    {
        [$owner, $profile, $request] = $this->submitted();
        DB::table('professional_profiles')->where('id', $profile->id)->update(['headline' => 'Cambio fuera de servicio']);
        $this->expectException(ValidationException::class);
        $this->workflow->approve($this->reviewer(), $request->id, 'Justificación de prueba suficiente.');
    }

    public static function evidenceTampering(): array
    {
        return [['content'], ['missing'], ['metadata'], ['owner'], ['status']];
    }

    #[DataProvider('evidenceTampering')]
    public function test_evidence_modifications_are_detected_before_approval(string $kind): void
    {
        [$owner, $profile, $request, $evidence, $file] = $this->submitted();
        match ($kind) {
            'content' => $this->disk->put($file->path, 'Contenido alterado'),
            'missing' => $this->disk->delete($file->path),
            'metadata' => DB::table('attachments')->where('id', $file->id)->update(['original_name' => 'otro.pdf']),
            'owner' => DB::table('attachments')->where('id', $file->id)->update(['user_id' => $this->reviewer([])->id]),
            'status' => DB::table('attachments')->where('id', $file->id)->update(['status' => 'pending_deletion']),
        };
        try {
            $this->workflow->approve($this->reviewer(), $request->id, 'Justificación de prueba suficiente.');
            $this->fail('Se aceptó evidencia alterada.');
        } catch (ValidationException|VerificationException|AuthorizationException) {
            $this->assertSame(Status::Pending, $request->fresh()->status);
            $this->assertSame(ProfileStatus::Pending, $profile->fresh()->verification_status);
        }
    }

    public function test_submission_revalidates_draft_content_and_rolls_back_partial_retention(): void
    {
        [$owner, $profile] = $this->professional();
        $request = $this->workflow->createDraft($owner, $profile->id);
        $a = $this->evidenceFile($owner);
        $b = $this->evidenceFile($owner);
        $this->workflow->addEvidence($owner, $request->id, $a->id, EvidenceType::ProfessionalLicense);
        $this->workflow->addEvidence($owner, $request->id, $b->id, EvidenceType::Certificate);
        $this->disk->put($b->path, 'Contenido alterado');
        try {
            $this->workflow->submit($owner, $request->id);
            $this->fail('Se presentó evidencia alterada.');
        } catch (ValidationException) {
            $this->assertNull($a->fresh()->verification_retained_at);
            $this->assertNull($b->fresh()->verification_retained_at);
            $this->assertSame(Status::Draft, $request->fresh()->status);
            $this->assertSame(ProfileStatus::Unverified, $profile->fresh()->verification_status);
        }
    }

    public function test_decisions_require_reasons_valid_states_and_cannot_be_overwritten(): void
    {
        [$owner, $profile, $request] = $this->submitted();
        $reviewer = $this->reviewer();
        foreach (['approve', 'reject'] as $method) {
            try {
                $this->workflow->$method($reviewer, $request->id, ' ');
                $this->fail('Se resolvió sin justificación.');
            } catch (ValidationException) {
                $this->assertSame(Status::Pending, $request->fresh()->status);
            }
        }
        try {
            $this->workflow->revoke($reviewer, $request->id, 'Justificación de prueba suficiente.');
            $this->fail('Se revocó una solicitud no aprobada.');
        } catch (ValidationException) {
            $this->assertSame(Status::Pending, $request->fresh()->status);
        }
        $this->workflow->approve($reviewer, $request->id, 'Justificación de prueba suficiente.');
        foreach (['approve', 'reject', 'submit'] as $method) {
            try {
                $this->workflow->$method($method === 'submit' ? $owner : $reviewer, $request->id, 'Justificación de prueba suficiente.');
                $this->fail('Se sobrescribió una decisión.');
            } catch (ValidationException) {
                $this->assertSame(1, $request->events()->where('action', 'approved')->count());
            }
        }
    }

    public function test_audit_model_is_append_only_and_records_no_credential_or_storage_paths(): void
    {
        [$owner, $profile, $request, $evidence, $file] = $this->submitted();
        $event = $request->events()->firstOrFail();
        foreach ([
            fn () => $event->forceFill(['reason' => 'Cambio histórico'])->save(),
            fn () => $event->delete(),
            fn () => $event->forceFill(['reason' => 'Cambio silencioso'])->saveQuietly(),
            fn () => $event->deleteQuietly(),
            fn () => $event->incrementQuietly('actor_id'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Se alteró el historial.');
            } catch (VerificationException) {
                $this->assertNull($event->fresh()->reason);
            }
        }
        $history = json_encode($this->workflow->history($owner, $request->id));
        $this->assertStringNotContainsString('12345678', $history);
        $this->assertStringNotContainsString($file->path, $history);
        $this->assertStringNotContainsString('profile_fingerprint', json_encode($this->workflow->view($owner, $request->id)));
        $this->expectException(MassAssignmentException::class);
        new VerificationRequest(['status' => 'approved']);
    }

    public function test_audit_failure_rolls_back_decision_profile_and_state_chain(): void
    {
        [$owner, $profile, $request] = $this->submitted();
        $before = $this->workflow->history($owner, $request->id);
        VerificationEvent::creating(function (VerificationEvent $event): void {
            if ($event->action === Action::Approved) {
                $event->actor_id = -1; // Actual MySQL unsigned/FK failure.
            }
        });
        try {
            $this->workflow->approve($this->reviewer(), $request->id, 'Justificación de prueba suficiente.');
            $this->fail('Se esperaba un fallo de auditoría.');
        } catch (VerificationException) {
            $this->assertSame(Status::Pending, $request->fresh()->status);
            $this->assertSame(ProfileStatus::Pending, $profile->fresh()->verification_status);
            $this->assertNull($profile->fresh()->verified_at);
            $this->assertSame($before, $this->workflow->history($owner, $request->id));
        }
    }

    public function test_submitted_evidence_cannot_be_reclassified_or_deleted_with_eloquent(): void
    {
        [$owner, $profile, $request, $evidence] = $this->submitted();
        foreach ([
            fn () => $evidence->forceFill(['type' => EvidenceType::Certificate])->save(),
            fn () => $evidence->delete(),
            fn () => $evidence->forceFill(['type' => EvidenceType::Certificate])->saveQuietly(),
            fn () => $evidence->deleteQuietly(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Eloquent alteró una evidencia presentada.');
            } catch (VerificationException) {
                $this->assertSame(EvidenceType::ProfessionalLicense, $evidence->fresh()->type);
                $this->assertSame(1, $request->evidences()->count());
            }
        }
    }

    public static function evidenceManifestTampering(): array
    {
        return [['remove'], ['reclassify'], ['append']];
    }

    #[DataProvider('evidenceManifestTampering')]
    public function test_direct_sql_changes_to_submitted_evidence_set_prevent_approval(string $kind): void
    {
        [$owner, $profile] = $this->professional();
        $request = $this->workflow->createDraft($owner, $profile->id);
        $credential = $this->workflow->addEvidence($owner, $request->id, $this->evidenceFile($owner)->id, EvidenceType::ProfessionalLicense);
        $certificate = $this->workflow->addEvidence($owner, $request->id, $this->evidenceFile($owner)->id, EvidenceType::Certificate);
        $this->workflow->submit($owner, $request->id);
        if ($kind === 'remove') {
            // Leave the credential intact: even removing an optional document must be detected.
            DB::table('verification_evidences')->where('id', $certificate->id)->delete();
        } elseif ($kind === 'reclassify') {
            DB::table('verification_evidences')->where('id', $credential->id)->update(['type' => 'certificate']);
        } else {
            $new = $this->evidenceFile($owner);
            $row = $certificate->getAttributes();
            unset($row['id']);
            $row['attachment_id'] = $new->id;
            $row['content_sha256'] = $new->sha256;
            DB::table('verification_evidences')->insert($row);
        }
        try {
            $this->workflow->approve($this->reviewer(), $request->id, 'Justificación de prueba suficiente.');
            $this->fail('Una modificación SQL del expediente pasó inadvertida.');
        } catch (ValidationException) {
            $this->assertSame(Status::Pending, $request->fresh()->status);
            $this->assertSame(ProfileStatus::Pending, $profile->fresh()->verification_status);
        }
    }

    public function test_stale_attachment_model_cannot_clear_retention_or_change_content_metadata(): void
    {
        [$owner, $profile] = $this->professional();
        $request = $this->workflow->createDraft($owner, $profile->id);
        $file = $this->evidenceFile($owner);
        $stale = $file->fresh();
        $this->workflow->addEvidence($owner, $request->id, $file->id, EvidenceType::ProfessionalLicense);
        $this->workflow->submit($owner, $request->id);
        foreach ([
            ['verification_retained_at' => null, 'status' => AttachmentStatus::PendingDeletion],
            ['path' => 'attachments/otra-clave.pdf'],
            ['sha256' => str_repeat('0', 64)],
        ] as $data) {
            try {
                $stale->forceFill($data)->save();
                $this->fail('Un modelo obsoleto alteró un adjunto retenido.');
            } catch (AttachmentException) {
                $this->assertNotNull($file->fresh()->verification_retained_at);
                $this->assertSame(AttachmentStatus::Available, $file->fresh()->status);
            }
        }
    }

    public function test_direct_sql_clearing_of_retention_marker_does_not_release_submitted_document(): void
    {
        [$owner, $profile, $request, $evidence, $file] = $this->submitted();
        DB::table('attachments')->where('id', $file->id)->update(['verification_retained_at' => null]);
        try {
            app(AttachmentService::class)->requestDeletion($owner, $file->id);
            $this->fail('Un marcador borrado liberó una evidencia presentada.');
        } catch (AttachmentException) {
            $this->disk->assertExists($file->path);
        }
        DB::table('attachments')->where('id', $file->id)->update(['status' => 'pending_deletion']);
        $this->expectException(AttachmentException::class);
        try {
            app(AttachmentService::class)->purge($owner, $file->id);
        } finally {
            $this->disk->assertExists($file->path);
        }
    }

    public function test_request_instances_reject_reopening_closed_decisions_or_rewriting_submission_seals(): void
    {
        [$owner, $profile, $request] = $this->submitted();
        $this->workflow->reject($this->reviewer(), $request->id, 'Documentación insuficiente para esta revisión.');
        foreach ([
            ['status' => Status::Pending, 'resolved_at' => null, 'reviewed_by' => null],
            ['profile_fingerprint' => str_repeat('0', 64)],
            ['evidence_fingerprint' => str_repeat('0', 64)],
            ['reason' => 'Decisión histórica sobrescrita'],
        ] as $data) {
            try {
                $request->fresh()->forceFill($data)->saveQuietly();
                $this->fail('Una instancia reabrió o reescribió una decisión cerrada.');
            } catch (VerificationException) {
                $this->assertSame(Status::Rejected, $request->fresh()->status);
            }
        }
    }

    public function test_original_evidence_remains_auditable_after_profile_is_invalidated(): void
    {
        [$owner, $profile, $request, $evidence, $file] = $this->submitted();
        app(ProfileService::class)->saveProfessionalProfile($owner, $owner->id, ['headline' => 'Datos actuales distintos']);
        $response = $this->workflow->downloadEvidence($this->reviewer(), $request->id, $evidence->id);
        ob_start();
        $response->sendContent();
        $this->assertSame($this->disk->get($file->path), ob_get_clean());
        $this->assertSame(Status::Invalidated, $request->fresh()->status);
    }

    public function test_mysql_enforces_unique_active_request_evidence_foreign_keys_and_lifecycle(): void
    {
        [$owner, $profile, $request, $evidence, $file] = $this->submitted();
        foreach ([
            fn () => DB::table('verification_requests')->insert(['professional_profile_id' => $profile->id]),
            fn () => DB::table('verification_requests')->insert(['professional_profile_id' => $profile->id + 10000]),
            fn () => DB::table('verification_requests')->where('id', $request->id)->update(['status' => 'approved']),
            fn () => DB::table('verification_events')->insert(['verification_request_id' => $request->id, 'actor_id' => User::max('id') + 10000, 'action' => 'created', 'to_status' => 'draft']),
            fn () => $evidence->replicate()->save(),
            fn () => DB::table('verification_evidences')->where('id', $evidence->id)->update(['attachment_id' => $file->id + 10000]),
            fn () => $request->delete(),
            fn () => $profile->delete(),
            fn () => $file->delete(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('MySQL permitió perder integridad.');
            } catch (QueryException $error) {
                $this->assertContains((int) $error->errorInfo[1], [1062, 1451, 1452, 3819]);
            }
        }
    }

    public function test_permission_seeders_are_additive_idempotent_and_preserve_explicit_grants(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = $this->reviewer([]);
        $admin->assignRole('admin');
        $admin->roles()->firstOrFail()->givePermissionTo('verification.review');
        $before = Permission::orderBy('id')->pluck('id', 'name')->all();
        $this->seed(DatabaseSeeder::class);
        $this->seed(VerificationPermissionSeeder::class);
        $after = Permission::orderBy('id')->pluck('id', 'name')->all();
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertTrue($admin->fresh()->can('verification.review'));
        $this->assertFalse($admin->fresh()->can('verification.decide'));
        $this->assertSame(25, Permission::count());
        $this->assertFalse($admin->fresh()->can('articles.manage'));
        $this->assertFalse($admin->fresh()->can('resources.manage'));
    }

    public function test_pending_queue_and_audit_permission_do_not_expose_private_credential_or_draft_files(): void
    {
        [$owner, $profile, $request] = $this->submitted();
        $reviewer = $this->reviewer(['verification.review']);
        $queue = $this->workflow->pending($reviewer);
        $this->assertCount(1, $queue);
        $this->assertSame($request->id, $queue[0]['id']);
        $this->assertStringNotContainsString('12345678', json_encode($queue));
        $auditor = $this->reviewer(['verification.audit']);
        $this->assertNotEmpty($this->workflow->history($auditor, $request->id));
        $this->expectException(AuthorizationException::class);
        $this->workflow->review($auditor, $request->id);
    }
}
