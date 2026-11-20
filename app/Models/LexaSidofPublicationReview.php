<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LexaSidofPublicationReview extends Model
{
    protected $guarded = ['*'];

    public function publication(): BelongsTo
    {
        return $this->belongsTo(LexaSidofPublication::class, 'lexa_sidof_publication_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
