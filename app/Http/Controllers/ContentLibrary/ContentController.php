<?php

namespace App\Http\Controllers\ContentLibrary;

use App\Enums\EditorialKind;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class ContentController extends Controller
{
    protected function kind(Request $request): EditorialKind
    {
        return EditorialKind::from($request->route('kind'));
    }

    protected function page(string $view, callable $data)
    {
        return DB::transaction(fn () => response()->view($view, $data())->header('Cache-Control', 'private, no-store'));
    }

    protected function completed(Request $request, string $url, string $message, array $data = [], int $status = 200)
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message, 'redirect' => $url] + $data, $status)->header('Cache-Control', 'private, no-store')
            : redirect()->to($url)->with('status', $message);
    }
}
