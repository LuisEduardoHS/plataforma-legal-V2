<?php

namespace App\Services\Messaging;

use App\Enums\MessageStatus;
use App\Enums\ProfileVisibility;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MessagingQuery
{
    public function __construct(private MessagingAccess $access) {}

    public function inbox(User $actor, bool $archived = false, int $size = 20, ?string $cursor = null, ?int $participantId = null): CursorPaginator
    {
        $actor = $this->access->viewer($actor);
        $this->size($size);
        $query = Conversation::join('conversation_participants as own', 'own.conversation_id', '=', 'conversations.id')
            ->where('own.user_id', $actor->id)->whereNull('own.hidden_at')
            ->where(fn ($pair) => $pair->where('user_low_id', $actor->id)->orWhere('user_high_id', $actor->id))
            ->select('conversations.*')
            ->selectRaw('CASE WHEN user_low_id = ? THEN user_high_id ELSE user_low_id END AS other_user_id', [$actor->id])
            ->addSelect('own.archived_at');
        $archived ? $query->whereNotNull('own.archived_at') : $query->whereNull('own.archived_at');
        if ($participantId !== null) {
            $query->where(fn ($pair) => $pair->where('user_low_id', $participantId)->orWhere('user_high_id', $participantId));
        }
        $latest = $this->visibleToRow(Message::query())->select('messages.id')->orderByDesc('messages.id')->limit(1);
        $unread = $this->visibleToRow(Message::query())->where('messages.status', 'sent')
            ->whereColumn('messages.sender_id', '<>', 'own.user_id')->whereRaw('messages.id > COALESCE(own.last_read_message_id, 0)')->selectRaw('COUNT(*)');

        return $query->selectSub($latest, 'latest_message_id')->selectSub($unread, 'unread_count')
            ->with(['otherParticipant:id,name,status', 'otherParticipant.profile:id,user_id,display_name,username,visibility',
                'latestVisibleMessage' => fn ($message) => $message->with($this->fileRelation())])
            ->orderByDesc('activity_at')->orderByDesc('id')
            ->cursorPaginate($size, ['*'], 'cursor', $this->cursor($cursor, inbox: true))->through(fn ($conversation) => [
                'id' => $conversation->id, 'activity_at' => $conversation->activity_at->toDateTimeString(),
                'archived' => $conversation->archived_at !== null, 'unread_count' => (int) $conversation->unread_count,
                'participant' => $this->userData($conversation->otherParticipant),
                'last_message' => $conversation->latestVisibleMessage ? $this->messageData($conversation->latestVisibleMessage) : null,
            ]);
    }

    public function history(User $actor, int $id, int $size = 20, ?string $cursor = null, bool $unreadOnly = false): CursorPaginator
    {
        return $this->access->conversation($actor, $id, function (User $actor, User $target, Conversation $conversation, ConversationParticipant $own, ConversationParticipant $other) use ($size, $cursor, $unreadOnly) {
            $this->size($size);
            $query = $this->access->visible($conversation->id, $own);
            if ($unreadOnly) {
                $query->where('status', 'sent')->where('sender_id', '<>', $actor->id)->where('messages.id', '>', $own->last_read_message_id ?? 0);
            }
            $query->select('messages.*')->selectSub(DB::table('message_user_hides')->select('message_id')
                ->whereColumn('message_id', 'messages.id')->where('user_id', $other->user_id)->limit(1)->sharedLock(), 'hidden_for_other');

            // Use a current read after acquiring the conversation locks. An ambient
            // REPEATABLE READ snapshot must not resurrect redacted message text.
            return $query->with($this->fileRelation())->sharedLock()->orderByDesc('id')
                ->cursorPaginate($size, ['messages.*'], 'cursor', $this->cursor($cursor))
                ->through(fn ($message) => $this->messageData($message) + ['read_by_other' => (int) $message->sender_id === (int) $actor->id && $message->status === MessageStatus::Sent
                    && $message->id <= ($other->last_read_message_id ?? 0)
                    && $message->id > ($other->hidden_through_message_id ?? 0) && ! $message->hidden_for_other]);
        });
    }

    public function participants(User $actor, int $id): array
    {
        return $this->access->conversation($actor, $id, function (User $actor, User $target, Conversation $conversation, ConversationParticipant $own) {
            $users = User::whereKey([$actor->id, $target->id])->with('profile:id,user_id,display_name,username,visibility')->get();

            return ['participants' => $users->map(fn ($user) => $this->userData($user))->all(),
                'last_read_message_id' => $own->last_read_message_id, 'last_read_at' => $own->last_read_at?->toDateTimeString()];
        });
    }

    public function unreadCount(User $actor): int
    {
        $actor = $this->access->viewer($actor);

        return $this->visibleToRow(Message::query())
            ->join('conversation_participants as own', 'own.conversation_id', '=', 'messages.conversation_id')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('own.user_id', $actor->id)->whereNull('own.hidden_at')
            ->where(fn ($pair) => $pair->where('user_low_id', $actor->id)->orWhere('user_high_id', $actor->id))
            ->where('messages.status', 'sent')->where('messages.sender_id', '<>', $actor->id)
            ->whereRaw('messages.id > COALESCE(own.last_read_message_id, 0)')->count();
    }

    private function visibleToRow(Builder $query): Builder
    {
        return $query->whereColumn('messages.conversation_id', 'own.conversation_id')
            ->whereRaw('messages.id > COALESCE(own.hidden_through_message_id, 0)')
            ->whereNotExists(fn ($hidden) => $hidden->selectRaw('1')->from('message_user_hides')
                ->whereColumn('message_id', 'messages.id')->whereColumn('user_id', 'own.user_id'));
    }

    private function fileRelation(): array
    {
        return ['attachments' => fn ($files) => $files->where('status', 'available')->where('visibility', 'private')->whereNull('verification_retained_at')
            ->whereNotExists(fn ($evidence) => $evidence->selectRaw('1')->from('verification_evidences')->whereColumn('attachment_id', 'attachments.id'))
            ->select('attachments.id', 'user_id', 'mime_type', 'category', 'size_bytes')];
    }

    private function messageData(Message $message): array
    {
        $deleted = $message->status === MessageStatus::Deleted;

        return ['id' => $message->id, 'conversation_id' => $message->conversation_id, 'sender_id' => $message->sender_id,
            'content' => $deleted ? null : $message->content, 'status' => $message->status->value,
            'version' => $message->version, 'sent_at' => $message->sent_at->toDateTimeString(),
            'edited_at' => $message->edited_at?->toDateTimeString(), 'deleted_at' => $message->deleted_at?->toDateTimeString(),
            'attachments' => $deleted ? [] : $message->attachments->filter(fn ($file) => (int) $file->user_id === (int) $message->sender_id)->map(fn ($file) => [
                'id' => $file->id, 'mime_type' => $file->mime_type, 'category' => $file->category, 'size_bytes' => $file->size_bytes, 'position' => $file->pivot->position,
            ])->values()->all()];
    }

    private function userData(User $user): array
    {
        $public = $user->profile?->visibility === ProfileVisibility::Public;

        return ['id' => $user->id, 'display_name' => $public ? ($user->profile->display_name ?? $user->name) : 'Usuario', 'username' => $public ? $user->profile->username : null];
    }

    private function size(int $size): void
    {
        Validator::make(['size' => $size], ['size' => ['integer', 'min:1', 'max:'.config('messaging.max_page_size')]])->validate();
    }

    private function cursor(?string $cursor, bool $inbox = false): ?Cursor
    {
        if ($cursor === null) {
            return null;
        }
        $parameters = json_decode(base64_decode(strtr($cursor, '-_', '+/'), true) ?: '', true);
        $keys = $inbox ? ['id', 'activity_at', '_pointsToNextItems'] : ['id', '_pointsToNextItems'];
        if (! is_array($parameters) || count($parameters) !== count($keys) || array_diff(array_keys($parameters), $keys) || ! is_bool($parameters['_pointsToNextItems'] ?? null)) {
            $this->access->invalid('cursor', 'El cursor no es válido.');
        }
        Validator::make($parameters, ['id' => ['required', 'integer', 'min:1'], 'activity_at' => $inbox ? ['required', 'date_format:Y-m-d H:i:s'] : ['prohibited']])->validate();

        return Cursor::fromEncoded($cursor);
    }
}
