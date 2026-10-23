<?php

namespace App\Policies;

use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Models\UserProfile;

class ProfilePolicy
{
    public function create(User $actor, User $owner): bool
    {
        return $actor->exists && $actor->isActive()
            && ($actor->is($owner) || $actor->can('users.manage'));
    }

    public function view(User $actor, UserProfile|ProfessionalProfile $profile): bool
    {
        return $this->create($actor, $profile->user);
    }

    public function update(User $actor, UserProfile|ProfessionalProfile $profile): bool
    {
        return $this->view($actor, $profile);
    }
}
