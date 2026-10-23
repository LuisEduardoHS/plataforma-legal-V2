<?php

namespace App\Models;

use App\Exceptions\ContentLibraryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContentRevision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['snapshot', 'actor', 'attachments'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new ContentLibraryException);
        static::deleting(fn () => throw new ContentLibraryException);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(LegalResource::class, 'legal_resource_id');
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, 'content_revision_attachments')->withPivot('position')->orderByPivot('position');
    }
}
