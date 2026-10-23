<?php

namespace App\Models;

use App\Enums\ProfileVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['user_id', 'photo_attachment_id', 'municipality_id', 'user', 'photo'];

    protected function casts(): array
    {
        return ['visibility' => ProfileVisibility::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'photo_attachment_id');
    }
}
