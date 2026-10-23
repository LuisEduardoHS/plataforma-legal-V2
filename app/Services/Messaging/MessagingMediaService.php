<?php

namespace App\Services\Messaging;

use App\Enums\MessageStatus;
use App\Exceptions\MessagingException;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MessagingMediaService
{
    public function __construct(private MessagingAccess $access, private MessageService $messages) {}

    public function download(User $actor, int $conversationId, int $messageId, int $attachmentId): StreamedResponse
    {
        try {
            [$buffer, $extension] = $this->access->conversation($actor, $conversationId, function (User $actor, User $target, Conversation $conversation, ConversationParticipant $participant) use ($messageId, $attachmentId) {
                $message = $this->access->visible($conversation->id, $participant)->whereKey($messageId)->where('status', MessageStatus::Sent)->lockForUpdate()->first();
                if (! $message) {
                    throw new AuthorizationException;
                }
                $file = $message->attachments()->where('attachments.id', $attachmentId)->lockForUpdate()->first();
                if (! $file) {
                    throw new AuthorizationException;
                }
                $this->messages->assertAttachment($file, $message->sender_id);
                $settings = config('filesystems.disks.'.$file->disk, []);
                if (($settings['visibility'] ?? null) !== 'private' || ($settings['serve'] ?? false) || isset($settings['url'])
                    || ! in_array($settings['driver'] ?? null, ['local', 's3'], true)) {
                    throw new MessagingException;
                }
                $source = null;
                $stream = null;
                try {
                    $source = Storage::disk($file->disk)->readStream($file->path);
                    $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
                    if (! is_resource($source) || ! is_resource($stream)) {
                        throw new MessagingException;
                    }
                    $limit = (int) config('attachments.max_bytes.'.$file->category, 0);
                    if ($file->size_bytes < 1 || $file->size_bytes > $limit
                        || stream_copy_to_stream($source, $stream, $limit + 1) !== $file->size_bytes || ! rewind($stream)) {
                        throw new MessagingException;
                    }
                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $stream);
                    if (! hash_equals($file->sha256, hash_final($hash)) || ! rewind($stream)) {
                        throw new MessagingException;
                    }

                    return [$stream, $file->extension];
                } catch (Throwable $error) {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    throw $error;
                } finally {
                    if (is_resource($source)) {
                        fclose($source);
                    }
                }
            });
        } catch (AuthorizationException|ValidationException|MessagingException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);
            throw new MessagingException;
        }

        return response()->streamDownload(function () use ($buffer) {
            try {
                fpassthru($buffer);
            } finally {
                fclose($buffer);
            }
        }, 'archivo-mensaje-'.$attachmentId.'.'.$extension, [
            'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
