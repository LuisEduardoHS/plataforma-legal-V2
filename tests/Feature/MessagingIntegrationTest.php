<?php

namespace Tests\Feature;

use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingQuery;
use App\Services\Profiles\ProfileService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EnforcedCsrfToken;
use Tests\TestCase;

class MessagingIntegrationTest extends TestCase
{
    private bool $started = false;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->started = true;
    }

    protected function tearDown(): void
    {
        try {
            Event::forget(QueryExecuted::class);
            Event::forget('eloquent.created: '.Message::class);
            DB::disableQueryLog();
            if ($this->started) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function pair(): array
    {
        $a = User::factory()->create()->refresh();
        $b = User::factory()->create()->refresh();

        return [$a, $b, app(ConversationService::class)->start($a, $b->id)];
    }

    private function send(User $actor, int $id, string $text = 'Mensaje sintético'): Message
    {
        return app(MessageService::class)->send($actor, $id, ['content' => $text, 'client_token' => (string) Str::uuid()]);
    }

    public function test_guest_requests_require_authentication_and_empty_inbox_has_no_demo_messages(): void
    {
        $this->get('/mensajes')->assertRedirect(route('login'));
        $this->getJson('/mensajes/1/actualizaciones')->assertUnauthorized();
        $this->postJson('/mensajes/conversaciones/1')->assertUnauthorized();
        $actor = User::factory()->create();
        $this->actingAs($actor)->get('/mensajes')->assertOk()->assertSee('No tienes conversaciones activas disponibles')->assertDontSee('data-messaging-data')->assertDontSee('Solo en esta página');
        $this->call('HEAD', '/mensajes')->assertOk();
    }

    public function test_start_recovers_the_canonical_pair_in_both_directions_without_extra_participants(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $id = $this->actingAs($a)->postJson('/mensajes/conversaciones/'.$b->id)->assertOk()->json('id');
        $this->postJson('/mensajes/conversaciones/'.$b->id)->assertOk()->assertJsonPath('id', $id);
        $this->actingAs($b)->postJson('/mensajes/conversaciones/'.$a->id)->assertOk()->assertJsonPath('id', $id);
        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, DB::table('conversation_participants')->where('conversation_id', $id)->count());
        $this->postJson('/mensajes/conversaciones/'.$b->id)->assertNotFound();
        $this->postJson('/mensajes/conversaciones/999999999999')->assertNotFound();
        $this->postJson('/mensajes/conversaciones/'.$a->id, ['participants' => [$a->id, $b->id, 3]])->assertUnprocessable();
    }

    public function test_public_profiles_and_professional_directory_start_real_conversations_without_contact_leaks(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        app(ProfileService::class)->saveUserProfile($target, $target->id, ['visibility' => 'public', 'display_name' => 'Alias público de testing', 'username' => 'chat_public_testing']);
        app(ProfileService::class)->saveProfessionalProfile($target, $target->id, ['visibility' => 'public', 'headline' => 'Profesional sintético']);
        $this->actingAs($actor)->get('/usuarios/'.$target->id)->assertOk()->assertSee(route('messages.start', $target->id))->assertSee('Enviar mensaje')->assertDontSee($target->email);
        $this->get('/profesionales')->assertOk()->assertSee(route('messages.start', $target->id));
        $this->get('/profesionales/'.$target->id)->assertOk()->assertSee(route('messages.start', $target->id));
        $this->post('/mensajes/conversaciones/'.$target->id)->assertRedirect();
        $chat = Conversation::sole();
        $this->get('/mensajes/'.$chat->id)->assertOk()->assertSee('Alias público de testing')->assertDontSee($target->email)->assertDontSee($target->name);
    }

    public function test_admins_and_nonparticipants_cannot_access_or_mutate_other_conversations(): void
    {
        [$a, $b, $chat] = $this->pair();
        $message = $this->send($a, $chat->id);
        $this->seed(RolePermissionSeeder::class);
        $outsider = User::factory()->create();
        $outsider->assignRole('admin');
        $this->actingAs($outsider);
        foreach (['/mensajes/'.$chat->id, '/mensajes/'.$chat->id.'/actualizaciones', '/mensajes/'.$chat->id.'/mensajes/'.$message->id.'/editar'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['content' => 'Intruso', 'client_token' => (string) Str::uuid()])->assertNotFound();
        $this->postJson('/mensajes/'.$chat->id.'/lectura', ['message_id' => $message->id])->assertNotFound();
        $this->deleteJson('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['version' => 1])->assertNotFound();
        $this->assertSame(1, Message::count());
        $this->get('/mensajes')->assertOk()->assertViewHas('inbox', fn ($page) => count($page) === 0);
    }

    public function test_send_retries_keep_one_message_and_the_same_id_after_edit_or_delete(): void
    {
        [$a, $b, $chat] = $this->pair();
        $payload = ['content' => '  Mensaje idempotente  ', 'client_token' => (string) Str::uuid()];
        $first = $this->actingAs($a)->postJson('/mensajes/'.$chat->id.'/mensajes', $payload)->assertOk();
        $id = $first->json('id');
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertSame(1, Message::count());
        $this->putJson('/mensajes/'.$chat->id.'/mensajes/'.$id, ['content' => 'Editado', 'version' => 1])->assertOk();
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', $payload)->assertOk()->assertJsonPath('id', $id)->assertJsonPath('version', 2);
        $this->deleteJson('/mensajes/'.$chat->id.'/mensajes/'.$id, ['version' => 2])->assertOk();
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', $payload)->assertOk()->assertJsonPath('id', $id)->assertJsonPath('status', 'deleted');
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['content' => 'Distinto', 'client_token' => $payload['client_token']])->assertUnprocessable();
        $this->assertSame(1, Message::count());
    }

    public function test_native_forms_keep_a_stable_compose_token_and_escape_message_text(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        $one = $this->get('/mensajes/'.$chat->id)->assertOk()->viewData('composer')['client_token'];
        $two = $this->get('/mensajes/'.$chat->id)->assertOk()->viewData('composer')['client_token'];
        $this->assertSame($one, $two);
        $this->from('/mensajes/'.$chat->id)->post('/mensajes/'.$chat->id.'/mensajes', ['content' => '   ', 'client_token' => $one])->assertRedirect()->assertSessionHasErrors('content')->assertSessionHasInput('client_token', $one);
        $this->post('/mensajes/'.$chat->id.'/mensajes', ['content' => '<script>hostil</script>', 'client_token' => $one])->assertRedirect(route('messages.show', $chat->id));
        $this->assertSame(1, Message::count());
        $this->get('/mensajes/'.$chat->id)->assertOk()->assertSee('&lt;script&gt;hostil&lt;/script&gt;', false)->assertDontSee('<script>hostil</script>', false);
        $this->assertNotSame($one, session('messaging.compose.'.$a->id.'.'.$chat->id.'.client_token'));
        $this->actingAs($b->fresh())->get('/mensajes/'.$chat->id)->assertOk()->assertSee('&lt;script&gt;hostil&lt;/script&gt;', false);
    }

    public function test_edits_require_sender_access_and_original_versions_even_after_validation_errors(): void
    {
        [$a, $b, $chat] = $this->pair();
        $message = $this->send($a, $chat->id);
        $this->actingAs($b)->putJson('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['content' => 'No', 'version' => 1])->assertNotFound();
        $this->actingAs($a)->get('/mensajes/'.$chat->id.'/mensajes/'.$message->id.'/editar')->assertOk();
        app(MessageService::class)->edit($a, $message->id, 'Cambio concurrente', 1);
        $this->put('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['content' => str_repeat('x', 10001), 'version' => 1])->assertRedirect(route('messages.edit', ['conversation' => $chat->id, 'message' => $message->id]))->assertSessionHasInput('version', '1');
        $this->get('/mensajes/'.$chat->id.'/mensajes/'.$message->id.'/editar')->assertOk()->assertSee('name="version" value="1"', false);
        $this->putJson('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['content' => 'No sobrescribir', 'version' => 1])->assertConflict();
        $this->deleteJson('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['version' => 1])->assertConflict();
        $this->putJson('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['content' => 'No', 'version' => ['bad']])->assertUnprocessable();
        $this->assertSame('Cambio concurrente', $message->fresh()->content);
    }

    public function test_message_context_cannot_be_swapped_even_when_actor_participates_in_both(): void
    {
        [$a, $b, $chat] = $this->pair();
        $c = User::factory()->create();
        $other = app(ConversationService::class)->start($a, $c->id);
        $message = $this->send($a, $other->id);
        $this->actingAs($a)->putJson('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['content' => 'No', 'version' => 1])->assertNotFound();
        $this->deleteJson('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['version' => 1])->assertNotFound();
        $this->postJson('/mensajes/'.$chat->id.'/mensajes/'.$message->id.'/ocultar')->assertNotFound();
        $this->postJson('/mensajes/'.$chat->id.'/lectura', ['message_id' => $message->id])->assertUnprocessable();
        $this->assertSame('Mensaje sintético', $message->fresh()->content);
    }

    public function test_individual_hides_and_archive_preferences_do_not_change_the_other_participant(): void
    {
        [$a, $b, $chat] = $this->pair();
        $first = $this->send($b, $chat->id, 'Anterior accesible');
        $latest = $this->send($b, $chat->id, 'Ocultar individual');
        $this->actingAs($a)->postJson('/mensajes/'.$chat->id.'/mensajes/'.$latest->id.'/ocultar')->assertOk();
        $this->postJson('/mensajes/'.$chat->id.'/mensajes/'.$latest->id.'/ocultar')->assertOk();
        $this->get('/mensajes/'.$chat->id)->assertOk()->assertDontSee('Ocultar individual')->assertSee('Anterior accesible');
        $this->postJson('/mensajes/'.$chat->id.'/archivo', ['archived' => 1])->assertOk();
        $this->get('/mensajes')->assertOk()->assertViewHas('inbox', fn ($p) => count($p) === 0);
        $this->get('/mensajes?archived=1')->assertOk()->assertViewHas('inbox', fn ($p) => count($p) === 1);
        $this->actingAs($b)->get('/mensajes/'.$chat->id)->assertOk()->assertSee('Ocultar individual');
        $this->get('/mensajes')->assertOk()->assertViewHas('inbox', fn ($p) => count($p) === 1);
        $this->actingAs($a)->postJson('/mensajes/'.$chat->id.'/archivo', ['archived' => 0])->assertOk();
        $this->postJson('/mensajes/'.$chat->id.'/ocultar')->assertOk();
        $this->get('/mensajes/'.$chat->id)->assertOk()->assertDontSee('Anterior accesible');
        $this->send($b, $chat->id, 'Mensaje posterior');
        $this->get('/mensajes/'.$chat->id)->assertOk()->assertSee('Mensaje posterior')->assertDontSee('Anterior accesible');
        $this->assertSame(3, Message::count());
    }

    public function test_delete_for_everyone_is_explicit_versioned_and_preserves_a_tombstone(): void
    {
        [$a, $b, $chat] = $this->pair();
        $message = $this->send($a, $chat->id, 'Texto a retirar');
        $this->actingAs($b)->deleteJson('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['version' => 1])->assertNotFound();
        $this->actingAs($a)->deleteJson('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['version' => 1])->assertOk();
        $this->deleteJson('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['version' => 1])->assertOk();
        $this->assertSame(MessageStatus::Deleted, $message->fresh()->status);
        $this->actingAs($b)->get('/mensajes/'.$chat->id)->assertOk()->assertSee('Mensaje eliminado para todos')->assertDontSee('Texto a retirar');
    }

    public function test_inbox_history_and_polling_never_mark_read_without_the_explicit_confirmation(): void
    {
        [$a, $b, $chat] = $this->pair();
        $first = $this->send($b, $chat->id, 'Uno');
        $last = $this->send($b, $chat->id, 'Dos');
        $this->actingAs($a)->get('/mensajes')->assertOk()->assertSee('2 mensajes no leídos');
        $this->get('/mensajes/'.$chat->id)->assertOk();
        $this->getJson('/mensajes/'.$chat->id.'/actualizaciones')->assertOk();
        $this->assertNull(DB::table('conversation_participants')->where('conversation_id', $chat->id)->where('user_id', $a->id)->value('last_read_message_id'));
        $this->postJson('/mensajes/'.$chat->id.'/lectura', ['message_id' => $last->id])->assertOk();
        $date = DB::table('conversation_participants')->where('conversation_id', $chat->id)->where('user_id', $a->id)->value('last_read_at');
        $this->postJson('/mensajes/'.$chat->id.'/lectura', ['message_id' => $first->id])->assertOk();
        $own = DB::table('conversation_participants')->where('conversation_id', $chat->id)->where('user_id', $a->id)->first();
        $this->assertSame($last->id, $own->last_read_message_id);
        $this->assertSame($date, $own->last_read_at);
        $this->actingAs($b)->get('/mensajes/'.$chat->id)->assertOk()->assertSee('Leído');
        $this->actingAs($a)->postJson('/mensajes/'.$chat->id.'/mensajes/'.$last->id.'/ocultar')->assertOk();
        $this->postJson('/mensajes/'.$chat->id.'/lectura', ['message_id' => $last->id])->assertUnprocessable();
    }

    public function test_blocking_is_directional_preserves_history_and_prevents_send_and_edit_for_both(): void
    {
        [$a, $b, $chat] = $this->pair();
        $message = $this->send($a, $chat->id, 'Historial conservado');
        $this->actingAs($a)->putJson('/mensajes/'.$chat->id.'/bloqueo')->assertOk();
        $this->putJson('/mensajes/'.$chat->id.'/bloqueo')->assertOk();
        $this->get('/mensajes/'.$chat->id)->assertOk()->assertSee('Historial conservado')->assertSee('Quitar mi bloqueo');
        $this->actingAs($b)->postJson('/mensajes/'.$chat->id.'/mensajes', ['content' => 'No', 'client_token' => (string) Str::uuid()])->assertNotFound();
        $this->actingAs($a)->putJson('/mensajes/'.$chat->id.'/mensajes/'.$message->id, ['content' => 'No', 'version' => 1])->assertNotFound();
        $this->actingAs($b)->deleteJson('/mensajes/'.$chat->id.'/bloqueo')->assertOk();
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['content' => 'No', 'client_token' => (string) Str::uuid()])->assertNotFound();
        $this->postJson('/mensajes/conversaciones/'.$a->id)->assertOk()->assertJsonPath('id', $chat->id);
        $this->actingAs($a)->deleteJson('/mensajes/'.$chat->id.'/bloqueo')->assertOk();
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['content' => 'Desbloqueado', 'client_token' => (string) Str::uuid()])->assertOk();
        $this->assertSame(2, Message::count());
    }

    public function test_block_prevents_new_pair_but_retrieving_existing_inactive_target_keeps_history_access(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        app(ConversationService::class)->block($a, $b->id);
        $this->actingAs($a)->postJson('/mensajes/conversaciones/'.$b->id)->assertNotFound();
        app(ConversationService::class)->unblock($a, $b->id);
        $chat = app(ConversationService::class)->start($a, $b->id);
        $this->send($b, $chat->id, 'Historia de cuenta desactivada');
        $b->forceFill(['status' => 'disabled'])->save();
        $this->postJson('/mensajes/conversaciones/'.$b->id)->assertOk()->assertJsonPath('id', $chat->id);
        $this->get('/mensajes/'.$chat->id)->assertOk()->assertSee('Historia de cuenta desactivada')->assertViewHas('context', fn ($c) => $c['can_send'] === false);
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['content' => 'No', 'client_token' => (string) Str::uuid()])->assertNotFound();
        $c = User::factory()->create();
        $this->actingAs($c)->postJson('/mensajes/conversaciones/'.$b->id)->assertNotFound();
    }

    public function test_history_and_inbox_have_stable_cursor_pages_and_do_not_expose_tokens_or_private_aliases(): void
    {
        [$a, $b, $chat] = $this->pair();
        for ($i = 0; $i < 21; $i++) {
            $this->send($b, $chat->id, 'Texto '.$i);
        }
        $this->actingAs($a);
        $response = $this->get('/mensajes/'.$chat->id)->assertOk()->assertDontSee($b->name)->assertDontSee($b->email);
        $history = $response->viewData('history');
        $this->assertCount(20, $history);
        $older = $this->get('/mensajes/'.$chat->id.'?history_cursor='.$history->nextCursor()->encode())->assertOk()->viewData('history');
        $this->assertCount(1, $older);
        $this->assertSame([], array_intersect(array_column($older->items(), 'id'), array_column($history->items(), 'id')));
        $poll = $this->getJson('/mensajes/'.$chat->id.'/actualizaciones')->assertOk()->assertJsonStructure(['history_html', 'inbox_html', 'information_html', 'can_send'])->assertDontSee(Message::first()->client_token)->assertDontSee(Message::first()->request_hash);
        $html = $poll->json('history_html');
        preg_match_all('/data-message-id="([0-9]+)"/', $html, $matches);
        $ids = array_map('intval', $matches[1]);
        $this->assertSame(count($ids), count(array_unique($ids)));
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
        $this->assertSame($html, $this->getJson('/mensajes/'.$chat->id.'/actualizaciones')->assertOk()->json('history_html'));
        $this->get('/mensajes?cursor=bad')->assertUnprocessable();
        $this->get('/mensajes/'.$chat->id.'?history_cursor=bad')->assertUnprocessable();
    }

    public function test_inbox_cursor_and_exact_participant_filter_remain_private(): void
    {
        $a = User::factory()->create();
        $this->actingAs($a);
        for ($i = 0; $i < 21; $i++) {
            $b = User::factory()->create();
            app(ConversationService::class)->start($a, $b->id);
        }
        $page = $this->get('/mensajes')->assertOk()->viewData('inbox');
        $this->assertCount(20, $page);
        $this->get('/mensajes?cursor='.$page->nextCursor()->encode())->assertOk()->assertViewHas('inbox', fn ($p) => count($p) === 1);
        $this->get('/mensajes?participant_id='.$b->id)->assertOk()->assertViewHas('inbox', fn ($p) => count($p) === 1);
        $this->get('/mensajes?user_id='.$b->id)->assertUnprocessable();
    }

    public static function inactiveStates(): array
    {
        return [['suspended'], ['disabled'], ['pending_deletion']];
    }

    #[DataProvider('inactiveStates')]
    public function test_persisted_inactive_actor_cannot_read_poll_send_or_create_with_stale_session(string $state): void
    {
        [$a, $b, $chat] = $this->pair();
        DB::table('users')->where('id', $a->id)->update(['status' => $state]);
        $this->actingAs($a)->get('/mensajes')->assertRedirect(route('login'));
        $this->actingAs($a)->getJson('/mensajes/'.$chat->id.'/actualizaciones')->assertUnauthorized();
        $this->actingAs($a)->postJson('/mensajes/'.$chat->id.'/mensajes', ['content' => 'No', 'client_token' => (string) Str::uuid()])->assertUnauthorized();
        $this->actingAs($a)->postJson('/mensajes/conversaciones/'.$b->id)->assertUnauthorized();
    }

    public function test_strict_inputs_reject_sender_ids_participants_invalid_tokens_and_hostile_shapes(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        foreach ([['sender_id' => $b->id], ['participants' => [$a->id, $b->id]], ['status' => 'sent'], ['content' => ['bad']], ['client_token' => 'bad'], ['attachment_ids' => ['1 OR 1=1']], ['uploads' => []]] as $extra) {
            $this->postJson('/mensajes/'.$chat->id.'/mensajes', $extra + ['content' => 'No', 'client_token' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['content' => 'No token'])->assertUnprocessable();
        $this->get('/mensajes?participant_id[]=bad')->assertUnprocessable();
        $this->assertSame(0, Message::count());
    }

    public function test_all_writes_require_csrf_and_rate_limits_protect_read_start_send_upload_and_files(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        foreach ([['post', '/mensajes/conversaciones/'.$b->id], ['post', '/mensajes/'.$chat->id.'/mensajes'], ['put', '/mensajes/'.$chat->id.'/mensajes/1'], ['delete', '/mensajes/'.$chat->id.'/mensajes/1'], ['post', '/mensajes/'.$chat->id.'/mensajes/1/ocultar'], ['post', '/mensajes/'.$chat->id.'/lectura'], ['post', '/mensajes/'.$chat->id.'/archivo'], ['post', '/mensajes/'.$chat->id.'/ocultar'], ['put', '/mensajes/'.$chat->id.'/bloqueo'], ['delete', '/mensajes/'.$chat->id.'/bloqueo'], ['post', '/mensajes/'.$chat->id.'/adjuntos'], ['delete', '/mensajes/'.$chat->id.'/adjuntos/1']] as [$method,$url]) {
            $this->$method($url)->assertStatus(419);
        }
    }

    public function test_rate_limit_bindings_cover_all_messaging_transports(): void
    {
        [$a,$b,$chat] = $this->pair();
        $this->actingAs($a);
        foreach (['messaging.read', 'messaging.start', 'messaging.write', 'messaging.upload', 'messaging.files'] as $name) {
            RateLimiter::for($name, fn () => Limit::perMinute(1)->by($name));
        }
        $this->get('/mensajes')->assertOk();
        $this->getJson('/mensajes/'.$chat->id.'/actualizaciones')->assertStatus(429);
        $this->postJson('/mensajes/conversaciones/'.$b->id)->assertOk();
        $this->postJson('/mensajes/conversaciones/'.$b->id)->assertStatus(429);
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['content' => 'Uno', 'client_token' => (string) Str::uuid()])->assertOk();
        $this->postJson('/mensajes/'.$chat->id.'/lectura', ['message_id' => 1])->assertStatus(429);
        $this->postJson('/mensajes/'.$chat->id.'/adjuntos')->assertUnprocessable();
        $this->postJson('/mensajes/'.$chat->id.'/adjuntos')->assertStatus(429);
        $url = '/mensajes/'.$chat->id.'/mensajes/1/archivos/1';
        $this->get($url)->assertNotFound();
        $this->get($url)->assertStatus(429);
    }

    public function test_sql_errors_and_partial_message_writes_are_sanitized_and_rolled_back(): void
    {
        [$a,$b,$chat] = $this->pair();
        $this->actingAs($a);
        config(['app.debug' => true]);
        Event::listen('eloquent.created: '.Message::class, fn () => throw new QueryException('mysql', 'INSERT private_payload', [], new \PDOException('secret SQL binding')));
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['content' => 'No guardar', 'client_token' => (string) Str::uuid()])->assertStatus(503)->assertDontSee('private_payload')->assertDontSee('secret SQL binding');
        $this->assertSame(0, Message::count());
        $this->mock(MessagingQuery::class)->shouldReceive('inbox')->once()->andThrow(new QueryException('mysql', 'SELECT secret_credentials', [], new \PDOException('private trace')));
        $this->get('/mensajes')->assertStatus(503)->assertDontSee('secret_credentials')->assertDontSee('private trace');
    }

    public function test_two_independent_blocks_must_both_be_removed_to_enable_sending(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a)->putJson('/mensajes/'.$chat->id.'/bloqueo')->assertOk();
        $this->actingAs($b)->putJson('/mensajes/'.$chat->id.'/bloqueo')->assertOk();
        $this->actingAs($a)->deleteJson('/mensajes/'.$chat->id.'/bloqueo')->assertOk();
        $this->getJson('/mensajes/'.$chat->id.'/actualizaciones')->assertOk()->assertJsonPath('can_send', false);
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['client_token' => (string) Str::uuid(), 'content' => 'No'])->assertNotFound();
        $this->actingAs($b)->deleteJson('/mensajes/'.$chat->id.'/bloqueo')->assertOk();
        $this->getJson('/mensajes/'.$chat->id.'/actualizaciones')->assertOk()->assertJsonPath('can_send', true);
    }

    public function test_inbox_and_poll_queries_are_bounded_as_visible_rows_grow(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->send($b, $chat->id);
        $this->actingAs($a);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/mensajes/'.$chat->id.'/actualizaciones')->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 5; $i++) {
            $this->send($b, $chat->id);
            $target = User::factory()->create();
            app(ConversationService::class)->start($a, $target->id);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/mensajes/'.$chat->id.'/actualizaciones')->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(35, $large);
    }
}
