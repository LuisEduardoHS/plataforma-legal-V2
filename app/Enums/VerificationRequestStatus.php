<?php

namespace App\Enums;

enum VerificationRequestStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
    case Invalidated = 'invalidated';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Pending],
            self::Pending => [self::Approved, self::Rejected, self::Invalidated],
            self::Approved => [self::Revoked, self::Invalidated],
            default => [],
        }, true);
    }
}
