<?php

namespace App\Services\Messaging;

use App\Enums\AttachmentStatus;
use App\Enums\MessageStatus;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class MessageService
{
    public function __construct(private MessagingAccess $access) {}

    public function send(User $actor, int $id, array $data): Message
    {
        return $this->access->conversation($actor, $id, function (User $actor, User $target, Conversation $conversation) use ($data) {
            Gate::forUser($actor)->authorize('send', $conversation);
            $this->access->unblocked($actor, $target);
            if (array_diff(array_keys($data), ['content', 'attachment_ids', 'client_token'])) {
                $this->access->invalid('message', 'Hay campos no permitidos.');
            }
            $valid = Validator::make($data, [
                'content' => ['sometimes', 'nullable', ...$this->textRules()],
                'attachment_ids' => ['sometimes', 'array', 'list', 'max:'.config('messaging.max_attachments')],
                'attachment_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
                'client_token' => ['sometimes', 'required', 'uuid'],
            ])->validate();
            $content = $this->text($valid['content'] ?? null);
            $ids = array_map('intval', $valid['attachment_ids'] ?? []);
            if (($content === null || $content === '') && $ids === []) {
                $this->access->invalid('content', 'Envía texto o archivos.');
            }
            $hash = hash('sha256', json_encode([$content, $ids], JSON_THROW_ON_ERROR));
            if (isset($valid['client_token'])) {
                $existing = Message::where('conversation_id', $conversation->id)->where('sender_id', $actor->id)->where('client_token', $valid['client_token'])->lockForUpdate()->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        $this->access->invalid('client_token', 'El identificador ya corresponde a otra solicitud.');
                    }

                    return $existing;
                }
            }
            $files = Attachment::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($files->count() !== count($ids)) {
                $this->access->invalid('attachment_ids', 'Hay archivos inexistentes.');
            }
            foreach ($files as $file) {
                $this->assertAttachment($file, $actor->id);
            }
            $message = new Message;
            $message->forceFill(['conversation_id' => $conversation->id, 'sender_id' => $actor->id, 'content' => $content,
                'status' => MessageStatus::Sent, 'version' => 1, 'sent_at' => now(), 'request_hash' => $hash,
                'client_token' => $valid['client_token'] ?? null])->save();
            foreach ($ids as $position => $fileId) {
                $message->attachments()->attach($fileId, ['position' => $position]);
            }
            foreach ($files as $file) {
                if ($file->messaging_shared_at === null) {
                    $file->forceFill(['messaging_shared_at' => now()])->save();
                }
            }
            $conversation->forceFill(['activity_at' => now()])->save();
            DB::table('conversation_participants')->where('conversation_id', $conversation->id)->update(['hidden_at' => null, 'updated_at' => now()]);

            return $message->fresh();
        });
    }

    public function edit(User $actor, int $id, ?string $content, int $expectedVersion): Message
    {
        return $this->withMessage($actor, $id, function (User $actor, User $target, Conversation $conversation, ConversationParticipant $participant, Message $message) use ($content, $expectedVersion) {
            Gate::forUser($actor)->authorize('update', $message);
            $this->access->unblocked($actor, $target);
            $this->access->version($message, $expectedVersion);
            if (! $this->access->visible($conversation->id, $participant)->whereKey($message->id)->exists()) {
                throw new AuthorizationException;
            }
            Validator::make(['content' => $content], ['content' => ['nullable', ...$this->textRules()]])->validate();
            $content = $this->text($content);
            $files = $message->attachments()->reorder()->orderBy('attachments.id')->lockForUpdate()->get();
            foreach ($files as $file) {
                $this->assertAttachment($file, $message->sender_id);
            }
            if (($content === null || $content === '') && $files->isEmpty()) {
                $this->access->invalid('content', 'El mensaje no puede quedar vacío.');
            }
            $message->forceFill(['content' => $content, 'edited_at' => now(), 'version' => $message->version + 1])->save();

            return $message->fresh();
        });
    }

    public function deleteForEveryone(User $actor, int $id, int $expectedVersion): void
    {
        $this->withMessage($actor, $id, function (User $actor, User $target, Conversation $conversation, ConversationParticipant $participant, Message $message) use ($expectedVersion) {
            Gate::forUser($actor)->authorize('delete', $message);
            if ($message->status === MessageStatus::Deleted) {
                return;
            }
            $this->access->version($message, $expectedVersion);
            $message->forceFill(['status' => MessageStatus::Deleted, 'content' => null, 'deleted_at' => now(), 'version' => $message->version + 1])->save();
        });
    }

    public function hideForMe(User $actor, int $id): void
    {
        $this->withMessage($actor, $id, function (User $actor, User $target, Conversation $conversation, ConversationParticipant $participant, Message $message) {
            $key = ['message_id' => $message->id, 'user_id' => $actor->id];
            if (DB::table('message_user_hides')->where($key)->lockForUpdate()->first() === null) {
                DB::table('message_user_hides')->insert($key + ['conversation_id' => $conversation->id, 'created_at' => now()]);
            }
        });
    }

    public function assertAttachment(Attachment $file, int $ownerId): void
    {
        $type = config('attachments.types.'.$file->mime_type);
        if ((int) $file->user_id !== $ownerId || $file->status !== AttachmentStatus::Available || $file->visibility !== 'private'
            || ! $type || $type['category'] !== $file->category || ! in_array($file->extension, $type['extensions'], true)
            || $file->verification_retained_at !== null
            || DB::table('verification_evidences')->where('attachment_id', $file->id)->lockForUpdate()->first() !== null) {
            $this->access->invalid('attachment_ids', 'Selecciona archivos propios, disponibles y ajenos a verificación profesional.');
        }
    }

    private function withMessage(User $actor, int $id, callable $operation): mixed
    {
        $conversationId = Message::whereKey($id)->value('conversation_id');
        if (! $conversationId) {
            throw new AuthorizationException;
        }

        return $this->access->conversation($actor, $conversationId, function (User $actor, User $target, Conversation $conversation, ConversationParticipant $participant) use ($id, $operation) {
            $message = $conversation->messages()->whereKey($id)->lockForUpdate()->first();
            if (! $message) {
                throw new AuthorizationException;
            }

            return $operation($actor, $target, $conversation, $participant, $message);
        });
    }

    private function textRules(): array
    {
        return ['string', 'max:'.config('messaging.message_max_length'), 'regex:/\A[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*\z/u'];
    }

    private function text(?string $text): ?string
    {
        return $text === null ? null : preg_replace('/\A[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+\z/u', '', $text);
    }
}
