<?php

namespace App\Services\ContentLibrary;

use App\Enums\SavedContentType;
use App\Models\SavedItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SavedLibraryQuery
{
    public function __construct(private ContentAccess $access) {}

    public function library(User $actor, ?SavedContentType $type = null, int $size = 20, ?string $cursor = null): CursorPaginator
    {
        return $this->access->actor($actor, fn (User $current) => $this->page($current, $type, $size, $cursor));
    }

    private function page(User $actor, ?SavedContentType $type, int $size, ?string $cursor): CursorPaginator
    {
        Validator::make(['size' => $size], ['size' => ['integer', 'min:1', 'max:'.config('content-library.max_page_size')]])->validate();
        $moderator = $actor->can('posts.moderate');
        $query = SavedItem::where('user_id', $actor->id)->where(function ($visible) use ($actor, $moderator) {
            $visible->whereHas('post', fn ($post) => $post->where('status', 'published')->whereHas('author', fn ($author) => $author->where('status', 'active')->sharedLock())
                ->where(function ($access) use ($actor, $moderator) {
                    $access->where('visibility', 'public')->orWhere('user_id', $actor->id);
                    if ($moderator) {
                        $access->orWhereRaw('1 = 1');
                    }
                })->sharedLock())
                ->orWhereHas('article', fn ($entry) => $this->currentPublic($entry))
                ->orWhereHas('resource', fn ($entry) => $this->currentPublic($entry));
        });
        if ($type) {
            $query->whereNotNull($type->key());
        }

        return $query->select('saved_items.*')->selectRaw('created_at as saved_at')
            ->with(['post' => fn ($post) => $post->select('id', 'content')->sharedLock(),
                'article' => fn ($entry) => $entry->select('id', 'title', 'slug')->sharedLock(),
                'resource' => fn ($entry) => $entry->select('id', 'title', 'slug', 'resource_type')->sharedLock()])
            ->sharedLock()
            ->orderByDesc('saved_at')->orderByDesc('id')
            ->cursorPaginate($size, ['*'], 'cursor', $this->cursor($cursor))->through(function ($saved) {
                $type = $saved->post_id ? SavedContentType::Post : ($saved->article_id ? SavedContentType::Article : SavedContentType::Resource);
                $target = match ($type) {
                    SavedContentType::Post => $saved->post, SavedContentType::Article => $saved->article, SavedContentType::Resource => $saved->resource
                };

                return ['id' => $saved->id, 'type' => $type->value, 'target_id' => $target->id, 'saved_at' => $saved->getRawOriginal('saved_at'),
                    'title' => $type === SavedContentType::Post ? null : $target->title,
                    'slug' => $type === SavedContentType::Post ? null : $target->slug,
                    'preview' => $type === SavedContentType::Post ? Str::limit($target->content ?? '', 300) : null];
            });
    }

    private function currentPublic($query)
    {
        return $query->where('status', 'published')->whereHas('author', fn ($author) => $author->where('status', 'active')->sharedLock())->sharedLock();
    }

    private function cursor(?string $cursor): ?Cursor
    {
        if ($cursor === null) {
            return null;
        }
        $values = json_decode(base64_decode(strtr($cursor, '-_', '+/'), true) ?: '', true);
        if (! is_array($values) || count($values) !== 3 || array_diff(array_keys($values), ['id', 'saved_at', '_pointsToNextItems']) || ! is_bool($values['_pointsToNextItems'] ?? null)) {
            $this->access->invalid('cursor', 'El cursor no es válido.');
        }
        Validator::make($values, ['id' => ['required', 'integer', 'min:1'], 'saved_at' => ['required', 'date_format:Y-m-d H:i:s']])->validate();

        return Cursor::fromEncoded($cursor);
    }
}
