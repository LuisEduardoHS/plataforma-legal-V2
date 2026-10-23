<?php

namespace App\Services\Community;

use App\Exceptions\CommunityException;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CommunityMediaService
{
    public function __construct(private CommunityAccess $access, private PostService $posts) {}

    public function download(?User $viewer, int $postId, int $attachmentId): StreamedResponse
    {
        $buffer = null;
        try {
            [$buffer, $extension] = $this->access->withPost($viewer, $postId, function (?User $viewer, Post $post) use ($attachmentId): array {
                Gate::forUser($viewer)->authorize('view', $post);
                $file = $post->attachments()->where('attachments.id', $attachmentId)->lockForUpdate()->first();
                if (! $file) {
                    throw new AuthorizationException;
                }
                $this->posts->assertAttachment($file, $post);
                $settings = config('filesystems.disks.'.$file->disk, []);
                if (($settings['visibility'] ?? null) !== 'private' || ($settings['serve'] ?? false)
                    || isset($settings['url']) || ! in_array($settings['driver'] ?? null, ['local', 's3'], true)) {
                    throw new CommunityException;
                }
                $source = null;
                $stream = null;
                try {
                    $source = Storage::disk($file->disk)->readStream($file->path);
                    $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
                    if (! is_resource($source) || ! is_resource($stream)) {
                        throw new CommunityException;
                    }
                    $limit = (int) config('attachments.max_bytes.'.$file->category, 0);
                    if ($file->size_bytes < 1 || $file->size_bytes > $limit
                        || stream_copy_to_stream($source, $stream, $limit + 1) !== $file->size_bytes || ! rewind($stream)) {
                        throw new CommunityException;
                    }
                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $stream);
                    if (! hash_equals($file->sha256, hash_final($hash)) || ! rewind($stream)) {
                        throw new CommunityException;
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
        } catch (AuthorizationException|ValidationException|CommunityException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);
            throw new CommunityException;
        }

        return response()->streamDownload(function () use ($buffer): void {
            try {
                fpassthru($buffer);
            } finally {
                fclose($buffer);
            }
        }, 'archivo-comunidad-'.$attachmentId.'.'.$extension, [
            'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
