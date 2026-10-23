<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function create(User $actor, User $target): bool
    {
        return $actor->isActive() && $target->isActive() && $actor->id !== $target->id;
    }

    public function view(User $actor, Conversation $conversation): bool
    {
        return $actor->isActive() && $conversation->contains($actor)
            && $conversation->participants()->where('user_id', $actor->id)->exists();
    }

    public function send(User $actor, Conversation $conversation): bool
    {
        return $this->view($actor, $conversation);
    }

    public function manage(User $actor, Conversation $conversation): bool
    {
        return $this->view($actor, $conversation);
    }
}
