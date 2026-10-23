<?php

namespace App\Enums;

enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Hidden = 'hidden';
    case Deleted = 'deleted';
}
