<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LexaLegalSource extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'state_id' => 'integer',
            'published_at' => 'date',
            'last_reformed_at' => 'date',
            'consulted_at' => 'date',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_verified' => 'boolean',
            'provenance' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(LexaLegalChunk::class, 'lexa_legal_source_id');
    }
}
