<?php

namespace App\Policies;

use App\Models\LexaConversation;
use App\Models\User;

class LexaConversationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActive();
    }

    public function create(User $actor): bool
    {
        return $actor->isActive();
    }

    public function view(User $actor, LexaConversation $conversation): bool
    {
        return $actor->isActive()
            && (int) $conversation->user_id === (int) $actor->id
            && $conversation->status !== 'deleted'
            && $conversation->deleted_at === null;
    }

    public function update(User $actor, LexaConversation $conversation): bool
    {
        return $this->view($actor, $conversation);
    }

    public function delete(User $actor, LexaConversation $conversation): bool
    {
        return $this->view($actor, $conversation);
    }
}
