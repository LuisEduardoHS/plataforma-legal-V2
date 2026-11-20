<?php

namespace App\Services\Lexa;

use App\Exceptions\LexaUnavailableException;
use App\Models\LexaConversation;
use App\Models\LexaMessage;
use App\Models\User;
use App\Services\Lexa\Contracts\AiProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LexaConversationService
{
    public function __construct(
        private LexaAccess $access,
        private LexaQuery $query,
        private AiProvider $provider,
    ) {}

    public function create(User $actor, string $mode = 'orientation', ?string $title = null, ?string $initialContent = null): array
    {
        if (! in_array($mode, config('lexa.modes', ['orientation', 'contract', 'organize']), true)) {
            throw ValidationException::withMessages(['mode' => 'El modo de consulta no es válido.']);
        }

        $cleanContent = $initialContent !== null ? $this->normalizeContent($initialContent) : null;
        $resolvedTitle = $this->resolveTitle($title, $cleanContent);

        return DB::transaction(function () use ($actor, $mode, $resolvedTitle, $cleanContent) {
            $current = $this->access->viewer($actor);
            Gate::forUser($current)->authorize('create', LexaConversation::class);

            $now = now();
            $conversation = new LexaConversation;
            $conversation->user_id = $current->id;
            $conversation->title = $resolvedTitle;
            $conversation->mode = $mode;
            $conversation->status = 'active';
            $conversation->version = 1;
            $conversation->last_message_at = $cleanContent !== null ? $now : null;
            $conversation->save();

            $messageDto = null;
            if ($cleanContent !== null) {
                $message = $this->persistUserMessage($current, $conversation, $cleanContent);
                $messageDto = $this->query->messageData($message);
            }

            return [
                'conversation' => $this->query->conversationData($conversation->fresh()),
                'message' => $messageDto,
            ];
        });
    }

    public function appendUserMessage(User $actor, int $conversationId, string $content): array
    {
        $cleanContent = $this->normalizeContent($content);

        return $this->access->ownedConversation($actor, $conversationId, function (User $current, LexaConversation $conversation) use ($cleanContent) {
            Gate::forUser($current)->authorize('update', $conversation);

            $message = $this->persistUserMessage($current, $conversation, $cleanContent);

            $conversation->last_message_at = $message->created_at ?? now();
            $conversation->version = $conversation->version + 1;
            $conversation->save();

            return [
                'conversation' => $this->query->conversationData($conversation),
                'message' => $this->query->messageData($message),
            ];
        }, lock: true);
    }

    public function generateReply(User $actor, int $conversationId): never
    {
        $this->access->ownedConversation($actor, $conversationId, function (User $current, LexaConversation $conversation) {
            Gate::forUser($current)->authorize('update', $conversation);

            if (! config('lexa.enabled_generation', false) || ! $this->provider->isConfigured()) {
                throw new LexaUnavailableException('La generación con IA real aún no está habilitada.');
            }

            // Real provider orchestration is deferred until LEXA-01-C / provider selection.
            throw new LexaUnavailableException('La generación con IA real aún no está habilitada.');
        });
    }

    public function delete(User $actor, int $conversationId): void
    {
        $this->access->ownedConversation($actor, $conversationId, function (User $current, LexaConversation $conversation) {
            Gate::forUser($current)->authorize('delete', $conversation);

            $conversation->status = 'deleted';
            $conversation->deleted_at = now();
            $conversation->version = $conversation->version + 1;
            $conversation->save();
        }, lock: true);
    }

    private function persistUserMessage(User $actor, LexaConversation $conversation, string $cleanContent): LexaMessage
    {
        $message = new LexaMessage;
        $message->lexa_conversation_id = $conversation->id;
        $message->user_id = $actor->id;
        // Client requests may never inject assistant or system roles.
        $message->role = 'user';
        $message->content = $cleanContent;
        $message->status = 'persisted';
        $message->save();

        return $message;
    }

    private function normalizeContent(string $content): string
    {
        $trimmed = trim($content);
        $max = (int) config('lexa.limits.max_prompt_chars', 5000);
        if ($trimmed === '') {
            throw ValidationException::withMessages(['content' => 'Escribe una consulta antes de enviarla.']);
        }
        if (mb_strlen($trimmed) > $max) {
            throw ValidationException::withMessages(['content' => "La consulta no puede exceder {$max} caracteres."]);
        }

        return $trimmed;
    }

    private function resolveTitle(?string $title, ?string $cleanContent): string
    {
        $maxTitle = (int) config('lexa.limits.max_title_chars', 72);
        $source = trim((string) ($title ?? ''));
        if ($source === '' && $cleanContent !== null) {
            $source = preg_replace('/\s+/', ' ', $cleanContent) ?? $cleanContent;
        }
        if ($source === '') {
            return 'Nueva consulta';
        }

        return Str::limit($source, $maxTitle, '');
    }
}
