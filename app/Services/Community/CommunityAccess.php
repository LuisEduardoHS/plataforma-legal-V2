<?php

namespace App\Services\Community;

use App\Exceptions\CommunityException;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommunityAccess
{
    public function viewer(?User $actor): ?User
    {
        if ($actor === null) {
            return null;
        }
        $current = User::find($actor->id);
        if (! $current || ! $current->isActive()) {
            throw new AuthorizationException;
        }

        return $current;
    }

    public function withActor(User $actor, callable $operation, ?int $otherId = null): mixed
    {
        return $this->transaction(function () use ($actor, $operation, $otherId) {
            $users = User::whereKey(array_filter([$actor->id, $otherId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = $users->get($actor->id);
            if (! $current || ! $current->isActive() || ($otherId !== null && ! $users->has($otherId))) {
                throw new AuthorizationException;
            }

            return $operation($current, $otherId === null ? null : $users->get($otherId));
        });
    }

    public function withPost(?User $actor, int $id, callable $operation, bool $withDeleted = false): mixed
    {
        return $this->transaction(function () use ($actor, $id, $operation, $withDeleted) {
            $ownerId = Post::withTrashed()->whereKey($id)->value('user_id');
            $users = User::whereKey(array_filter([$actor?->id, $ownerId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = $actor === null ? null : $users->get($actor->id);
            $query = $withDeleted ? Post::withTrashed() : Post::query();
            $post = $query->whereKey($id)->lockForUpdate()->first();
            if (! $post || ! $users->has($ownerId) || ($actor !== null && (! $current || ! $current->isActive()))) {
                throw new AuthorizationException;
            }
            $post->setRelation('author', $users->get($ownerId));

            return $operation($current, $post);
        });
    }

    public function version(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            $this->invalid('version', 'El contenido cambió. Consulta su versión actual antes de editar.');
        }
    }

    public function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function transaction(callable $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $error) {
            report($error);
            throw new CommunityException;
        }
    }
}
