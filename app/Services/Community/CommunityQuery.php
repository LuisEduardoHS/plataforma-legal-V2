<?php

namespace App\Services\Community;

use App\Enums\PostStatus;
use App\Enums\PostVisibility;
use App\Enums\ProfileVisibility;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CommunityQuery
{
    public function __construct(private CommunityAccess $access) {}

    public function recent(?User $viewer = null, array $filters = [], int $size = 20, ?string $cursor = null): CursorPaginator
    {
        $viewer = $this->access->viewer($viewer);

        return $this->page(Post::publiclyVisible(), $filters, $size, $cursor, $viewer);
    }

    public function following(User $viewer, array $filters = [], int $size = 20, ?string $cursor = null): CursorPaginator
    {
        $viewer = $this->access->viewer($viewer);
        $query = Post::publiclyVisible()->whereIn('user_id', DB::table('user_follows')->select('followed_id')->where('follower_id', $viewer->id)->sharedLock());

        return $this->page($query, $filters, $size, $cursor, $viewer);
    }

    public function profile(int $userId, ?User $viewer = null, int $size = 20, ?string $cursor = null): CursorPaginator
    {
        $viewer = $this->access->viewer($viewer);
        $query = Post::where('user_id', $userId)->where('status', PostStatus::Published->value)
            ->whereHas('author', fn ($author) => $author->where('status', 'active')->sharedLock());
        if ($viewer?->id !== $userId) {
            $query->where('visibility', PostVisibility::Public->value);
        }

        return $this->page($query, [], $size, $cursor, $viewer);
    }

    public function owned(User $viewer, array $filters = [], int $size = 20, ?string $cursor = null): CursorPaginator
    {
        $viewer = $this->access->viewer($viewer);

        return $this->page(Post::where('user_id', $viewer->id), $filters, $size, $cursor, $viewer, true);
    }

    public function moderation(User $viewer, array $filters = [], int $size = 20, ?string $cursor = null): CursorPaginator
    {
        $viewer = $this->access->viewer($viewer);
        if (! $viewer->can('posts.moderate')) {
            throw new AuthorizationException;
        }

        return $this->page(Post::query(), $filters, $size, $cursor, $viewer, true);
    }

    public function detail(int $id, ?User $viewer = null): array
    {
        $viewer = $this->access->viewer($viewer);
        $post = $this->loaded(Post::query())->find($id);
        if (! $post) {
            throw new AuthorizationException;
        }
        Gate::forUser($viewer)->authorize('view', $post);

        return $this->postData($post, $viewer);
    }

    public function comments(int $postId, ?User $viewer = null, int $size = 20, ?string $cursor = null, bool $review = false): CursorPaginator
    {
        $viewer = $this->access->viewer($viewer);
        $post = Post::with(['author' => fn ($author) => $author->sharedLock()])->sharedLock()->find($postId);
        if ($review && ! $viewer?->can('comments.moderate')) {
            throw new AuthorizationException;
        }
        if (! $post || (! $review && $post->status !== PostStatus::Published)) {
            throw new AuthorizationException;
        }
        Gate::forUser($viewer)->authorize('view', $post);
        $this->pagination($size);

        $query = Comment::where('post_id', $postId);
        if (! $review) {
            $query->visibleInCommunity();
        }

        return $query->sharedLock()->with($this->authorRelations())
            ->orderBy('id')->cursorPaginate($size, ['comments.*'], 'cursor', $this->cursor($cursor))
            ->through(fn ($comment) => [
                'id' => $comment->id, 'post_id' => $comment->post_id, 'parent_id' => $comment->parent_id,
                'author' => $this->authorData($comment->author), 'content' => $comment->content,
                'version' => $comment->version, 'created_at' => $comment->created_at->toDateTimeString(),
                'edited_at' => $comment->edited_at?->toDateTimeString(),
                'status' => $comment->status->value,
                'can_edit' => $viewer !== null && Gate::forUser($viewer)->allows('interact', $post) && Gate::forUser($viewer)->allows('update', $comment),
                'can_delete' => $viewer !== null && Gate::forUser($viewer)->allows('delete', $comment),
                'can_moderate' => $viewer !== null && Gate::forUser($viewer)->allows('moderate', $comment),
                'can_reply' => $viewer !== null && $viewer->can('comments.create') && $comment->parent_id === null
                    && $comment->status->value === 'visible' && $comment->author->isActive() && Gate::forUser($viewer)->allows('interact', $post),
            ]);
    }

    public function connections(User $viewer, int $userId, bool $followers = false, int $size = 20, ?string $cursor = null): CursorPaginator
    {
        $viewer = $this->access->viewer($viewer);
        $this->connectionTarget($viewer, $userId);
        $this->pagination($size);
        $column = $followers ? 'follower_id' : 'followed_id';
        $matching = $followers ? 'followed_id' : 'follower_id';

        return User::where('status', 'active')->whereIn('id', DB::table('user_follows')->select($column)->where($matching, $userId)->sharedLock())
            ->with(['profile' => fn ($profile) => $profile->select('id', 'user_id', 'display_name', 'username', 'visibility')->sharedLock()])->sharedLock()->orderBy('id')
            ->cursorPaginate($size, ['id', 'name', 'status'], 'cursor', $this->cursor($cursor))
            ->through(fn ($user) => $this->authorData($user));
    }

    public function connectionSummary(User $viewer, int $userId): array
    {
        $viewer = $this->access->viewer($viewer);
        $target = $this->connectionTarget($viewer, $userId);

        return [
            'author' => $this->authorData($target),
            'followers' => DB::table('user_follows')->join('users', 'users.id', '=', 'user_follows.follower_id')->where('followed_id', $userId)->where('users.status', 'active')->count(),
            'following' => DB::table('user_follows')->join('users', 'users.id', '=', 'user_follows.followed_id')->where('follower_id', $userId)->where('users.status', 'active')->count(),
            'is_following' => DB::table('user_follows')->where(['follower_id' => $viewer->id, 'followed_id' => $userId])->exists(),
        ];
    }

    private function connectionTarget(User $viewer, int $userId): User
    {
        $target = User::with(['profile' => fn ($profile) => $profile->sharedLock()])->sharedLock()->find($userId);
        if (! $target || ! $target->isActive()
            || ($viewer->id !== $target->id && $target->profile?->visibility !== ProfileVisibility::Public)) {
            throw new AuthorizationException;
        }

        return $target;
    }

    private function page(Builder $query, array $filters, int $size, ?string $cursor, ?User $viewer = null, bool $byId = false): CursorPaginator
    {
        if (array_diff(array_keys($filters), ['category_id', 'specialty_id', 'tag_id'])) {
            $this->access->invalid('filters', 'Filtro no permitido.');
        }
        $valid = Validator::make($filters, [
            'category_id' => ['sometimes', 'integer', Rule::exists('content_categories', 'id')],
            'specialty_id' => ['sometimes', 'integer', Rule::exists('legal_specialties', 'id')],
            'tag_id' => ['sometimes', 'integer', Rule::exists('tags', 'id')],
        ])->validate();
        foreach (['category_id' => 'content_category_id', 'specialty_id' => 'legal_specialty_id'] as $key => $column) {
            if (isset($valid[$key])) {
                $query->where($column, $valid[$key]);
            }
        }
        if (isset($valid['tag_id'])) {
            $query->whereHas('tags', fn ($tags) => $tags->where('tags.id', $valid['tag_id']));
        }
        $this->pagination($size);

        $query = $this->loaded($query);
        if (! $byId) {
            $query->orderByDesc('published_at');
        }

        return $query->orderByDesc('id')
            ->cursorPaginate($size, ['posts.*'], 'cursor', $this->cursor($cursor, posts: ! $byId))
            ->through(fn ($post) => $this->postData($post, $viewer));
    }

    private function loaded(Builder $query): Builder
    {
        return $query->sharedLock()->with($this->authorRelations() + [
            'category' => fn ($category) => $category->select('id', 'name', 'slug', 'is_active'),
            'specialty' => fn ($specialty) => $specialty->select('id', 'name', 'slug', 'is_active'),
            'tags' => fn ($tags) => $tags->select('tags.id', 'name', 'slug', 'is_active')->orderBy('tags.id'),
            'attachments' => fn ($files) => $files->where('status', 'available')->where('visibility', 'private')
                ->whereNull('verification_retained_at')
                ->whereNotExists(fn ($evidence) => $evidence->selectRaw('1')->from('verification_evidences')->whereColumn('attachment_id', 'attachments.id')->sharedLock())
                ->select('attachments.id', 'user_id', 'original_name', 'extension', 'mime_type', 'category', 'size_bytes')->sharedLock(),
        ])->withCount([
            'reactions as likes_count' => fn ($reactions) => $reactions->where('type', 'like')->whereHas('user', fn ($user) => $user->where('status', 'active')),
            'comments as comments_count' => fn ($comments) => $comments->visibleInCommunity(),
        ]);
    }

    private function authorRelations(): array
    {
        return [
            'author' => fn ($author) => $author->select('id', 'name', 'status')->sharedLock(),
            'author.profile' => fn ($profile) => $profile->select('id', 'user_id', 'display_name', 'username', 'visibility')->sharedLock(),
            'author.professionalProfile' => fn ($profile) => $profile->select('id', 'user_id', 'verification_status', 'visibility')->where('visibility', 'public')->sharedLock(),
        ];
    }

    private function authorData(User $author): array
    {
        $public = $author->isActive() && $author->profile?->visibility === ProfileVisibility::Public;
        $professional = $author->relationLoaded('professionalProfile') ? $author->professionalProfile : null;

        return [
            'id' => $author->id,
            'display_name' => $public ? ($author->profile->display_name ?? $author->name) : 'Usuario',
            'username' => $public ? $author->profile->username : null,
            'profile_public' => $public,
            'active' => $author->isActive(),
            'professional_public' => $author->isActive() && $professional !== null,
            'professional_verified' => $author->isActive() && $professional?->verification_status->value === 'verified',
        ];
    }

    private function postData(Post $post, ?User $viewer = null): array
    {
        return [
            'id' => $post->id, 'author' => $this->authorData($post->author), 'content' => $post->content,
            'status' => $post->status->value, 'visibility' => $post->visibility->value, 'version' => $post->version,
            'published_at' => $post->published_at?->toDateTimeString(), 'edited_at' => $post->edited_at?->toDateTimeString(),
            'category' => $post->category?->only(['id', 'name', 'slug', 'is_active']),
            'specialty' => $post->specialty?->only(['id', 'name', 'slug', 'is_active']),
            'tags' => $post->tags->map(fn ($tag) => $tag->only(['id', 'name', 'slug', 'is_active']))->all(),
            'attachments' => $post->attachments->filter(fn ($file) => (int) $file->user_id === (int) $post->user_id)->map(fn ($file) => [
                'id' => $file->id, 'mime_type' => $file->mime_type, 'category' => $file->category,
                'size_bytes' => $file->size_bytes, 'position' => $file->pivot->position,
                'name' => $file->original_name, 'extension' => $file->extension,
            ])->values()->all(),
            'likes_count' => $post->likes_count, 'comments_count' => $post->comments_count,
            'can_edit' => $viewer !== null && Gate::forUser($viewer)->allows('update', $post),
            'can_delete' => $viewer !== null && Gate::forUser($viewer)->allows('delete', $post),
            'can_interact' => $viewer !== null && Gate::forUser($viewer)->allows('interact', $post),
            'can_comment' => $viewer !== null && $viewer->can('comments.create') && Gate::forUser($viewer)->allows('interact', $post),
            'can_moderate' => $viewer !== null && in_array($post->status, [PostStatus::Published, PostStatus::Hidden], true) && Gate::forUser($viewer)->allows('moderate', $post),
        ];
    }

    private function pagination(int $size): void
    {
        Validator::make(['size' => $size], ['size' => ['integer', 'min:1', 'max:'.config('community.max_page_size')]])->validate();
    }

    private function cursor(?string $cursor, bool $posts = false): ?Cursor
    {
        if ($cursor === null) {
            return null;
        }
        $parameters = json_decode(base64_decode(strtr($cursor, '-_', '+/'), true) ?: '', true);
        $keys = $posts ? ['id', 'published_at', '_pointsToNextItems'] : ['id', '_pointsToNextItems'];
        if (! is_array($parameters) || count($parameters) !== count($keys)
            || array_diff(array_keys($parameters), $keys)
            || ! is_bool($parameters['_pointsToNextItems'] ?? null)) {
            $this->access->invalid('cursor', 'El cursor no es válido.');
        }
        Validator::make($parameters, [
            'id' => ['required', 'integer', 'min:1'],
            'published_at' => $posts ? ['required', 'string', 'date_format:Y-m-d H:i:s'] : ['prohibited'],
        ])->validate();

        return Cursor::fromEncoded($cursor);
    }
}
