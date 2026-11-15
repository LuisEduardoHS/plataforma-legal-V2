<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Messaging\MessagingAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class MessagingController extends Controller
{
    protected function page(string $view, callable $data)
    {
        return DB::transaction(fn () => response()->view($view, $data())->header('Cache-Control', 'private, no-store'));
    }

    protected function completed(Request $request, string $url, string $message, array $data = [], int $status = 200)
    {
        return $request->expectsJson() ? response()->json(['message' => $message, 'redirect' => $url] + $data, $status)->header('Cache-Control', 'private, no-store')
            : redirect()->to($url)->with('status', $message);
    }

    protected function draftKey(Request $request, int $id): string
    {
        return 'messaging.compose.'.$request->user()->id.'.'.$id;
    }

    protected function composer(Request $request, int $id): array
    {
        $key = $this->draftKey($request, $id);
        $draft = $request->session()->get($key);
        $draft ??= ['client_token' => (string) Str::uuid(), 'attachment_ids' => []];
        $request->session()->put($key, $draft);
        $files = Attachment::whereKey($draft['attachment_ids'])->where('user_id', $request->user()->id)->where('status', 'available')->where('visibility', 'private')
            ->sharedLock()->get(['id', 'original_name', 'extension', 'size_bytes'])->map(fn ($file) => [
                'id' => $file->id, 'name' => $file->original_name, 'extension' => $file->extension, 'size_bytes' => $file->size_bytes,
            ])->all();

        return ['client_token' => $draft['client_token'], 'attachments' => $files];
    }

    protected function boundMessage(Request $request, int $conversation, int $message): void
    {
        app(MessagingAccess::class)->conversation($request->user(), $conversation, function (User $actor, User $target, Conversation $chat) use ($message) {
            if (! $chat->messages()->whereKey($message)->sharedLock()->exists()) {
                throw new AuthorizationException;
            }
        });
    }
}
