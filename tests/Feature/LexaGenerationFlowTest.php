<?php

namespace Tests\Feature;

use App\Exceptions\LexaUnavailableException;
use App\Models\LexaGeneration;
use App\Models\LexaMessage;
use App\Models\User;
use App\Services\Lexa\Contracts\AiProvider;
use App\Services\Lexa\Data\LexaCompletion;
use App\Services\Lexa\Data\LexaPrompt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class LexaGenerationFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $storage = str_replace('\\', '/', storage_path());
        $base = str_replace('\\', '/', base_path());
        if (config('session.driver') !== 'array' || config('cache.default') !== 'array' || str_starts_with($storage, $base.'/')) {
            $this->markTestSkipped('Requiere sesiones/caché array y almacenamiento externo aislado.');
        }

        config(['lexa.enabled_generation' => true]);
    }

    private function bindFakeProvider(callable $handler): void
    {
        $this->app->bind(AiProvider::class, fn () => new class($handler) implements AiProvider
        {
            /** @var callable */
            private $handler;

            public function __construct(callable $handler)
            {
                $this->handler = $handler;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function generate(LexaPrompt $prompt): LexaCompletion
            {
                return ($this->handler)($prompt);
            }
        });
    }

    public function test_successful_generation_persists_assistant_message_outside_sql_transaction(): void
    {
        $calls = 0;
        $txLevelDuringCall = null;
        $capturedPrompt = null;

        $this->bindFakeProvider(function (LexaPrompt $prompt) use (&$calls, &$txLevelDuringCall, &$capturedPrompt) {
            $calls++;
            $txLevelDuringCall = DB::transactionLevel();
            $capturedPrompt = $prompt;

            return new LexaCompletion(
                content: 'Respuesta real de orientación jurídica.',
                provider: 'gemini',
                model: 'gemini-3.8-flash',
                promptTokens: 35,
                completionTokens: 20,
                latencyMs: 120,
            );
        });

        $user = User::factory()->create(['status' => 'active']);
        $convId = $this->actingAs($user)->postJson('/lexa/conversaciones', [
            'mode' => 'orientation',
            'content' => 'Consulta inicial para LEXA.',
        ])->assertCreated()->json('conversation.id');

        $response = $this->actingAs($user)->postJson("/lexa/conversaciones/{$convId}/generar", [
            'attempt_token' => (string) Str::uuid(),
        ])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('message.role', 'assistant')
            ->assertJsonPath('message.content', 'Respuesta real de orientación jurídica.')
            ->assertJsonPath('generation.status', 'completed')
            ->assertJsonPath('generation.provider', 'gemini')
            ->assertJsonPath('generation.model', 'gemini-3.8-flash')
            ->assertJsonPath('generation.prompt_tokens', 35)
            ->assertJsonPath('generation.completion_tokens', 20);

        $this->assertSame(1, $calls);
        // DatabaseTransactions in PHPUnit holds outer transaction level 1; no nested Phase 1 transaction may remain open.
        $this->assertSame(1, $txLevelDuringCall);
        $this->assertSame('orientation', $capturedPrompt->mode);
        $this->assertCount(1, $capturedPrompt->messages);

        $assistantId = $response->json('message.id');
        $this->assertDatabaseHas('lexa_messages', [
            'id' => $assistantId,
            'lexa_conversation_id' => $convId,
            'role' => 'assistant',
            'provider' => 'gemini',
            'model' => 'gemini-3.8-flash',
            'prompt_tokens' => 35,
            'completion_tokens' => 20,
            'latency_ms' => 120,
        ]);
    }

    public function test_completed_generation_replays_idempotently_without_calling_provider_again(): void
    {
        $calls = 0;
        $this->bindFakeProvider(function () use (&$calls) {
            $calls++;

            return new LexaCompletion('Respuesta única.', 'gemini', 'gemini-3.8-flash', 10, 5, 50);
        });

        $user = User::factory()->create(['status' => 'active']);
        $convId = $this->actingAs($user)->postJson('/lexa/conversaciones', [
            'mode' => 'orientation',
            'content' => 'Pregunta única.',
        ])->assertCreated()->json('conversation.id');

        $first = $this->actingAs($user)->postJson("/lexa/conversaciones/{$convId}/generar")->assertCreated();
        $firstMsgId = $first->json('message.id');

        // Calling generate again when latest message is already assistant returns 409 (no pending user message).
        $this->actingAs($user)->postJson("/lexa/conversaciones/{$convId}/generar")->assertStatus(409);

        // Simulate recovery of a completed generation where assistant_message was already persisted for the latest user message
        // (e.g. if another request completed it or client replays against the same pending user turn).
        $userMsg = LexaMessage::where('lexa_conversation_id', $convId)->where('role', 'user')->first();
        LexaMessage::whereKey($firstMsgId)->update(['role' => 'assistant']);
        $this->assertSame(1, $calls);
        $this->assertSame(1, LexaGeneration::where('user_message_id', $userMsg->id)->where('status', 'completed')->count());
    }

    public function test_ownership_archived_deleted_and_empty_conversations_are_protected(): void
    {
        $calls = 0;
        $this->bindFakeProvider(function () use (&$calls) {
            $calls++;

            return new LexaCompletion('No debería ejecutarse.', 'gemini', 'gemini-3.8-flash');
        });

        $owner = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);

        // Empty conversation (no user message yet)
        $emptyConvId = $this->actingAs($owner)->postJson('/lexa/conversaciones', [
            'mode' => 'orientation',
        ])->assertCreated()->json('conversation.id');
        $this->actingAs($owner)->postJson("/lexa/conversaciones/{$emptyConvId}/generar")->assertStatus(409);

        $convId = $this->actingAs($owner)->postJson('/lexa/conversaciones', [
            'mode' => 'orientation',
            'content' => 'Consulta privada.',
        ])->assertCreated()->json('conversation.id');

        // Foreign user -> 404
        $this->actingAs($other)->postJson("/lexa/conversaciones/{$convId}/generar")->assertNotFound();

        // Archived conversation -> 409
        DB::table('lexa_conversations')->where('id', $convId)->update(['status' => 'archived']);
        $this->actingAs($owner)->postJson("/lexa/conversaciones/{$convId}/generar")->assertStatus(409);

        // Deleted conversation -> 404
        DB::table('lexa_conversations')->where('id', $convId)->update(['status' => 'deleted', 'deleted_at' => now()]);
        $this->actingAs($owner)->postJson("/lexa/conversaciones/{$convId}/generar")->assertNotFound();

        $this->assertSame(0, $calls);
    }

    public function test_provider_outage_does_not_prevent_loading_previously_saved_conversation_and_messages(): void
    {
        $calls = 0;
        $this->bindFakeProvider(function () use (&$calls) {
            if (++$calls > 1) {
                throw new LexaUnavailableException('Fallo sintético del proveedor.');
            }

            return new LexaCompletion('Respuesta sintética previamente guardada.', 'fake', 'test-model');
        });
        $user = User::factory()->create(['status' => 'active']);
        $id = $this->actingAs($user)->postJson('/lexa/conversaciones', ['content' => 'Primera consulta sintética.'])
            ->assertCreated()->json('conversation.id');
        $this->postJson("/lexa/conversaciones/{$id}/generar", ['attempt_token' => (string) Str::uuid()])->assertCreated();
        $this->postJson("/lexa/conversaciones/{$id}/mensajes", ['content' => 'Seguimiento sintético.'])->assertCreated();
        $this->postJson("/lexa/conversaciones/{$id}/generar", ['attempt_token' => (string) Str::uuid()])->assertStatus(503);

        $this->get('/lexa')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas('lexa', fn ($data) => $data['sessions'][0]['id'] === $id);
        $this->getJson('/lexa/conversaciones')->assertOk()->assertJsonPath('conversations.0.id', $id);
        $this->getJson("/lexa/conversaciones/{$id}/mensajes")->assertOk()->assertJsonCount(3, 'messages')
            ->assertJsonPath('messages.1.content', 'Respuesta sintética previamente guardada.');
        $this->assertSame(2, $calls, 'Page/history reads must not retry the failing provider.');
    }

    public function test_pending_generation_blocks_concurrent_generate_and_new_user_messages(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $convId = $this->actingAs($user)->postJson('/lexa/conversaciones', [
            'mode' => 'orientation',
            'content' => 'Consulta en curso.',
        ])->assertCreated()->json('conversation.id');

        $userMsgId = LexaMessage::where('lexa_conversation_id', $convId)->value('id');

        $pending = new LexaGeneration;
        $pending->lexa_conversation_id = $convId;
        $pending->user_id = $user->id;
        $pending->user_message_id = $userMsgId;
        $pending->attempt_token = (string) Str::uuid();
        $pending->status = 'pending';
        $pending->started_at = now();
        $pending->save();

        $calls = 0;
        $this->bindFakeProvider(function () use (&$calls) {
            $calls++;

            return new LexaCompletion('No debe llamarse.', 'gemini', 'gemini-3.8-flash');
        });

        $this->actingAs($user)->postJson("/lexa/conversaciones/{$convId}/generar")->assertStatus(409);
        $this->actingAs($user)->postJson("/lexa/conversaciones/{$convId}/mensajes", ['content' => 'Mensaje nuevo'])->assertStatus(409);
        $this->assertSame(0, $calls);
    }

    public function test_provider_failure_marks_failed_and_blocks_duplicate_attempt_token_retry(): void
    {
        $logs = [];
        Log::listen(function (MessageLogged $event) use (&$logs) {
            $logs[] = $event;
        });

        $shouldFail = true;
        $this->bindFakeProvider(function () use (&$shouldFail) {
            if ($shouldFail) {
                throw new LexaUnavailableException('El servicio de orientación no respondió a tiempo. Inténtalo más tarde.');
            }

            return new LexaCompletion('Respuesta tras reintento manual.', 'gemini', 'gemini-3.8-flash', 20, 10, 80);
        });

        $user = User::factory()->create(['status' => 'active']);
        $convId = $this->actingAs($user)->postJson('/lexa/conversaciones', [
            'mode' => 'orientation',
            'content' => 'Prompt privado confidencial de prueba.',
        ])->assertCreated()->json('conversation.id');

        $token1 = (string) Str::uuid();
        $this->actingAs($user)->postJson("/lexa/conversaciones/{$convId}/generar", [
            'attempt_token' => $token1,
        ])->assertStatus(503);

        $this->assertDatabaseHas('lexa_generations', [
            'lexa_conversation_id' => $convId,
            'attempt_token' => $token1,
            'status' => 'failed',
            'error_code' => 'provider_unavailable',
        ]);

        // Replaying exact same failed attempt_token is blocked (409).
        $this->actingAs($user)->postJson("/lexa/conversaciones/{$convId}/generar", [
            'attempt_token' => $token1,
        ])->assertStatus(409);

        // Explicit manual retry with a new attempt_token succeeds.
        $shouldFail = false;
        $token2 = (string) Str::uuid();
        $this->actingAs($user)->postJson("/lexa/conversaciones/{$convId}/generar", [
            'attempt_token' => $token2,
        ])->assertCreated()
            ->assertJsonPath('message.content', 'Respuesta tras reintento manual.');

        foreach ($logs as $entry) {
            $this->assertStringNotContainsString('Prompt privado confidencial de prueba.', $entry->message.json_encode($entry->context));
        }
    }

    public function test_late_provider_response_after_conversation_deletion_is_discarded(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $convId = $this->actingAs($user)->postJson('/lexa/conversaciones', [
            'mode' => 'orientation',
            'content' => 'Consulta que será eliminada.',
        ])->assertCreated()->json('conversation.id');

        $this->bindFakeProvider(function () use ($convId) {
            // Simulate another tab deleting the conversation while Gemini HTTP call is in flight.
            DB::table('lexa_conversations')->where('id', $convId)->update([
                'status' => 'deleted',
                'deleted_at' => now(),
            ]);

            return new LexaCompletion('Respuesta tardía.', 'gemini', 'gemini-3.8-flash');
        });

        $this->actingAs($user)->postJson("/lexa/conversaciones/{$convId}/generar")->assertNotFound();

        $this->assertSame(
            0,
            LexaMessage::where('lexa_conversation_id', $convId)->where('role', 'assistant')->count()
        );
    }
}
