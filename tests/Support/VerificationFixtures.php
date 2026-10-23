<?php

namespace Tests\Support;

use App\Enums\AttachmentStatus;
use App\Enums\VerificationEvidenceType;
use App\Models\Attachment;
use App\Models\LegalSpecialty;
use App\Models\User;
use App\Services\Profiles\ProfileService;
use App\Services\Verification\VerificationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait VerificationFixtures
{
    protected function professional(): array
    {
        $owner = User::factory()->create()->refresh();
        $specialty = LegalSpecialty::create(['name' => 'Especialidad de prueba', 'slug' => (string) Str::uuid()]);
        $profile = app(ProfileService::class)->saveProfessionalProfile($owner, $owner->id, [
            'headline' => 'Profesional de prueba', 'license_number' => '12345678', 'specialty_ids' => [$specialty->id],
        ]);

        return [$owner, $profile];
    }

    protected function evidenceFile(User $owner, string $category = 'document', AttachmentStatus $status = AttachmentStatus::Available): Attachment
    {
        $content = "%PDF-1.4\nDocumento de prueba de flujo interno: ".Str::uuid().".\n%%EOF";
        $name = Str::uuid().'.pdf';
        $file = new Attachment;
        $file->forceFill([
            'user_id' => $owner->id, 'original_name' => 'evidencia.pdf', 'storage_name' => $name,
            'path' => 'attachments/'.$name, 'disk' => 'attachments', 'mime_type' => 'application/pdf',
            'extension' => 'pdf', 'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content),
            'category' => $category, 'visibility' => 'private', 'status' => $status,
        ])->save();
        Storage::disk('attachments')->put($file->path, $content, ['visibility' => 'private']);

        return $file;
    }

    protected function reviewer(?array $permissions = null): User
    {
        $reviewer = User::factory()->create()->refresh();
        $permissions ??= ['verification.review', 'verification.evidence.read', 'verification.decide', 'verification.revoke', 'verification.audit'];
        if ($permissions !== []) {
            $reviewer->givePermissionTo($permissions);
        }

        return $reviewer;
    }

    protected function submitted(): array
    {
        [$owner, $profile] = $this->professional();
        $service = app(VerificationService::class);
        $request = $service->createDraft($owner, $profile->id);
        $attachment = $this->evidenceFile($owner);
        $evidence = $service->addEvidence($owner, $request->id, $attachment->id, VerificationEvidenceType::ProfessionalLicense);
        $service->submit($owner, $request->id);

        return [$owner, $profile, $request->fresh(), $evidence, $attachment];
    }
}
