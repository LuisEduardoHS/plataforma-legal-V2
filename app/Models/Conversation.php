<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['participants', 'messages'];

    protected function casts(): array
    {
        return ['activity_at' => 'datetime'];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    // Read-only aliases supplied by MessagingQuery, never persisted columns.
    public function latestVisibleMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'latest_message_id');
    }

    public function otherParticipant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'other_user_id');
    }

    public function contains(User $actor): bool
    {
        return in_array((int) $actor->id, [(int) $this->user_low_id, (int) $this->user_high_id], true);
    }
}
