<?php

namespace App\Models;

use App\Enums\AttentionMode;
use App\Enums\ProfessionalVerificationStatus;
use App\Enums\ProfileVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProfessionalProfile extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['license_number', 'contact_email', 'contact_phone', 'user_id', 'municipality_id', 'user'];

    protected function casts(): array
    {
        return [
            'license_number' => 'encrypted',
            'years_experience' => 'integer',
            'contact_visible' => 'boolean',
            'visibility' => ProfileVisibility::class,
            'verification_status' => ProfessionalVerificationStatus::class,
            'attention_mode' => AttentionMode::class,
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(LegalSpecialty::class, 'professional_profile_specialties')->withTimestamps();
    }
}
