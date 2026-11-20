<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LexaMessage extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'lexa_conversation_id' => 'integer',
            'user_id' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'latency_ms' => 'integer',
            'citations_json' => 'array',
            'grounding_json' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(LexaConversation::class, 'lexa_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
