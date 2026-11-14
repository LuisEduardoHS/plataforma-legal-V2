<?php

namespace App\Http\Controllers\Profiles;

use App\Exceptions\AttachmentException;
use App\Exceptions\ProfileException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profiles\ProfilePhotoRequest;
use App\Services\Attachments\AttachmentService;
use App\Services\Profiles\ProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProfilePhotoController extends Controller
{
    public function __construct(private ProfileService $profiles, private AttachmentService $attachments) {}

    public function store(ProfilePhotoRequest $request)
    {
        try {
            // Upload reservations must commit outside any profile transaction.
            $photo = $this->attachments->store($request->user(), $request->file('photo'));
            try {
                $this->profiles->saveUserProfile($request->user(), $request->user()->id, ['photo_attachment_id' => $photo->id]);
            } catch (Throwable $error) {
                try {
                    $this->attachments->requestDeletion($request->user(), $photo->id);
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
                throw $error;
            }
        } catch (AttachmentException|ProfileException $error) {
            return back()->withErrors(['photo' => 'No se pudo guardar la fotografía. Inténtalo nuevamente.']);
        }

        return to_route('profile')->with('status', 'La fotografía privada se guardó correctamente.');
    }

    public function destroy(ProfilePhotoRequest $request)
    {
        try {
            $this->profiles->saveUserProfile($request->user(), $request->user()->id, ['photo_attachment_id' => null]);
        } catch (ProfileException $error) {
            return back()->withErrors(['photo' => $error->getMessage()]);
        }

        // Unlink only: retention and other references belong to AttachmentService.
        return to_route('profile')->with('status', 'Se quitó la fotografía del perfil.');
    }

    public function show(Request $request)
    {
        try {
            return DB::transaction(function () use ($request) {
                abort_unless($request->user()->profile()->exists(), 404);
                $profile = $this->profiles->userProfile($request->user(), $request->user()->id);
                $photo = $profile->photo;
                abort_unless($photo && $photo->category === 'image', 404);

                return $this->attachments->download($request->user(), $photo->id);
            });
        } catch (AttachmentException $error) {
            return response('La fotografía no está disponible temporalmente.', 503)->header('Content-Type', 'text/plain; charset=UTF-8');
        }
    }
}
