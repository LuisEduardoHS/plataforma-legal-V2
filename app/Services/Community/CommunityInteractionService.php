<?php

namespace App\Services\Community;

use App\Enums\CommentStatus;
use App\Enums\ReactionType;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class CommunityInteractionService
{
    public function __construct(private CommunityAccess $access) {}

    public function comment(User $actor, int $postId, array $data): Comment
    {
        return $this->access->withPost($actor, $postId, function (User $actor, Post $post) use ($data): Comment {
            Gate::forUser($actor)->authorize('interact', $post);
            if (! $actor->can('comments.create')) {
                throw new AuthorizationException;
            }
            if (array_diff(array_keys($data), ['content', 'parent_id'])) {
                $this->access->invalid('comment', 'La solicitud contiene campos no permitidos.');
            }
            $valid = Validator::make($data, [
                'content' => $this->contentRules(),
                'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ])->validate();
            if (isset($valid['parent_id'])) {
                $parent = $post->comments()->whereKey($valid['parent_id'])->visibleInCommunity()->lockForUpdate()->first();
                if (! $parent || $parent->parent_id !== null) {
                    $this->access->invalid('parent_id', 'Responde únicamente a un comentario principal visible de esta publicación.');
                }
            }
            $comment = new Comment;
            $comment->forceFill($valid + [
                'post_id' => $post->id, 'user_id' => $actor->id, 'status' => CommentStatus::Visible, 'version' => 1,
            ]);
            $comment->content = $this->content($valid['content']);
            $comment->save();

            return $comment->fresh();
        });
    }

    public function editComment(User $actor, int $id, string $content, int $expectedVersion): Comment
    {
        return $this->withComment($actor, $id, function (User $actor, Post $post, Comment $comment) use ($content, $expectedVersion): Comment {
            Gate::forUser($actor)->authorize('interact', $post);
            Gate::forUser($actor)->authorize('update', $comment);
            $this->access->version($comment->version, $expectedVersion);
            Validator::make(['content' => $content], ['content' => $this->contentRules()])->validate();
            $comment->forceFill(['content' => $this->content($content), 'edited_at' => now(), 'version' => $comment->version + 1])->save();

            return $comment->fresh();
        });
    }

    public function deleteComment(User $actor, int $id, int $expectedVersion): void
    {
        $this->withComment($actor, $id, function (User $actor, Post $post, Comment $comment) use ($expectedVersion): void {
            Gate::forUser($actor)->authorize('delete', $comment);
            if ($comment->trashed()) {
                return;
            }
            $this->access->version($comment->version, $expectedVersion);
            $comment->forceFill(['status' => CommentStatus::Deleted, 'deleted_at' => now(), 'version' => $comment->version + 1])->save();
        }, true);
    }

    public function moderateComment(User $actor, int $id, bool $hidden, int $expectedVersion): Comment
    {
        return $this->withComment($actor, $id, function (User $actor, Post $post, Comment $comment) use ($hidden, $expectedVersion): Comment {
            Gate::forUser($actor)->authorize('view', $post);
            Gate::forUser($actor)->authorize('moderate', $comment);
            $this->access->version($comment->version, $expectedVersion);
            $comment->forceFill(['status' => $hidden ? CommentStatus::Hidden : CommentStatus::Visible, 'version' => $comment->version + 1])->save();

            return $comment->fresh();
        });
    }

    public function react(User $actor, int $postId, ReactionType $type = ReactionType::Like): PostReaction
    {
        return $this->access->withPost($actor, $postId, function (User $actor, Post $post) use ($type): PostReaction {
            Gate::forUser($actor)->authorize('interact', $post);
            $reaction = PostReaction::where(['post_id' => $post->id, 'user_id' => $actor->id, 'type' => $type->value])->lockForUpdate()->first();
            if (! $reaction) {
                $reaction = new PostReaction;
                $reaction->forceFill(['post_id' => $post->id, 'user_id' => $actor->id, 'type' => $type])->save();
            }

            return $reaction;
        });
    }

    public function unreact(User $actor, int $postId, ReactionType $type = ReactionType::Like): void
    {
        $this->access->withPost($actor, $postId, function (User $actor, Post $post) use ($type): void {
            Gate::forUser($actor)->authorize('interact', $post);
            PostReaction::where(['post_id' => $post->id, 'user_id' => $actor->id, 'type' => $type->value])->delete();
        });
    }

    public function follow(User $actor, int $targetId): void
    {
        $this->access->withActor($actor, function (User $actor, User $target): void {
            if ($actor->id === $target->id || ! $target->isActive()) {
                $this->access->invalid('follow', 'El destino debe ser otra cuenta activa.');
            }
            $key = ['follower_id' => $actor->id, 'followed_id' => $target->id];
            if (DB::table('user_follows')->where($key)->lockForUpdate()->first() === null) {
                DB::table('user_follows')->insert($key + ['created_at' => now(), 'updated_at' => now()]);
            }
        }, $targetId);
    }

    public function unfollow(User $actor, int $targetId): void
    {
        $this->access->withActor($actor, function (User $actor, User $target): void {
            DB::table('user_follows')->where(['follower_id' => $actor->id, 'followed_id' => $target->id])->delete();
        }, $targetId);
    }

    private function withComment(User $actor, int $id, callable $operation, bool $withDeleted = false): mixed
    {
        $postId = Comment::withTrashed()->whereKey($id)->value('post_id');
        if (! $postId) {
            throw new AuthorizationException;
        }

        return $this->access->withPost($actor, $postId, function (User $actor, Post $post) use ($id, $operation, $withDeleted) {
            $query = $withDeleted ? Comment::withTrashed() : Comment::query();
            $comment = $query->where('post_id', $post->id)->whereKey($id)->lockForUpdate()->first();
            if (! $comment) {
                throw new AuthorizationException;
            }

            return $operation($actor, $post, $comment);
        }, $withDeleted);
    }

    private function contentRules(): array
    {
        return ['required', 'string', 'max:'.config('community.comment_max_length'), 'regex:/\A[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*\z/u'];
    }

    private function content(string $content): string
    {
        $content = preg_replace('/\A[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+\z/u', '', $content);
        if ($content === '') {
            $this->access->invalid('content', 'El comentario no puede estar vacío.');
        }

        return $content;
    }
}
