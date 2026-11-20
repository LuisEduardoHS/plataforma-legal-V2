<?php

namespace App\Services\Lexa;

use App\Models\LexaConversation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LexaAccess
{
    public function viewer(User $actor): User
    {
        $current = User::whereKey($actor->id)->sharedLock()->first();
        if (! $current || ! $current->isActive()) {
            throw new AuthorizationException;
        }

        return $current;
    }

    public function ownedConversation(User $actor, int $id, callable $operation, bool $lock = false): mixed
    {
        return DB::transaction(function () use ($actor, $id, $operation, $lock) {
            $current = $this->viewer($actor);

            $query = LexaConversation::whereKey($id);
            $conversation = $lock ? $query->lockForUpdate()->first() : $query->sharedLock()->first();

            // Foreign, deleted, and missing conversations must be indistinguishable.
            if (! $conversation || (int) $conversation->user_id !== (int) $current->id || $conversation->status === 'deleted' || $conversation->deleted_at !== null) {
                throw new NotFoundHttpException;
            }

            Gate::forUser($current)->authorize('view', $conversation);

            return $operation($current, $conversation);
        });
    }
}
