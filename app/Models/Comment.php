<?php

namespace App\Models;

use App\Enums\CommentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use SoftDeletes;

    protected $guarded = ['*'];

    protected $hidden = ['author'];

    protected function casts(): array
    {
        return ['status' => CommentStatus::class, 'edited_at' => 'datetime', 'version' => 'integer'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeVisibleInCommunity(Builder $query): Builder
    {
        return $query->where('comments.status', CommentStatus::Visible->value)
            ->whereHas('author', fn ($author) => $author->where('status', 'active'))
            ->where(fn ($parents) => $parents->whereNull('parent_id')->orWhereHas('parent', fn ($parent) => $parent
                ->where('status', CommentStatus::Visible->value)
                ->whereHas('author', fn ($author) => $author->where('status', 'active'))));
    }
}
