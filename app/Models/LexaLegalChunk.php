<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LexaLegalChunk extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'lexa_legal_source_id' => 'integer',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LexaLegalSource::class, 'lexa_legal_source_id');
    }
}
