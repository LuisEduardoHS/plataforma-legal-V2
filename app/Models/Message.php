<?php

namespace App\Models;

use App\Enums\MessageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Message extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['sender', 'attachments', 'client_token', 'request_hash'];

    protected function casts(): array
    {
        return ['status' => MessageStatus::class, 'version' => 'integer', 'sent_at' => 'datetime',
            'edited_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, 'message_attachments')->withPivot('position')->withTimestamps()->orderByPivot('position');
    }
}
