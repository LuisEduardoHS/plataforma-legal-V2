<?php

namespace App\Policies;

use App\Models\SavedItem;
use App\Models\User;

class SavedItemPolicy
{
    public function create(User $actor): bool
    {
        return $actor->isActive();
    }

    public function view(User $actor, SavedItem $item): bool
    {
        return $actor->isActive() && (int) $actor->id === (int) $item->user_id;
    }

    public function delete(User $actor, SavedItem $item): bool
    {
        return $this->view($actor, $item);
    }
}
