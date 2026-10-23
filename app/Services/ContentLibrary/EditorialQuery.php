<?php

namespace App\Services\ContentLibrary;

use App\Enums\EditorialKind;
use App\Enums\EditorialStatus;
use App\Enums\LegalResourceType;
use App\Enums\ProfileVisibility;
use App\Models\EditorialEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EditorialQuery
{
    public function __construct(private ContentAccess $access) {}

    public function recent(EditorialKind $kind, ?User $viewer = null, array $filters = [], int $size = 20, ?string $cursor = null): CursorPaginator
    {
        $this->access->viewer($viewer);
        $model = $kind->model();

        return $this->page($kind, $model::publiclyVisible(), $filters, $size, $cursor, 'published_at');
    }

    public function workspace(EditorialKind $kind, User $actor, bool $all = false, ?EditorialStatus $status = null, int $size = 20, ?string $cursor = null): CursorPaginator
    {
        $actor = $this->access->viewer($actor);
        if ($all && ! $actor->can($kind->permission().'.manage')) {
            throw new AuthorizationException;
        }
        $model = $kind->model();
        $query = $model::query();
        if (! $all) {
            $query->where('user_id', $actor->id);
        }
        if ($status) {
            $query->where('status', $status->value);
        }

        return $this->page($kind, $query, [], $size, $cursor, 'updated_at');
    }

    public function detail(EditorialKind $kind, int $id, ?User $viewer = null): array
    {
        return $this->access->entry($kind, $viewer, $id, function (?User $actor, EditorialEntry $entry) {
            Gate::forUser($actor)->authorize('view', $entry);
            $entry->load($this->relations());

            return $this->data($entry, detail: true);
        });
    }

    public function revisions(EditorialKind $kind, User $actor, int $id, int $size = 20, ?string $cursor = null): CursorPaginator
    {
        return $this->access->entry($kind, $actor, $id, function (User $actor, EditorialEntry $entry) use ($size, $cursor) {
            Gate::forUser($actor)->authorize('audit', $entry);
            $this->size($size);

            return $entry->revisions()->with('attachments:attachments.id')->sharedLock()->orderByDesc('id')
                ->cursorPaginate($size, ['content_revisions.*'], 'cursor', $this->cursor($cursor))
                ->through(fn ($revision) => ['id' => $revision->id, 'version' => $revision->version,
                    'recorded_by' => $revision->recorded_by, 'created_at' => $revision->created_at->toDateTimeString(),
                    'snapshot' => $revision->snapshot, 'attachment_ids' => $revision->attachments->modelKeys()]);
        }, withDeleted: true);
    }

    private function page(EditorialKind $kind, Builder $query, array $filters, int $size, ?string $cursor, string $date): CursorPaginator
    {
        $rules = ['author_id' => ['sometimes', 'integer', 'min:1'],
            'category_id' => ['sometimes', 'integer', Rule::exists('content_categories', 'id')],
            'tag_id' => ['sometimes', 'integer', Rule::exists('tags', 'id')],
            'specialty_id' => ['sometimes', 'integer', Rule::exists('legal_specialties', 'id')],
            'title' => ['sometimes', 'required', 'string', 'max:100']];
        if ($kind === EditorialKind::Resource) {
            $rules['resource_type'] = ['sometimes', Rule::enum(LegalResourceType::class)];
        }
        if (array_diff(array_keys($filters), array_keys($rules))) {
            $this->access->invalid('filters', 'Filtro no permitido.');
        }
        $valid = Validator::make($filters, $rules)->validate();
        foreach (['author_id' => 'user_id', 'category_id' => 'content_category_id', 'resource_type' => 'resource_type'] as $key => $column) {
            if (isset($valid[$key])) {
                $query->where($column, $valid[$key]);
            }
        }
        if (isset($valid['tag_id'])) {
            $query->whereHas('tags', fn ($tags) => $tags->where('tags.id', $valid['tag_id']));
        }
        if (isset($valid['specialty_id'])) {
            $query->whereHas('specialties', fn ($items) => $items->where('legal_specialties.id', $valid['specialty_id']));
        }
        if (isset($valid['title'])) {
            $query->whereRaw("title LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $valid['title']).'%']);
        }
        $this->size($size);

        return $this->load($query)->orderByDesc($date)->orderByDesc('id')
            ->cursorPaginate($size, ['*'], 'cursor', $this->cursor($cursor, $date))->through(fn ($entry) => $this->data($entry));
    }

    private function load(Builder $query): Builder
    {
        return $query->with($this->relations());
    }

    private function relations(): array
    {
        return ['author:id,name,status', 'author.profile:id,user_id,display_name,username,visibility',
            'category:id,name,slug,is_active', 'tags:id,name,slug,is_active', 'specialties:id,name,slug,is_active',
            'attachments' => fn ($files) => $files->where('status', 'available')->where('visibility', 'private')->whereNull('verification_retained_at')
                ->whereNotExists(fn ($evidence) => $evidence->selectRaw('1')->from('verification_evidences')->whereColumn('attachment_id', 'attachments.id'))
                ->select('attachments.id', 'user_id', 'mime_type', 'category', 'size_bytes')];
    }

    private function data(EditorialEntry $entry, bool $detail = false): array
    {
        $public = $entry->author->profile?->visibility === ProfileVisibility::Public;
        $data = ['id' => $entry->id, 'type' => $entry->kind()->value, 'title' => $entry->title, 'slug' => $entry->slug,
            'status' => $entry->status->value, 'version' => $entry->version,
            'published_at' => $entry->published_at?->toDateTimeString(), 'updated_at' => $entry->updated_at->toDateTimeString(),
            'editorial_updated_at' => $entry->editorial_updated_at?->toDateTimeString(),
            'author' => ['id' => $entry->user_id, 'display_name' => $public ? ($entry->author->profile->display_name ?? $entry->author->name) : 'Usuario', 'username' => $public ? $entry->author->profile->username : null],
            'category' => $entry->category?->only(['id', 'name', 'slug', 'is_active']),
            'tags' => $entry->tags->map(fn ($item) => $item->only(['id', 'name', 'slug', 'is_active']))->all(),
            'specialties' => $entry->specialties->map(fn ($item) => $item->only(['id', 'name', 'slug', 'is_active']))->all(),
            'attachments' => $entry->attachments->filter(fn ($file) => (int) $file->user_id === (int) $entry->user_id)->map(fn ($file) => [
                'id' => $file->id, 'mime_type' => $file->mime_type, 'category' => $file->category, 'size_bytes' => $file->size_bytes, 'position' => $file->pivot->position,
            ])->values()->all()];
        if ($entry->kind() === EditorialKind::Article) {
            $data['summary'] = $entry->summary;
            if ($detail) {
                $data['body'] = $entry->body;
            }
        } else {
            $data += ['description' => $entry->description, 'resource_type' => $entry->resource_type->value,
                'external_url' => $entry->external_url, 'source_label' => 'Recurso aportado por usuario'];
        }

        return $data;
    }

    private function size(int $size): void
    {
        Validator::make(['size' => $size], ['size' => ['integer', 'min:1', 'max:'.config('content-library.max_page_size')]])->validate();
    }

    private function cursor(?string $cursor, ?string $date = null): ?Cursor
    {
        if ($cursor === null) {
            return null;
        }
        $values = json_decode(base64_decode(strtr($cursor, '-_', '+/'), true) ?: '', true);
        $keys = $date ? ['id', $date, '_pointsToNextItems'] : ['id', '_pointsToNextItems'];
        if (! is_array($values) || count($values) !== count($keys) || array_diff(array_keys($values), $keys) || ! is_bool($values['_pointsToNextItems'] ?? null)) {
            $this->access->invalid('cursor', 'El cursor no es válido.');
        }
        $rules = ['id' => ['required', 'integer', 'min:1']];
        if ($date) {
            $rules[$date] = ['required', 'date_format:Y-m-d H:i:s'];
        }
        Validator::make($values, $rules)->validate();

        return Cursor::fromEncoded($cursor);
    }
}
