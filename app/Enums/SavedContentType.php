<?php

namespace App\Enums;

use App\Models\Article;
use App\Models\LegalResource;
use App\Models\Post;

enum SavedContentType: string
{
    case Post = 'post';
    case Article = 'article';
    case Resource = 'resource';

    public function key(): string
    {
        return match ($this) {
            self::Post => 'post_id', self::Article => 'article_id', self::Resource => 'legal_resource_id'
        };
    }

    public function model(): string
    {
        return match ($this) {
            self::Post => Post::class, self::Article => Article::class, self::Resource => LegalResource::class
        };
    }
}
