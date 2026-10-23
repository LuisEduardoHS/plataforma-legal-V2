<?php

namespace App\Models;

use App\Enums\EditorialKind;

class Article extends EditorialEntry
{
    public function kind(): EditorialKind
    {
        return EditorialKind::Article;
    }
}
