<?php

namespace App\Services\Messaging;

use App\Exceptions\MessagingException;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MessagingAccess
{
    public function viewer(User $actor): User
    {
        $current = User::find($actor->id);
        if (! $current || ! $current->isActive()) {
            throw new AuthorizationException;
        }

        return $current;
    }

    public function pair(User $actor, int $targetId, callable $operation): mixed
    {
        return $this->transaction(function () use ($actor, $targetId, $operation) {
            $users = User::whereKey([$actor->id, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = $users->get($actor->id);
            $target = $users->get($targetId);
            if (! $current || ! $current->isActive() || ! $target || $current->id === $target->id) {
                throw new AuthorizationException;
            }

            return $operation($current, $target);
        });
    }

    public function conversation(User $actor, int $id, callable $operation): mixed
    {
        $pair = Conversation::find($id);
        if (! $pair || ! $pair->contains($actor)) {
            throw new AuthorizationException;
        }
        $targetId = (int) ($pair->user_low_id == $actor->id ? $pair->user_high_id : $pair->user_low_id);

        return $this->pair($actor, $targetId, function (User $actor, User $target) use ($id, $operation) {
            $conversation = Conversation::whereKey($id)->lockForUpdate()->first();
            if (! $conversation || ! $conversation->contains($actor) || ! $conversation->contains($target)) {
                throw new AuthorizationException;
            }
            $participants = $conversation->participants()->orderBy('user_id')->lockForUpdate()->get()->keyBy('user_id');
            if ($participants->count() !== 2 || ! $participants->has($actor->id) || ! $participants->has($target->id)) {
                throw new AuthorizationException;
            }
            Gate::forUser($actor)->authorize('view', $conversation);

            return $operation($actor, $target, $conversation, $participants->get($actor->id), $participants->get($target->id));
        });
    }

    public function unblocked(User $actor, User $target): void
    {
        if (! $target->isActive() || DB::table('user_blocks')->where(function ($query) use ($actor, $target) {
            $query->where(fn ($pair) => $pair->where('blocker_id', $actor->id)->where('blocked_id', $target->id))
                ->orWhere(fn ($pair) => $pair->where('blocker_id', $target->id)->where('blocked_id', $actor->id));
        })->lockForUpdate()->first() !== null) {
            throw new AuthorizationException;
        }
    }

    public function visible(int $conversationId, ConversationParticipant $participant): Builder
    {
        return Message::where('conversation_id', $conversationId)
            ->where('messages.id', '>', $participant->hidden_through_message_id ?? 0)
            ->whereNotExists(fn ($hidden) => $hidden->selectRaw('1')->from('message_user_hides')
                ->whereColumn('message_user_hides.message_id', 'messages.id')->where('user_id', $participant->user_id)->sharedLock());
    }

    public function participant(int $conversationId, int $userId, array $changes): void
    {
        DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)->update($changes + ['updated_at' => now()]);
    }

    public function version(Message $message, int $version): void
    {
        if ($message->version !== $version) {
            $this->invalid('version', 'El mensaje cambió. Consulta la versión actual.');
        }
    }

    public function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function transaction(callable $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $error) {
            report($error);
            throw new MessagingException;
        }
    }
}
