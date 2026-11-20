<?php

namespace Tests\Feature;

use App\Enums\AttachmentStatus;
use App\Exceptions\AttachmentException;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestingIsolation;
use Tests\TestCase;

class MessagingMediaIntegrationTest extends TestCase
{
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments');
        $this->assertIsolatedDisk();
        Event::listen('eloquent.creating: '.Attachment::class, fn () => $this->assertIsolatedDisk());
    }

    private function assertIsolatedDisk(): void
    {
        $disk = Storage::disk(config('attachments.disk'));
        $root = str_replace('\\', '/', $disk->path(''));
        $this->assertStringStartsWith(str_replace('\\', '/', storage_path('framework/testing/disks/attachments')), $root);
        $this->assertStringNotContainsString('/storage/app/attachments', $root);
        $this->assertSame(TestingIsolation::database(), DB::selectOne('SELECT DATABASE() db')->db);
    }

    protected function tearDown(): void
    {
        try {
            Event::forget('eloquent.creating: '.Attachment::class);
            Event::forget('eloquent.updating: '.Conversation::class);
            Event::forget(QueryExecuted::class);
            if ($this->users !== []) {
                foreach (Attachment::whereIn('user_id', $this->users)->get() as $file) {
                    Storage::disk('attachments')->delete($file->path);
                }
                DB::transaction(function () {
                    $conversations = DB::table('conversations')->whereIn('user_low_id', $this->users)->orWhereIn('user_high_id', $this->users)->pluck('id');
                    $messages = DB::table('messages')->whereIn('conversation_id', $conversations)->pluck('id');
                    DB::table('conversation_participants')->whereIn('conversation_id', $conversations)->update(['last_read_message_id' => null, 'hidden_through_message_id' => null]);
                    DB::table('message_user_hides')->whereIn('conversation_id', $conversations)->delete();
                    DB::table('message_attachments')->whereIn('message_id', $messages)->delete();
                    DB::table('messages')->whereIn('id', $messages)->delete();
                    DB::table('conversation_participants')->whereIn('conversation_id', $conversations)->delete();
                    DB::table('conversations')->whereIn('id', $conversations)->delete();
                    DB::table('user_blocks')->whereIn('blocker_id', $this->users)->orWhereIn('blocked_id', $this->users)->delete();
                    DB::table('attachments')->whereIn('user_id', $this->users)->delete();
                    DB::table('users')->whereIn('id', $this->users)->delete();
                });
            }
        } finally {
            parent::tearDown();
        }
    }

    private function member(): User
    {
        $user = User::factory()->create()->refresh();
        $this->users[] = $user->id;

        return $user;
    }

    private function pair(): array
    {
        $a = $this->member();
        $b = $this->member();

        return [$a, $b, app(ConversationService::class)->start($a, $b->id)];
    }

    private function file(string $name = 'documento-<script>.txt'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, 'Documento sintetico aislado de INTEGRACION-05.');
    }

    private function prepared(int $chat): Attachment
    {
        $id = $this->postJson('/mensajes/'.$chat.'/adjuntos', ['uploads' => [$this->file()]])->assertCreated()->json('attachments.0.id');

        return Attachment::findOrFail($id);
    }

    public function test_two_phase_upload_sends_the_same_attachment_ids_on_retries_and_never_reuploads(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        $token = $this->get('/mensajes/'.$chat->id)->assertOk()->viewData('composer')['client_token'];
        $file = $this->prepared($chat->id);
        $this->assertSame(AttachmentStatus::Available, $file->status);
        $this->assertNull($file->messaging_shared_at);
        $this->get('/mensajes/'.$chat->id)->assertOk()->assertSee('documento-&lt;script&gt;.txt', false)->assertViewHas('composer', fn ($c) => $c['client_token'] === $token && $c['attachments'][0]['id'] === $file->id);
        $data = ['content' => 'Con archivo', 'client_token' => $token, 'attachment_ids' => [$file->id]];
        $first = $this->postJson('/mensajes/'.$chat->id.'/mensajes', $data)->assertOk()->json('id');
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', $data)->assertOk()->assertJsonPath('id', $first);
        $this->assertSame(1, Message::where('conversation_id', $chat->id)->count());
        $this->assertSame(1, Attachment::where('user_id', $a->id)->count());
        $this->assertNotNull($file->fresh()->messaging_shared_at);
        $this->assertCount(0, $this->get('/mensajes/'.$chat->id)->assertOk()->viewData('composer')['attachments']);
    }

    public function test_context_downloads_are_private_and_hide_delete_and_block_follow_domain_rules(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        $file = $this->prepared($chat->id);
        $id = $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['client_token' => (string) Str::uuid(), 'attachment_ids' => [$file->id]])->assertOk()->json('id');
        $url = '/mensajes/'.$chat->id.'/mensajes/'.$id.'/archivos/'.$file->id;
        $this->get('/mensajes/'.$chat->id)->assertOk()->assertDontSee($file->path)->assertDontSee($file->storage_name)->assertDontSee($file->sha256)->assertDontSee('<iframe', false)->assertDontSee('<video', false)->assertSee('Descargar');
        $this->actingAs($b);
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('attachments')->get($file->path), $response->streamedContent());
        $this->putJson('/mensajes/'.$chat->id.'/bloqueo')->assertOk();
        $this->get($url)->assertOk();
        $this->postJson('/mensajes/'.$chat->id.'/mensajes/'.$id.'/ocultar')->assertOk();
        $this->get($url)->assertNotFound();
        $this->actingAs($a)->get($url)->assertOk();
        $this->deleteJson('/mensajes/'.$chat->id.'/mensajes/'.$id, ['version' => 1])->assertOk();
        $this->get($url)->assertNotFound();
        $this->assertSame(AttachmentStatus::Available, $file->fresh()->status);
        $this->assertSame(1, DB::table('message_attachments')->where('attachment_id', $file->id)->count());
    }

    public function test_files_cannot_be_downloaded_by_guests_outsiders_or_a_different_message_context(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        $file = $this->prepared($chat->id);
        $id = $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['client_token' => (string) Str::uuid(), 'attachment_ids' => [$file->id]])->assertOk()->json('id');
        $url = '/mensajes/'.$chat->id.'/mensajes/'.$id.'/archivos/'.$file->id;
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->member())->get($url)->assertNotFound();
        $this->actingAs($a);
        $second = app(MessageService::class)->send($a, $chat->id, ['content' => 'Sin archivo']);
        $this->get('/mensajes/'.$chat->id.'/mensajes/'.$second->id.'/archivos/'.$file->id)->assertNotFound();
        $otherChat = app(ConversationService::class)->start($a, $this->member()->id);
        $this->get('/mensajes/'.$otherChat->id.'/mensajes/'.$id.'/archivos/'.$file->id)->assertNotFound();
    }

    public function test_native_upload_and_removal_only_affect_prepared_files_in_the_actors_session(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        $this->post('/mensajes/'.$chat->id.'/adjuntos', ['uploads' => [$this->file()]])->assertRedirect(route('messages.show', $chat->id));
        $file = Attachment::where('user_id', $a->id)->sole();
        $this->get('/mensajes/'.$chat->id)->assertOk()->assertViewHas('composer', fn ($c) => count($c['attachments']) === 1);
        $this->actingAs($b)->deleteJson('/mensajes/'.$chat->id.'/adjuntos/'.$file->id)->assertNotFound();
        $this->actingAs($a)->delete('/mensajes/'.$chat->id.'/adjuntos/'.$file->id)->assertRedirect();
        $this->assertSame(AttachmentStatus::PendingDeletion, $file->fresh()->status);
        $this->get('/mensajes/'.$chat->id)->assertOk()->assertViewHas('composer', fn ($c) => count($c['attachments']) === 0);
        Storage::disk('attachments')->assertExists($file->path);
    }

    public function test_references_from_another_send_retain_files_when_removed_from_the_composer(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        $file = $this->prepared($chat->id);
        app(MessageService::class)->send($a, $chat->id, ['attachment_ids' => [$file->id]]);
        $this->deleteJson('/mensajes/'.$chat->id.'/adjuntos/'.$file->id)->assertOk();
        $this->assertSame(AttachmentStatus::Available, $file->fresh()->status);
        Storage::disk('attachments')->assertExists($file->path);
    }

    public function test_partial_message_failure_keeps_prepared_files_reusable_with_the_original_token(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        $file = $this->prepared($chat->id);
        $this->travel(2)->seconds(); // Force activity_at to become dirty before injecting its write failure.
        Event::listen('eloquent.updating: '.Conversation::class, fn () => throw new QueryException('mysql', 'UPDATE private_payload', [], new \PDOException('private binding')));
        $payload = ['client_token' => (string) Str::uuid(), 'content' => 'Retry', 'attachment_ids' => [$file->id]];
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', $payload)->assertStatus(503)->assertDontSee('private_payload')->assertDontSee('private binding');
        $this->assertSame(0, Message::where('conversation_id', $chat->id)->count());
        $this->assertSame(0, DB::table('message_attachments')->where('attachment_id', $file->id)->count());
        $this->assertNull($file->fresh()->messaging_shared_at);
        $this->assertSame(AttachmentStatus::Available, $file->fresh()->status);
        Event::forget('eloquent.updating: '.Conversation::class);
        $id = $this->postJson('/mensajes/'.$chat->id.'/mensajes', $payload)->assertOk()->json('id');
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertSame(1, Attachment::where('user_id', $a->id)->count());
    }

    public function test_partial_upload_failure_compensates_only_successful_new_reservations(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        $existing = app(AttachmentService::class)->store($a, $this->file('conservar.txt'));
        $real = app(AttachmentService::class);
        $mock = Mockery::mock(AttachmentService::class);
        $calls = 0;
        $mock->shouldReceive('store')->twice()->andReturnUsing(function ($actor, $file) use ($real, &$calls) {
            if (++$calls === 2) {
                throw new AttachmentException;
            }

            return $real->store($actor, $file);
        });
        $mock->shouldReceive('requestDeletion')->once()->andReturnUsing(fn ($actor, $id) => $real->requestDeletion($actor, $id));
        $this->app->instance(AttachmentService::class, $mock);
        $this->postJson('/mensajes/'.$chat->id.'/adjuntos', ['uploads' => [$this->file('nuevo1.txt'), $this->file('nuevo2.txt')]])->assertStatus(503);
        $this->assertSame(AttachmentStatus::Available, $existing->fresh()->status);
        $new = Attachment::where('user_id', $a->id)->where('id', '!=', $existing->id)->sole();
        $this->assertSame(AttachmentStatus::PendingDeletion, $new->status);
        $this->assertSame(0, Message::where('conversation_id', $chat->id)->count());
    }

    public static function badFiles(): array
    {
        return [['demo.docx', 'PK office'], ['script.svg', '<svg></svg>'], ['false.png', 'texto'], ['page.html', '<html>'], ['empty.txt', '']];
    }

    #[DataProvider('badFiles')]
    public function test_unsupported_formats_and_mime_mismatches_leave_no_uploaded_records(string $name, string $content): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        $this->postJson('/mensajes/'.$chat->id.'/adjuntos', ['uploads' => [UploadedFile::fake()->createWithContent($name, $content)]])->assertUnprocessable();
        $this->assertSame(0, Attachment::where('user_id', $a->id)->count());
    }

    public function test_category_sizes_counts_foreign_and_retained_files_are_enforced(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        config(['attachments.max_bytes.document' => 10]);
        $this->postJson('/mensajes/'.$chat->id.'/adjuntos', ['uploads' => [$this->file()]])->assertUnprocessable();
        config(['attachments.max_bytes.document' => 20 * 1024 * 1024]);
        $foreign = app(AttachmentService::class)->store($b, $this->file());
        $retained = app(AttachmentService::class)->store($a, $this->file('retener.txt'));
        $retained->forceFill(['verification_retained_at' => now()])->save();
        foreach ([$foreign, $retained] as $file) {
            $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['client_token' => (string) Str::uuid(), 'attachment_ids' => [$file->id]])->assertUnprocessable();
        }
        $this->postJson('/mensajes/'.$chat->id.'/adjuntos', ['uploads' => array_map(fn ($i) => $this->file('archivo'.$i.'.txt'), range(1, 4))])->assertCreated();
        $this->postJson('/mensajes/'.$chat->id.'/adjuntos', ['uploads' => [$this->file('quinto.txt')]])->assertUnprocessable();
        $this->assertSame(5, Attachment::where('user_id', $a->id)->count());
    }

    public function test_blocked_uploads_are_rejected_before_physical_io_and_corruption_returns_safe_errors(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        app(ConversationService::class)->block($b, $a->id);
        $this->postJson('/mensajes/'.$chat->id.'/adjuntos', ['uploads' => [$this->file()]])->assertNotFound();
        $this->assertSame(0, Attachment::where('user_id', $a->id)->count());
        app(ConversationService::class)->unblock($b, $a->id);
        $file = $this->prepared($chat->id);
        $id = $this->postJson('/mensajes/'.$chat->id.'/mensajes', ['client_token' => (string) Str::uuid(), 'attachment_ids' => [$file->id]])->assertOk()->json('id');
        Storage::disk('attachments')->put($file->path, 'objeto alterado');
        $this->get('/mensajes/'.$chat->id.'/mensajes/'.$id.'/archivos/'.$file->id)->assertStatus(503)->assertDontSee($file->path)->assertDontSee($file->sha256);
    }

    public function test_lost_acknowledgement_after_commit_can_retry_text_and_file_ids_without_duplicates(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        $file = $this->prepared($chat->id);
        $armed = true;
        Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$armed) {
            if ($armed && str_contains($event->sql, 'select `id`, `original_name`, `extension`, `size_bytes` from `attachments`')) {
                $armed = false;
                throw new QueryException('mysql', 'SELECT private_post_commit', [], new \PDOException('private SQL data'));
            }
        });
        $payload = ['content' => 'Confirmación perdida', 'client_token' => (string) Str::uuid(), 'attachment_ids' => [$file->id]];
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', $payload)->assertStatus(503)->assertDontSee('private_post_commit');
        $this->assertFalse($armed);
        $message = Message::where('conversation_id', $chat->id)->sole();
        $this->postJson('/mensajes/'.$chat->id.'/mensajes', $payload)->assertOk()->assertJsonPath('id', $message->id);
        $this->assertSame(1, Message::where('conversation_id', $chat->id)->count());
        $this->assertSame(1, Attachment::where('user_id', $a->id)->count());
    }

    public function test_access_revoked_during_upload_compensates_the_new_file_before_it_can_be_sent(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        $real = app(AttachmentService::class);
        $mock = Mockery::mock(AttachmentService::class);
        $mock->shouldReceive('store')->once()->andReturnUsing(function ($actor, $file) use ($real, $a, $b) {
            $result = $real->store($actor, $file);
            app(ConversationService::class)->block($b, $a->id);

            return $result;
        });
        $mock->shouldReceive('requestDeletion')->once()->andReturnUsing(fn ($actor, $id) => $real->requestDeletion($actor, $id));
        $this->app->instance(AttachmentService::class, $mock);
        $this->postJson('/mensajes/'.$chat->id.'/adjuntos', ['uploads' => [$this->file()]])->assertNotFound();
        $this->assertSame(AttachmentStatus::PendingDeletion, Attachment::where('user_id', $a->id)->sole()->status);
        $this->assertSame(0, Message::where('conversation_id', $chat->id)->count());
    }

    public function test_failed_physical_write_leaves_a_traceable_reservation_and_no_message_or_object(): void
    {
        [$a, $b, $chat] = $this->pair();
        $this->actingAs($a);
        $disk = Storage::disk('attachments');
        $mock = Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('put')->once()->andReturnUsing(function ($path) use ($disk): bool {
            $disk->put($path, 'partial bytes');

            return false;
        });
        Storage::set('attachments', $mock);
        $this->postJson('/mensajes/'.$chat->id.'/adjuntos', ['uploads' => [$this->file()]])->assertStatus(503);
        $file = Attachment::where('user_id', $a->id)->sole();
        $this->assertSame(AttachmentStatus::Deleted, $file->status);
        $disk->assertMissing($file->path);
        $this->assertSame(0, Message::where('conversation_id', $chat->id)->count());
    }
}
