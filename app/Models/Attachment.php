<?php

namespace App\Models;

use App\Enums\AttachmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    // Metadata and lifecycle may only be assigned by trusted backend code.
    protected $guarded = ['*'];

    protected $hidden = ['disk', 'path', 'storage_name', 'sha256'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'status' => AttachmentStatus::class,
            'deletion_requested_at' => 'datetime',
            'deleted_at' => 'datetime',
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
