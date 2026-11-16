<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordRecoveryRequest;
use App\Services\Auth\PasswordRecovery;
use App\Services\Auth\RecoveryMailer;
use Illuminate\Support\Facades\Auth;

class PasswordRecoveryController extends Controller
{
    public function create(RecoveryMailer $mailer)
    {
        return response()->view('pages.auth.recovery', ['reset' => false, 'captureOnly' => ! app()->environment('production') && $mailer->name() === 'array'])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function edit()
    {
        return response()->view('pages.auth.recovery', ['reset' => true, 'captureOnly' => false])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(PasswordRecoveryRequest $request, PasswordRecovery $recovery)
    {
        $recovery->request($request->validated('email'));
        $message = 'Si existe una cuenta activa con ese correo y la entrega está habilitada, recibirás instrucciones. La solicitud no confirma una entrega de correo.';

        return ($request->expectsJson() ? response()->json(['message' => $message])
            : to_route('password.request')->with('status', $message))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function update(PasswordRecoveryRequest $request, PasswordRecovery $recovery)
    {
        if (! $recovery->reset($request->safe()->only(['email', 'token', 'password', 'password_confirmation']))) {
            $message = 'El enlace no es válido, caducó, ya fue utilizado o la cuenta no está disponible. Solicita uno nuevo.';

            return ($request->expectsJson() ? response()->json(['message' => $message], 422)
                : to_route('password.reset')->withErrors(['token' => $message])->withInput(['email' => $request->validated('email')]))
                ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        }
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $message = 'La contraseña cambió. Inicia sesión con la contraseña nueva; las sesiones anteriores se rechazarán en su siguiente acceso.';

        return ($request->expectsJson() ? response()->json(['message' => $message, 'redirect' => route('login')])
            : to_route('login')->with('status', $message))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
