<?php

namespace App\Http\Controllers\Profiles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profiles\DirectoryRequest;
use App\Models\LegalSpecialty;
use App\Models\Municipality;
use App\Models\State;
use App\Services\Profiles\ProfessionalDirectory;
use Illuminate\Database\QueryException;

class ProfessionalDirectoryController extends Controller
{
    public function __invoke(DirectoryRequest $request, ProfessionalDirectory $directory)
    {
        $filters = $request->validated();
        try {
            if (! empty($filters['municipality_id']) && empty($filters['state_id'])) {
                $filters['state_id'] = Municipality::whereKey($filters['municipality_id'])->value('state_id');
            }

            return response()->view('pages.professionals.index', [
                'profiles' => $directory->search($filters)->appends($filters),
                'filters' => $filters,
                'specialties' => LegalSpecialty::active()->ordered()->get(['id', 'name']),
                'states' => State::orderBy('name')->get(['id', 'name']),
                'municipalities' => empty($filters['state_id']) ? collect() : Municipality::where('state_id', $filters['state_id'])->orderBy('name')->get(['id', 'name']),
                'unavailable' => false,
            ])->header('Cache-Control', 'private, no-store');
        } catch (QueryException $error) {
            report($error);

            $message = 'No pudimos cargar los perfiles. Inténtalo más tarde.';

            return ($request->expectsJson() ? response()->json(['message' => $message], 503)
                : response()->view('errors.503', compact('message'), 503))->header('Cache-Control', 'private, no-store');
        }
    }
}
