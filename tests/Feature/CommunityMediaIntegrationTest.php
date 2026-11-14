<?php

namespace Tests\Feature;

use App\Enums\AttachmentStatus;
use App\Exceptions\AttachmentException;
use App\Models\Attachment;
use App\Models\Post;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Community\PostService;
use App\Services\Profiles\ProfileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CommunityMediaIntegrationTest extends TestCase
{
    private array $userIds = [];

    private array $permissionIds = [];

    protected function setUp(): void
    {
        parent::setUp(); // Stop before durable upload fixtures unless isolated in testing.
        Storage::fake('attachments');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->userIds !== []) {
                DB::transaction(function () {
                    $posts = DB::table('posts')->whereIn('user_id', $this->userIds)->pluck('id');
                    foreach (DB::table('comments')->whereIn('post_id', $posts)->orderByDesc('id')->pluck('id') as $id) {
                        DB::table('comments')->where('id', $id)->delete();
                    }
                    DB::table('posts')->whereIn('id', $posts)->delete();
                    DB::table('user_profiles')->whereIn('user_id', $this->userIds)->delete();
                    DB::table('attachments')->whereIn('user_id', $this->userIds)->delete();
                    DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $this->userIds)->delete();
                    DB::table('users')->whereIn('id', $this->userIds)->delete();
                    DB::table('permissions')->whereIn('id', $this->permissionIds)->delete();
                });
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } finally {
            parent::tearDown();
        }
    }

    private function member(bool $permission = true): User
    {
        $user = User::factory()->create()->refresh();
        $this->userIds[] = $user->id;
        if ($permission) {
            foreach (['posts.create', 'posts.update.own', 'posts.delete.own'] as $name) {
                $record = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                if ($record->wasRecentlyCreated) {
                    $this->permissionIds[] = $record->id;
                }
                $user->givePermissionTo($record);
            }
        }

        return $user;
    }

    private function image(string $name = 'foto.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aO2kAAAAASUVORK5CYII='));
    }

    private function upload(UploadedFile $file, string $visibility = 'public', string $intent = 'publish')
    {
        return $this->postJson('/comunidad/publicaciones', ['content' => 'Texto con archivo', 'visibility' => $visibility, 'intent' => $intent, 'uploads' => [$file]]);
    }

    public function test_private_upload_association_safe_metadata_and_context_download_work(): void
    {
        $owner = $this->member();
        $this->actingAs($owner);
        $response = $this->upload($this->image('imagen-<script>.png'))->assertCreated();
        $id = $response->json('id');
        $file = Attachment::where('user_id', $owner->id)->firstOrFail();
        $this->assertSame('private', $file->visibility);
        $this->assertSame(AttachmentStatus::Available, $file->status);
        $this->assertNotNull($file->community_published_at);
        $this->get('/comunidad/publicaciones/'.$id)->assertOk()->assertSee('imagen-&lt;script&gt;.png', false)->assertDontSee($file->path)->assertDontSee($file->storage_name)->assertDontSee($file->sha256);
        auth()->logout();
        $response = $this->get('/comunidad/publicaciones/'.$id.'/archivos/'.$file->id)->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('attachments')->get($file->path), $response->streamedContent());
    }

    public function test_public_photo_and_attachment_policies_are_not_weakened_for_context_downloads(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $this->actingAs($owner);
        $id = $this->upload($this->image(), 'private')->assertCreated()->json('id');
        $file = Attachment::where('user_id', $owner->id)->firstOrFail();
        app(ProfileService::class)->saveUserProfile($owner, $owner->id, ['photo_attachment_id' => $file->id]);
        $this->actingAs($other)->get('/comunidad/publicaciones/'.$id.'/archivos/'.$file->id)->assertNotFound();
        $this->get('/perfil/fotografia?attachment_id='.$file->id)->assertNotFound();
        $post = app(PostService::class)->create($owner, ['content' => 'Otro post', 'visibility' => 'public']);
        app(PostService::class)->publish($owner, $post->id, $post->version);
        $this->get('/comunidad/publicaciones/'.$post->id.'/archivos/'.$file->id)->assertNotFound();
        $this->actingAs($owner)->get('/comunidad/publicaciones/'.$id.'/archivos/'.$file->id)->assertOk();
    }

    public static function invalidUploads(): array
    {
        return [['archivo.docx', 'PK office'], ['archivo.svg', '<svg></svg>'], ['archivo.png', 'texto'], ['archivo.mp3', 'audio'], ['archivo.jpg', 'png']];
    }

    #[DataProvider('invalidUploads')]
    public function test_invalid_file_formats_and_mismatched_extensions_do_not_leave_records(string $name, string $content): void
    {
        $owner = $this->member();
        $this->actingAs($owner);
        $file = $content === 'png' ? $this->image($name) : UploadedFile::fake()->createWithContent($name, $content);
        $this->upload($file)->assertUnprocessable();
        $this->assertSame(0, Post::where('user_id', $owner->id)->count());
        $this->assertSame(0, Attachment::where('user_id', $owner->id)->count());
    }

    public function test_count_and_size_limits_are_checked_before_publishing(): void
    {
        $owner = $this->member();
        $this->actingAs($owner);
        $this->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'publish', 'uploads' => array_map(fn () => $this->image(), range(1, 5))])->assertUnprocessable();
        config(['attachments.max_bytes.image' => 10]);
        $this->upload($this->image())->assertUnprocessable();
        $this->assertSame(0, Attachment::where('user_id', $owner->id)->count());
    }

    public function test_files_only_can_be_published_and_pdf_txt_are_supported(): void
    {
        $owner = $this->member();
        $this->actingAs($owner);
        $this->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'publish', 'uploads' => [$this->image(), UploadedFile::fake()->createWithContent('consulta.txt', 'Texto legal de prueba'), UploadedFile::fake()->createWithContent('consulta.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")]])->assertCreated();
        $this->assertSame(3, Attachment::where('user_id', $owner->id)->count());
        $this->assertNull(Post::where('user_id', $owner->id)->firstOrFail()->content);
    }

    public function test_failed_association_compensates_only_new_uploads_and_rolls_back_the_post(): void
    {
        $owner = $this->member();
        $existing = app(AttachmentService::class)->store($owner, $this->image());
        $this->actingAs($owner)->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'publish', 'tag_ids' => [999999999], 'attachment_ids' => [$existing->id], 'uploads' => [$this->image()]])->assertUnprocessable();
        $this->assertSame(0, Post::where('user_id', $owner->id)->count());
        $this->assertSame(AttachmentStatus::Available, $existing->fresh()->status);
        $this->assertSame(AttachmentStatus::PendingDeletion, Attachment::where('user_id', $owner->id)->where('id', '!=', $existing->id)->firstOrFail()->status);
    }

    public function test_second_upload_failure_compensates_the_first_without_creating_a_post(): void
    {
        $owner = $this->member();
        $actual = app(AttachmentService::class);
        $calls = 0;
        $mock = Mockery::mock($actual)->makePartial();
        $mock->shouldReceive('store')->twice()->andReturnUsing(function ($actor, $file) use ($actual, &$calls) {
            if (++$calls === 2) {
                throw new AttachmentException;
            }

            return $actual->store($actor, $file);
        });
        $this->app->instance(AttachmentService::class, $mock);
        $this->actingAs($owner)->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'publish', 'uploads' => [$this->image(), $this->image()]])->assertStatus(503);
        $this->assertSame(0, Post::where('user_id', $owner->id)->count());
        $this->assertSame(AttachmentStatus::PendingDeletion, Attachment::where('user_id', $owner->id)->firstOrFail()->status);
    }

    public function test_stale_edit_upload_is_compensated_without_overwriting_the_current_content(): void
    {
        $owner = $this->member();
        $this->actingAs($owner);
        $id = $this->upload($this->image())->assertCreated()->json('id');
        $old = Attachment::where('user_id', $owner->id)->firstOrFail();
        app(PostService::class)->update($owner, $id, ['content' => 'Edición concurrente'], 2);
        $this->putJson('/comunidad/publicaciones/'.$id, ['visibility' => 'public', 'intent' => 'save', 'content' => 'No guardar', 'version' => 2, 'uploads' => [$this->image()]])->assertConflict();
        $this->assertSame('Edición concurrente', Post::findOrFail($id)->content);
        $this->assertSame([$old->id], Post::findOrFail($id)->attachments->modelKeys());
        $this->assertSame(AttachmentStatus::Available, $old->fresh()->status);
        $this->assertSame(AttachmentStatus::PendingDeletion, Attachment::where('user_id', $owner->id)->where('id', '!=', $old->id)->firstOrFail()->status);
    }

    public function test_unlink_and_logical_delete_preserve_files_and_context_access_is_revoked(): void
    {
        $owner = $this->member();
        $this->actingAs($owner);
        $id = $this->upload($this->image())->assertCreated()->json('id');
        $file = Attachment::where('user_id', $owner->id)->firstOrFail();
        $this->putJson('/comunidad/publicaciones/'.$id, ['visibility' => 'public', 'intent' => 'save', 'content' => 'Sin archivo', 'version' => 2, 'replace_attachments' => true])->assertOk();
        $this->get('/comunidad/publicaciones/'.$id.'/archivos/'.$file->id)->assertNotFound();
        $this->assertSame(AttachmentStatus::Available, $file->fresh()->status);
        Storage::disk('attachments')->assertExists($file->path);
        $this->deleteJson('/comunidad/publicaciones/'.$id, ['version' => 3])->assertOk();
        $this->assertNotNull($file->fresh()->community_published_at);
    }

    public function test_foreign_and_retained_files_cannot_be_associated(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $file = app(AttachmentService::class)->store($owner, $this->image());
        $this->actingAs($other)->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'publish', 'attachment_ids' => [$file->id]])->assertUnprocessable();
        $file->forceFill(['verification_retained_at' => now()])->save();
        $this->actingAs($owner)->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'publish', 'attachment_ids' => [$file->id]])->assertUnprocessable();
        $this->assertSame(0, Post::whereIn('user_id', [$owner->id, $other->id])->count());
    }

    public function test_authorization_happens_before_uploading_and_storage_failures_are_safe(): void
    {
        $unprivileged = $this->member(false);
        $this->actingAs($unprivileged);
        $this->upload($this->image())->assertForbidden();
        $this->assertSame(0, Attachment::where('user_id', $unprivileged->id)->count());
        $owner = $this->member();
        $this->actingAs($owner);
        $id = $this->upload($this->image())->assertCreated()->json('id');
        $file = Attachment::where('user_id',$owner->id)->firstOrFail();
        Storage::disk('attachments')->put($file->path,'Objeto alterado');
        config(['app.debug' => true]);
        $this->get('/comunidad/publicaciones/'.$id.'/archivos/'.$file->id)->assertStatus(503)->assertDontSee($file->path)->assertDontSee('Stack trace');
    }
}
