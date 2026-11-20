<?php

namespace App\Policies;

use App\Models\LexaSidofPublication;
use App\Models\User;

class LexaSidofPublicationPolicy
{
    public const PERMISSION_REVIEW = 'lexa.sources.review';

    public function viewAny(User $actor): bool
    {
        return $actor->isActive() && $actor->can(self::PERMISSION_REVIEW);
    }

    public function review(User $actor, LexaSidofPublication $publication): bool
    {
        return $actor->isActive() && $actor->can(self::PERMISSION_REVIEW);
    }
}
