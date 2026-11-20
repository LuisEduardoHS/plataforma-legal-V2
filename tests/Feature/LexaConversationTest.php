<?php

namespace Tests\Feature;

use App\Models\LexaConversation;
use App\Models\LexaMessage;
use App\Models\User;
use App\Services\Lexa\Contracts\AiProvider;
use App\Services\Lexa\Providers\NullAiProvider;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LexaConversationTest extends TestCase
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
    }

    public function test_guest_and_inactive_accounts_cannot_access_lexa_endpoints(): void
    {
        $this->getJson('/lexa/conversaciones')->assertUnauthorized();
        $this->postJson('/lexa/conversaciones', ['mode' => 'orientation'])->assertUnauthorized();

        $inactive = User::factory()->create(['status' => 'suspended']);
        $this->actingAs($inactive)->getJson('/lexa/conversaciones')->assertUnauthorized();
        $this->actingAs($inactive)->postJson('/lexa/conversaciones', ['mode' => 'orientation'])->assertUnauthorized();
    }

    public function test_user_can_create_list_show_append_and_delete_own_conversations(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $create = $this->actingAs($user)->postJson('/lexa/conversaciones', [
            'mode' => 'contract',
            'content' => 'Quiero revisar las cláusulas de rescisión de un contrato.',
        ])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('conversation.mode', 'contract')
            ->assertJsonPath('message.role', 'user');

        $conversationId = $create->json('conversation.id');
        $this->assertNotNull($conversationId);

        $this->actingAs($user)->getJson('/lexa/conversaciones')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.id', $conversationId);

        $this->actingAs($user)->getJson("/lexa/conversaciones/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversationId);

        $this->actingAs($user)->postJson("/lexa/conversaciones/{$conversationId}/mensajes", [
            'content' => 'Segundo mensaje de seguimiento.',
        ])->assertCreated()
            ->assertJsonPath('message.role', 'user')
            ->assertJsonPath('message.content', 'Segundo mensaje de seguimiento.');

        $this->actingAs($user)->getJson("/lexa/conversaciones/{$conversationId}/mensajes")
            ->assertOk()
            ->assertJsonCount(2, 'messages');

        $this->actingAs($user)->deleteJson("/lexa/conversaciones/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->actingAs($user)->getJson("/lexa/conversaciones/{$conversationId}")->assertNotFound();
        $this->actingAs($user)->getJson('/lexa/conversaciones')->assertOk()->assertJsonCount(0, 'conversations');
    }

    public function test_users_are_strictly_isolated_and_cannot_enumerate_foreign_conversations(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);

        $conversationId = $this->actingAs($owner)->postJson('/lexa/conversaciones', [
            'mode' => 'orientation',
            'content' => 'Consulta privada del propietario.',
        ])->assertCreated()->json('conversation.id');

        // Foreign, missing, and deleted all return 404 to avoid enumeration.
        $this->actingAs($other)->getJson('/lexa/conversaciones')->assertOk()->assertJsonCount(0, 'conversations');
        $this->actingAs($other)->getJson("/lexa/conversaciones/{$conversationId}")->assertNotFound();
        $this->actingAs($other)->getJson("/lexa/conversaciones/{$conversationId}/mensajes")->assertNotFound();
        $this->actingAs($other)->postJson("/lexa/conversaciones/{$conversationId}/mensajes", ['content' => 'Intrusión'])->assertNotFound();
        $this->actingAs($other)->postJson("/lexa/conversaciones/{$conversationId}/generar")->assertNotFound();
        $this->actingAs($other)->deleteJson("/lexa/conversaciones/{$conversationId}")->assertNotFound();
        $this->actingAs($other)->getJson('/lexa/conversaciones/999999999')->assertNotFound();

        $this->assertSame(1, LexaMessage::where('lexa_conversation_id', $conversationId)->count());
    }

    public function test_role_spoofing_forged_fields_and_oversized_inputs_are_rejected(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $conversationId = $this->actingAs($user)->postJson('/lexa/conversaciones', [
            'mode' => 'orientation',
            'content' => 'Inicio válido.',
        ])->assertCreated()->json('conversation.id');

        foreach (['assistant', 'system'] as $forgedRole) {
            $this->actingAs($user)->postJson('/lexa/conversaciones', [
                'mode' => 'orientation',
                'content' => 'Intento de rol falso.',
                'role' => $forgedRole,
            ])->assertUnprocessable();

            $this->actingAs($user)->postJson("/lexa/conversaciones/{$conversationId}/mensajes", [
                'content' => 'Intento de inyectar respuesta IA.',
                'role' => $forgedRole,
            ])->assertUnprocessable();
        }

        $this->actingAs($user)->postJson("/lexa/conversaciones/{$conversationId}/mensajes", [
            'content' => 'Campos internos falsificados.',
            'user_id' => 1,
            'provider' => 'fake',
            'model' => 'fake',
            'citations_json' => [['source' => 'fake']],
        ])->assertUnprocessable();

        $this->actingAs($user)->postJson("/lexa/conversaciones/{$conversationId}/mensajes", [
            'content' => '   ',
        ])->assertUnprocessable();

        $this->actingAs($user)->postJson("/lexa/conversaciones/{$conversationId}/mensajes", [
            'content' => str_repeat('a', 5001),
        ])->assertUnprocessable();

        $this->actingAs($user)->postJson('/lexa/conversaciones', [
            'mode' => 'invalid-mode',
            'content' => 'Modo inválido.',
        ])->assertUnprocessable();

        $this->assertSame(1, LexaMessage::where('lexa_conversation_id', $conversationId)->count());
    }

    public function test_pagination_preserves_order_and_rejects_invalid_cursors(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $conversationId = $this->actingAs($user)->postJson('/lexa/conversaciones', [
            'mode' => 'organize',
            'content' => 'Mensaje 1',
        ])->assertCreated()->json('conversation.id');

        $this->actingAs($user)->postJson("/lexa/conversaciones/{$conversationId}/mensajes", ['content' => 'Mensaje 2'])->assertCreated();
        $this->actingAs($user)->postJson("/lexa/conversaciones/{$conversationId}/mensajes", ['content' => 'Mensaje 3'])->assertCreated();

        $firstPage = $this->actingAs($user)->getJson("/lexa/conversaciones/{$conversationId}/mensajes?size=2")
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.content', 'Mensaje 1')
            ->assertJsonPath('messages.1.content', 'Mensaje 2');

        $cursor = $firstPage->json('next_cursor');
        $this->assertNotEmpty($cursor);

        $this->actingAs($user)->getJson("/lexa/conversaciones/{$conversationId}/mensajes?size=2&cursor=".urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.content', 'Mensaje 3');

        $this->actingAs($user)->getJson("/lexa/conversaciones/{$conversationId}/mensajes?cursor=invalido")
            ->assertUnprocessable();

        $this->actingAs($user)->getJson('/lexa/conversaciones/not-a-number')
            ->assertNotFound();
    }

    public function test_archived_conversations_remain_accessible_only_to_owner(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);

        $conversationId = $this->actingAs($owner)->postJson('/lexa/conversaciones', [
            'mode' => 'orientation',
            'content' => 'Consulta archivada.',
        ])->assertCreated()->json('conversation.id');

        DB::table('lexa_conversations')->where('id', $conversationId)->update(['status' => 'archived']);

        $this->actingAs($owner)->getJson("/lexa/conversaciones/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('conversation.status', 'archived');
        $this->actingAs($owner)->getJson('/lexa/conversaciones')
            ->assertOk()
            ->assertJsonCount(1, 'conversations');

        $this->actingAs($other)->getJson("/lexa/conversaciones/{$conversationId}")
            ->assertNotFound();
    }

    public function test_generation_endpoint_is_disabled_and_never_creates_fake_assistant_messages(): void
    {
        $this->assertInstanceOf(NullAiProvider::class, app(AiProvider::class));
        $this->assertFalse(app(AiProvider::class)->isConfigured());

        $user = User::factory()->create(['status' => 'active']);
        $conversationId = $this->actingAs($user)->postJson('/lexa/conversaciones', [
            'mode' => 'orientation',
            'content' => '¿Qué debo revisar en un contrato?',
        ])->assertCreated()->json('conversation.id');

        $this->actingAs($user)->postJson("/lexa/conversaciones/{$conversationId}/generar")
            ->assertStatus(503)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(
            0,
            LexaMessage::where('lexa_conversation_id', $conversationId)->whereIn('role', ['assistant', 'system'])->count()
        );
    }

    public function test_mutations_enforce_csrf_protection(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app->make(\Illuminate\Contracts\Encryption\Encrypter::class)) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $this->actingAs($user)
            ->post('/lexa/conversaciones', ['mode' => 'orientation', 'content' => 'Sin token'])
            ->assertStatus(419);

        $this->assertSame(0, LexaConversation::where('user_id', $user->id)->count());
    }

    public function test_lexa_page_hydrates_only_own_persisted_conversations_without_example_responses(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);

        $this->actingAs($owner)->postJson('/lexa/conversaciones', [
            'mode' => 'orientation',
            'content' => 'Mi consulta privada persistida',
        ])->assertCreated();

        $this->actingAs($other)->postJson('/lexa/conversaciones', [
            'mode' => 'contract',
            'content' => 'Consulta ajena confidencial',
        ])->assertCreated();

        $response = $this->actingAs($owner)->get('/lexa')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $html = $response->getContent();
        $this->assertStringContainsString('Mi consulta privada persistida', $html);
        $this->assertStringNotContainsString('Consulta ajena confidencial', $html);
        $this->assertStringNotContainsString('example_responses', $html);
    }
}
