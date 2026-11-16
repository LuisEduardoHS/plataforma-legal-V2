<?php

namespace Tests\Feature;

use App\Enums\VerificationEvidenceType;
use App\Models\Attachment;
use App\Models\User;
use App\Models\VerificationEvent;
use App\Models\VerificationRequest;
use App\Services\Community\PostService;
use App\Services\Profiles\ProfileService;
use App\Services\Verification\VerificationService;
use Database\Seeders\VerificationPermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\EnforcedCsrfToken;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

class VerificationIntegrationTest extends TestCase
{
    use VerificationFixtures;

    protected function setUp(): void
    {
        parent::setUp(); // Confirms actual MySQL testing database before any fixture.
        DB::beginTransaction();
        Storage::fake('attachments');
        $this->seed(VerificationPermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        try {
            Event::forget(QueryExecuted::class);
            Event::forget('eloquent.creating: '.VerificationEvent::class);
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function version(User $actor, int $id, bool $review = false): string
    {
        return $this->actingAs($actor)->get(route($review ? 'verification.review.show' : 'verification.show', $id))
            ->assertOk()->viewData('dossier')['version'];
    }

    public function test_guest_and_conventional_account_access_and_professional_onboarding(): void
    {
        $this->get('/verificacion')->assertRedirect('/iniciar-sesion');
        $this->get('/revision-profesional')->assertRedirect('/iniciar-sesion');
        $this->postJson(route('verification.store'))->assertUnauthorized();
        $member = User::factory()->create();
        $this->actingAs($member)->get('/verificacion')->assertOk()->assertSee('No tienes una presentación profesional');
        $this->postJson(route('verification.store'))->assertUnprocessable();
        $this->put('/perfil/profesional', ['visibility' => 'private', 'contact_visible' => '0', 'headline' => 'Presentación nueva'])
            ->assertRedirect('/perfil');
        $created = $this->postJson(route('verification.store'))->assertCreated();
        $id = $created->json('id');
        $this->get(route('verification.show', $id))->assertOk()->assertSee('Borrador')->assertSee('Añadir un documento');
        $this->assertSame('draft', VerificationRequest::findOrFail($id)->status->value);
        $this->assertSame(1, VerificationEvent::where('action', 'created')->count());
        $this->postJson(route('verification.store'))->assertUnprocessable();
        $this->assertSame(1, VerificationRequest::count());
        $this->assertFalse($member->fresh()->can('verification.review'));
    }

    public function test_manipulated_fields_are_rejected_without_assigning_state_actor_or_permissions(): void
    {
        [$owner, $profile] = $this->professional();
        $this->actingAs($owner)->postJson(route('verification.store'), ['professional_profile_id' => $profile->id, 'status' => 'approved', 'reviewed_by' => $owner->id])
            ->assertUnprocessable()->assertJsonValidationErrors('form');
        $this->assertSame(0, VerificationRequest::count());
        $id = $this->postJson(route('verification.store'))->assertCreated()->json('id');
        $version = $this->version($owner, $id);
        foreach (['status' => 'approved', 'actor_id' => $owner->id, 'attachment_id' => 1, 'permissions' => ['verification.decide']] as $key => $value) {
            $this->postJson(route('verification.submit', $id), ['version' => $version, $key => $value])->assertUnprocessable()->assertJsonValidationErrors('form');
        }
        $this->postJson(route('verification.submit', $id), ['version' => []])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame('draft', VerificationRequest::findOrFail($id)->status->value);
        $this->assertSame(1, VerificationEvent::count());
    }

    public function test_incomplete_submission_preserves_draft_and_complete_submission_retains_real_evidence(): void
    {
        [$owner, $profile] = $this->professional();
        $id = $this->actingAs($owner)->postJson(route('verification.store'))->assertCreated()->json('id');
        $version = $this->version($owner, $id);
        $this->postJson(route('verification.submit', $id), ['version' => $version])->assertUnprocessable();
        $file = $this->evidenceFile($owner);
        app(VerificationService::class)->addEvidence($owner, $id, $file->id, VerificationEvidenceType::ProfessionalLicense);
        $this->postJson(route('verification.submit', $id), ['version' => $version])->assertConflict();
        $version = $this->version($owner, $id);
        $this->postJson(route('verification.submit', $id), ['version' => $version])->assertOk();
        $this->assertSame('pending', VerificationRequest::findOrFail($id)->status->value);
        $this->assertSame('pending', $profile->fresh()->verification_status->value);
        $this->assertNotNull($file->fresh()->verification_retained_at);
        $this->postJson(route('verification.submit', $id), ['version' => $version])->assertConflict();
        $this->assertSame(1, VerificationEvent::where('action', 'submitted')->count());
        $fresh = $this->version($owner, $id);
        $this->deleteJson(route('verification.evidence.destroy', ['dossier' => $id, 'evidence' => VerificationRequest::find($id)->evidences()->value('id')]), ['version' => $fresh])->assertUnprocessable();
        Storage::disk('attachments')->assertExists($file->path);
    }

    public function test_draft_evidence_removal_is_contextual_versioned_audited_and_never_purges(): void
    {
        [$owner, $profile] = $this->professional();
        $service = app(VerificationService::class);
        $draft = $service->createDraft($owner, $profile->id);
        $file = $this->evidenceFile($owner);
        $evidence = $service->addEvidence($owner, $draft->id, $file->id, VerificationEvidenceType::ProfessionalLicense);
        $version = $this->version($owner, $draft->id);
        $this->deleteJson(route('verification.evidence.destroy', ['dossier' => $draft->id, 'evidence' => $evidence->id]), ['version' => $version])->assertOk();
        $this->assertSame(0, $draft->evidences()->count());
        $this->assertSame('available', $file->fresh()->status->value);
        Storage::disk('attachments')->assertExists($file->path);
        $this->assertSame(1, VerificationEvent::where('action', 'evidence_removed')->count());
        $this->postJson(route('verification.submit', $draft->id), ['version' => $version])->assertConflict();
    }

    public function test_owner_and_third_party_boundaries_do_not_leak_dossiers_or_evidence(): void
    {
        [$owner, $profile, $dossier, $evidence, $file] = $this->submitted();
        [$other, $otherProfile] = $this->professional();
        $otherDraft = app(VerificationService::class)->createDraft($other, $otherProfile->id);
        foreach ([$dossier->id, 99999999] as $id) {
            $this->actingAs($other)->get(route('verification.show', $id))->assertNotFound()->assertDontSee($file->original_name);
            $this->getJson(route('verification.download', ['dossier' => $id, 'evidence' => $evidence->id]))->assertNotFound();
            $this->getJson(route('verification.history', $id))->assertNotFound();
            $this->postJson(route('verification.submit', $id), ['version' => str_repeat('a', 64)])->assertNotFound();
        }
        $this->actingAs($owner)->get(route('verification.download', ['dossier' => $otherDraft->id, 'evidence' => $evidence->id]))->assertNotFound();
        $response = $this->get(route('verification.show', $dossier->id))->assertOk()->assertDontSee($file->path)->assertDontSee($file->sha256)
            ->assertDontSee($file->storage_name)->assertDontSee('12345678');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $download = $this->get(route('verification.download', ['dossier' => $dossier->id, 'evidence' => $evidence->id]))->assertOk();
        $this->assertSame(Storage::disk('attachments')->get($file->path), $download->streamedContent());
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $download->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(1, VerificationEvent::where('action', 'evidence_read')->count());
    }

    public function test_admin_has_no_implicit_review_access_and_reviewer_cannot_use_owner_routes(): void
    {
        [$owner, $profile, $dossier, $evidence] = $this->submitted();
        $admin = User::factory()->create();
        $admin->assignRole(Role::create(['name' => 'admin', 'guard_name' => 'web']));
        $this->actingAs($admin)->get('/revision-profesional')->assertForbidden();
        $this->get(route('verification.review.show', $dossier->id))->assertNotFound();
        $reviewer = $this->reviewer(['verification.review']);
        $this->actingAs($reviewer)->get('/revision-profesional')->assertOk()->assertSee('Revisar expediente '.$dossier->id);
        $this->get(route('verification.review.show', $dossier->id))->assertOk()->assertSee('12345678')->assertDontSee('name="reason"', false);
        $this->get(route('verification.show', $dossier->id))->assertNotFound();
        $this->get(route('verification.download', ['dossier' => $dossier->id, 'evidence' => $evidence->id]))->assertNotFound();
        $this->get(route('verification.history', $dossier->id))->assertNotFound();
        $this->postJson(route('verification.approve', $dossier->id), ['version' => str_repeat('a', 64), 'reason' => 'Decisión no autorizada'])->assertNotFound();
        $this->assertSame('pending', $dossier->fresh()->status->value);
    }

    public function test_self_review_is_denied_even_with_all_permissions_and_drafts_are_private(): void
    {
        [$owner, $profile, $dossier] = $this->submitted();
        $owner->givePermissionTo(Permission::where('name', 'like', 'verification.%')->get());
        $this->actingAs($owner)->get(route('verification.review.show', $dossier->id))->assertNotFound();
        $this->postJson(route('verification.approve', $dossier->id), ['version' => $this->version($owner, $dossier->id), 'reason' => 'No debe aprobarse a sí mismo'])->assertNotFound();
        $queue = $this->get('/revision-profesional')->assertOk()->viewData('requests');
        $this->assertCount(0, $queue);
        [$other, $otherProfile] = $this->professional();
        $draft = app(VerificationService::class)->createDraft($other, $otherProfile->id);
        $this->get(route('verification.review.show', $draft->id))->assertNotFound();
        $this->get(route('verification.history', $draft->id))->assertOk(); // Separate audit permission, minimal events only.
        $this->assertSame(0, VerificationEvent::where('action', 'approved')->count());
    }

    public function test_explicit_permissions_are_independent_and_revocation_is_rechecked_from_database(): void
    {
        [$owner, $profile, $dossier, $evidence] = $this->submitted();
        $auditor = $this->reviewer(['verification.audit']);
        $this->actingAs($auditor)->get(route('verification.history', $dossier->id))->assertOk()->assertDontSee('12345678');
        $this->get('/revision-profesional')->assertForbidden();
        $reader = $this->reviewer(['verification.evidence.read', 'verification.decide', 'verification.revoke']);
        $this->actingAs($reader)->get(route('verification.download', ['dossier' => $dossier->id, 'evidence' => $evidence->id]))->assertNotFound();
        $this->postJson(route('verification.approve', $dossier->id), ['version' => str_repeat('a', 64), 'reason' => 'Sin permiso de revisión'])->assertNotFound();
        $reader->givePermissionTo('verification.review');
        $version = $this->version($reader, $dossier->id, true);
        $reader->revokePermissionTo('verification.decide');
        $this->postJson(route('verification.approve', $dossier->id), ['version' => $version, 'reason' => 'Permiso retirado después de abrir'])->assertNotFound();
    }

    public function test_decisions_are_versioned_audited_communicable_and_resolved_requests_cannot_reopen(): void
    {
        [$owner, $profile, $dossier] = $this->submitted();
        $reviewer = $this->reviewer();
        $second = $this->reviewer();
        $version = $this->version($reviewer, $dossier->id, true);
        $otherVersion = $this->version($second, $dossier->id, true);
        $this->assertSame($version, $otherVersion); // Reading does not invalidate an otherwise identical form.
        $this->actingAs($reviewer)->postJson(route('verification.approve', $dossier->id), ['version' => $version, 'reason' => 'Documentación revisada internamente.'])->assertOk();
        $this->actingAs($second)->postJson(route('verification.reject', $dossier->id), ['version' => $otherVersion, 'reason' => 'Decisión concurrente obsoleta.'])->assertConflict();
        $this->assertSame('verified', $profile->fresh()->verification_status->value);
        $this->assertSame(1, VerificationEvent::where('action', 'approved')->count());
        $version = $this->version($reviewer, $dossier->id, true);
        $reason = 'Retiro de aprobación <script>no ejecutar</script>';
        $this->postJson(route('verification.revoke', $dossier->id), ['version' => $version, 'reason' => $reason])->assertOk();
        $this->actingAs($owner)->get(route('verification.show', $dossier->id))->assertOk()->assertSee('&lt;script&gt;no ejecutar&lt;/script&gt;', false)->assertDontSee('<script>no ejecutar</script>', false);
        $this->get(route('verification.history', $dossier->id))->assertOk()->assertSee('Verificación revocada')->assertSee('Solicitud aprobada');
        $this->assertSame('revoked', $profile->fresh()->verification_status->value);
        $this->assertNull($profile->fresh()->verified_at);
        $this->postJson(route('verification.store'))->assertCreated();
        $this->assertSame(2, VerificationRequest::count());
    }

    public function test_reason_validation_cannot_change_a_decision_and_rejection_allows_a_new_dossier(): void
    {
        [$owner, $profile, $dossier] = $this->submitted();
        $reviewer = $this->reviewer();
        $version = $this->version($reviewer, $dossier->id, true);
        foreach (['', 'corto', str_repeat('x', 2001), ['not' => 'text']] as $reason) {
            $this->postJson(route('verification.reject', $dossier->id), ['version' => $version, 'reason' => $reason])->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->assertSame('pending', $dossier->fresh()->status->value);
        $this->postJson(route('verification.reject', $dossier->id), ['version' => $version, 'reason' => 'Se requiere documentación legible.'])->assertOk();
        $this->assertSame('rejected', $profile->fresh()->verification_status->value);
        $this->actingAs($owner)->postJson(route('verification.store'))->assertCreated();
    }

    public function test_profile_edits_invalidate_pending_and_approved_dossiers_and_public_badges_follow_the_domain(): void
    {
        [$owner, $profile, $dossier] = $this->submitted();
        $profiles = app(ProfileService::class);
        $profiles->saveProfessionalProfile($owner, $owner->id, ['visibility' => 'public']);
        $profiles->saveUserProfile($owner, $owner->id, ['visibility' => 'public', 'display_name' => 'Nombre público']);
        foreach (['posts.create', 'posts.update.own'] as $permission) {
            $owner->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $post = app(PostService::class)->create($owner, ['content' => 'Publicación de profesional', 'visibility' => 'public']);
        app(PostService::class)->publish($owner, $post->id, $post->version);
        $reviewer = $this->reviewer();
        $version = $this->version($reviewer, $dossier->id, true);
        $this->postJson(route('verification.approve', $dossier->id), ['version' => $version, 'reason' => 'Revisión interna completada.'])->assertOk();
        foreach (['/profesionales', '/profesionales/'.$owner->id, '/comunidad', '/inicio'] as $url) {
            $this->get($url)->assertOk()->assertSee('Perfil verificado')->assertDontSee('12345678');
        }
        $profiles->saveProfessionalProfile($owner, $owner->id, ['headline' => 'Cambió la presentación']);
        $this->assertSame('invalidated', $dossier->fresh()->status->value);
        foreach (['/profesionales', '/profesionales/'.$owner->id, '/comunidad', '/inicio'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Perfil verificado');
        }
        $this->get(route('verification.review.show', $dossier->id))->assertOk()->assertDontSee('12345678')->assertSee('ya no corresponden');
        $this->postJson(route('verification.approve', $dossier->id), ['version' => $version, 'reason' => 'Intento con versión anterior'])->assertConflict();
        $new = app(VerificationService::class)->createDraft($owner, $profile->id);
        $file = $this->evidenceFile($owner);
        app(VerificationService::class)->addEvidence($owner, $new->id, $file->id, VerificationEvidenceType::ProfessionalLicense);
        app(VerificationService::class)->submit($owner, $new->id);
        $profiles->saveProfessionalProfile($owner, $owner->id, ['headline' => 'Otro cambio pendiente']);
        $this->assertSame('invalidated', $new->fresh()->status->value);
    }

    public function test_revocation_removes_public_badge_and_private_professional_profiles_never_publish_it(): void
    {
        [$owner, $profile, $dossier] = $this->submitted();
        $profiles = app(ProfileService::class);
        $profiles->saveProfessionalProfile($owner, $owner->id, ['visibility' => 'public']);
        $reviewer = $this->reviewer();
        $verification = app(VerificationService::class);
        $verification->approve($reviewer, $dossier->id, 'Revisión interna para probar visibilidad.');
        $this->get('/profesionales')->assertOk()->assertSee('Perfil verificado');
        $this->get('/profesionales/'.$owner->id)->assertOk()->assertSee('Perfil verificado')->assertDontSee('12345678');
        $verification->revoke($reviewer, $dossier->id, 'Retiro de verificación de prueba.');
        $this->get('/profesionales')->assertOk()->assertDontSee('Perfil verificado');
        $this->get('/profesionales/'.$owner->id)->assertOk()->assertSee('Verificación revocada')->assertDontSee('Perfil verificado');
        $draft = $verification->createDraft($owner, $profile->id);
        $file = $this->evidenceFile($owner);
        $verification->addEvidence($owner, $draft->id, $file->id, VerificationEvidenceType::ProfessionalLicense);
        $verification->submit($owner, $draft->id);
        $verification->approve($reviewer, $draft->id, 'Nueva revisión interna para prueba.');
        $profiles->saveProfessionalProfile($owner, $owner->id, ['visibility' => 'private']);
        $this->assertSame('verified', $profile->fresh()->verification_status->value);
        $this->get('/profesionales')->assertOk()->assertDontSee('Perfil verificado');
        $this->get('/profesionales/'.$owner->id)->assertNotFound()->assertDontSee('12345678');
    }

    public function test_inactive_owner_cannot_be_approved_but_reviewer_can_reject(): void
    {
        [$owner, $profile, $dossier] = $this->submitted();
        DB::table('users')->where('id', $owner->id)->update(['status' => 'suspended']);
        $reviewer = $this->reviewer();
        $version = $this->version($reviewer, $dossier->id, true);
        $this->postJson(route('verification.approve', $dossier->id), ['version' => $version, 'reason' => 'La cuenta está suspendida.'])->assertUnprocessable();
        $this->postJson(route('verification.reject', $dossier->id), ['version' => $version, 'reason' => 'No puede completarse con cuenta inactiva.'])->assertOk();
        $this->actingAs($owner)->getJson('/verificacion')->assertUnauthorized();
    }

    public static function inactiveStatuses(): array
    {
        return [['suspended'], ['disabled'], ['pending_deletion']];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_inactive_reviewer_cannot_use_cached_authorization(string $status): void
    {
        [$owner, $profile, $dossier] = $this->submitted();
        $reviewer = $this->reviewer();
        $version = $this->version($reviewer, $dossier->id, true);
        DB::table('users')->where('id', $reviewer->id)->update(['status' => $status]);
        $this->postJson(route('verification.approve', $dossier->id), ['version' => $version, 'reason' => 'No debe registrarse esta decisión.'])->assertUnauthorized();
        $this->assertSame('pending', $dossier->fresh()->status->value);
    }

    public function test_audit_failure_rolls_back_decision_and_profile_without_exposing_sql(): void
    {
        [$owner, $profile, $dossier] = $this->submitted();
        $reviewer = $this->reviewer();
        $version = $this->version($reviewer, $dossier->id, true);
        Event::listen('eloquent.creating: '.VerificationEvent::class, fn () => throw new QueryException('mysql', 'private_audit_sql', ['private-binding'], new \PDOException('private-diagnostic')));
        $this->postJson(route('verification.approve', $dossier->id), ['version' => $version, 'reason' => 'Auditoría debe ser durable.'])->assertStatus(503)
            ->assertDontSee('private_audit_sql')->assertDontSee('private-binding')->assertDontSee('private-diagnostic');
        $this->assertSame('pending', $dossier->fresh()->status->value);
        $this->assertSame('pending', $profile->fresh()->verification_status->value);
        $this->assertSame(0, VerificationEvent::where('action', 'approved')->count());
    }

    public function test_missing_stored_document_fails_closed_without_logging_a_successful_read(): void
    {
        [$owner, $profile, $dossier, $evidence, $file] = $this->submitted();
        Storage::disk('attachments')->delete($file->path); // Own synthetic object, fake disk only.
        $this->actingAs($owner)->get(route('verification.download', ['dossier' => $dossier->id, 'evidence' => $evidence->id]))->assertStatus(503)->assertDontSee($file->path);
        $this->assertSame(0, VerificationEvent::where('action', 'evidence_read')->count());
        $this->assertNotNull($file->fresh()->verification_retained_at);
    }

    public function test_sql_errors_before_domain_entry_are_generic_even_with_debug_enabled(): void
    {
        [$owner, $profile] = $this->professional();
        $draft = app(VerificationService::class)->createDraft($owner, $profile->id);
        config(['app.debug' => true]);
        Event::listen(QueryExecuted::class, function ($event) {
            if (str_contains($event->sql, 'verification_requests')) {
                throw new QueryException('mysql', 'private_verification_sql', ['secret@example.test'], new \PDOException('secret-diagnostic'));
            }
        });
        $this->actingAs($owner)->get(route('verification.show', $draft->id))->assertStatus(503)->assertDontSee('private_verification_sql')->assertDontSee('secret@example.test');
    }

    public function test_real_csrf_and_upload_rate_limit_protect_mutations_before_filesystem_writes(): void
    {
        [$owner, $profile] = $this->professional();
        $this->actingAs($owner);
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $this->withSession(['_token' => 'verification-csrf']);
        $this->postJson(route('verification.store'))->assertStatus(419);
        $id = $this->postJson(route('verification.store'), ['_token' => 'verification-csrf'])->assertCreated()->json('id');
        $version = $this->version($owner, $id);
        $this->postJson(route('verification.submit', $id), ['version' => $version, '_token' => 'wrong'])->assertStatus(419);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('verification.evidence.store', $id), ['version' => $version, '_token' => 'verification-csrf'])->assertUnprocessable();
        }
        $this->postJson(route('verification.evidence.store', $id), ['version' => $version, '_token' => 'verification-csrf'])->assertStatus(429);
        $this->assertSame(0, Attachment::count());
    }
}
