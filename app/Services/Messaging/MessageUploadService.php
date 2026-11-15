<?php

namespace App\Services\Messaging;

use App\Models\User;
use App\Services\Attachments\AttachmentService;
use Throwable;

class MessageUploadService
{
    public function __construct(private MessagingAccess $access, private AttachmentService $attachments) {}

    public function upload(User $actor, int $conversationId, array $uploads): array
    {
        $authorize = fn () => $this->access->conversation($actor, $conversationId,
            fn (User $current, User $target) => $this->access->unblocked($current, $target));
        $authorize();
        $created = [];
        try {
            foreach ($uploads as $upload) {
                $created[] = $this->attachments->store($actor, $upload);
            }
            $authorize();

            return array_map(fn ($file) => ['id' => $file->id, 'name' => $file->original_name, 'extension' => $file->extension,
                'mime_type' => $file->mime_type, 'size_bytes' => $file->size_bytes], $created);
        } catch (Throwable $error) {
            foreach ($created as $file) {
                try {
                    $this->attachments->requestDeletion($actor, $file->id);
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }
            throw $error;
        }
    }
}
