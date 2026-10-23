<?php

namespace App\Models;

use App\Enums\VerificationEvidenceType;
use App\Enums\VerificationRequestStatus;
use App\Exceptions\VerificationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationEvidence extends Model
{
    protected $table = 'verification_evidences';

    protected $guarded = ['*'];

    protected $hidden = ['content_sha256', 'attachment_fingerprint', 'attachment', 'request'];

    protected function performUpdate(Builder $query)
    {
        // Corrections replace a draft evidence through the domain service.
        throw new VerificationException;
    }

    protected function performDeleteOnModel()
    {
        $status = VerificationRequest::whereKey($this->getRawOriginal('verification_request_id'))->value('status');
        if ($status !== VerificationRequestStatus::Draft) {
            throw new VerificationException;
        }

        parent::performDeleteOnModel();
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new VerificationException;
    }

    protected function casts(): array
    {
        return ['type' => VerificationEvidenceType::class, 'size_bytes' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(VerificationRequest::class, 'verification_request_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
