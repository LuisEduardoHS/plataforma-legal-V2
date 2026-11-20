<?php

namespace App\Services\Lexa;

use App\Exceptions\LexaConflictException;
use App\Exceptions\LexaUnavailableException;
use App\Models\LexaConversation;
use App\Models\LexaGeneration;
use App\Models\LexaMessage;
use App\Models\User;
use App\Services\Lexa\Contracts\AiProvider;
use App\Services\Lexa\Data\LexaCompletion;
use App\Services\Lexa\Data\LexaPrompt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

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

            if ($conversation->status !== 'active') {
                throw new LexaConflictException('No puedes enviar mensajes en una conversación archivada.');
            }

            $pendingExists = LexaGeneration::where('lexa_conversation_id', $conversation->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->exists();

            if ($pendingExists) {
                throw new LexaConflictException('Espera a que termine la generación actual antes de enviar otro mensaje.');
            }

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

    public function generateReply(User $actor, int $conversationId, ?string $clientAttemptToken = null): array
    {
        // Phase 1: short transaction with row locks to reserve the turn and build bounded prompt.
        $reserved = $this->access->ownedConversation($actor, $conversationId, function (User $current, LexaConversation $conversation) use ($clientAttemptToken) {
            Gate::forUser($current)->authorize('update', $conversation);

            if (! config('lexa.enabled_generation', false) || ! $this->provider->isConfigured()) {
                throw new LexaUnavailableException('La generación con IA real aún no está habilitada.');
            }

            if ($conversation->status !== 'active') {
                throw new LexaConflictException('No se pueden generar respuestas en una conversación archivada.');
            }

            $latestMessage = LexaMessage::where('lexa_conversation_id', $conversation->id)
                ->where('user_id', $current->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $latestMessage || $latestMessage->role !== 'user') {
                throw new LexaConflictException('No hay una consulta pendiente de respuesta.');
            }

            $existing = LexaGeneration::where('lexa_conversation_id', $conversation->id)
                ->where('user_message_id', $latestMessage->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status === 'completed' && $existing->assistant_message_id !== null) {
                    $assistantMessage = LexaMessage::where('lexa_conversation_id', $conversation->id)
                        ->whereKey($existing->assistant_message_id)
                        ->first();

                    if ($assistantMessage) {
                        return [
                            'replayed' => true,
                            'result' => [
                                'conversation' => $this->query->conversationData($conversation),
                                'message' => $this->query->messageData($assistantMessage),
                                'generation' => $this->generationData($existing),
                                'replayed' => true,
                            ],
                        ];
                    }
                }

                if ($existing->status === 'pending') {
                    throw new LexaConflictException('Ya hay una generación en curso para esta consulta.');
                }

                if ($existing->status === 'failed' && $clientAttemptToken !== null && $existing->attempt_token === $clientAttemptToken) {
                    throw new LexaConflictException('Este intento ya finalizó sin respuesta confirmada. Inicia un nuevo reintento manual.');
                }
            }

            $attemptToken = $clientAttemptToken ?? (string) Str::uuid();
            $now = now();

            if ($existing !== null) {
                $existing->attempt_token = $attemptToken;
                $existing->status = 'pending';
                $existing->error_code = null;
                $existing->started_at = $now;
                $existing->completed_at = null;
                $existing->save();
                $generationId = $existing->id;
            } else {
                $generation = new LexaGeneration;
                $generation->lexa_conversation_id = $conversation->id;
                $generation->user_id = $current->id;
                $generation->user_message_id = $latestMessage->id;
                $generation->attempt_token = $attemptToken;
                $generation->status = 'pending';
                $generation->started_at = $now;
                $generation->save();
                $generationId = $generation->id;
            }

            $maxContext = max(1, (int) config('lexa.limits.max_context_messages', 12));
            $history = LexaMessage::where('lexa_conversation_id', $conversation->id)
                ->where('user_id', $current->id)
                ->whereIn('role', ['user', 'assistant'])
                ->orderByDesc('id')
                ->limit($maxContext)
                ->get()
                ->reverse()
                ->values()
                ->map(fn (LexaMessage $msg) => [
                    'role' => $msg->role,
                    'content' => $msg->content,
                ])
                ->all();

            return [
                'replayed' => false,
                'generation_id' => $generationId,
                'attempt_token' => $attemptToken,
                'user_message_id' => $latestMessage->id,
                'prompt' => new LexaPrompt(
                    mode: $conversation->mode,
                    messages: $history,
                    maxOutputTokens: (int) config('lexa.limits.max_output_tokens', 1024),
                ),
            ];
        }, lock: true);

        if ($reserved['replayed']) {
            return $reserved['result'];
        }

        // Phase 2: external AI provider call strictly OUTSIDE any SQL transaction or lock.
        try {
            $completion = $this->provider->generate($reserved['prompt']);
        } catch (Throwable $error) {
            $this->markGenerationFailed(
                $actor,
                $conversationId,
                $reserved['generation_id'],
                $reserved['attempt_token'],
                $error instanceof LexaUnavailableException ? 'provider_unavailable' : 'provider_error',
            );

            if ($error instanceof LexaUnavailableException) {
                throw $error;
            }

            throw new LexaUnavailableException('El servicio de orientación no está disponible temporalmente.');
        }

        // Phase 3: short transaction revalidating ownership, active state, attempt token, and latest message.
        return $this->persistCompletion(
            $actor,
            $conversationId,
            $reserved['generation_id'],
            $reserved['attempt_token'],
            $reserved['user_message_id'],
            $completion,
        );
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

    private function persistCompletion(
        User $actor,
        int $conversationId,
        int $generationId,
        string $attemptToken,
        int $expectedUserMessageId,
        LexaCompletion $completion,
    ): array {
        return $this->access->ownedConversation($actor, $conversationId, function (User $current, LexaConversation $conversation) use (
            $generationId,
            $attemptToken,
            $expectedUserMessageId,
            $completion,
        ) {
            Gate::forUser($current)->authorize('update', $conversation);

            if ($conversation->status !== 'active') {
                throw new LexaConflictException('La conversación dejó de estar activa durante la generación.');
            }

            $generation = LexaGeneration::where('lexa_conversation_id', $conversation->id)
                ->whereKey($generationId)
                ->lockForUpdate()
                ->first();

            if (! $generation || $generation->status !== 'pending' || $generation->attempt_token !== $attemptToken) {
                throw new LexaConflictException('El intento de generación ya no es válido.');
            }

            $latestMessage = LexaMessage::where('lexa_conversation_id', $conversation->id)
                ->where('user_id', $current->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $latestMessage || (int) $latestMessage->id !== $expectedUserMessageId || $latestMessage->role !== 'user') {
                $generation->status = 'failed';
                $generation->error_code = 'stale_context';
                $generation->completed_at = now();
                $generation->save();

                throw new LexaConflictException('El historial cambió durante la generación.');
            }

            $assistantMessage = new LexaMessage;
            $assistantMessage->lexa_conversation_id = $conversation->id;
            $assistantMessage->user_id = $current->id;
            $assistantMessage->role = 'assistant';
            $assistantMessage->content = $completion->content;
            $assistantMessage->status = 'completed';
            $assistantMessage->provider = $completion->provider;
            $assistantMessage->model = $completion->model;
            $assistantMessage->prompt_tokens = $completion->promptTokens;
            $assistantMessage->completion_tokens = $completion->completionTokens;
            $assistantMessage->latency_ms = $completion->latencyMs;
            $assistantMessage->citations_json = $completion->citations !== [] ? $completion->citations : null;
            $assistantMessage->save();

            $now = now();
            $generation->assistant_message_id = $assistantMessage->id;
            $generation->status = 'completed';
            $generation->provider = $completion->provider;
            $generation->model = $completion->model;
            $generation->prompt_tokens = $completion->promptTokens;
            $generation->completion_tokens = $completion->completionTokens;
            $generation->latency_ms = $completion->latencyMs;
            $generation->completed_at = $now;
            $generation->save();

            $conversation->last_message_at = $assistantMessage->created_at ?? $now;
            $conversation->version = $conversation->version + 1;
            $conversation->save();

            return [
                'conversation' => $this->query->conversationData($conversation),
                'message' => $this->query->messageData($assistantMessage),
                'generation' => $this->generationData($generation),
                'replayed' => false,
            ];
        }, lock: true);
    }

    private function markGenerationFailed(
        User $actor,
        int $conversationId,
        int $generationId,
        string $attemptToken,
        string $errorCode,
    ): void {
        try {
            $this->access->ownedConversation($actor, $conversationId, function (User $current, LexaConversation $conversation) use ($generationId, $attemptToken, $errorCode) {
                $generation = LexaGeneration::where('lexa_conversation_id', $conversation->id)
                    ->whereKey($generationId)
                    ->lockForUpdate()
                    ->first();

                if ($generation && $generation->status === 'pending' && $generation->attempt_token === $attemptToken) {
                    $generation->status = 'failed';
                    $generation->error_code = $errorCode;
                    $generation->completed_at = now();
                    $generation->save();
                }
            }, lock: true);
        } catch (Throwable) {
            // If conversation was deleted or user became inactive during external call, ignore cleanup failure.
        }
    }

    private function generationData(LexaGeneration $generation): array
    {
        return [
            'id' => $generation->id,
            'status' => $generation->status,
            'attempt_token' => $generation->attempt_token,
            'provider' => $generation->provider,
            'model' => $generation->model,
            'prompt_tokens' => $generation->prompt_tokens,
            'completion_tokens' => $generation->completion_tokens,
            'latency_ms' => $generation->latency_ms,
        ];
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
