<?php

namespace App\Enums;

enum EditorialStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Hidden = 'hidden';
    case Archived = 'archived';
    case Deleted = 'deleted';

    public function targets(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Archived, self::Deleted],
            self::Published => [self::Draft, self::Hidden, self::Archived, self::Deleted],
            self::Hidden => [self::Published, self::Deleted],
            self::Archived => [self::Draft, self::Deleted],
            self::Deleted => [],
        };
    }
}
