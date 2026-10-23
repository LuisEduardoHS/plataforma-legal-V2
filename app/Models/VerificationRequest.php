<?php

namespace App\Models;

use App\Enums\VerificationRequestStatus;
use App\Exceptions\VerificationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VerificationRequest extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['active_profile_id', 'profile_fingerprint', 'evidence_fingerprint', 'profile', 'evidences', 'events', 'reviewer'];

    protected function casts(): array
    {
        return ['status' => VerificationRequestStatus::class, 'submitted_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    protected function performUpdate(Builder $query)
    {
        $previous = VerificationRequestStatus::from($this->getRawOriginal('status'));
        if ($this->isDirty('professional_profile_id')
            || ($previous !== $this->status && ! $previous->canTransitionTo($this->status))
            || ($previous === $this->status && $this->isDirty([
                'status', 'submitted_at', 'resolved_at', 'reviewed_by', 'reason', 'profile_fingerprint', 'evidence_fingerprint',
            ]))
            || ($previous !== VerificationRequestStatus::Draft && $this->isDirty([
                'submitted_at', 'profile_fingerprint', 'evidence_fingerprint',
            ]))) {
            throw new VerificationException;
        }

        return parent::performUpdate($query);
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new VerificationException;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProfessionalProfile::class, 'professional_profile_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(VerificationEvidence::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(VerificationEvent::class);
    }
}
