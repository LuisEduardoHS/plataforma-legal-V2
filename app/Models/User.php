<?php

namespace App\Models;

use App\Enums\UserStatus;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    protected $attributes = ['status' => 'active'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable {
        HasRoles::roles as private permissionRoles;
        HasRoles::permissions as private directPermissions;
    }

    public function roles(): BelongsToMany
    {
        return $this->permissionRoles()->sharedLock();
    }

    public function permissions(): BelongsToMany
    {
        return $this->directPermissions()->sharedLock();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected function performInsert(Builder $query)
    {
        $this->validatedStatus();

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        $next = $this->validatedStatus();
        if (! $this->isDirty('status')) {
            return parent::performUpdate($query);
        }

        // Compare with the persisted state, not a possibly stale instance.
        return $this->getConnection()->transaction(function () use ($query, $next) {
            $status = static::whereKey($this->getKey())->lockForUpdate()->value('status');
            $previous = is_string($status) ? UserStatus::tryFrom($status) : null;
            if (! $previous || ! $previous->canTransitionTo($next)) {
                throw ValidationException::withMessages(['status' => 'La transición de estado de cuenta no está permitida.']);
            }

            return parent::performUpdate($query);
        });
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        if ($column === 'status' || array_key_exists('status', $extra)) {
            throw ValidationException::withMessages(['status' => 'El estado de cuenta debe actualizarse mediante save.']);
        }

        return parent::incrementOrDecrement($column, $amount, $extra, $method);
    }

    private function validatedStatus(): UserStatus
    {
        $status = $this->getAttribute('status');
        $value = is_string($status) ? UserStatus::tryFrom($status) : null;
        if (! $value) {
            throw ValidationException::withMessages(['status' => 'El estado de cuenta no es válido.']);
        }

        return $value;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function professionalProfile(): HasOne
    {
        return $this->hasOne(ProfessionalProfile::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function legalResources(): HasMany
    {
        return $this->hasMany(LegalResource::class);
    }

    public function savedItems(): HasMany
    {
        return $this->hasMany(SavedItem::class);
    }

    public function conversationParticipants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function blockedUsers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'user_blocks', 'blocker_id', 'blocked_id')->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function following(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'user_follows', 'follower_id', 'followed_id')->withTimestamps();
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'user_follows', 'followed_id', 'follower_id')->withTimestamps();
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isDisabled(): bool
    {
        return $this->status === 'disabled';
    }

    public function isPendingDeletion(): bool
    {
        return $this->status === 'pending_deletion';
    }
}
