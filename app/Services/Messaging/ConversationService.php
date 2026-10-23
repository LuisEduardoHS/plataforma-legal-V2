<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConversationService
{
    public function __construct(private MessagingAccess $access) {}

    public function start(User $actor, int $targetId): Conversation
    {
        return $this->access->pair($actor, $targetId, function (User $actor, User $target) {
            $low = min($actor->id, $target->id);
            $high = max($actor->id, $target->id);
            $existing = Conversation::where('user_low_id', $low)->where('user_high_id', $high)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            Gate::forUser($actor)->authorize('create', [Conversation::class, $target]);
            $this->access->unblocked($actor, $target);
            $conversation = new Conversation;
            $conversation->forceFill(['user_low_id' => $low, 'user_high_id' => $high, 'activity_at' => now()])->save();
            foreach ([$low, $high] as $id) {
                DB::table('conversation_participants')->insert(['conversation_id' => $conversation->id, 'user_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $conversation;
        });
    }

    public function archive(User $actor, int $id, bool $archived = true): void
    {
        $this->access->conversation($actor, $id, function (User $actor, User $target, Conversation $conversation, ConversationParticipant $participant) use ($archived) {
            Gate::forUser($actor)->authorize('manage', $conversation);
            if (($participant->archived_at !== null) !== $archived) {
                $this->access->participant($conversation->id, $actor->id, ['archived_at' => $archived ? now() : null]);
            }
        });
    }

    public function hide(User $actor, int $id): void
    {
        $this->access->conversation($actor, $id, function (User $actor, User $target, Conversation $conversation, ConversationParticipant $participant) {
            Gate::forUser($actor)->authorize('manage', $conversation);
            $last = $conversation->messages()->orderByDesc('id')->lockForUpdate()->first();
            $this->access->participant($conversation->id, $actor->id, [
                'hidden_at' => $participant->hidden_at ?? now(),
                'hidden_through_message_id' => $last?->id ?? $participant->hidden_through_message_id,
            ]);
        });
    }

    public function markRead(User $actor, int $id, int $messageId): void
    {
        $this->access->conversation($actor, $id, function (User $actor, User $target, Conversation $conversation, ConversationParticipant $participant) use ($messageId) {
            $message = $this->access->visible($conversation->id, $participant)->whereKey($messageId)->lockForUpdate()->first();
            if (! $message) {
                $this->access->invalid('message_id', 'Selecciona un mensaje accesible de esta conversación.');
            }
            if ($messageId > ($participant->last_read_message_id ?? 0)) {
                $this->access->participant($conversation->id, $actor->id, ['last_read_message_id' => $messageId, 'last_read_at' => now()]);
            }
        });
    }

    public function block(User $actor, int $targetId): void
    {
        $this->access->pair($actor, $targetId, function (User $actor, User $target) {
            $key = ['blocker_id' => $actor->id, 'blocked_id' => $target->id];
            if (DB::table('user_blocks')->where($key)->lockForUpdate()->first() === null) {
                DB::table('user_blocks')->insert($key + ['created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function unblock(User $actor, int $targetId): void
    {
        $this->access->pair($actor, $targetId, function (User $actor, User $target) {
            DB::table('user_blocks')->where('blocker_id', $actor->id)->where('blocked_id', $target->id)->delete();
        });
    }
}
