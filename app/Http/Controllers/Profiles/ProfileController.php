<?php

namespace App\Http\Controllers\Profiles;

use App\Exceptions\ProfileException;
use App\Exceptions\VerificationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profiles\SaveProfessionalProfileRequest;
use App\Http\Requests\Profiles\SaveUserProfileRequest;
use App\Models\LegalSpecialty;
use App\Models\Municipality;
use App\Models\State;
use App\Services\Profiles\ProfileService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    public function __construct(private ProfileService $profiles) {}

    public function show(Request $request)
    {
        return $this->read(function () use ($request) {
            [$profile, $professional] = $this->ownData($request);

            return view('pages.profile.show', compact('profile', 'professional'));
        });
    }

    public function edit(Request $request)
    {
        return $this->editForm($request, false);
    }

    public function editProfessional(Request $request)
    {
        return $this->editForm($request, true);
    }

    private function editForm(Request $request, bool $isProfessional)
    {
        $validator = Validator::make($request->only('state_id'), ['state_id' => ['nullable', 'integer', 'exists:states,id']]);
        if ($validator->fails()) {
            return to_route($isProfessional ? 'profile.professional.edit' : 'profile.edit')->withErrors(['state_id' => 'Selecciona un estado válido.']);
        }
        $state = $validator->validated();

        return $this->read(function () use ($request, $isProfessional, $state) {
            [$personal, $professional] = $this->ownData($request);
            $profile = $isProfessional ? $professional : $personal;
            $selectedState = $state['state_id'] ?? $profile['state_id'] ?? null;
            $municipalities = Municipality::where('state_id', $selectedState)->orderBy('name')->get(['id', 'name']);
            if ($profile && $profile['municipality_id'] && ! $municipalities->contains('id', $profile['municipality_id'])) {
                $municipalities->push(Municipality::findOrFail($profile['municipality_id'], ['id', 'name']));
            }

            return view('pages.profile.edit', [
                'profile' => $profile, 'isProfessional' => $isProfessional,
                'states' => State::orderBy('name')->get(['id', 'name']),
                'selectedState' => $selectedState, 'municipalities' => $municipalities,
                'specialties' => $isProfessional ? LegalSpecialty::active()->ordered()->get(['id', 'name']) : collect(),
            ]);
        });
    }

    public function update(SaveUserProfileRequest $request)
    {
        try {
            $this->profiles->saveUserProfile($request->user(), $request->user()->id, $request->validated());
        } catch (ProfileException $error) {
            return back()->withErrors(['profile' => $error->getMessage()]);
        }

        return to_route('profile')->with('status', 'Tu perfil se guardó correctamente.');
    }

    public function updateProfessional(SaveProfessionalProfileRequest $request)
    {
        $data = $request->safe()->except('update_specialties');
        if ($request->boolean('update_specialties')) {
            $data['specialty_ids'] = $data['specialty_ids'] ?? [];
        } else {
            unset($data['specialty_ids']);
        }
        // A blank replacement field preserves the encrypted credential.
        if (empty($data['license_number'])) {
            unset($data['license_number']);
        }
        try {
            $this->profiles->saveProfessionalProfile($request->user(), $request->user()->id, $data);
        } catch (ProfileException|VerificationException $error) {
            return back()->withErrors(['profile' => $error->getMessage()]);
        }

        return to_route('profile')->with('status', 'Tu perfil profesional se guardó. Los cambios materiales requieren una nueva verificación.');
    }

    private function ownData(Request $request): array
    {
        $user = $request->user();
        $personal = null;
        if ($user->profile()->exists()) {
            $model = $this->profiles->userProfile($user, $user->id)->load('municipality.state.country', 'photo');
            $personal = $model->only(['display_name', 'username', 'biography', 'website', 'municipality_id']);
            $personal += [
                'name' => $model->display_name ?? $user->name,
                'visibility' => $model->visibility->value,
                'state_id' => $model->municipality?->state_id,
                'location' => $model->municipality ? $model->municipality->name.', '.$model->municipality->state->name.', '.$model->municipality->state->country->name : null,
                'has_photo' => $model->photo && $model->photo->category === 'image' && Gate::forUser($user)->allows('view', $model->photo),
            ];
        }
        $professional = null;
        if ($user->professionalProfile()->exists()) {
            $model = $this->profiles->professionalProfile($user, $user->id)->load('municipality.state.country', 'specialties');
            $professional = $model->only(['headline', 'description', 'years_experience', 'municipality_id', 'website', 'contact_email', 'contact_phone', 'contact_visible']);
            $professional += [
                'visibility' => $model->visibility->value,
                'verification_status' => $model->verification_status->value,
                'license_registered' => $model->getRawOriginal('license_number') !== null,
                'attention_mode' => $model->attention_mode?->value,
                'state_id' => $model->municipality?->state_id,
                'location' => $model->municipality ? $model->municipality->name.', '.$model->municipality->state->name : null,
                'specialty_ids' => $model->specialties->pluck('id')->all(),
                'specialties' => $model->specialties->pluck('name')->all(),
            ];
        }

        return [$personal, $professional];
    }

    private function read(callable $read)
    {
        try {
            return DB::transaction($read);
        } catch (QueryException $error) {
            report($error);

            return response('Los perfiles no están disponibles temporalmente.', 503)->header('Content-Type', 'text/plain; charset=UTF-8');
        }
    }
}
