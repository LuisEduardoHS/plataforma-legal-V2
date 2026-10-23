<?php

namespace App\Services\Community;

use App\Enums\AttachmentStatus;
use App\Enums\PostStatus;
use App\Enums\PostVisibility;
use App\Models\Attachment;
use App\Models\ContentCategory;
use App\Models\LegalSpecialty;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PostService
{
    public function __construct(private CommunityAccess $access) {}

    public function create(User $actor, array $data): Post
    {
        return $this->access->withActor($actor, function (User $actor) use ($data): Post {
            Gate::forUser($actor)->authorize('create', Post::class);
            $post = new Post;
            $post->forceFill(['user_id' => $actor->id, 'status' => PostStatus::Draft, 'visibility' => PostVisibility::Private, 'version' => 1]);
            $valid = $this->validate($data);
            $this->catalogs($post, $valid);
            $post->save();
            $this->relations($post, $valid);

            return $post->fresh();
        });
    }

    public function update(User $actor, int $id, array $data, int $expectedVersion): Post
    {
        return $this->access->withPost($actor, $id, function (User $actor, Post $post) use ($data, $expectedVersion): Post {
            Gate::forUser($actor)->authorize('update', $post);
            $this->access->version($post->version, $expectedVersion);
            $valid = $this->validate($data);
            $this->catalogs($post, $valid);
            $this->relations($post, $valid);
            if ($post->status !== PostStatus::Draft) {
                $this->publishable($post, true);
            }
            $post->forceFill(['edited_at' => now(), 'version' => $post->version + 1])->save();

            return $post->fresh();
        });
    }

    public function publish(User $actor, int $id, int $expectedVersion): Post
    {
        return $this->access->withPost($actor, $id, function (User $actor, Post $post) use ($expectedVersion): Post {
            Gate::forUser($actor)->authorize('update', $post);
            if ((int) $actor->id === (int) $post->user_id) {
                Gate::forUser($actor)->authorize('create', Post::class);
            }
            $this->access->version($post->version, $expectedVersion);
            if ($post->status !== PostStatus::Draft) {
                $this->access->invalid('status', 'Solo se pueden publicar borradores.');
            }
            $this->publishable($post, true);
            $post->forceFill(['status' => PostStatus::Published, 'published_at' => now(), 'version' => $post->version + 1])->save();

            return $post->fresh();
        });
    }

    public function moderate(User $actor, int $id, bool $hidden, int $expectedVersion): Post
    {
        return $this->access->withPost($actor, $id, function (User $actor, Post $post) use ($hidden, $expectedVersion): Post {
            Gate::forUser($actor)->authorize('moderate', $post);
            $this->access->version($post->version, $expectedVersion);
            if (! in_array($post->status, [PostStatus::Published, PostStatus::Hidden], true)) {
                $this->access->invalid('status', 'La publicación no admite esta acción de moderación.');
            }
            if (! $hidden) {
                $this->publishable($post, true);
            }
            $post->forceFill(['status' => $hidden ? PostStatus::Hidden : PostStatus::Published, 'version' => $post->version + 1])->save();

            return $post->fresh();
        });
    }

    public function delete(User $actor, int $id, int $expectedVersion): void
    {
        $this->access->withPost($actor, $id, function (User $actor, Post $post) use ($expectedVersion): void {
            Gate::forUser($actor)->authorize('delete', $post);
            if ($post->trashed()) {
                return;
            }
            $this->access->version($post->version, $expectedVersion);
            $post->forceFill(['status' => PostStatus::Deleted, 'deleted_at' => now(), 'version' => $post->version + 1])->save();
        }, withDeleted: true);
    }

    private function validate(array $data): array
    {
        $rules = [
            'content' => ['sometimes', 'nullable', 'string', 'max:'.config('community.post_max_length'), 'regex:/\A[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*\z/u'],
            'visibility' => ['sometimes', Rule::enum(PostVisibility::class)],
            'content_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'legal_specialty_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'tag_ids' => ['sometimes', 'array', 'max:'.config('community.max_tags')],
            'tag_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'attachment_ids' => ['sometimes', 'array', 'max:'.config('community.max_attachments')],
            'attachment_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
        if (array_diff(array_keys($data), ['content', 'visibility', 'content_category_id', 'legal_specialty_id', 'tag_ids', 'attachment_ids'])) {
            $this->access->invalid('post', 'La solicitud contiene campos no permitidos.');
        }
        if (isset($data['content']) && is_string($data['content'])) {
            $data['content'] = preg_replace('/\A[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+\z/u', '', $data['content']);
        }

        return Validator::make($data, $rules)->validate();
    }

    private function catalogs(Post $post, array $data): void
    {
        foreach (['content_category_id' => ContentCategory::class, 'legal_specialty_id' => LegalSpecialty::class] as $field => $model) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            if ($data[$field] !== null) {
                $catalog = $model::whereKey($data[$field])->lockForUpdate()->first();
                if (! $catalog || (! $catalog->is_active && (int) $post->$field !== (int) $catalog->id)) {
                    $this->access->invalid($field, 'Selecciona un registro existente y activo.');
                }
            }
            $post->$field = $data[$field];
        }
        $post->forceFill(array_intersect_key($data, array_flip(['content', 'visibility'])));
    }

    private function relations(Post $post, array $data): void
    {
        if (array_key_exists('tag_ids', $data)) {
            $ids = array_map('intval', $data['tag_ids']);
            $current = $post->tags()->orderBy('tags.id')->lockForUpdate()->pluck('tags.id')->all();
            $tags = Tag::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($tags->count() !== count($ids) || $tags->contains(fn ($tag) => ! $tag->is_active && ! in_array($tag->id, $current, true))) {
                $this->access->invalid('tag_ids', 'Las etiquetas nuevas deben existir y estar activas.');
            }
            $post->tags()->sync($ids);
        }
        if (array_key_exists('attachment_ids', $data)) {
            $ids = array_map('intval', $data['attachment_ids']);
            $files = Attachment::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($files->count() !== count($ids)) {
                $this->access->invalid('attachment_ids', 'Hay archivos inexistentes.');
            }
            foreach ($files as $file) {
                $this->assertAttachment($file, $post);
            }
            $post->attachments()->detach();
            foreach ($ids as $position => $id) {
                $post->attachments()->attach($id, ['position' => $position]);
            }
        }
    }

    public function assertAttachment(Attachment $file, Post $post): void
    {
        if ((int) $file->user_id !== (int) $post->user_id || $file->status !== AttachmentStatus::Available
            || $file->visibility !== 'private' || ! in_array($file->category, ['image', 'video', 'document'], true)
            || $file->verification_retained_at !== null
            || DB::table('verification_evidences')->where('attachment_id', $file->id)->lockForUpdate()->first() !== null) {
            $this->access->invalid('attachment_ids', 'Selecciona archivos propios, disponibles y ajenos a expedientes de verificación.');
        }
    }

    private function publishable(Post $post, bool $markShared = false): void
    {
        $files = $post->attachments()->reorder()->orderBy('attachments.id')->lockForUpdate()->get();
        foreach ($files as $file) {
            $this->assertAttachment($file, $post);
            if ($markShared && $file->community_published_at === null) {
                $file->forceFill(['community_published_at' => now()])->save();
            }
        }
        if (($post->content === null || trim($post->content) === '') && $files->isEmpty()) {
            $this->access->invalid('content', 'Una publicación debe contener texto o archivos.');
        }
    }
}
