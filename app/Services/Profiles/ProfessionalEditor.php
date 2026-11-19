<?php

namespace App\Services\Profiles;

use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Services\Verification\VerificationIntegrity;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** HTTP confirmation only. ProfileService remains responsible for every domain write. */
class ProfessionalEditor
{
    public function __construct(private ProfileService $profiles) {}

    public function version(User $actor): string
    {
        return $this->locked($actor, fn ($profile) => $this->stamp($actor, $profile));
    }

    public function prepare(Request $request, array $data): ?array
    {
        return $this->locked($request->user(), function ($profile) use ($request, $data) {
            $version = $this->stamp($request->user(), $profile);
            $this->checkVersion($version, $request->input('profile_version'));
            $data = $this->data($data);
            $categories = $this->categories($profile, $data);
            if ($categories === []) {
                $this->profiles->saveProfessionalProfile($request->user(), $request->user()->id, $data);

                return null;
            }

            return [
                'categories' => $categories,
                'notice' => match ($profile->verification_status->value) {
                    'verified' => 'Guardar estos cambios invalidará tu verificación vigente. La insignia dejará de mostrarse hasta una nueva aprobación.',
                    'pending' => 'Guardar estos cambios invalidará tu solicitud presentada. Tendrás que preparar una nueva solicitud para pedir revisión.',
                    default => 'Vas a retirar las especialidades inactivas de tu presentación. Revisa la selección antes de confirmarla.',
                },
                'pending_change' => Crypt::encryptString(json_encode([
                    'purpose' => 'professional-edit-v1', 'user' => $request->user()->id,
                    'session' => $this->session($request), 'expires' => now()->addMinutes(5)->timestamp,
                    'version' => $version, 'data' => $data,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ];
        });
    }

    public function update(Request $request, array $data): void
    {
        if ($request->filled('pending_change')) {
            $pending = $this->pending($request);
            abort_unless($request->boolean('confirm_material'), 409);
            $this->locked($request->user(), function ($profile) use ($request, $pending) {
                $this->checkVersion($this->stamp($request->user(), $profile), $pending['version'] ?? null);
                $this->profiles->saveProfessionalProfile($request->user(), $request->user()->id, $pending['data']);
            });

            return;
        }

        // Preserve the existing unverified API contract, but never bypass a required confirmation.
        $this->locked($request->user(), function ($profile) use ($request, $data) {
            if ($request->exists('profile_version')) {
                $this->checkVersion($this->stamp($request->user(), $profile), $request->input('profile_version'));
            }
            $data = $this->data($data);
            if ($this->categories($profile, $data) !== []) {
                throw ValidationException::withMessages(['profile' => 'Revisa y confirma los cambios desde el editor antes de guardar.']);
            }
            $this->profiles->saveProfessionalProfile($request->user(), $request->user()->id, $data);
        });
    }

    public function oldInput(Request $request): array
    {
        if ($request->filled('pending_change')) {
            $pending = $this->pending($request);
            $data = $pending['data'] + ['profile_version' => $pending['version'],
                'update_specialties' => array_key_exists('specialty_ids', $pending['data'])];
        } else {
            $data = $request->validated();
        }
        unset($data['license_number'], $data['pending_change'], $data['confirm_material']);

        return $data;
    }

    private function pending(Request $request): array
    {
        try {
            $pending = json_decode(Crypt::decryptString($request->input('pending_change')), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $error) {
            abort(409, 'La confirmación no es válida. Abre de nuevo la presentación.');
        }
        abort_unless(is_array($pending) && ($pending['purpose'] ?? null) === 'professional-edit-v1'
            && ($pending['user'] ?? null) === $request->user()->id
            && is_string($pending['session'] ?? null) && hash_equals($this->session($request), $pending['session'])
            && is_int($pending['expires'] ?? null) && $pending['expires'] >= now()->timestamp
            && is_array($pending['data'] ?? null), 409,
            'La confirmación caducó o pertenece a otra sesión. Revisa la presentación de nuevo.');

        return $pending;
    }

    private function data(array $data): array
    {
        $update = filter_var($data['update_specialties'] ?? false, FILTER_VALIDATE_BOOLEAN);
        unset($data['update_specialties'], $data['profile_version']);
        if ($update) {
            $data['specialty_ids'] = $data['specialty_ids'] ?? [];
        } else {
            unset($data['specialty_ids']);
        }
        if (empty($data['license_number'])) {
            unset($data['license_number']);
        }

        return $data;
    }

    private function categories(?ProfessionalProfile $profile, array $data): array
    {
        if (! $profile) {
            return [];
        }
        $categories = [];
        $labels = ['headline' => 'Presentación breve', 'description' => 'Descripción profesional',
            'license_number' => 'Cédula declarada (privada)', 'years_experience' => 'Experiencia',
            'municipality_id' => 'Ubicación', 'attention_mode' => 'Modalidad de atención'];
        $candidate = clone $profile;
        $fields = $data;
        unset($fields['specialty_ids']);
        $candidate->forceFill($fields);
        $hasVerification = in_array($profile->verification_status->value, ['verified', 'pending'], true);
        if ($hasVerification) {
            foreach (VerificationIntegrity::MATERIAL_FIELDS as $field) {
                if ($candidate->isDirty($field)) {
                    $categories[] = $labels[$field];
                }
            }
        }
        if (array_key_exists('specialty_ids', $data)) {
            $ids = array_map('intval', $data['specialty_ids']);
            sort($ids);
            $current = $profile->specialties->pluck('id')->sort()->values()->all();
            if ($hasVerification && $ids !== $current) {
                $categories[] = 'Especialidades';
            }
            if ($profile->specialties->contains(fn ($specialty) => ! $specialty->is_active && ! in_array($specialty->id, $ids, true))) {
                $categories[] = 'Retirada de las especialidades inactivas registradas';
            }
        }

        return $categories;
    }

    private function stamp(User $actor, ?ProfessionalProfile $profile): string
    {
        return hash_hmac('sha256', json_encode([
            'professional-edit-v1', $actor->id, $profile?->getAttributes(),
            $profile?->specialties->map(fn ($item) => [$item->id, $item->is_active, $item->getRawOriginal('updated_at')])->all(),
            // Closed dossiers are immutable; the latest row detects every new/current transition.
            $profile?->verificationRequests()->orderByDesc('id')->lockForUpdate()->first(['id', 'status', 'updated_at'])?->toArray(),
        ], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function checkVersion(string $current, mixed $version): void
    {
        abort_unless(is_string($version) && hash_equals($current, $version), 409,
            'La presentación o su expediente cambió. Abre los datos actuales antes de volver a guardar.');
    }

    private function session(Request $request): string
    {
        return hash_hmac('sha256', $request->session()->getId(), config('app.key'));
    }

    private function locked(User $actor, callable $operation): mixed
    {
        return DB::transaction(function () use ($actor, $operation) {
            $current = User::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($current?->isActive(), 403);
            $profile = ProfessionalProfile::where('user_id', $actor->id)->lockForUpdate()->first();
            if ($profile) {
                Gate::forUser($current)->authorize('update', $profile);
                $profile->setRelation('specialties', $profile->specialties()->orderBy('legal_specialties.id')->lockForUpdate()->get());
            } else {
                Gate::forUser($current)->authorize('create', [ProfessionalProfile::class, $current]);
            }

            return $operation($profile);
        });
    }
}
