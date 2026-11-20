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
use App\Services\Profiles\ProfessionalEditor;
use App\Services\Profiles\ProfessionalHub;
use App\Services\Profiles\ProfileService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function __construct(private ProfileService $profiles) {}

    public function show(Request $request)
    {
        return $this->read(function () use ($request) {
            // Take the existing workflow's owner locks before the profile read locks.
            $journey = app(ProfessionalHub::class)->project($request->user());
            [$profile, $professional] = $this->ownData($request);

            return view('pages.profile.show', compact('profile', 'professional', 'journey'));
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
        if ($request->expectsJson()) {
            $validator = Validator::make($request->query(), ['state_id' => ['required', 'integer', 'exists:states,id']]);
            if ($validator->fails() || array_diff(array_keys($request->query()), ['state_id'])) {
                return response()->json(['message' => 'Selecciona un estado válido.'], 422)
                    ->header('Cache-Control', 'private, no-store')->header('Vary', 'Accept');
            }

            return $this->read(fn () => response()->json([
                'state_id' => (int) $validator->validated()['state_id'],
                'municipalities' => Municipality::where('state_id', $validator->validated()['state_id'])
                    ->orderBy('name')->get(['id', 'name']),
            ])->header('Cache-Control', 'private, no-store')->header('Vary', 'Accept'));
        }

        $validator = Validator::make($request->only('state_id'), ['state_id' => ['nullable', 'integer', 'exists:states,id']]);
        if ($validator->fails()) {
            return to_route($isProfessional ? 'profile.professional.edit' : 'profile.edit')->withErrors(['state_id' => 'Selecciona un estado válido.']);
        }
        $state = $validator->validated();

        return $this->read(function () use ($request, $isProfessional, $state) {
            $editorVersion = $isProfessional ? app(ProfessionalEditor::class)->version($request->user()) : null;
            [$personal, $professional] = $this->ownData($request);
            $profile = $isProfessional ? $professional : $personal;
            // A failed submission may return to a URL whose state predates the live catalog.
            $errors = $request->session()->get('errors');
            $recovering = $errors instanceof ViewErrorBag && $errors->any();
            $old = $request->session()->getOldInput();
            $hasOldMunicipality = $recovering && array_key_exists('municipality_id', $old);
            $invalidMunicipality = $recovering && $errors->has('municipality_id');
            $oldMunicipality = $hasOldMunicipality ? $old['municipality_id'] : null;
            $oldState = $hasOldMunicipality && ! $invalidMunicipality && is_scalar($oldMunicipality) && ctype_digit((string) $oldMunicipality)
                ? Municipality::whereKey($oldMunicipality)->value('state_id') : null;
            $selectedState = $oldState ?? $state['state_id'] ?? $profile['state_id'] ?? null;
            $municipalities = Municipality::where('state_id', $selectedState)->orderBy('name')->get(['id', 'name']);
            $selectedMunicipality = $hasOldMunicipality ? $oldMunicipality : ($profile['municipality_id'] ?? null);
            if ($invalidMunicipality || ! is_scalar($selectedMunicipality) || ! $municipalities->contains('id', $selectedMunicipality)) {
                $selectedMunicipality = '';
            }

            return view('pages.profile.edit', [
                'profile' => $profile, 'isProfessional' => $isProfessional,
                'states' => State::orderBy('name')->get(['id', 'name']),
                'selectedState' => $selectedState, 'municipalities' => $municipalities, 'selectedMunicipality' => (string) $selectedMunicipality,
                'specialties' => $isProfessional ? LegalSpecialty::active()->ordered()->get(['id', 'name']) : collect(),
                'editorVersion' => $editorVersion,
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
        try {
            app(ProfessionalEditor::class)->update($request, $request->validated());
        } catch (ProfileException|VerificationException|ValidationException $error) {
            return $this->professionalFailure($request, $error);
        }

        return to_route('profile')->with('status', 'Tu perfil profesional se guardó. Los cambios materiales requieren una nueva verificación.');
    }

    public function prepareProfessional(SaveProfessionalProfileRequest $request)
    {
        // The review POST saves only when no confirmation is needed. A material write uses one final PUT.
        try {
            $pending = app(ProfessionalEditor::class)->prepare($request, $request->validated());
        } catch (ProfileException|VerificationException|ValidationException $error) {
            return $this->professionalFailure($request, $error);
        }
        if ($pending) {
            return $request->expectsJson() ? response()->json($pending)
                : response()->view('pages.profile.confirm-professional', $pending)->header('Cache-Control', 'private, no-store');
        }

        return $request->expectsJson() ? response()->json(['redirect' => route('profile')])
            : to_route('profile')->with('status', 'Tu presentación profesional se guardó correctamente.');
    }

    private function professionalFailure(SaveProfessionalProfileRequest $request, ProfileException|VerificationException|ValidationException $error)
    {
        $errors = $error instanceof ValidationException ? $error->errors() : ['profile' => [$error->getMessage()]];
        if ($request->expectsJson()) {
            return response()->json(['errors' => $errors], 422);
        }

        return to_route('profile.professional.edit')->withErrors($errors)
            ->withInput(app(ProfessionalEditor::class)->oldInput($request));
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
                'inactive_specialties' => $model->specialties->where('is_active', false)->pluck('name')->all(),
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

            $message = 'Los perfiles no están disponibles temporalmente.';

            return (request()->expectsJson() ? response()->json(['message' => $message], 503)
                : response()->view('errors.503', compact('message'), 503))->header('Cache-Control', 'private, no-store');
        }
    }
}
