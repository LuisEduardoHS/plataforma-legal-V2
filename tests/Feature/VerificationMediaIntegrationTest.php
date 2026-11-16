<?php

namespace Tests\Feature;

use App\Enums\VerificationEvidenceType;
use App\Models\Attachment;
use App\Models\User;
use App\Models\VerificationEvent;
use App\Services\Verification\VerificationService;
use Database\Seeders\VerificationPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

class VerificationMediaIntegrationTest extends TestCase
{
    use VerificationFixtures {
        professional as private fixtureProfessional;
        reviewer as private fixtureReviewer;
    }

    private array $userIds = [];

    private array $specialtyIds = [];

    private array $permissionIds = [];

    protected function setUp(): void
    {
        parent::setUp(); // No exterior transaction: BACK-02 reservations must commit.
        Storage::fake('attachments');
        $existing = DB::table('permissions')->pluck('id')->all();
        $this->seed(VerificationPermissionSeeder::class);
        $this->permissionIds = DB::table('permissions')->whereNotIn('id', $existing)->pluck('id')->all();
        Event::listen('eloquent.creating: '.Attachment::class, function () {
            $this->assertSame('plataforma_legal_v2_test', DB::selectOne('SELECT DATABASE() db')->db);
            $root = str_replace('\\', '/', Storage::disk(config('attachments.disk'))->path(''));
            $this->assertStringStartsWith(str_replace('\\', '/', storage_path('framework/testing/disks/attachments')).'/', $root);
        });
    }

    protected function tearDown(): void
    {
        try {
            Event::forget('eloquent.creating: '.Attachment::class);
            Event::forget('eloquent.creating: '.VerificationEvent::class);
            Event::forget('eloquent.retrieved: '.Attachment::class);
            if (config('database.connections.verification_http_rival')) {
                $rival = DB::connection('verification_http_rival');
                while ($rival->transactionLevel() > 0) {
                    $rival->rollBack();
                }
                DB::purge('verification_http_rival');
            }
            $paths = DB::table('attachments')->whereIn('user_id', $this->userIds)->pluck('path');
            DB::transaction(function () {
                $profiles = DB::table('professional_profiles')->whereIn('user_id', $this->userIds)->pluck('id');
                $requests = DB::table('verification_requests')->whereIn('professional_profile_id', $profiles)->pluck('id');
                DB::table('verification_events')->whereIn('verification_request_id', $requests)->delete();
                DB::table('verification_evidences')->whereIn('verification_request_id', $requests)->delete();
                DB::table('verification_requests')->whereIn('id', $requests)->delete();
                DB::table('professional_profile_specialties')->whereIn('professional_profile_id', $profiles)->delete();
                DB::table('professional_profiles')->whereIn('id', $profiles)->delete();
                DB::table('attachments')->whereIn('user_id', $this->userIds)->delete();
                DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $this->userIds)->delete();
                DB::table('users')->whereIn('id', $this->userIds)->delete();
                DB::table('legal_specialties')->whereIn('id', $this->specialtyIds)->delete();
                DB::table('permissions')->whereIn('id', $this->permissionIds)->delete();
            });
            Storage::disk('attachments')->delete($paths->all());
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } finally {
            parent::tearDown();
        }
    }

    protected function professional(): array
    {
        [$owner, $profile] = $this->fixtureProfessional();
        $this->userIds[] = $owner->id;
        $this->specialtyIds = [...$this->specialtyIds, ...$profile->specialties()->pluck('legal_specialties.id')->all()];

        return [$owner, $profile];
    }

    protected function reviewer(?array $permissions = null): User
    {
        $reviewer = $this->fixtureReviewer($permissions);
        $this->userIds[] = $reviewer->id;

        return $reviewer;
    }

    private function draft(): array
    {
        [$owner, $profile] = $this->professional();
        $id = $this->actingAs($owner)->postJson(route('verification.store'))->assertCreated()->json('id');

        return [$owner, $id];
    }

    private function version(User $actor, int $id, bool $review = false): string
    {
        return $this->actingAs($actor)->get(route($review ? 'verification.review.show' : 'verification.show', $id))->assertOk()->viewData('dossier')['version'];
    }

    private function pdf(?string $content = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('evidencia-<script>.pdf', $content ?? "%PDF-1.4\nEvidencia sintética ".Str::uuid()."\n%%EOF");
    }

    private function upload(User $owner, int $id, UploadedFile $file, ?string $version = null)
    {
        return $this->actingAs($owner)->postJson(route('verification.evidence.store', $id), [
            'file' => $file, 'type' => 'professional_license', 'version' => $version ?? $this->version($owner, $id),
        ]);
    }

    public function test_real_http_upload_presentation_review_and_download_keep_evidence_private(): void
    {
        [$owner, $id] = $this->draft();
        $response = $this->upload($owner, $id, $this->pdf())->assertCreated();
        $this->assertSame(['id', 'message', 'redirect'], array_keys($response->json()));
        $file = Attachment::where('user_id', $owner->id)->firstOrFail();
        $this->assertSame('available', $file->status->value);
        $this->assertSame('private', $file->visibility);
        $this->assertNull($file->verification_retained_at);
        $this->get(route('verification.show', $id))->assertOk()->assertSee('evidencia-&lt;script&gt;.pdf', false)
            ->assertDontSee($file->path)->assertDontSee($file->sha256)->assertDontSee($file->storage_name);
        $this->postJson(route('verification.submit', $id), ['version' => $this->version($owner, $id)])->assertOk();
        $this->assertNotNull($file->fresh()->verification_retained_at);
        $reviewer = $this->reviewer();
        $version = $this->version($reviewer, $id, true);
        $evidence = DB::table('verification_evidences')->where('verification_request_id', $id)->value('id');
        $download = $this->get(route('verification.download', ['dossier' => $id, 'evidence' => $evidence]))->assertOk();
        $this->assertSame(Storage::disk('attachments')->get($file->path), $download->streamedContent());
        $this->postJson(route('verification.approve', $id), ['version' => $version, 'reason' => 'Revisión documental humana completada.'])->assertOk();
        $this->assertSame(1, VerificationEvent::where('verification_request_id', $id)->where('action', 'approved')->count());
    }

    public static function badFiles(): array
    {
        return [
            ['fake.png', 'No es una imagen PNG'], ['evidence.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
            ['empty.txt', ''], ['audio.mp3', 'ID3contenido sintético'],
        ];
    }

    #[DataProvider('badFiles')]
    public function test_invalid_uploads_do_not_reserve_objects(string $name, string $content): void
    {
        [$owner, $id] = $this->draft();
        $this->upload($owner, $id, UploadedFile::fake()->createWithContent($name, $content))->assertUnprocessable();
        $this->assertSame(0, Attachment::where('user_id', $owner->id)->count());
        $this->assertSame([], Storage::disk('attachments')->allFiles());
    }

    public function test_oversized_or_foreign_attachment_parameters_are_rejected_before_storage(): void
    {
        [$owner, $id] = $this->draft();
        $this->upload($owner, $id, UploadedFile::fake()->create('large.pdf', 20481, 'application/pdf'))->assertUnprocessable();
        $this->postJson(route('verification.evidence.store', $id), [
            'version' => $this->version($owner, $id), 'type' => 'professional_license', 'file' => $this->pdf(), 'attachment_id' => 999999,
        ])->assertUnprocessable()->assertJsonValidationErrors('form');
        $this->assertSame(0, Attachment::where('user_id', $owner->id)->count());
    }

    public function test_duplicate_document_compensation_keeps_durable_metadata_and_does_not_purge(): void
    {
        [$owner, $id] = $this->draft();
        $content = "%PDF-1.4\nMismo contenido sintético\n%%EOF";
        $this->upload($owner, $id, $this->pdf($content))->assertCreated();
        $this->upload($owner, $id, $this->pdf($content))->assertUnprocessable();
        $files = Attachment::where('user_id', $owner->id)->orderBy('id')->get();
        $this->assertSame(['available', 'pending_deletion'], $files->map(fn ($file) => $file->status->value)->all());
        $this->assertSame(1, DB::table('verification_evidences')->where('verification_request_id', $id)->count());
        foreach ($files as $file) {
            Storage::disk('attachments')->assertExists($file->path);
        }
    }

    public function test_association_audit_failure_compensates_only_new_upload_and_sanitizes_response(): void
    {
        [$owner, $id] = $this->draft();
        Event::listen('eloquent.creating: '.VerificationEvent::class, function ($event) {
            if ($event->action->value === 'evidence_added') {
                throw new QueryException('mysql', 'private_evidence_insert', ['private-binding'], new \PDOException('private storage context'));
            }
        });
        $this->upload($owner, $id, $this->pdf())->assertStatus(503)->assertDontSee('private_evidence_insert')->assertDontSee('private-binding');
        $file = Attachment::where('user_id', $owner->id)->firstOrFail();
        $this->assertSame('pending_deletion', $file->status->value);
        $this->assertSame(0, DB::table('verification_evidences')->where('verification_request_id', $id)->count());
        Storage::disk('attachments')->assertExists($file->path);
    }

    public function test_storage_failure_preserves_reservation_lifecycle_without_a_false_association(): void
    {
        [$owner, $id] = $this->draft();
        $disk = Storage::disk('attachments');
        $failure = Mockery::mock($disk)->makePartial();
        $failure->shouldReceive('put')->once()->andThrow(new \RuntimeException('private storage path /secret'));
        Storage::set('attachments', $failure); // Fault injection; the real service and DB lifecycle still execute.
        $this->upload($owner, $id, $this->pdf())->assertStatus(503)->assertDontSee('/secret');
        Storage::set('attachments', $disk);
        $this->assertSame('deleted', Attachment::where('user_id', $owner->id)->firstOrFail()->status->value);
        $this->assertSame(0, DB::table('verification_evidences')->where('verification_request_id', $id)->count());
    }

    public function test_version_change_during_physical_upload_prevents_stale_association_and_retains_prior_evidence(): void
    {
        [$owner, $id] = $this->draft();
        $existing = $this->evidenceFile($owner);
        app(VerificationService::class)->addEvidence($owner, $id, $existing->id, VerificationEvidenceType::ProfessionalLicense);
        $version = $this->version($owner, $id);
        $armed = true;
        Event::listen('eloquent.retrieved: '.Attachment::class, function ($file) use (&$armed, $owner, $id, $existing) {
            if ($armed && $file->id !== $existing->id && $file->status?->value === 'available') {
                $armed = false;
                app(VerificationService::class)->submit($owner, $id);
            }
        });
        $this->upload($owner, $id, $this->pdf(), $version)->assertConflict();
        $this->assertFalse($armed);
        $this->assertNotNull($existing->fresh()->verification_retained_at);
        $files = Attachment::where('user_id', $owner->id)->orderBy('id')->get();
        $this->assertSame(['available', 'pending_deletion'], $files->map(fn ($file) => $file->status->value)->all());
        $this->assertSame(1, DB::table('verification_evidences')->where('verification_request_id', $id)->count());
        Storage::disk('attachments')->assertExists($existing->path);
    }

    public function test_independent_mysql_lock_contention_returns_safe_failure_then_only_one_http_decision_wins(): void
    {
        [$owner, $profile, $dossier] = $this->submitted();
        $reviewer = $this->reviewer();
        $second = $this->reviewer();
        $version = $this->version($reviewer, $dossier->id, true);
        $config = config('database.connections.mysql');
        $config['database'] = 'plataforma_legal_v2_test';
        $config['url'] = null;
        config(['database.connections.verification_http_rival' => $config]);
        $rival = DB::connection('verification_http_rival');
        $this->assertSame('plataforma_legal_v2_test', $rival->selectOne('SELECT DATABASE() db')->db);
        $this->assertNotSame(DB::selectOne('SELECT CONNECTION_ID() id')->id, $rival->selectOne('SELECT CONNECTION_ID() id')->id);
        $prior = DB::selectOne('SELECT @@innodb_lock_wait_timeout seconds')->seconds;
        try {
            $rival->beginTransaction();
            $rival->table('professional_profiles')->where('id', $profile->id)->lockForUpdate()->first();
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
            $this->postJson(route('verification.approve', $dossier->id), ['version' => $version, 'reason' => 'Competencia real entre conexiones.'])->assertStatus(503)->assertDontSee('SQLSTATE');
        } finally {
            $rival->rollBack();
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) $prior);
        }
        $this->postJson(route('verification.approve', $dossier->id), ['version' => $version, 'reason' => 'Decisión después de liberar el bloqueo.'])->assertOk();
        $this->actingAs($second)->postJson(route('verification.reject', $dossier->id), ['version' => $version, 'reason' => 'Segunda decisión ya obsoleta.'])->assertConflict();
        $this->assertSame('approved', $dossier->fresh()->status->value);
        $this->assertSame(1, VerificationEvent::where('verification_request_id', $dossier->id)->where('action', 'approved')->count());
        $this->assertSame(0, VerificationEvent::where('verification_request_id', $dossier->id)->where('action', 'rejected')->count());
    }
}
