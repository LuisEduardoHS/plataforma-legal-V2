<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LexaSidofPublication extends Model
{
    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_RELEVANT = 'relevant';

    public const STATUS_NOT_RELEVANT = 'not_relevant';

    public const STATUS_REQUIRES_LEGAL_ANALYSIS = 'requires_legal_analysis';

    /** @var array<int, string> */
    public const REVIEW_STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_RELEVANT,
        self::STATUS_NOT_RELEVANT,
        self::STATUS_REQUIRES_LEGAL_ANALYSIS,
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'cod_nota' => 'integer',
            'cod_diario' => 'integer',
            'published_at' => 'date',
            'matched_instrument_keys' => 'array',
            'reviewed_by_user_id' => 'integer',
            'reviewed_at' => 'datetime',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'detection_count' => 'integer',
            'confirmed_reform' => 'boolean',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function reviewEvents(): HasMany
    {
        return $this->hasMany(LexaSidofPublicationReview::class, 'lexa_sidof_publication_id');
    }
}
