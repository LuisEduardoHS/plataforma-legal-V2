<?php

namespace App\Services\Attachments;

use App\Enums\AttachmentStatus;
use App\Exceptions\AttachmentException;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AttachmentService
{
    public function __construct(private AttachmentValidator $validator) {}

    public function store(User $actor, UploadedFile $file): Attachment
    {
        Gate::forUser($this->actor($actor))->authorize('create', Attachment::class);
        $metadata = $this->validator->validate($file);

        // A durable reservation must survive a failed upload or a process crash.
        if (DB::transactionLevel() !== 0) {
            throw new AttachmentException;
        }

        $attachment = null;
        $stream = null;
        try {
            $disk = config('attachments.disk');
            $this->assertPrivateDisk($disk);
            $name = Str::uuid().'.'.$metadata['extension'];
            $attachment = new Attachment;
            $attachment->forceFill($metadata + [
                'user_id' => $actor->getKey(),
                'storage_name' => $name,
                'path' => 'attachments/'.now()->format('Y/m').'/'.$name,
                'disk' => $disk,
                'visibility' => 'private',
                'status' => AttachmentStatus::PendingUpload,
            ])->save();

            $stream = fopen($file->getRealPath(), 'rb');
            if (! is_resource($stream)) {
                throw new AttachmentException;
            }

            DB::transaction(function () use ($attachment, $stream): void {
                $record = Attachment::whereKey($attachment->id)->lockForUpdate()->firstOrFail();
                if ($record->status !== AttachmentStatus::PendingUpload
                    || ! Storage::disk($record->disk)->put($record->path, $stream, ['visibility' => 'private'])) {
                    throw new AttachmentException;
                }
                $record->forceFill(['status' => AttachmentStatus::Available])->save();
            });

            return $attachment->fresh();
        } catch (Throwable $error) {
            report($error);
            if ($attachment?->exists) {
                $this->cleanupFailedUpload($attachment);
            }
            throw new AttachmentException;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function metadata(User $actor, int $id): array
    {
        return $this->authorized($actor, $id, 'view')->toArray();
    }

    public function download(User $actor, int $id): StreamedResponse
    {
        $record = $this->authorized($actor, $id, 'view');
        try {
            $this->assertPrivateDisk($record->disk);
            $stream = Storage::disk($record->disk)->readStream($record->path);
            if (! is_resource($stream)) {
                throw new AttachmentException;
            }
        } catch (Throwable $error) {
            report($error);
            throw new AttachmentException;
        }

        // Open before sending headers so storage failures do not leak backend errors.
        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 'archivo-'.$record->id.'.'.$record->extension, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function requestDeletion(User $actor, int $id): void
    {
        $this->safely(fn () => DB::transaction(function () use ($actor, $id): void {
            $record = $this->authorized($actor, $id, 'delete', true);
            if ($this->isVerificationRetained($record) || $this->isCommunityReferenced($record) || $this->isMessagingReferenced($record)) {
                throw new AttachmentException;
            }
            if (in_array($record->status, [AttachmentStatus::PendingDeletion, AttachmentStatus::Deleted], true)) {
                return;
            }
            $record->forceFill([
                'status' => AttachmentStatus::PendingDeletion,
                'deletion_requested_at' => now(),
            ])->save();
        }));
    }

    public function purge(User $actor, int $id): void
    {
        try {
            DB::transaction(function () use ($actor, $id): void {
                $record = $this->authorized($actor, $id, 'delete', true);
                if ($this->isVerificationRetained($record) || $this->isCommunityReferenced($record) || $this->isMessagingReferenced($record)) {
                    throw new AttachmentException;
                }
                if ($record->status === AttachmentStatus::Deleted) {
                    return;
                }
                if ($record->status !== AttachmentStatus::PendingDeletion) {
                    throw new AttachmentException;
                }
                $this->assertPrivateDisk($record->disk);
                // Flysystem deletion is idempotent, including when the object is missing.
                if (! Storage::disk($record->disk)->delete($record->path)) {
                    throw new AttachmentException;
                }
                $record->forceFill(['status' => AttachmentStatus::Deleted, 'deleted_at' => now()])->save();
            });
        } catch (AuthorizationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);
            throw new AttachmentException;
        }
    }

    private function actor(User $actor): User
    {
        // Recheck persisted account state, including when the caller holds a stale model.
        $current = $this->safely(fn () => User::find($actor->getKey()));
        if (! $current || ! $current->isActive()) {
            throw new AuthorizationException;
        }

        return $current;
    }

    private function authorized(User $actor, int $id, string $ability, bool $lock = false): Attachment
    {
        $current = $this->actor($actor);
        $query = Attachment::whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $record = $this->safely(fn () => $query->first());
        if (! $record) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize($ability, $record);

        return $record;
    }

    private function safely(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (AuthorizationException|AttachmentException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);
            throw new AttachmentException;
        }
    }

    private function assertPrivateDisk(string $disk): void
    {
        $settings = config('filesystems.disks.'.$disk, []);
        if (($settings['visibility'] ?? null) !== 'private' || ($settings['serve'] ?? false)
            || ! in_array($settings['driver'] ?? null, ['local', 's3'], true)
            || isset($settings['url'])) {
            throw new AttachmentException;
        }
    }

    private function cleanupFailedUpload(Attachment $record): void
    {
        // If cleanup itself fails, the reservation keeps the exact key for a retry.
        try {
            DB::transaction(function () use ($record): void {
                $current = Attachment::whereKey($record->id)->lockForUpdate()->first();
                // Never compensate a committed upload or an evidence retained after it.
                if (! $current || $current->status === AttachmentStatus::Available
                    || $current->verification_retained_at !== null) {
                    return;
                }
                $deleted = Storage::disk($current->disk)->delete($current->path);
                $current->forceFill([
                    'status' => $deleted ? AttachmentStatus::Deleted : AttachmentStatus::PendingDeletion,
                    'deletion_requested_at' => now(),
                    'deleted_at' => $deleted ? now() : null,
                ])->save();
            });
        } catch (Throwable $error) {
            report($error);
            // A pending_upload reservation also remains recoverable via requestDeletion.
        }
    }

    private function isVerificationRetained(Attachment $record): bool
    {
        if ($record->verification_retained_at !== null) {
            return true;
        }

        // A cleared marker must not release a document still bound to a submission.
        // A locking read sees the latest committed association, not an older RR snapshot.
        return DB::table('verification_evidences as evidence')
            ->join('verification_requests as request', 'request.id', '=', 'evidence.verification_request_id')
            ->where('evidence.attachment_id', $record->id)->whereNotNull('request.submitted_at')
            ->select('evidence.id')->lockForUpdate()->first() !== null;
    }

    private function isCommunityReferenced(Attachment $record): bool
    {
        return DB::table('post_attachments as linked')
            ->join('posts', 'posts.id', '=', 'linked.post_id')
            ->where('linked.attachment_id', $record->id)->whereNull('posts.deleted_at')
            ->select('linked.post_id')->lockForUpdate()->first() !== null;
    }

    private function isMessagingReferenced(Attachment $record): bool
    {
        return DB::table('message_attachments as linked')->join('messages', 'messages.id', '=', 'linked.message_id')
            ->where('linked.attachment_id', $record->id)->whereNull('messages.deleted_at')
            ->select('linked.message_id')->lockForUpdate()->first() !== null;
    }
}
