<?php

namespace App\Models;

use App\Enums\EditorialKind;

class LegalResource extends EditorialEntry
{
    public function kind(): EditorialKind
    {
        return EditorialKind::Resource;
    }
}
