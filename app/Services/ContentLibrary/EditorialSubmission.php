<?php

namespace App\Services\ContentLibrary;

use App\Enums\EditorialKind;
use App\Enums\EditorialStatus;
use App\Models\EditorialEntry;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class EditorialSubmission
{
    public function __construct(private EditorialService $editorial, private AttachmentService $attachments, private ContentAccess $access) {}

    public function submit(EditorialKind $kind, User $actor, array $data, array $uploads, string $intent, ?int $id = null, ?int $version = null): EditorialEntry
    {
        // Avoid physical I/O before authorization/version checks. The domain
        // repeats these checks under its locks after uploads have completed.
        if ($id === null) {
            $this->access->actor($actor, function (User $current) use ($kind, $intent) {
                $model = $kind->model();
                Gate::forUser($current)->authorize('create', $model);
                if ($intent === 'publish') {
                    $entry = new $model;
                    $entry->forceFill(['user_id' => $current->id]);
                    Gate::forUser($current)->authorize('publish', $entry);
                }
            });
        } else {
            $this->access->entry($kind, $actor, $id, function (User $current, EditorialEntry $entry) use ($version, $intent, $uploads) {
                Gate::forUser($current)->authorize('update', $entry);
                $this->access->version($entry, $version);
                if ($uploads !== [] && (int) $entry->user_id !== (int) $current->id) {
                    $this->access->invalid('uploads', 'Solo el autor puede agregar archivos propios al contenido.');
                }
                if ($intent === 'publish' || $entry->status === EditorialStatus::Published) {
                    Gate::forUser($current)->authorize('publish', $entry);
                }
            });
        }
        $created = [];
        try {
            foreach ($uploads as $upload) {
                $created[] = $this->attachments->store($actor, $upload)->id;
            }
            $data['attachment_ids'] = [...($data['attachment_ids'] ?? []), ...$created];

            return DB::transaction(function () use ($kind, $actor, $data, $intent, $id, $version) {
                $entry = $id === null ? $this->editorial->create($kind, $actor, $data)
                    : $this->editorial->update($kind, $actor, $id, $data, $version);

                return $intent === 'publish' && $entry->status !== EditorialStatus::Published
                    ? $this->editorial->transition($kind, $actor, $entry->id, EditorialStatus::Published, $entry->version) : $entry;
            });
        } catch (Throwable $error) {
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
