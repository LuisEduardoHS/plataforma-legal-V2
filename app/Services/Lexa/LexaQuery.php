<?php

namespace App\Services\Lexa;

use App\Models\LexaConversation;
use App\Models\LexaMessage;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LexaQuery
{
    public function __construct(private LexaAccess $access) {}

    public function conversations(User $actor, ?string $cursor = null, ?int $size = null): CursorPaginator
    {
        $perPage = $this->clampSize($size ?? (int) config('lexa.limits.conversations_per_page', 20), 50);

        return DB::transaction(function () use ($actor, $cursor, $perPage) {
            $current = $this->access->viewer($actor);

            return LexaConversation::visibleTo($current)
                ->sharedLock()
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->cursorPaginate($perPage, ['*'], 'cursor', $this->decodeCursor($cursor, ['last_message_at', 'id', '_pointsToNextItems']))
                ->through(fn (LexaConversation $conversation) => $this->conversationData($conversation));
        });
    }

    public function conversation(User $actor, int $id): array
    {
        return $this->access->ownedConversation($actor, $id, fn (User $current, LexaConversation $conversation) => $this->conversationData($conversation));
    }

    public function messages(User $actor, int $id, ?string $cursor = null, ?int $size = null): CursorPaginator
    {
        $perPage = $this->clampSize($size ?? (int) config('lexa.limits.messages_per_page', 30), 100);

        return $this->access->ownedConversation($actor, $id, function (User $current, LexaConversation $conversation) use ($cursor, $perPage) {
            return LexaMessage::where('lexa_conversation_id', $conversation->id)
                ->where('user_id', $current->id)
                ->sharedLock()
                ->orderBy('id')
                ->cursorPaginate($perPage, ['*'], 'cursor', $this->decodeCursor($cursor, ['id', '_pointsToNextItems']))
                ->through(fn (LexaMessage $message) => $this->messageData($message));
        });
    }

    public function conversationData(LexaConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'mode' => $conversation->mode,
            'status' => $conversation->status,
            'version' => $conversation->version,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'created_at' => $conversation->created_at?->toIso8601String(),
        ];
    }

    public function messageData(LexaMessage $message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->lexa_conversation_id,
            'role' => $message->role,
            'content' => $message->content,
            'status' => $message->status,
            'citations' => $message->citations_json ?? [],
            'grounding' => $message->grounding_json,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function clampSize(int $size, int $max): int
    {
        if ($size < 1 || $size > $max) {
            throw ValidationException::withMessages(['size' => 'El tamaño de página no es válido.']);
        }

        return $size;
    }

    private function decodeCursor(?string $cursor, array $expectedKeys): ?Cursor
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = json_decode(base64_decode(strtr($cursor, '-_', '+/'), true) ?: '', true);
        if (! is_array($decoded) || count($decoded) !== count($expectedKeys)
            || array_diff($expectedKeys, array_keys($decoded)) !== []
            || ! is_bool($decoded['_pointsToNextItems'] ?? null)) {
            throw ValidationException::withMessages(['cursor' => 'El cursor no es válido.']);
        }

        Validator::make($decoded, [
            'id' => ['required', 'integer', 'min:1'],
            '_pointsToNextItems' => ['required', 'boolean'],
        ])->validate();

        return Cursor::fromEncoded($cursor);
    }
}
