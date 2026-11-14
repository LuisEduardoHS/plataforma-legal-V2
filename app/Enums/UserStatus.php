<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Disabled = 'disabled';
    case PendingDeletion = 'pending_deletion';

    public function canTransitionTo(self $next): bool
    {
        return $this === $next || in_array($next, match ($this) {
            self::Active => [self::Suspended, self::Disabled, self::PendingDeletion],
            self::Suspended => [self::Active, self::Disabled, self::PendingDeletion],
            self::Disabled => [self::Active, self::PendingDeletion],
            self::PendingDeletion => [],
        }, true);
    }
}
