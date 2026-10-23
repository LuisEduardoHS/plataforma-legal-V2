<?php

namespace Tests\Feature;

use App\Enums\AttachmentStatus;
use App\Enums\ProfessionalVerificationStatus as ProfileStatus;
use App\Enums\VerificationEvidenceType;
use App\Enums\VerificationRequestStatus as Status;
use App\Exceptions\AttachmentException;
use App\Exceptions\VerificationException;
use App\Models\Attachment;
use App\Models\LegalSpecialty;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Attachments\AttachmentService;
use App\Services\Profiles\ProfileService;
use App\Services\Verification\VerificationService;
use Database\Seeders\VerificationPermissionSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

class VerificationConcurrencyTest extends TestCase
{
    use VerificationFixtures;

    public function test_upload_cleanup_cannot_delete_a_committed_file_retained_before_response_failure(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('plataforma_legal_v2_test', DB::connection()->getDatabaseName());
        $this->assertSame('plataforma_legal_v2_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $disk = Storage::fake('attachments');
        $owner = null;
        $temporary = null;
        $failed = false;
        try {
            $owner = User::factory()->create()->refresh();
            $temporary = tempnam(sys_get_temp_dir(), 'back04-review-');
            file_put_contents($temporary, 'Documento de prueba para fallo posterior al commit.');
            Attachment::retrieved(function (Attachment $file) use (&$failed): void {
                if (! $failed && $file->status === AttachmentStatus::Available) {
                    $failed = true;
                    DB::table('attachments')->where('id', $file->id)->update(['verification_retained_at' => now()]);
                    throw new \RuntimeException('Injected failure after committing an available upload.');
                }
            });
            try {
                app(AttachmentService::class)->store($owner, new UploadedFile($temporary, 'prueba.txt', 'text/plain', UPLOAD_ERR_OK, true));
                $this->fail('Se esperaba un fallo posterior al commit.');
            } catch (AttachmentException) {
                $this->assertTrue($failed);
                $file = Attachment::where('user_id', $owner->id)->firstOrFail();
                $this->assertSame(AttachmentStatus::Available, $file->status);
                $this->assertNotNull($file->verification_retained_at);
                $disk->assertExists($file->path);
            }
        } finally {
            Event::forget('eloquent.retrieved: '.Attachment::class);
            if ($owner !== null) {
                DB::table('attachments')->where('user_id', $owner->id)->delete();
                DB::table('users')->where('id', $owner->id)->delete();
            }
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
            $disk->delete($disk->allFiles());
        }
    }

    public function test_independent_mysql_connections_serialize_competing_decisions_and_reject_duplicate_active_requests(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('plataforma_legal_v2_test', DB::connection()->getDatabaseName());
        $this->assertSame('plataforma_legal_v2_test', DB::selectOne('SELECT DATABASE() AS db')->db);

        // These fixtures must be committed so the second connection can see them.
        // Cleanup is selective and runs even when a test assertion fails.
        $default = DB::getDefaultConnection();
        $db = DB::connection($default);
        $disk = Storage::fake('attachments');
        $userIds = [];
        $profileId = null;
        $specialtyIds = [];
        $requestIds = [];
        $existingPermissionIds = DB::table('permissions')->pluck('id')->all();
        $newPermissionIds = [];
        try {
            $this->seed(VerificationPermissionSeeder::class);
            $newPermissionIds = DB::table('permissions')->whereNotIn('id', $existingPermissionIds)->pluck('id')->all();
            $owner = User::factory()->create()->refresh();
            $userIds[] = $owner->id;
            $specialty = LegalSpecialty::create(['name' => 'Especialidad concurrente', 'slug' => fake()->uuid()]);
            $specialtyIds[] = $specialty->id;
            $profile = app(ProfileService::class)->saveProfessionalProfile($owner, $owner->id, [
                'headline' => 'Perfil concurrente', 'license_number' => '12345678', 'specialty_ids' => [$specialty->id],
            ]);
            $profileId = $profile->id;
            $request = app(VerificationService::class)->createDraft($owner, $profile->id);
            $requestIds[] = $request->id;
            $attachment = $this->evidenceFile($owner);
            app(VerificationService::class)->addEvidence($owner, $request->id, $attachment->id, VerificationEvidenceType::ProfessionalLicense);
            app(VerificationService::class)->submit($owner, $request->id);
            $a = User::factory()->create()->refresh();
            $userIds[] = $a->id;
            $a->givePermissionTo(['verification.review', 'verification.decide']);
            $b = User::factory()->create()->refresh();
            $userIds[] = $b->id;
            $b->givePermissionTo(['verification.review', 'verification.decide']);

            config(['database.connections.verification_race' => config('database.connections.'.$default)]);
            $other = DB::connection('verification_race');
            $this->assertSame('plataforma_legal_v2_test', $other->selectOne('SELECT DATABASE() AS db')->db);
            $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $reportedCode = null;
            app(ExceptionHandler::class)->reportable(function (QueryException $error) use (&$reportedCode): void {
                $reportedCode = (int) ($error->errorInfo[1] ?? 0);
            });

            $db->beginTransaction();
            app(VerificationService::class)->approve($a, $request->id, 'Primera decisión interna documentada.');
            DB::setDefaultConnection('verification_race');
            try {
                app(VerificationService::class)->reject($b, $request->id, 'Segunda decisión que debe quedar bloqueada.');
                $this->fail('La segunda conexión ignoró el bloqueo de la primera.');
            } catch (VerificationException) {
                $this->assertSame(1205, $reportedCode);
                $this->assertSame(0, $other->transactionLevel());
            } finally {
                DB::setDefaultConnection($default);
            }
            $db->commit();

            DB::setDefaultConnection('verification_race');
            try {
                app(VerificationService::class)->reject($b, $request->id, 'Decisión obsoleta después de liberar el bloqueo.');
                $this->fail('La decisión obsoleta sobrescribió la aprobación.');
            } catch (ValidationException) {
                $this->assertSame(Status::Approved, VerificationRequest::findOrFail($request->id)->status);
            } finally {
                DB::setDefaultConnection($default);
            }

            try {
                $other->table('verification_requests')->insert(['professional_profile_id' => $profile->id]);
                $this->fail('La segunda conexión duplicó el expediente activo.');
            } catch (QueryException $error) {
                $this->assertSame(1062, (int) $error->errorInfo[1]);
            }
            $this->assertSame(ProfileStatus::Verified, $profile->fresh()->verification_status);
            $this->assertSame(1, $request->events()->where('action', 'approved')->count());
            $this->assertSame(0, $request->events()->where('action', 'rejected')->count());
        } finally {
            DB::setDefaultConnection($default);
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            DB::disconnect('verification_race');
            if ($requestIds !== []) {
                DB::table('verification_events')->whereIn('verification_request_id', $requestIds)->delete();
                DB::table('verification_evidences')->whereIn('verification_request_id', $requestIds)->delete();
                DB::table('verification_requests')->whereIn('id', $requestIds)->delete();
            }
            if ($profileId !== null) {
                DB::table('professional_profile_specialties')->where('professional_profile_id', $profileId)->delete();
                DB::table('professional_profiles')->where('id', $profileId)->delete();
            }
            if ($userIds !== []) {
                DB::table('attachments')->whereIn('user_id', $userIds)->delete();
                DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
                DB::table('users')->whereIn('id', $userIds)->delete();
            }
            DB::table('legal_specialties')->whereIn('id', $specialtyIds)->delete();
            DB::table('permissions')->whereIn('id', $newPermissionIds)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $disk->delete($disk->allFiles());
        }
    }
}
