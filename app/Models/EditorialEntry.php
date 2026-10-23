<?php

namespace App\Models;

use App\Enums\EditorialKind;
use App\Enums\EditorialStatus;
use App\Enums\LegalResourceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

abstract class EditorialEntry extends Model
{
    use SoftDeletes;

    protected $guarded = ['*'];

    protected $hidden = ['author', 'attachments', 'revisions'];

    abstract public function kind(): EditorialKind;

    protected function casts(): array
    {
        return ['status' => EditorialStatus::class, 'version' => 'integer', 'published_at' => 'datetime',
            'editorial_updated_at' => 'datetime', 'resource_type' => LegalResourceType::class];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'content_category_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ContentRevision::class, $this->kind()->key());
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, $this->prefix().'_tag', $this->kind()->key(), 'tag_id')->withTimestamps();
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(LegalSpecialty::class, $this->prefix().'_specialty', $this->kind()->key(), 'legal_specialty_id')->withTimestamps();
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, $this->prefix().'_attachment', $this->kind()->key(), 'attachment_id')->withPivot('position')->withTimestamps()->orderByPivot('position');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('status', EditorialStatus::Published->value)->whereHas('author', fn ($author) => $author->where('status', 'active'));
    }

    private function prefix(): string
    {
        return $this->kind() === EditorialKind::Article ? 'article' : 'legal_resource';
    }
}
