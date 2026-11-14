<?php

namespace App\Services\Community;

use App\Models\Post;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class PostSubmission
{
    public function __construct(private PostService $posts, private AttachmentService $attachments) {}

    public function submit(User $actor, array $data, array $uploads, string $intent, ?int $id = null, ?int $version = null): Post
    {
        // Authorize before physical I/O. PostService repeats authorization and
        // compares the original client version under its authoritative locks.
        if ($id === null) {
            Gate::forUser($actor)->authorize('create', Post::class);
        } else {
            $current = Post::with('author')->findOrFail($id);
            Gate::forUser($actor)->authorize('update', $current);
            if ($uploads !== [] && ! array_key_exists('attachment_ids', $data)) {
                $data['attachment_ids'] = $current->attachments()->pluck('attachments.id')->all();
            }
        }
        $created = [];
        try {
            foreach ($uploads as $upload) {
                $created[] = $this->attachments->store($actor, $upload)->id;
            }
            if ($created !== []) {
                $data['attachment_ids'] = [...($data['attachment_ids'] ?? []), ...$created];
            }

            return DB::transaction(function () use ($actor, $data, $intent, $id, $version) {
                $post = $id === null ? $this->posts->create($actor, $data) : $this->posts->update($actor, $id, $data, $version);

                return $intent === 'publish' ? $this->posts->publish($actor, $post->id, $post->version) : $post;
            });
        } catch (Throwable $error) {
            // Reservations are durable; compensate only newly uploaded objects.
            foreach ($created as $attachmentId) {
                try {
                    $this->attachments->requestDeletion($actor, $attachmentId);
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }
            throw $error;
        }
    }
}
