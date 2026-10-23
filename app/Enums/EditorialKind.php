<?php

namespace App\Enums;

use App\Models\Article;
use App\Models\LegalResource;

enum EditorialKind: string
{
    case Article = 'article';
    case Resource = 'resource';

    public function model(): string
    {
        return $this === self::Article ? Article::class : LegalResource::class;
    }

    public function key(): string
    {
        return $this === self::Article ? 'article_id' : 'legal_resource_id';
    }

    public function permission(): string
    {
        return $this === self::Article ? 'articles' : 'resources';
    }
}
