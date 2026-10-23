<?php

namespace App\Policies;

class ArticlePolicy extends EditorialPolicy
{
    protected function prefix(): string
    {
        return 'articles';
    }
}
