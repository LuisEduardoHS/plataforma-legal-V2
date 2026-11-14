<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class CommunityController extends Controller
{
    protected function page(string $view, callable $data)
    {
        return DB::transaction(fn () => response()->view($view, $data())->header('Cache-Control', 'private, no-store'));
    }

    protected function saved(Request $request, string $url, string $message, array $data = [], int $status = 200)
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message, 'redirect' => $url] + $data, $status)->header('Cache-Control', 'private, no-store')
            : redirect()->to($url)->with('status', $message);
    }
}
