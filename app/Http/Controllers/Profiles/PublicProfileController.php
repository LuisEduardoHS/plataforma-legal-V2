<?php

namespace App\Http\Controllers\Profiles;

use App\Http\Controllers\Controller;
use App\Services\Profiles\ProfileService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PublicProfileController extends Controller
{
    public function __construct(private ProfileService $profiles) {}

    public function show(string $userId)
    {
        $userId = $this->publicId($userId);

        return $this->read(fn () => view('pages.profile.public', [
            'profile' => $this->profiles->publicUserProfile($userId), 'professional' => null, 'publicUserId' => $userId,
        ]));
    }

    public function professional(string $userId)
    {
        $userId = $this->publicId($userId);

        return $this->read(function () use ($userId) {
            $professional = $this->profiles->publicProfessionalProfile($userId);
            try {
                $profile = $this->profiles->publicUserProfile($userId);
            } catch (ModelNotFoundException) {
                $profile = null;
            }

            return view('pages.profile.public', ['profile' => $profile, 'professional' => $professional, 'publicUserId' => $userId]);
        });
    }

    private function read(callable $read)
    {
        try {
            return response(DB::transaction($read))->header('Cache-Control', 'private, no-store');
        } catch (QueryException $error) {
            report($error);

            return response('El perfil no está disponible temporalmente.', 503)->header('Content-Type', 'text/plain; charset=UTF-8');
        }
    }

    private function publicId(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        return $id;
    }
}
