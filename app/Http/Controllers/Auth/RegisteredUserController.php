<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\Auth\ReadingReturn;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class RegisteredUserController extends Controller
{
    public function create(Request $request, ReadingReturn $return): View
    {
        $return->capture($request);

        return view('pages.auth.register', ['hasReadingReturn' => $return->hasPending($request)]);
    }

    public function store(RegisterRequest $request, ReadingReturn $return): RedirectResponse
    {
        try {
            $user = DB::transaction(function () use ($request): User {
                $role = Role::where('name', 'user')->where('guard_name', 'web')->sharedLock()->first();
                abort_unless($role, 503, 'El registro no está disponible temporalmente.');

                // User owns the hashing and active default; profiles remain optional.
                $user = User::create($request->safe()->only('name', 'email', 'password'));
                $user->assignRole($role);

                return $user;
            });
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw ValidationException::withMessages(['email' => 'Este correo electrónico ya está registrado.']);
            }
            throw $error;
        }

        event(new Registered($user));
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $return->redirect($request);
    }
}
