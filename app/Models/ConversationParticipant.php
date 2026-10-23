<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationParticipant extends Model
{
    // Composite keys are updated explicitly by domain services, never Model::save().
    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = ['*'];

    protected $hidden = ['conversation', 'user'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'hidden_at' => 'datetime', 'last_read_at' => 'datetime',
            'last_read_message_id' => 'integer', 'hidden_through_message_id' => 'integer'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
