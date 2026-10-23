<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VerificationRequest;

class VerificationRequestPolicy
{
    public function view(User $actor, VerificationRequest $request): bool
    {
        return $actor->isActive() && (
            (int) $actor->id === (int) $request->profile->user_id || $this->review($actor, $request)
        );
    }

    public function review(User $actor, VerificationRequest $request): bool
    {
        return $actor->isActive() && (int) $actor->id !== (int) $request->profile->user_id
            && $request->submitted_at !== null && $actor->can('verification.review');
    }

    public function decide(User $actor, VerificationRequest $request): bool
    {
        return $this->review($actor, $request) && $actor->can('verification.decide');
    }

    public function revoke(User $actor, VerificationRequest $request): bool
    {
        return $this->review($actor, $request) && $actor->can('verification.revoke');
    }

    public function readEvidence(User $actor, VerificationRequest $request): bool
    {
        return $actor->isActive() && (
            (int) $actor->id === (int) $request->profile->user_id
            || ($this->review($actor, $request) && $actor->can('verification.evidence.read'))
        );
    }

    public function audit(User $actor, VerificationRequest $request): bool
    {
        return $actor->isActive() && (
            (int) $actor->id === (int) $request->profile->user_id || $actor->can('verification.audit')
        );
    }
}
