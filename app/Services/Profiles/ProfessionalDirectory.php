<?php

namespace App\Services\Profiles;

use App\Enums\ProfileVisibility;
use App\Models\ProfessionalProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProfessionalDirectory
{
    public function search(array $filters): LengthAwarePaginator
    {
        return DB::transaction(function () use ($filters) {
            $query = ProfessionalProfile::query()
                ->select(['id', 'user_id', 'headline', 'municipality_id', 'attention_mode', 'verification_status'])
                ->where('visibility', ProfileVisibility::Public->value)
                ->whereHas('user', fn ($q) => $q->where('status', 'active')->sharedLock())
                ->with([
                    'user' => fn ($q) => $q->select('id', 'name', 'status')->sharedLock(),
                    'user.profile' => fn ($q) => $q->select('id', 'user_id', 'display_name', 'username')->where('visibility', 'public')->sharedLock(),
                    'municipality.state.country',
                    'specialties' => fn ($q) => $q->active()->ordered()->sharedLock(),
                ])->sharedLock();

            if (! empty($filters['specialty_id'])) {
                $query->whereHas('specialties', fn ($q) => $q->active()->whereKey($filters['specialty_id']));
            }
            if (! empty($filters['state_id'])) {
                $query->whereHas('municipality', fn ($q) => $q->where('state_id', $filters['state_id']));
            }
            if (! empty($filters['municipality_id'])) {
                $query->where('municipality_id', $filters['municipality_id']);
            }
            if (isset($filters['q']) && $filters['q'] !== '') {
                // Escape wildcard characters as literals; all input remains bound.
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
                $like = fn ($q, $column) => $q->whereRaw($column." LIKE ? ESCAPE '!'", [$term]);
                $query->where(function ($q) use ($like) {
                    $like($q, 'headline');
                    $q->orWhereHas('specialties', fn ($s) => $s->active()->where(fn ($s) => $like($s, 'name')))
                        ->orWhereHas('municipality', fn ($m) => $m->where(fn ($m) => $like($m, 'name'))->orWhereHas('state', fn ($s) => $like($s, 'name')))
                        ->orWhereHas('user.profile', function ($p) use ($like) {
                            $p->where('visibility', 'public')->where(function ($p) use ($like) {
                                $like($p, 'display_name');
                                $p->orWhere(fn ($p) => $like($p, 'username'))
                                    ->orWhere(fn ($p) => $p->whereNull('display_name')->whereHas('user', fn ($u) => $like($u, 'name')));
                            });
                        });
                });
            }

            return $query->orderBy('id')->paginate(12)->through(function (ProfessionalProfile $profile) {
                // The personal profile has independent visibility. Never fall back
                // to account identity when it is private or absent.
                $name = $profile->user->profile
                    ? ($profile->user->profile->display_name ?? $profile->user->name) : 'Profesional';
                $municipality = $profile->municipality;

                return [
                    'id' => $profile->id,
                    'name' => $name,
                    'initials' => mb_strtoupper(mb_substr($name, 0, 1)),
                    'headline' => $profile->headline,
                    'specialties' => $profile->specialties->pluck('name')->all(),
                    'location' => $municipality ? $municipality->name.', '.$municipality->state->name : 'Ubicación no indicada',
                    'attention_mode' => $profile->attention_mode?->value,
                    'verification_status' => $profile->verification_status->value,
                    'url' => route('professionals.show', $profile->user_id),
                ];
            });
        });
    }
}
