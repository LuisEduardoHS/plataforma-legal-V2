<?php

namespace App\Policies;

use App\Enums\PostStatus;
use App\Enums\PostVisibility;
use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function create(User $actor): bool
    {
        return $actor->isActive() && $actor->can('posts.create');
    }

    public function view(?User $actor, Post $post): bool
    {
        if ($post->trashed() || ($actor !== null && ! $actor->isActive())) {
            return false;
        }
        if ($post->author->isActive() && $post->status === PostStatus::Published && $post->visibility === PostVisibility::Public) {
            return true;
        }

        return $actor !== null && ((int) $actor->id === (int) $post->user_id || $actor->can('posts.moderate'));
    }

    public function update(User $actor, Post $post): bool
    {
        return $actor->isActive() && ! $post->trashed() && (
            ((int) $actor->id === (int) $post->user_id && $actor->can('posts.update.own'))
            || $actor->can('posts.moderate')
        );
    }

    public function delete(User $actor, Post $post): bool
    {
        return $actor->isActive() && (
            ((int) $actor->id === (int) $post->user_id && $actor->can('posts.delete.own'))
            || $actor->can('posts.moderate')
        );
    }

    public function moderate(User $actor, Post $post): bool
    {
        return $actor->isActive() && ! $post->trashed() && $actor->can('posts.moderate');
    }

    public function interact(User $actor, Post $post): bool
    {
        return $post->status === PostStatus::Published && $post->author->isActive() && $this->view($actor, $post);
    }
}
