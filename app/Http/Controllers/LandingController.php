<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LandingController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Account validity and credential revocation remain with web middleware.
        $response = $request->user()
            ? redirect()->route('home')
            : response()->view('pages.landing.index');

        return $response->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
