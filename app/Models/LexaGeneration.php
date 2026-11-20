<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LexaGeneration extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'lexa_conversation_id' => 'integer',
            'user_id' => 'integer',
            'user_message_id' => 'integer',
            'assistant_message_id' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'latency_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(LexaConversation::class, 'lexa_conversation_id');
    }

    public function userMessage(): BelongsTo
    {
        return $this->belongsTo(LexaMessage::class, 'user_message_id');
    }

    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(LexaMessage::class, 'assistant_message_id');
    }
}
