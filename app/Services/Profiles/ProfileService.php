<?php

namespace App\Services\Profiles;

use App\Enums\AttentionMode;
use App\Enums\ProfessionalVerificationStatus;
use App\Enums\ProfileVisibility;
use App\Exceptions\ProfileException;
use App\Models\Attachment;
use App\Models\LegalSpecialty;
use App\Models\Municipality;
use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileService
{
    public function saveUserProfile(User $actor, int $userId, array $data): UserProfile
    {
        return $this->mutate($actor, $userId, UserProfile::class, function (UserProfile $profile, User $owner) use ($data): void {
            $rules = $this->commonRules() + [
                'display_name' => ['sometimes', 'nullable', 'string', 'max:150'],
                'username' => ['sometimes', 'nullable', 'string', 'regex:/\A[a-z][a-z0-9_]{2,29}\z/', Rule::unique('user_profiles')->ignore($profile->id)],
                'biography' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'photo_attachment_id' => ['sometimes', 'nullable', 'integer'],
            ];
            if (isset($data['username']) && is_string($data['username'])) {
                $data['username'] = strtolower(trim($data['username']));
                $data['username'] = $data['username'] === '' ? null : $data['username'];
            }
            $valid = $this->validate($data, $rules);
            if (isset($valid['photo_attachment_id'])) {
                $photo = Attachment::whereKey($valid['photo_attachment_id'])->lockForUpdate()->first();
                if (! $photo || $photo->category !== 'image'
                    || Gate::forUser($owner)->denies('view', $photo)) {
                    throw ValidationException::withMessages(['photo_attachment_id' => 'Selecciona una imagen propia y disponible.']);
                }
            }
            $profile->forceFill($valid)->save();
        });
    }

    public function saveProfessionalProfile(User $actor, int $userId, array $data): ProfessionalProfile
    {
        return $this->mutate($actor, $userId, ProfessionalProfile::class, function (ProfessionalProfile $profile) use ($data): void {
            $rules = $this->commonRules() + [
                'headline' => ['sometimes', 'nullable', 'string', 'max:180'],
                'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
                // Declarative identifier only; this product rule does not authenticate a credential.
                'license_number' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9]{5,12}\z/'],
                'years_experience' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:80'],
                'attention_mode' => ['sometimes', 'nullable', Rule::enum(AttentionMode::class)],
                'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
                'contact_phone' => ['sometimes', 'nullable', 'string', 'regex:/\A[+0-9() .-]{7,30}\z/'],
                'contact_visible' => ['sometimes', 'boolean'],
                'specialty_ids' => ['sometimes', 'array', 'max:20'],
                'specialty_ids.*' => ['required', 'integer', 'distinct'],
            ];
            $valid = $this->validate($data, $rules);
            $ids = array_key_exists('specialty_ids', $valid) ? array_map('intval', $valid['specialty_ids']) : null;
            unset($valid['specialty_ids']);
            $specialtiesChanged = false;
            if ($ids !== null) {
                $activeIds = LegalSpecialty::active()->whereKey($ids)->orderBy('id')->lockForUpdate()->pluck('id')->all();
                sort($ids);
                if ($ids !== $activeIds) {
                    throw ValidationException::withMessages(['specialty_ids' => 'Selecciona únicamente especialidades existentes y activas.']);
                }
                $currentIds = $profile->exists ? $profile->specialties()->orderBy('legal_specialties.id')->pluck('legal_specialties.id')->all() : [];
                $specialtiesChanged = $currentIds !== $ids;
            }
            $profile->forceFill($valid);
            $materialFields = ['headline', 'description', 'license_number', 'years_experience', 'municipality_id', 'attention_mode'];
            if (! $profile->exists || $profile->isDirty($materialFields) || $specialtiesChanged) {
                $profile->forceFill([
                    'verification_status' => ProfessionalVerificationStatus::Unverified,
                    'verified_at' => null,
                ]);
            }
            $profile->save();
            if ($ids !== null) {
                $profile->specialties()->sync($ids);
            }
        });
    }

    public function syncSpecialties(User $actor, int $userId, array $ids): ProfessionalProfile
    {
        return $this->saveProfessionalProfile($actor, $userId, ['specialty_ids' => $ids]);
    }

    public function userProfile(User $actor, int $userId): UserProfile
    {
        return $this->privateProfile($actor, $userId, UserProfile::class);
    }

    public function professionalProfile(User $actor, int $userId): ProfessionalProfile
    {
        return $this->privateProfile($actor, $userId, ProfessionalProfile::class);
    }

    public function publicUserProfile(int $userId): array
    {
        $profile = $this->publicProfile($userId, UserProfile::class);

        return [
            'id' => $profile->id,
            'username' => $profile->username,
            'display_name' => $profile->display_name ?? $profile->user->name,
            'biography' => $profile->biography,
            'website' => $profile->website,
            'location' => $this->location($profile->municipality),
        ];
    }

    public function publicProfessionalProfile(int $userId): array
    {
        $profile = $this->publicProfile($userId, ProfessionalProfile::class);
        $specialties = $profile->specialties()->active()->ordered()->get(['legal_specialties.id', 'name', 'slug']);

        return [
            'id' => $profile->id,
            'headline' => $profile->headline,
            'description' => $profile->description,
            'years_experience' => $profile->years_experience,
            'attention_mode' => $profile->attention_mode?->value,
            'website' => $profile->website,
            'verification_status' => $profile->verification_status->value,
            'location' => $this->location($profile->municipality),
            'specialties' => $specialties->map(fn ($specialty) => $specialty->only(['id', 'name', 'slug']))->all(),
            'contact' => $profile->contact_visible ? [
                'email' => $profile->contact_email,
                'phone' => $profile->contact_phone,
            ] : null,
        ];
    }

    private function mutate(User $actor, int $userId, string $model, callable $operation): UserProfile|ProfessionalProfile
    {
        try {
            return DB::transaction(function () use ($actor, $userId, $model, $operation) {
                // Lock users in a stable order; the owner lock serializes one-to-one creation.
                $users = User::whereKey([$actor->getKey(), $userId])->orderBy('id')->lockForUpdate()->get();
                $current = $users->firstWhere('id', $actor->getKey());
                $owner = $users->firstWhere('id', $userId);
                if (! $current || ! $owner) {
                    throw new AuthorizationException;
                }
                $profile = $model::where('user_id', $userId)->lockForUpdate()->first();
                if ($profile) {
                    Gate::forUser($current)->authorize('update', $profile);
                } else {
                    Gate::forUser($current)->authorize('create', [$model, $owner]);
                    $profile = new $model;
                    $profile->forceFill(['user_id' => $userId]);
                }
                $operation($profile, $owner);

                return $profile->fresh();
            });
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw ValidationException::withMessages(['profile' => 'El perfil o nombre de usuario ya está registrado.']);
            }
            report($error);
            throw new ProfileException;
        }
    }

    private function privateProfile(User $actor, int $userId, string $model): UserProfile|ProfessionalProfile
    {
        $current = User::find($actor->getKey());
        $profile = $model::where('user_id', $userId)->first();
        if (! $current || ! $profile) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('view', $profile);

        return $profile;
    }

    private function publicProfile(int $userId, string $model): UserProfile|ProfessionalProfile
    {
        $profile = $model::with(['user', 'municipality.state.country'])
            ->where('user_id', $userId)->where('visibility', ProfileVisibility::Public->value)
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))->first();

        if (! $profile) {
            throw (new ModelNotFoundException)->setModel($model);
        }

        return $profile;
    }

    private function commonRules(): array
    {
        return [
            'municipality_id' => ['sometimes', 'nullable', 'integer', Rule::exists('municipalities', 'id')],
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'visibility' => ['sometimes', Rule::enum(ProfileVisibility::class)],
        ];
    }

    private function validate(array $data, array $rules): array
    {
        $allowed = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
        if (array_diff(array_keys($data), $allowed)) {
            throw ValidationException::withMessages(['profile' => 'La solicitud contiene campos no permitidos.']);
        }

        return Validator::make($data, $rules)->validate();
    }

    private function location(?Municipality $municipality): ?array
    {
        if (! $municipality) {
            return null;
        }

        return [
            'municipality' => $municipality->only(['id', 'name', 'code']),
            'state' => $municipality->state->only(['id', 'name', 'code']),
            'country' => $municipality->state->country->only(['id', 'name', 'iso_code']),
        ];
    }
}
