<?php

namespace App\Models;

use App\Enums\VerificationAction;
use App\Enums\VerificationRequestStatus;
use App\Exceptions\VerificationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['actor', 'request', 'attachment'];

    protected function casts(): array
    {
        return [
            'action' => VerificationAction::class,
            'from_status' => VerificationRequestStatus::class,
            'to_status' => VerificationRequestStatus::class,
            'created_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query)
    {
        throw new VerificationException;
    }

    protected function performDeleteOnModel()
    {
        throw new VerificationException;
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new VerificationException;
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(VerificationRequest::class, 'verification_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
