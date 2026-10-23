<?php

namespace App\Models;

use App\Enums\AttachmentStatus;
use App\Exceptions\AttachmentException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    // Metadata and lifecycle may only be assigned by trusted backend code.
    protected $guarded = ['*'];

    protected $hidden = ['disk', 'path', 'storage_name', 'sha256', 'verification_retained_at', 'community_published_at'];

    protected static function booted(): void
    {
        static::updating(function (self $attachment): void {
            $retained = static::whereKey($attachment->id)->value('verification_retained_at');
            $published = static::whereKey($attachment->id)->value('community_published_at');
            if ($published !== null && $attachment->isDirty('community_published_at')) {
                throw new AttachmentException;
            }
            if ($retained !== null && $attachment->isDirty([
                'user_id', 'original_name', 'disk', 'path', 'storage_name', 'category', 'visibility',
                'extension', 'mime_type', 'sha256', 'size_bytes', 'status', 'deletion_requested_at',
                'deleted_at', 'verification_retained_at', 'community_published_at',
            ])) {
                throw new AttachmentException;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'status' => AttachmentStatus::class,
            'deletion_requested_at' => 'datetime',
            'deleted_at' => 'datetime',
            'verification_retained_at' => 'datetime',
            'community_published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', AttachmentStatus::Available->value);
    }
}
