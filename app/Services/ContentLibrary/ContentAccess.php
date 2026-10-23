<?php

namespace App\Services\ContentLibrary;

use App\Enums\EditorialKind;
use App\Exceptions\ContentLibraryException;
use App\Models\EditorialEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContentAccess
{
    public function viewer(?User $actor): ?User
    {
        if (! $actor) {
            return null;
        }
        $current = User::find($actor->id);
        if (! $current || ! $current->isActive()) {
            throw new AuthorizationException;
        }

        return $current;
    }

    public function actor(User $actor, callable $operation): mixed
    {
        return $this->transaction(function () use ($actor, $operation) {
            $current = User::whereKey($actor->id)->lockForUpdate()->first();
            if (! $current || ! $current->isActive()) {
                throw new AuthorizationException;
            }

            return $operation($current);
        });
    }

    public function entry(EditorialKind $kind, ?User $actor, int $id, callable $operation, bool $withDeleted = false): mixed
    {
        return $this->transaction(function () use ($kind, $actor, $id, $operation, $withDeleted) {
            $model = $kind->model();
            $ownerId = $model::withTrashed()->whereKey($id)->value('user_id');
            $users = User::whereKey(array_filter([$actor?->id, $ownerId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = $actor ? $users->get($actor->id) : null;
            $query = $withDeleted ? $model::withTrashed() : $model::query();
            $entry = $query->whereKey($id)->lockForUpdate()->first();
            if (! $entry || ! $users->has($ownerId) || ($actor && (! $current || ! $current->isActive()))) {
                throw new AuthorizationException;
            }
            $entry->setRelation('author', $users->get($ownerId));

            return $operation($current, $entry);
        });
    }

    public function version(EditorialEntry $entry, int $expected): void
    {
        if ($entry->version !== $expected) {
            $this->invalid('version', 'El contenido cambió. Consulta su versión actual.');
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
            throw new ContentLibraryException;
        }
    }
}
