<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Enums\PostVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    protected $guarded = ['*'];

    protected $hidden = ['author', 'attachments'];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class, 'visibility' => PostVisibility::class,
            'published_at' => 'datetime', 'edited_at' => 'datetime', 'version' => 'integer',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'content_category_id');
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(LegalSpecialty::class, 'legal_specialty_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, 'post_attachments')->withPivot('position')->withTimestamps()->orderByPivot('position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(PostReaction::class);
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('posts.status', PostStatus::Published->value)
            ->where('posts.visibility', PostVisibility::Public->value)
            ->whereHas('author', fn ($author) => $author->where('status', 'active'));
    }
}
