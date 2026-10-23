<?php

namespace App\Services\ContentLibrary;

use App\Enums\EditorialKind;
use App\Exceptions\ContentLibraryException;
use App\Models\EditorialEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EditorialMediaService
{
    public function __construct(private ContentAccess $access, private EditorialService $editorial) {}

    public function download(EditorialKind $kind, ?User $actor, int $id, int $attachmentId): StreamedResponse
    {
        try {
            [$buffer, $extension] = $this->access->entry($kind, $actor, $id, function (?User $actor, EditorialEntry $entry) use ($attachmentId) {
                Gate::forUser($actor)->authorize('view', $entry);
                $file = $entry->attachments()->where('attachments.id', $attachmentId)->lockForUpdate()->first();
                if (! $file) {
                    throw new AuthorizationException;
                }
                $this->editorial->assertAttachment($file, $entry);
                $settings = config('filesystems.disks.'.$file->disk, []);
                if (($settings['visibility'] ?? null) !== 'private' || ($settings['serve'] ?? false) || isset($settings['url'])
                    || ! in_array($settings['driver'] ?? null, ['local', 's3'], true)) {
                    throw new ContentLibraryException;
                }
                $source = null;
                $stream = null;
                try {
                    $source = Storage::disk($file->disk)->readStream($file->path);
                    $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
                    if (! is_resource($source) || ! is_resource($stream)) {
                        throw new ContentLibraryException;
                    }
                    $limit = (int) config('attachments.max_bytes.'.$file->category, 0);
                    if ($file->size_bytes < 1 || $file->size_bytes > $limit
                        || stream_copy_to_stream($source, $stream, $limit + 1) !== $file->size_bytes || ! rewind($stream)) {
                        throw new ContentLibraryException;
                    }
                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $stream);
                    if (! hash_equals($file->sha256, hash_final($hash)) || ! rewind($stream)) {
                        throw new ContentLibraryException;
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
        } catch (AuthorizationException|ValidationException|ContentLibraryException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);
            throw new ContentLibraryException;
        }

        return response()->streamDownload(function () use ($buffer) {
            try {
                fpassthru($buffer);
            } finally {
                fclose($buffer);
            }
        }, 'archivo-editorial-'.$attachmentId.'.'.$extension, [
            'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
