<?php

namespace App\Policies;

use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    public function update(User $actor, Message $message): bool
    {
        return $actor->isActive() && (int) $message->sender_id === (int) $actor->id && $message->status === MessageStatus::Sent;
    }

    public function delete(User $actor, Message $message): bool
    {
        return $actor->isActive() && (int) $message->sender_id === (int) $actor->id;
    }
}
