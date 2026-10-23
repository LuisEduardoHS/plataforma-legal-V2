<?php

namespace Tests\Feature;

use App\Enums\AttachmentStatus;
use App\Enums\MessageStatus;
use App\Enums\VerificationEvidenceType;
use App\Exceptions\AttachmentException;
use App\Exceptions\MessagingException;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Attachments\AttachmentService;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingMediaService;
use App\Services\Messaging\MessagingQuery;
use App\Services\Profiles\ProfileService;
use App\Services\Verification\VerificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use VerificationFixtures;

    private bool $started = false;

    private ConversationService $conversations;

    private MessageService $messages;

    private MessagingQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('plataforma_legal_v2_test', DB::connection()->getDatabaseName());
        $this->assertSame('plataforma_legal_v2_test', DB::selectOne('SELECT DATABASE() db')->db);
        DB::beginTransaction();
        $this->started = true;
        Storage::fake('attachments');
        $this->conversations = app(ConversationService::class);
        $this->messages = app(MessageService::class);
        $this->query = app(MessagingQuery::class);
    }

    protected function tearDown(): void
    {
        try {
            Event::forget('eloquent.created: '.Message::class);
            Event::forget('eloquent.updating: '.Conversation::class);
            DB::disableQueryLog();
            if ($this->started) {
                DB::rollBack();
                Storage::disk('attachments')->delete(Storage::disk('attachments')->allFiles());
            }
        } finally {
            parent::tearDown();
        }
    }

    private function pair(): array
    {
        $a = User::factory()->create()->refresh();
        $b = User::factory()->create()->refresh();

        return [$a, $b, $this->conversations->start($a, $b->id)];
    }

    private function rejects(string $exception, callable $operation): void
    {
        try {
            $operation();
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);

            return;
        }
        $this->fail('Se aceptó una operación restringida: '.$exception);
    }

    private function member(User $actor, Conversation $conversation): ConversationParticipant
    {
        return $conversation->participants()->where('user_id', $actor->id)->firstOrFail();
    }

    public function test_normalized_pair_has_exactly_two_members_and_is_recovered_in_either_order(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $this->assertSame(min($a->id, $b->id), $conversation->user_low_id);
        $this->assertSame(max($a->id, $b->id), $conversation->user_high_id);
        $this->assertSame($conversation->id, $this->conversations->start($b, $a->id)->id);
        $this->assertSame($conversation->id, $this->conversations->start($a, $b->id)->id);
        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, $conversation->participants()->count());
        $this->assertTrue($a->conversationParticipants()->first()->conversation->is($conversation));
        $this->assertTrue($this->member($b, $conversation)->user->is($b));
        $this->assertCount(2, $this->query->participants($a, $conversation->id)['participants']);
        $this->rejects(AuthorizationException::class, fn () => $this->conversations->start($a, $a->id));
    }

    public function test_professionals_use_their_existing_user_without_privilege_changes(): void
    {
        [$professional] = $this->professional();
        $user = User::factory()->create()->refresh();
        $conversation = $this->conversations->start($user, $professional->id);
        $message = $this->messages->send($professional, $conversation->id, ['content' => 'Consulta profesional']);
        $this->assertSame($professional->id, $message->sender_id);
        $this->assertSame([], $professional->getRoleNames()->all());
        $this->assertSame([], $professional->getDirectPermissions()->all());
    }

    public function test_messages_are_persisted_as_untrusted_text_and_internal_fields_are_rejected(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $message = $this->messages->send($a, $conversation->id, ['content' => '  <script>no ejecutar</script>  ']);
        $this->assertSame('<script>no ejecutar</script>', $message->content);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertTrue($a->sentMessages()->first()->is($message));
        $this->assertTrue($message->sender->is($a));
        $this->assertTrue($message->conversation->is($conversation));
        foreach ([[], ['content' => ''], ['content' => "\u{200B}\u{00A0}"], ['content' => "a\0b"], ['content' => str_repeat('á', 10001)], ['content' => ['x']], ['sender_id' => $b->id], ['status' => 'sent'], ['version' => 9], ['attachment_ids' => ['x' => 1]]] as $data) {
            $this->rejects(ValidationException::class, fn () => $this->messages->send($a, $conversation->id, $data));
        }
        foreach ([Conversation::class, ConversationParticipant::class, Message::class, UserBlock::class] as $model) {
            $this->rejects(MassAssignmentException::class, fn () => $model::create(['user_id' => $a->id]));
        }
        $this->assertSame(1, Message::count());
    }

    public function test_send_tokens_are_idempotent_but_cannot_be_reused_with_different_input(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $data = ['content' => 'Mensaje único', 'client_token' => (string) Str::uuid()];
        $message = $this->messages->send($a, $conversation->id, $data);
        $this->assertSame($message->id, $this->messages->send($a, $conversation->id, $data)->id);
        $this->rejects(ValidationException::class, fn () => $this->messages->send($a, $conversation->id, array_replace($data, ['content' => 'Otro'])));
        $this->assertNotSame($message->id, $this->messages->send($b, $conversation->id, $data)->id);
        $this->assertSame(2, Message::count());
        $this->assertArrayNotHasKey('request_hash', $message->toArray());
    }

    public function test_admin_permissions_do_not_grant_access_to_other_peoples_conversations(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $this->seed(RolePermissionSeeder::class);
        $outsider = User::factory()->create()->refresh();
        $outsider->assignRole('admin');
        $file = $this->evidenceFile($a);
        $message = $this->messages->send($a, $conversation->id, ['attachment_ids' => [$file->id]]);
        foreach ([
            fn () => $this->query->history($outsider, $conversation->id),
            fn () => $this->query->participants($outsider, $conversation->id),
            fn () => $this->messages->send($outsider, $conversation->id, ['content' => 'Intrusión']),
            fn () => $this->messages->edit($outsider, $message->id, 'Intrusión', 1),
            fn () => $this->messages->deleteForEveryone($outsider, $message->id, 1),
            fn () => $this->messages->hideForMe($outsider, $message->id),
            fn () => $this->conversations->archive($outsider, $conversation->id),
            fn () => $this->conversations->hide($outsider, $conversation->id),
            fn () => $this->conversations->markRead($outsider, $conversation->id, $message->id),
            fn () => app(MessagingMediaService::class)->download($outsider, $conversation->id, $message->id, $file->id),
        ] as $operation) {
            $this->rejects(AuthorizationException::class, $operation);
        }
        $this->assertSame([], $this->query->inbox($outsider)->items());
        $this->assertSame(0, $this->query->unreadCount($outsider));
    }

    public function test_read_markers_advance_only_with_accessible_messages_and_never_regress(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $first = $this->messages->send($a, $conversation->id, ['content' => 'Primero']);
        $second = $this->messages->send($a, $conversation->id, ['content' => 'Segundo']);
        $this->assertSame(2, $this->query->unreadCount($b));
        $this->assertSame(0, $this->query->unreadCount($a));
        $this->assertSame(2, $this->query->inbox($b)->items()[0]['unread_count']);
        $this->conversations->markRead($b, $conversation->id, $second->id);
        $readAt = $this->member($b, $conversation)->last_read_at;
        $this->conversations->markRead($b, $conversation->id, $first->id);
        $this->conversations->markRead($b, $conversation->id, $second->id);
        $this->assertSame($second->id, $this->member($b, $conversation)->last_read_message_id);
        $this->assertTrue($readAt->equalTo($this->member($b, $conversation)->last_read_at));
        $this->assertSame(0, $this->query->unreadCount($b));
        $this->assertTrue($this->query->history($a, $conversation->id)->items()[0]['read_by_other']);
        $this->assertFalse($this->query->history($b, $conversation->id)->items()[0]['read_by_other']);
        $third = $this->messages->send($a, $conversation->id, ['content' => 'Tercero']);
        $this->assertSame([$third->id], collect($this->query->history($b, $conversation->id, unreadOnly: true)->items())->pluck('id')->all());
        $other = $this->conversations->start($a, User::factory()->create()->id);
        $foreign = $this->messages->send($a, $other->id, ['content' => 'Otra conversación']);
        $this->rejects(ValidationException::class, fn () => $this->conversations->markRead($b, $conversation->id, $foreign->id));
        $this->rejects(ValidationException::class, fn () => $this->conversations->markRead($b, $conversation->id, 999999999));
        $this->assertSame($second->id, $this->member($b, $conversation)->last_read_message_id);
    }

    public function test_only_sender_can_edit_or_delete_for_everyone_with_version_checks(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $message = $this->messages->send($a, $conversation->id, ['content' => 'Original']);
        $this->rejects(AuthorizationException::class, fn () => $this->messages->edit($b, $message->id, 'Ajeno', 1));
        $this->rejects(AuthorizationException::class, fn () => $this->messages->deleteForEveryone($b, $message->id, 1));
        $edited = $this->messages->edit($a, $message->id, 'Corregido', 1);
        $this->assertSame(2, $edited->version);
        $this->assertNotNull($edited->edited_at);
        $this->rejects(ValidationException::class, fn () => $this->messages->edit($a, $message->id, 'Obsoleto', 1));
        $this->rejects(ValidationException::class, fn () => $this->messages->edit($a, $message->id, ' ', 2));
        $this->messages->deleteForEveryone($a, $message->id, 2);
        $this->messages->deleteForEveryone($a, $message->id, 2);
        $this->assertSame(MessageStatus::Deleted, $message->fresh()->status);
        $this->assertNull($message->fresh()->content);
        $this->assertNotNull($message->fresh()->deleted_at);
        $history = $this->query->history($b, $conversation->id)->items();
        $this->assertSame('deleted', $history[0]['status']);
        $this->assertNull($history[0]['content']);
        $this->assertSame([], $history[0]['attachments']);
        $this->assertSame(0, $this->query->unreadCount($b));
        $this->rejects(AuthorizationException::class, fn () => $this->messages->edit($a, $message->id, 'Restaurar', 3));
    }

    public function test_hide_message_is_individual_and_does_not_claim_read_or_release_files(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $file = $this->evidenceFile($a);
        $message = $this->messages->send($a, $conversation->id, ['attachment_ids' => [$file->id]]);
        $this->messages->hideForMe($b, $message->id);
        $this->messages->hideForMe($b, $message->id);
        $this->assertSame([], $this->query->history($b, $conversation->id)->items());
        $this->assertCount(1, $this->query->history($a, $conversation->id)->items());
        $this->assertSame(0, $this->query->unreadCount($b));
        $this->assertNull($this->member($b, $conversation)->last_read_message_id);
        $this->assertNull($this->query->inbox($b)->items()[0]['last_message']);
        $this->rejects(ValidationException::class, fn () => $this->conversations->markRead($b, $conversation->id, $message->id));
        $this->rejects(AuthorizationException::class, fn () => app(MessagingMediaService::class)->download($b, $conversation->id, $message->id, $file->id));
        $this->messages->hideForMe($a, $message->id);
        $this->rejects(AttachmentException::class, fn () => app(AttachmentService::class)->requestDeletion($a, $file->id));
        $this->assertSame(1, Message::count());
    }

    public function test_archive_and_hide_history_do_not_change_other_participant_preferences(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $first = $this->messages->send($a, $conversation->id, ['content' => 'Histórico']);
        $this->conversations->archive($b, $conversation->id);
        $this->conversations->archive($b, $conversation->id);
        $this->assertSame([], $this->query->inbox($b)->items());
        $this->assertCount(1, $this->query->inbox($b, archived: true)->items());
        $this->assertNull($this->member($a, $conversation)->archived_at);
        $this->conversations->hide($b, $conversation->id);
        $this->assertSame([], $this->query->history($b, $conversation->id)->items());
        $this->assertNull($this->member($b, $conversation)->last_read_message_id);
        $this->assertSame($first->id, $this->member($b, $conversation)->hidden_through_message_id);
        $this->assertCount(1, $this->query->history($a, $conversation->id)->items());
        $new = $this->messages->send($a, $conversation->id, ['content' => 'Nuevo']);
        $this->assertCount(1, $this->query->inbox($b, archived: true)->items());
        $this->assertSame([$new->id], collect($this->query->history($b, $conversation->id)->items())->pluck('id')->all());
        $this->assertSame(1, $this->query->unreadCount($b));
        $this->conversations->archive($b, $conversation->id, false);
        $this->assertCount(1, $this->query->inbox($b)->items());
    }

    public function test_blocking_is_directional_to_manage_but_prevents_new_messages_both_ways(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $message = $this->messages->send($a, $conversation->id, ['content' => 'Histórico']);
        $this->conversations->block($b, $a->id);
        $this->conversations->block($b, $a->id);
        $this->assertSame(1, $b->blockedUsers()->count());
        $this->assertTrue(UserBlock::first()->blocker->is($b));
        $this->assertTrue(UserBlock::first()->blocked->is($a));
        $this->rejects(AuthorizationException::class, fn () => $this->messages->send($a, $conversation->id, ['content' => 'No']));
        $this->rejects(AuthorizationException::class, fn () => $this->messages->send($b, $conversation->id, ['content' => 'No']));
        $this->rejects(AuthorizationException::class, fn () => $this->messages->edit($a, $message->id, 'No', 1));
        $this->assertSame($conversation->id, $this->conversations->start($a, $b->id)->id);
        $this->assertCount(1, $this->query->history($a, $conversation->id)->items());
        $this->conversations->unblock($a, $b->id);
        $this->assertSame(1, UserBlock::count());
        $this->conversations->unblock($b, $a->id);
        $this->conversations->unblock($b, $a->id);
        $this->messages->send($a, $conversation->id, ['content' => 'Permitido']);
        $this->assertSame(2, Message::count());
        $other = User::factory()->create()->refresh();
        $this->conversations->block($a, $other->id);
        $this->rejects(AuthorizationException::class, fn () => $this->conversations->start($other, $a->id));
        $this->assertSame(1, Conversation::count());
        $this->rejects(AuthorizationException::class, fn () => $this->conversations->block($a, $a->id));
    }

    public function test_persisted_inactive_accounts_cannot_operate_even_with_stale_models(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $message = $this->messages->send($a, $conversation->id, ['content' => 'Histórico']);
        foreach (['suspended', 'disabled', 'pending_deletion'] as $status) {
            DB::table('users')->where('id', $a->id)->update(['status' => $status]);
            foreach ([
                fn () => $this->messages->send($a, $conversation->id, ['content' => 'No']),
                fn () => $this->messages->edit($a, $message->id, 'No', 1),
                fn () => $this->messages->deleteForEveryone($a, $message->id, 1),
                fn () => $this->messages->hideForMe($a, $message->id),
                fn () => $this->conversations->archive($a, $conversation->id),
                fn () => $this->conversations->hide($a, $conversation->id),
                fn () => $this->conversations->markRead($a, $conversation->id, $message->id),
                fn () => $this->conversations->block($a, $b->id),
                fn () => $this->conversations->unblock($a, $b->id),
                fn () => $this->query->inbox($a),
                fn () => $this->query->history($a, $conversation->id),
            ] as $operation) {
                $this->rejects(AuthorizationException::class, $operation);
            }
            $this->rejects(AuthorizationException::class, fn () => $this->messages->send($b, $conversation->id, ['content' => 'Destino inactivo']));
            $this->assertCount(1, $this->query->history($b, $conversation->id)->items());
            $this->assertCount(1, $this->query->inbox($b)->items());
        }
    }

    public function test_owned_available_files_are_ordered_private_and_downloadable_by_the_recipient(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $first = $this->evidenceFile($a);
        $second = $this->evidenceFile($a);
        $message = $this->messages->send($a, $conversation->id, ['attachment_ids' => [$second->id, $first->id]]);
        $this->assertSame([$second->id, $first->id], $message->attachments->modelKeys());
        $this->assertNotNull($first->fresh()->messaging_shared_at);
        $response = app(MessagingMediaService::class)->download($b, $conversation->id, $message->id, $first->id);
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        $this->assertSame(Storage::disk('attachments')->get($first->path), $content);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('private', $first->fresh()->visibility);
        $data = $this->query->history($b, $conversation->id)->items()[0];
        $this->assertSame([$second->id, $first->id], array_column($data['attachments'], 'id'));
        $this->assertArrayNotHasKey('path', $data['attachments'][0]);
        $this->rejects(AuthorizationException::class, fn () => app(AttachmentService::class)->download($b, $first->id));
    }

    public function test_attachment_validation_limits_ownership_status_and_verification_exclusion(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $foreign = $this->evidenceFile($b);
        $pending = $this->evidenceFile($a, status: AttachmentStatus::PendingUpload);
        $retained = $this->evidenceFile($a);
        $retained->forceFill(['verification_retained_at' => now()])->save();
        foreach ([$foreign, $pending, $retained] as $file) {
            $this->rejects(ValidationException::class, fn () => $this->messages->send($a, $conversation->id, ['attachment_ids' => [$file->id]]));
        }
        $file = $this->evidenceFile($a);
        $this->rejects(ValidationException::class, fn () => $this->messages->send($a, $conversation->id, ['attachment_ids' => [$file->id, $file->id]]));
        config(['messaging.max_attachments' => 1]);
        $this->rejects(ValidationException::class, fn () => $this->messages->send($a, $conversation->id, ['attachment_ids' => [$file->id, $pending->id]]));
        $this->assertSame(0, Message::count());
        [$professional, $profile] = $this->professional();
        $professionalConversation = $this->conversations->start($professional, $b->id);
        $workflow = app(VerificationService::class);
        $request = $workflow->createDraft($professional, $profile->id);
        $evidence = $this->evidenceFile($professional);
        $workflow->addEvidence($professional, $request->id, $evidence->id, VerificationEvidenceType::ProfessionalLicense);
        $this->rejects(ValidationException::class, fn () => $this->messages->send($professional, $professionalConversation->id, ['attachment_ids' => [$evidence->id]]));
        $shared = $this->evidenceFile($professional);
        $message = $this->messages->send($professional, $professionalConversation->id, ['attachment_ids' => [$shared->id]]);
        $this->rejects(ValidationException::class, fn () => $workflow->addEvidence($professional, $request->id, $shared->id, VerificationEvidenceType::Certificate));
        $this->messages->deleteForEveryone($professional, $message->id, 1);
        $this->rejects(ValidationException::class, fn () => $workflow->addEvidence($professional, $request->id, $shared->id, VerificationEvidenceType::Certificate));
        $this->rejects(AttachmentException::class, fn () => $shared->fresh()->forceFill(['messaging_shared_at' => null])->save());
    }

    public function test_referenced_files_survive_hiding_and_archiving_until_all_messages_are_deleted(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $file = $this->evidenceFile($a);
        $first = $this->messages->send($a, $conversation->id, ['attachment_ids' => [$file->id]]);
        $second = $this->messages->send($a, $conversation->id, ['attachment_ids' => [$file->id]]);
        $this->conversations->archive($a, $conversation->id);
        $this->conversations->hide($b, $conversation->id);
        $this->rejects(AttachmentException::class, fn () => app(AttachmentService::class)->requestDeletion($a, $file->id));
        $this->messages->deleteForEveryone($a, $first->id, 1);
        $this->rejects(AttachmentException::class, fn () => app(AttachmentService::class)->purge($a, $file->id));
        Storage::disk('attachments')->assertExists($file->path);
        $this->messages->deleteForEveryone($a, $second->id, 1);
        $this->rejects(AuthorizationException::class, fn () => app(MessagingMediaService::class)->download($a, $conversation->id, $second->id, $file->id));
        app(AttachmentService::class)->requestDeletion($a, $file->id);
        app(AttachmentService::class)->purge($a, $file->id);
        Storage::disk('attachments')->assertMissing($file->path);
        $this->assertSame(AttachmentStatus::Deleted, $file->fresh()->status);
    }

    public function test_media_checks_integrity_association_and_live_retention(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $file = $this->evidenceFile($a);
        $extra = $this->evidenceFile($a);
        $message = $this->messages->send($a, $conversation->id, ['attachment_ids' => [$file->id]]);
        $media = app(MessagingMediaService::class);
        $this->rejects(AuthorizationException::class, fn () => $media->download($b, $conversation->id, $message->id, $extra->id));
        Storage::disk('attachments')->put($file->path, 'Contenido alterado');
        $this->rejects(MessagingException::class, fn () => $media->download($b, $conversation->id, $message->id, $file->id));
        DB::table('attachments')->where('id', $file->id)->update(['verification_retained_at' => now()]);
        $this->rejects(ValidationException::class, fn () => $media->download($b, $conversation->id, $message->id, $file->id));
        $this->assertSame([], $this->query->history($b, $conversation->id)->items()[0]['attachments']);
    }

    public function test_history_and_inbox_cursor_pagination_are_deterministic_and_private(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->messages->send($a, $conversation->id, ['content' => 'Mensaje '.$i])->id;
        }
        $page = $this->query->history($b, $conversation->id, size: 2);
        $seen = array_column($page->items(), 'id');
        $this->messages->send($a, $conversation->id, ['content' => 'Nuevo entre páginas']);
        while ($cursor = $page->nextCursor()) {
            $page = $this->query->history($b, $conversation->id, size: 2, cursor: $cursor->encode());
            $seen = array_merge($seen, array_column($page->items(), 'id'));
        }
        $this->assertSame(array_reverse($ids), $seen);
        $conversationIds = [$conversation->id];
        for ($i = 0; $i < 3; $i++) {
            $conversationIds[] = $this->conversations->start($b, User::factory()->create()->id)->id;
        }
        DB::table('conversations')->whereIn('id', $conversationIds)->update(['activity_at' => '2026-01-01 12:00:00']);
        $page = $this->query->inbox($b, size: 2);
        $seen = array_column($page->items(), 'id');
        while ($cursor = $page->nextCursor()) {
            $page = $this->query->inbox($b, size: 2, cursor: $cursor->encode());
            $seen = array_merge($seen, array_column($page->items(), 'id'));
        }
        $this->assertSame(array_reverse($conversationIds), $seen);
        $this->assertSame([$conversation->id], array_column($this->query->inbox($b, participantId: $a->id)->items(), 'id'));
        foreach (['null', '{}', 'true', '{"id":1}', '{"id":[],"_pointsToNextItems":true}'] as $json) {
            $this->rejects(ValidationException::class, fn () => $this->query->inbox($b, cursor: base64_encode($json)));
        }
        $this->rejects(ValidationException::class, fn () => $this->query->history($b, $conversation->id, size: 51));
        $this->rejects(ValidationException::class, fn () => $this->query->inbox($b, size: 0));
    }

    public function test_inbox_uses_bounded_queries_and_hides_private_profile_fields(): void
    {
        [$a, $b, $conversation] = $this->pair();
        app(ProfileService::class)->saveUserProfile($a, $a->id, ['display_name' => 'Privado sensible', 'username' => 'privado_sensible', 'visibility' => 'private']);
        $this->messages->send($a, $conversation->id, ['content' => 'Mensaje']);
        for ($i = 0; $i < 4; $i++) {
            $other = User::factory()->create()->refresh();
            $chat = $this->conversations->start($b, $other->id);
            $this->messages->send($other, $chat->id, ['content' => 'Otro mensaje']);
        }
        DB::enableQueryLog();
        $this->query->inbox($b, size: 1);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $data = $this->query->inbox($b, size: 5)->items();
        $this->assertSame($one, count(DB::getQueryLog()));
        $this->assertLessThanOrEqual(6, $one);
        $json = json_encode($data);
        foreach ([$a->email, 'Privado sensible', 'privado_sensible', 'license_number', 'path', 'request_hash', 'last_read_message_id', 'hidden_through_message_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_mysql_enforces_pair_order_uniqueness_sender_membership_and_read_integrity(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $outsider = User::factory()->create()->refresh();
        $other = $this->conversations->start($a, $outsider->id);
        $message = $this->messages->send($a, $conversation->id, ['content' => 'Mensaje']);
        $foreign = $this->messages->send($outsider, $other->id, ['content' => 'Ajeno']);
        foreach ([
            fn () => DB::table('conversations')->insert(['user_low_id' => $a->id, 'user_high_id' => $a->id, 'activity_at' => now()]),
            fn () => DB::table('conversations')->insert(['user_low_id' => $conversation->user_high_id, 'user_high_id' => $conversation->user_low_id, 'activity_at' => now()]),
            fn () => DB::table('conversations')->insert(['user_low_id' => $conversation->user_low_id, 'user_high_id' => $conversation->user_high_id, 'activity_at' => now()]),
            fn () => DB::table('messages')->insert(['conversation_id' => $conversation->id, 'sender_id' => $outsider->id, 'content' => 'No', 'sent_at' => now(), 'request_hash' => str_repeat('a', 64)]),
            fn () => DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $b->id)->update(['last_read_message_id' => $foreign->id]),
            fn () => DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $b->id)->update(['hidden_through_message_id' => $foreign->id]),
            fn () => DB::table('message_user_hides')->insert(['conversation_id' => $conversation->id, 'message_id' => $message->id, 'user_id' => $outsider->id, 'created_at' => now()]),
            fn () => DB::table('messages')->where('id', $message->id)->update(['status' => 'deleted']),
            fn () => DB::table('user_blocks')->insert(['blocker_id' => $a->id, 'blocked_id' => $a->id]),
            fn () => DB::table('users')->where('id', $b->id)->delete(),
        ] as $operation) {
            $this->rejects(QueryException::class, $operation);
        }
    }

    public function test_send_failure_rolls_back_message_files_marker_and_inbox_state(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $conversation->forceFill(['activity_at' => now()->subMinute()])->save();
        $file = $this->evidenceFile($a);
        $this->conversations->hide($b, $conversation->id);
        $before = $this->member($b, $conversation)->hidden_at;
        Conversation::updating(function () {
            throw new \RuntimeException('Injected domain failure');
        });
        $this->rejects(\RuntimeException::class, fn () => $this->messages->send($a, $conversation->id, ['content' => 'No persistir', 'attachment_ids' => [$file->id]]));
        $this->assertSame(0, Message::count());
        $this->assertSame(0, DB::table('message_attachments')->count());
        $this->assertNull($file->fresh()->messaging_shared_at);
        $this->assertTrue($before->equalTo($this->member($b, $conversation)->hidden_at));
        Storage::disk('attachments')->assertExists($file->path);
    }

    public function test_new_targets_must_be_active_and_forged_membership_never_opens_a_chat(): void
    {
        [$a, $b, $conversation] = $this->pair();
        $target = User::factory()->create()->refresh();
        $target->forceFill(['status' => 'disabled'])->save();
        $this->rejects(AuthorizationException::class, fn () => $this->conversations->start($a, $target->id));
        DB::table('conversation_participants')->insert(['conversation_id' => $conversation->id, 'user_id' => $target->id]);
        $target->forceFill(['status' => 'active'])->save();
        $this->rejects(AuthorizationException::class, fn () => $this->query->history($target, $conversation->id));
        $this->rejects(AuthorizationException::class, fn () => $this->messages->send($target, $conversation->id, ['content' => 'Intrusión']));
        $this->rejects(AuthorizationException::class, fn () => $this->query->history($a, $conversation->id));
        $this->assertSame([], $this->query->inbox($target)->items());
    }

    public function test_allowed_other_types_work_and_public_profiles_expose_only_public_aliases(): void
    {
        [$a, $b, $conversation] = $this->pair();
        app(ProfileService::class)->saveUserProfile($a, $a->id, ['display_name' => 'Alias público', 'username' => 'alias_publico', 'visibility' => 'public']);
        $file = $this->evidenceFile($a, 'other');
        $file->forceFill(['mime_type' => 'audio/mpeg', 'extension' => 'mp3'])->save();
        $message = $this->messages->send($a, $conversation->id, ['attachment_ids' => [$file->id]]);
        $this->assertSame('other', $this->query->history($b, $conversation->id)->items()[0]['attachments'][0]['category']);
        $participant = $this->query->inbox($b)->items()[0]['participant'];
        $this->assertSame('Alias público', $participant['display_name']);
        $this->assertSame('alias_publico', $participant['username']);
        $this->assertSame(['id', 'display_name', 'username'], array_keys($participant));
        $this->assertSame($message->id, $this->query->inbox($b)->items()[0]['last_message']['id']);
    }
}
