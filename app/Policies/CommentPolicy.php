<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function update(User $actor, Comment $comment): bool
    {
        return $actor->isActive() && ! $comment->trashed()
            && ((int) $actor->id === (int) $comment->user_id || $actor->can('comments.moderate'));
    }

    public function delete(User $actor, Comment $comment): bool
    {
        return $actor->isActive()
            && ((int) $actor->id === (int) $comment->user_id || $actor->can('comments.moderate'));
    }

    public function moderate(User $actor, Comment $comment): bool
    {
        return $actor->isActive() && ! $comment->trashed() && $actor->can('comments.moderate');
    }
}
