<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LexaConversation extends Model
{
    use SoftDeletes;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'version' => 'integer',
            'last_message_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LexaMessage::class, 'lexa_conversation_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'archived'])
            ->whereNull('deleted_at');
    }
}
