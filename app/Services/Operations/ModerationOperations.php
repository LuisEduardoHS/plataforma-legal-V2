<?php

namespace App\Services\Operations;

use App\Models\User;
use App\Services\Community\CommunityInteractionService;
use App\Services\Community\PostService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ModerationOperations
{
    public function __construct(private PostService $posts, private CommunityInteractionService $comments,
        private OperationsAccess $access, private OperationsAudit $audit) {}

    public function apply(User $actor, string $type, int $id, bool $hidden, int $version, string $reason)
    {
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'min:10', 'max:2000']])->validate();

        return DB::transaction(function () use ($actor, $type, $id, $hidden, $version, $reason) {
            // Domain services retain their ordered account/resource locks and policies.
            $result = match ($type) {
                'post' => $this->posts->moderate($actor, $id, $hidden, $version),
                'comment' => $this->comments->moderateComment($actor, $id, $hidden, $version),
                default => throw new \LogicException('Recurso de moderación no admitido.'),
            };
            $current = User::whereKey($actor->id)->sharedLock()->firstOrFail();
            abort_unless($current->isActive() && in_array($type === 'post' ? 'posts.moderate' : 'comments.moderate', $this->access->effective($current), true), 403);
            $this->audit->record($actor->id, $result->user_id, 'content_moderated', $type, $id,
                ['version' => $version], ['version' => $result->version, 'status' => $result->status->value], trim($reason));

            return $result;
        });
    }
}
