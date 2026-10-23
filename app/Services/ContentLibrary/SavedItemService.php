<?php

namespace App\Services\ContentLibrary;

use App\Enums\EditorialKind;
use App\Enums\SavedContentType;
use App\Models\SavedItem;
use App\Models\User;
use App\Services\Community\CommunityAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class SavedItemService
{
    public function __construct(private ContentAccess $access, private CommunityAccess $community) {}

    public function save(User $actor, SavedContentType $type, int $id): SavedItem
    {
        $save = function (User $actor, $target) use ($type) {
            Gate::forUser($actor)->authorize('create', SavedItem::class);
            Gate::forUser($actor)->authorize('view', $target);
            if ($target->status->value !== 'published' || ! $target->author->isActive()) {
                throw new AuthorizationException;
            }
            $key = ['user_id' => $actor->id, $type->key() => $target->id];
            $saved = SavedItem::where($key)->lockForUpdate()->first();
            if (! $saved) {
                $saved = new SavedItem;
                $saved->forceFill($key)->save();
            }

            return $saved;
        };

        return $type === SavedContentType::Post ? $this->community->withPost($actor, $id, $save)
            : $this->access->entry($type === SavedContentType::Article ? EditorialKind::Article : EditorialKind::Resource, $actor, $id, $save);
    }

    public function remove(User $actor, SavedContentType $type, int $id): void
    {
        $this->access->actor($actor, function (User $actor) use ($type, $id) {
            $saved = SavedItem::where('user_id', $actor->id)->where($type->key(), $id)->lockForUpdate()->first();
            if ($saved) {
                Gate::forUser($actor)->authorize('delete', $saved);
                $saved->delete();
            }
        });
    }
}
