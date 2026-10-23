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
        $this->access->viewer($viewer);

        return $this->page(Post::publiclyVisible(), $filters, $size, $cursor);
    }

    public function following(User $viewer, array $filters = [], int $size = 20, ?string $cursor = null): CursorPaginator
    {
        $viewer = $this->access->viewer($viewer);
        $query = Post::publiclyVisible()->whereIn('user_id', DB::table('user_follows')->select('followed_id')->where('follower_id', $viewer->id));

        return $this->page($query, $filters, $size, $cursor);
    }

    public function profile(int $userId, ?User $viewer = null, int $size = 20, ?string $cursor = null): CursorPaginator
    {
        $viewer = $this->access->viewer($viewer);
        $query = Post::where('user_id', $userId)->where('status', PostStatus::Published->value)
            ->whereHas('author', fn ($author) => $author->where('status', 'active'));
        if ($viewer?->id !== $userId) {
            $query->where('visibility', PostVisibility::Public->value);
        }

        return $this->page($query, [], $size, $cursor);
    }

    public function detail(int $id, ?User $viewer = null): array
    {
        $viewer = $this->access->viewer($viewer);
        $post = $this->loaded(Post::query())->find($id);
        if (! $post) {
            throw new AuthorizationException;
        }
        Gate::forUser($viewer)->authorize('view', $post);

        return $this->postData($post);
    }

    public function comments(int $postId, ?User $viewer = null, int $size = 20, ?string $cursor = null): CursorPaginator
    {
        $viewer = $this->access->viewer($viewer);
        $post = Post::with('author')->find($postId);
        if (! $post || $post->status !== PostStatus::Published) {
            throw new AuthorizationException;
        }
        Gate::forUser($viewer)->authorize('view', $post);
        $this->pagination($size);

        return Comment::where('post_id', $postId)->visibleInCommunity()->with($this->authorRelations())
            ->orderBy('id')->cursorPaginate($size, ['comments.*'], 'cursor', $this->cursor($cursor))
            ->through(fn ($comment) => [
                'id' => $comment->id, 'post_id' => $comment->post_id, 'parent_id' => $comment->parent_id,
                'author' => $this->authorData($comment->author), 'content' => $comment->content,
                'version' => $comment->version, 'created_at' => $comment->created_at->toDateTimeString(),
                'edited_at' => $comment->edited_at?->toDateTimeString(),
            ]);
    }

    public function connections(User $viewer, int $userId, bool $followers = false, int $size = 20, ?string $cursor = null): CursorPaginator
    {
        $viewer = $this->access->viewer($viewer);
        $target = User::with('profile')->find($userId);
        if (! $target || ! $target->isActive()
            || ($viewer->id !== $target->id && $target->profile?->visibility !== ProfileVisibility::Public)) {
            throw new AuthorizationException;
        }
        $this->pagination($size);
        $column = $followers ? 'follower_id' : 'followed_id';
        $matching = $followers ? 'followed_id' : 'follower_id';

        return User::where('status', 'active')->whereIn('id', DB::table('user_follows')->select($column)->where($matching, $userId))
            ->with('profile:id,user_id,display_name,username,visibility')->orderBy('id')
            ->cursorPaginate($size, ['id', 'name', 'status'], 'cursor', $this->cursor($cursor))
            ->through(fn ($user) => $this->authorData($user));
    }

    private function page(Builder $query, array $filters, int $size, ?string $cursor): CursorPaginator
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

        return $this->loaded($query)->orderByDesc('published_at')->orderByDesc('id')
            ->cursorPaginate($size, ['posts.*'], 'cursor', $this->cursor($cursor, posts: true))
            ->through(fn ($post) => $this->postData($post));
    }

    private function loaded(Builder $query): Builder
    {
        return $query->with($this->authorRelations() + [
            'category' => fn ($category) => $category->select('id', 'name', 'slug', 'is_active'),
            'specialty' => fn ($specialty) => $specialty->select('id', 'name', 'slug', 'is_active'),
            'tags' => fn ($tags) => $tags->select('tags.id', 'name', 'slug', 'is_active')->orderBy('tags.id'),
            'attachments' => fn ($files) => $files->where('status', 'available')->where('visibility', 'private')
                ->whereNull('verification_retained_at')
                ->whereNotExists(fn ($evidence) => $evidence->selectRaw('1')->from('verification_evidences')->whereColumn('attachment_id', 'attachments.id'))
                ->select('attachments.id', 'user_id', 'mime_type', 'category', 'size_bytes'),
        ])->withCount([
            'reactions as likes_count' => fn ($reactions) => $reactions->where('type', 'like')->whereHas('user', fn ($user) => $user->where('status', 'active')),
            'comments as comments_count' => fn ($comments) => $comments->visibleInCommunity(),
        ]);
    }

    private function authorRelations(): array
    {
        return [
            'author' => fn ($author) => $author->select('id', 'name', 'status'),
            'author.profile' => fn ($profile) => $profile->select('id', 'user_id', 'display_name', 'username', 'visibility'),
        ];
    }

    private function authorData(User $author): array
    {
        $public = $author->profile?->visibility === ProfileVisibility::Public;

        return [
            'id' => $author->id,
            'display_name' => $public ? ($author->profile->display_name ?? $author->name) : 'Usuario',
            'username' => $public ? $author->profile->username : null,
        ];
    }

    private function postData(Post $post): array
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
            ])->values()->all(),
            'likes_count' => $post->likes_count, 'comments_count' => $post->comments_count,
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
