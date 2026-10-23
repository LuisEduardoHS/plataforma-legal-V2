<?php

namespace App\Services\Verification;

use App\Enums\AttachmentStatus;
use App\Exceptions\VerificationException;
use App\Models\Attachment;
use App\Models\ProfessionalProfile;
use App\Models\VerificationEvidence;
use App\Models\VerificationRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class VerificationIntegrity
{
    public const MATERIAL_FIELDS = ['headline', 'description', 'license_number', 'years_experience', 'municipality_id', 'attention_mode'];

    public function profileFingerprint(ProfessionalProfile $profile): string
    {
        $specialties = $profile->specialties()->orderBy('legal_specialties.id');
        if ($profile->getConnection()->transactionLevel() > 0) {
            $specialties->lockForUpdate();
        }

        return $this->fingerprint([
            'id' => $profile->id,
            'data' => $profile->only(self::MATERIAL_FIELDS),
            'specialties' => $specialties->pluck('legal_specialties.id')->all(),
        ]);
    }

    public function attachmentFingerprint(Attachment $attachment): string
    {
        return $this->fingerprint($attachment->only([
            'id', 'user_id', 'original_name', 'disk', 'path', 'storage_name', 'category',
            'visibility', 'extension', 'mime_type', 'sha256', 'size_bytes',
        ]));
    }

    public function evidenceFingerprint(VerificationRequest $request): string
    {
        $evidences = $request->evidences()->orderBy('id');
        if ($request->getConnection()->transactionLevel() > 0) {
            $evidences->lockForUpdate();
        }

        return $this->fingerprint([
            'request_id' => $request->id,
            'evidences' => $evidences->get([
                'id', 'attachment_id', 'type', 'content_sha256', 'size_bytes', 'mime_type', 'attachment_fingerprint',
            ])->map(fn ($evidence) => $evidence->getAttributes())->all(),
        ]);
    }

    /** @return resource */
    public function verifiedStream(Attachment $attachment, ?VerificationEvidence $evidence = null)
    {
        $source = null;
        $buffer = null;
        try {
            $settings = config('filesystems.disks.'.$attachment->disk, []);
            if ($attachment->status !== AttachmentStatus::Available || $attachment->visibility !== 'private'
                || $attachment->community_published_at !== null
                || ! in_array($attachment->category, ['image', 'document'], true)
                || $attachment->size_bytes < 1 || $attachment->size_bytes > config('verification.max_evidence_bytes')
                || ($settings['visibility'] ?? null) !== 'private' || ($settings['serve'] ?? false)
                || isset($settings['url']) || ! in_array($settings['driver'] ?? null, ['local', 's3'], true)) {
                $this->changed();
            }
            if ($evidence && (! hash_equals($evidence->attachment_fingerprint, $this->attachmentFingerprint($attachment))
                || ! hash_equals($evidence->content_sha256, $attachment->sha256)
                || $evidence->size_bytes !== $attachment->size_bytes || $evidence->mime_type !== $attachment->mime_type)) {
                $this->changed();
            }
            $source = Storage::disk($attachment->disk)->readStream($attachment->path);
            $buffer = fopen('php://temp/maxmemory:2097152', 'w+b');
            if (! is_resource($source) || ! is_resource($buffer)) {
                throw new VerificationException;
            }
            $bytes = stream_copy_to_stream($source, $buffer, config('verification.max_evidence_bytes') + 1);
            if ($bytes !== $attachment->size_bytes || ! rewind($buffer)) {
                $this->changed();
            }
            $hash = hash_init('sha256');
            hash_update_stream($hash, $buffer);
            if (! hash_equals($attachment->sha256, hash_final($hash)) || ! rewind($buffer)) {
                $this->changed();
            }

            return $buffer;
        } catch (Throwable $error) {
            if (is_resource($buffer)) {
                fclose($buffer);
            }
            if ($error instanceof ValidationException || $error instanceof VerificationException) {
                throw $error;
            }
            report($error);
            throw new VerificationException;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    private function fingerprint(array $data): string
    {
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function changed(): never
    {
        throw ValidationException::withMessages(['evidence' => 'La evidencia no está disponible o su integridad cambió.']);
    }
}
