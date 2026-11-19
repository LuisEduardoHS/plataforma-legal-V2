<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\ReadingReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request, ReadingReturn $return): View
    {
        $return->capture($request);

        return view('pages.auth.login', ['hasReadingReturn' => $return->hasPending($request)]);
    }

    public function store(LoginRequest $request, ReadingReturn $return): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        return $return->redirect($request);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing');
    }
}
