<?php

namespace App\Services\ContentLibrary;

use App\Enums\AttachmentStatus;
use App\Enums\EditorialKind;
use App\Enums\EditorialStatus as Status;
use App\Enums\LegalResourceType;
use App\Models\Attachment;
use App\Models\ContentCategory;
use App\Models\ContentRevision;
use App\Models\EditorialEntry;
use App\Models\LegalSpecialty;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EditorialService
{
    public function __construct(private ContentAccess $access) {}

    public function create(EditorialKind $kind, User $actor, array $data): EditorialEntry
    {
        return $this->access->actor($actor, function (User $actor) use ($kind, $data) {
            $model = $kind->model();
            Gate::forUser($actor)->authorize('create', $model);
            $valid = $this->validate($kind, $data, creating: true);
            $entry = new $model;
            $base = Str::slug(Str::limit($valid['title'], 130, '')) ?: ($kind === EditorialKind::Article ? 'articulo' : 'recurso');
            $slug = $valid['slug'] ?? ($base.'-'.Str::lower((string) Str::ulid()));
            if ($model::withTrashed()->where('slug', $slug)->lockForUpdate()->first()) {
                $this->access->invalid('slug', 'El slug ya está reservado.');
            }
            $entry->forceFill(['user_id' => $actor->id, 'slug' => $slug, 'status' => Status::Draft, 'version' => 1]);
            $this->fields($entry, $valid);
            $entry->save();
            $this->relations($entry, $valid);
            $this->revision($entry, $actor);

            return $entry->fresh();
        });
    }

    public function update(EditorialKind $kind, User $actor, int $id, array $data, int $expectedVersion): EditorialEntry
    {
        return $this->access->entry($kind, $actor, $id, function (User $actor, EditorialEntry $entry) use ($data, $expectedVersion) {
            Gate::forUser($actor)->authorize('update', $entry);
            $this->access->version($entry, $expectedVersion);
            $valid = $this->validate($entry->kind(), $data);
            $this->fields($entry, $valid);
            $this->relations($entry, $valid);
            if ($entry->status === Status::Published) {
                Gate::forUser($actor)->authorize('publish', $entry);
                $this->publishable($entry);
            }
            $entry->forceFill(['version' => $entry->version + 1, 'editorial_updated_at' => now()])->save();
            $this->revision($entry, $actor);

            return $entry->fresh();
        });
    }

    public function transition(EditorialKind $kind, User $actor, int $id, Status $target, int $expectedVersion): EditorialEntry
    {
        return $this->access->entry($kind, $actor, $id, function (User $actor, EditorialEntry $entry) use ($target, $expectedVersion) {
            Gate::forUser($actor)->authorize($target === Status::Deleted ? 'delete' : 'update', $entry);
            if ($entry->status === Status::Deleted && $target === Status::Deleted) {
                return $entry;
            }
            $this->access->version($entry, $expectedVersion);
            $allowed = match ($entry->status) {
                Status::Draft => [Status::Published, Status::Archived, Status::Deleted],
                Status::Published => [Status::Draft, Status::Hidden, Status::Archived, Status::Deleted],
                Status::Hidden => [Status::Published, Status::Deleted],
                Status::Archived => [Status::Draft, Status::Deleted],
                Status::Deleted => [],
            };
            if (! in_array($target, $allowed, true)) {
                $this->access->invalid('status', 'La transición no está permitida.');
            }
            if ($target === Status::Hidden || ($entry->status === Status::Hidden && $target !== Status::Deleted)) {
                Gate::forUser($actor)->authorize('moderate', $entry);
            }
            if ($target === Status::Published || ($entry->status === Status::Published && $target === Status::Draft)) {
                Gate::forUser($actor)->authorize('publish', $entry);
            }
            if ($target === Status::Published) {
                $this->publishable($entry);
                $entry->published_at ??= now();
            }
            $entry->forceFill(['status' => $target, 'deleted_at' => $target === Status::Deleted ? now() : null,
                'editorial_updated_at' => now(), 'version' => $entry->version + 1])->save();
            $this->revision($entry, $actor);

            return $entry->fresh();
        }, withDeleted: true);
    }

    private function validate(EditorialKind $kind, array $data, bool $creating = false): array
    {
        $text = fn ($limit) => ['string', 'max:'.config('content-library.'.$limit), 'regex:/\A[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*\z/u'];
        $rules = [
            'title' => [$creating ? 'required' : 'sometimes', 'required', ...$text('title_max_length')],
            'content_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'tag_ids' => ['sometimes', 'array', 'list', 'max:'.config('content-library.max_tags')],
            'specialty_ids' => ['sometimes', 'array', 'list', 'max:'.config('content-library.max_specialties')],
            'attachment_ids' => ['sometimes', 'array', 'list', 'max:'.config('content-library.max_attachments')],
        ];
        foreach (['tag_ids', 'specialty_ids', 'attachment_ids'] as $key) {
            $rules[$key.'.*'] = ['required', 'integer', 'min:1', 'distinct'];
        }
        if ($creating) {
            $rules['slug'] = ['sometimes', 'required', 'string', 'max:190', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'];
        }
        if ($kind === EditorialKind::Article) {
            $rules += ['summary' => ['sometimes', 'nullable', ...$text('summary_max_length')], 'body' => ['sometimes', 'nullable', ...$text('body_max_length')]];
        } else {
            $rules += ['description' => ['sometimes', 'nullable', ...$text('description_max_length')],
                'resource_type' => [$creating ? 'required' : 'sometimes', Rule::enum(LegalResourceType::class)],
                'external_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:https']];
        }
        $allowed = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
        if (array_diff(array_keys($data), $allowed)) {
            $this->access->invalid('content', 'Hay campos no permitidos.');
        }
        foreach (['title', 'summary', 'body', 'description'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = preg_replace('/\A[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+\z/u', '', $data[$key]);
            }
        }
        $valid = Validator::make($data, $rules)->validate();
        if (isset($valid['external_url'])) {
            $this->url($valid['external_url']);
        }

        return $valid;
    }

    private function fields(EditorialEntry $entry, array $data): void
    {
        if (array_key_exists('content_category_id', $data) && $data['content_category_id'] !== null) {
            $category = ContentCategory::whereKey($data['content_category_id'])->lockForUpdate()->first();
            if (! $category || (! $category->is_active && (int) $entry->content_category_id !== (int) $category->id)) {
                $this->access->invalid('content_category_id', 'Selecciona una categoría existente y activa.');
            }
        }
        $entry->forceFill(array_intersect_key($data, array_flip(['title', 'summary', 'body', 'description', 'resource_type', 'external_url', 'content_category_id'])));
        if ($entry->kind() === EditorialKind::Resource && $entry->external_url && $entry->resource_type === LegalResourceType::InstitutionalLink) {
            $this->url($entry->external_url, institutional: true);
        }
    }

    private function relations(EditorialEntry $entry, array $data): void
    {
        foreach (['tag_ids' => ['tags', Tag::class], 'specialty_ids' => ['specialties', LegalSpecialty::class]] as $key => [$relation, $model]) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $ids = array_map('intval', $data[$key]);
            $table = (new $model)->getTable();
            $old = $entry->$relation()->orderBy($table.'.id')->lockForUpdate()->pluck($table.'.id')->all();
            $catalogs = $model::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($catalogs->count() !== count($ids) || $catalogs->contains(fn ($item) => ! $item->is_active && ! in_array($item->id, $old, true))) {
                $this->access->invalid($key, 'Las nuevas asociaciones deben existir y estar activas.');
            }
            $entry->$relation()->sync($ids);
        }
        if (array_key_exists('attachment_ids', $data)) {
            $ids = array_map('intval', $data['attachment_ids']);
            $files = Attachment::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($files->count() !== count($ids)) {
                $this->access->invalid('attachment_ids', 'Hay archivos inexistentes.');
            }
            foreach ($files as $file) {
                $this->assertAttachment($file, $entry);
            }
            $entry->attachments()->detach();
            foreach ($ids as $position => $id) {
                $entry->attachments()->attach($id, ['position' => $position]);
            }
        }
    }

    public function assertAttachment(Attachment $file, EditorialEntry $entry): void
    {
        $type = config('attachments.types.'.$file->mime_type);
        if ((int) $file->user_id !== (int) $entry->user_id || $file->status !== AttachmentStatus::Available || $file->visibility !== 'private'
            || ! $type || $type['category'] !== $file->category || ! in_array($file->extension, $type['extensions'], true)
            || $file->verification_retained_at !== null
            || DB::table('verification_evidences')->where('attachment_id', $file->id)->lockForUpdate()->first() !== null) {
            $this->access->invalid('attachment_ids', 'Selecciona archivos propios, disponibles y ajenos a verificación profesional.');
        }
    }

    private function publishable(EditorialEntry $entry): void
    {
        $files = $entry->attachments()->reorder()->orderBy('attachments.id')->lockForUpdate()->get();
        if ($entry->kind() === EditorialKind::Article && ($entry->body === null || trim($entry->body) === '')) {
            $this->access->invalid('body', 'Un artículo publicado requiere cuerpo.');
        }
        if ($entry->kind() === EditorialKind::Resource) {
            if ($entry->description === null || trim($entry->description) === '' || (! $entry->external_url && $files->isEmpty())) {
                $this->access->invalid('description', 'El recurso requiere descripción y un enlace o archivo.');
            }
            if (in_array($entry->resource_type, [LegalResourceType::Document, LegalResourceType::Template], true) && $files->isEmpty()) {
                $this->access->invalid('attachment_ids', 'Un documento o plantilla requiere archivo.');
            }
            if ($entry->resource_type === LegalResourceType::InstitutionalLink) {
                if (! $entry->external_url) {
                    $this->access->invalid('external_url', 'El enlace institucional es obligatorio.');
                }
                $this->url($entry->external_url, institutional: true);
            }
            if ($entry->external_url) {
                $this->url($entry->external_url);
            }
        }
        foreach ($files as $file) {
            $this->assertAttachment($file, $entry);
            if ($file->editorial_published_at === null) {
                $file->forceFill(['editorial_published_at' => now()])->save();
            }
        }
    }

    private function revision(EditorialEntry $entry, User $actor): void
    {
        $entry->load(['category', 'tags', 'specialties', 'attachments']);
        $snapshot = $entry->only(['title', 'slug', 'summary', 'body', 'description', 'resource_type', 'external_url', 'status', 'version', 'published_at', 'editorial_updated_at', 'deleted_at']);
        $snapshot['category'] = $entry->category?->only(['id', 'name', 'slug', 'is_active']);
        foreach (['tags', 'specialties'] as $relation) {
            $snapshot[$relation] = $entry->$relation->map(fn ($item) => $item->only(['id', 'name', 'slug', 'is_active']))->all();
        }
        $revision = new ContentRevision;
        $revision->forceFill([$entry->kind()->key() => $entry->id, 'recorded_by' => $actor->id, 'version' => $entry->version, 'snapshot' => $snapshot])->save();
        foreach ($entry->attachments as $file) {
            $revision->attachments()->attach($file->id, ['position' => $file->pivot->position]);
        }
    }

    private function url(string $url, bool $institutional = false): void
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)
            || ! preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}\z/', $host)
            || preg_match('/\.(?:local|localhost|test|example|invalid|onion)\z/', $host)) {
            $this->access->invalid('external_url', 'Utiliza una URL HTTPS pública sin credenciales.');
        }
        if ($institutional && ! collect(config('content-library.institutional_hosts'))->contains(fn ($allowed) => $host === $allowed || str_ends_with($host, '.'.$allowed))) {
            $this->access->invalid('external_url', 'El dominio no está entre las instituciones admitidas.');
        }
    }
}
