<?php

namespace App\Policies;

use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\User;

class AttachmentPolicy
{
    public function create(User $user): bool
    {
        return $user->exists && $user->isActive();
    }

    public function view(User $user, Attachment $attachment): bool
    {
        return $this->delete($user, $attachment)
            && $attachment->status === AttachmentStatus::Available
            && $attachment->visibility === 'private';
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $this->create($user) && (int) $user->getKey() === (int) $attachment->user_id;
    }
}
