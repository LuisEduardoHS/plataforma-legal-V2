<?php

namespace App\Policies;

use App\Enums\EditorialStatus;
use App\Models\EditorialEntry;
use App\Models\User;

abstract class EditorialPolicy
{
    abstract protected function prefix(): string;

    public function create(User $actor): bool
    {
        return $actor->isActive() && ($actor->can($this->prefix().'.create') || $actor->can($this->prefix().'.manage'));
    }

    public function view(?User $actor, EditorialEntry $entry): bool
    {
        if ($entry->trashed() || ($actor && ! $actor->isActive())) {
            return false;
        }

        return ($entry->status === EditorialStatus::Published && $entry->author->isActive())
            || ($actor && ((int) $actor->id === (int) $entry->user_id || $actor->can($this->prefix().'.manage')));
    }

    public function update(User $actor, EditorialEntry $entry): bool
    {
        return ! $entry->trashed() && $this->audit($actor, $entry);
    }

    public function audit(User $actor, EditorialEntry $entry): bool
    {
        return $actor->isActive() && ($actor->can($this->prefix().'.manage')
            || ((int) $actor->id === (int) $entry->user_id && $actor->can($this->prefix().'.update.own')));
    }

    public function publish(User $actor, EditorialEntry $entry): bool
    {
        return $this->update($actor, $entry) && ($actor->can($this->prefix().'.manage')
            || ((int) $actor->id === (int) $entry->user_id && $actor->can($this->prefix().'.publish.own')));
    }

    public function moderate(User $actor, EditorialEntry $entry): bool
    {
        return $actor->isActive() && ! $entry->trashed() && $actor->can($this->prefix().'.manage');
    }

    public function delete(User $actor, EditorialEntry $entry): bool
    {
        return $this->audit($actor, $entry);
    }
}
