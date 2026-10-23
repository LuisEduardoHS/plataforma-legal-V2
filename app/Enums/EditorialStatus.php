<?php

namespace App\Enums;

enum EditorialStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Hidden = 'hidden';
    case Archived = 'archived';
    case Deleted = 'deleted';
}
