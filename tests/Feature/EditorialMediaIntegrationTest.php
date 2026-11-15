<?php

namespace Tests\Feature;

use App\Enums\AttachmentStatus;
use App\Enums\EditorialKind;
use App\Enums\EditorialStatus;
use App\Exceptions\AttachmentException;
use App\Models\Attachment;
use App\Models\ContentRevision;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\ContentLibrary\EditorialService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EditorialMediaIntegrationTest extends TestCase
{
    private array $users = [];

    private array $permissions = [];

    protected function setUp(): void
    {
        parent::setUp(); // Durable reservations must only exist in the actual testing DB.
        Storage::fake('attachments');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertIsolatedDisk();
        // Runs inside the HTTP request, before AttachmentService writes bytes.
        Event::listen('eloquent.creating: '.Attachment::class, fn () => $this->assertIsolatedDisk());
    }

    private function assertIsolatedDisk(): void
    {
        $root = str_replace('\\', '/', Storage::disk(config('attachments.disk'))->path(''));
        $this->assertStringStartsWith(str_replace('\\', '/', storage_path('framework/testing/disks/attachments')), $root);
        $this->assertStringNotContainsString('/storage/app/attachments', $root);
    }

    protected function tearDown(): void
    {
        try {
            Event::forget('eloquent.creating: '.Attachment::class);
            Event::forget('eloquent.creating: '.ContentRevision::class);
            if ($this->users !== []) {
                $files = Attachment::whereIn('user_id', $this->users)->get();
                foreach ($files as $file) {
                    Storage::disk('attachments')->delete($file->path);
                }
                DB::transaction(function () {
                    $articles = DB::table('articles')->whereIn('user_id', $this->users)->pluck('id');
                    $resources = DB::table('legal_resources')->whereIn('user_id', $this->users)->pluck('id');
                    $revisions = DB::table('content_revisions')->whereIn('article_id', $articles)->orWhereIn('legal_resource_id', $resources)->pluck('id');
                    DB::table('content_revision_attachments')->whereIn('content_revision_id', $revisions)->delete();
                    DB::table('content_revisions')->whereIn('id', $revisions)->delete();
                    DB::table('saved_items')->whereIn('article_id', $articles)->orWhereIn('legal_resource_id', $resources)->delete();
                    foreach (['article' => $articles, 'legal_resource' => $resources] as $prefix => $ids) {
                        foreach (['attachment', 'tag', 'specialty'] as $suffix) {
                            DB::table($prefix.'_'.$suffix)->whereIn($prefix.'_id', $ids)->delete();
                        }
                    }
                    DB::table('articles')->whereIn('id', $articles)->delete();
                    DB::table('legal_resources')->whereIn('id', $resources)->delete();
                    DB::table('attachments')->whereIn('user_id', $this->users)->delete();
                    DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $this->users)->delete();
                    DB::table('users')->whereIn('id', $this->users)->delete();
                    DB::table('permissions')->whereIn('id', $this->permissions)->delete();
                });
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } finally {
            parent::tearDown();
        }
    }

    private function writer(): User
    {
        $user = User::factory()->create()->refresh();
        $this->users[] = $user->id;
        foreach (['articles.create', 'articles.update.own', 'articles.publish.own', 'resources.create', 'resources.update.own', 'resources.publish.own'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if ($permission->wasRecentlyCreated) {
                $this->permissions[] = $permission->id;
            }
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function file(string $name = 'prueba-<script>.txt'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, 'Documento sintetico aislado para INTEGRACION-04.');
    }

    public static function kinds(): array
    {
        return [['articulos', EditorialKind::Article], ['recursos', EditorialKind::Resource]];
    }

    #[DataProvider('kinds')]
    public function test_http_upload_and_current_context_download_are_private_and_expose_only_safe_metadata(string $path, EditorialKind $kind): void
    {
        $owner = $this->writer();
        $this->actingAs($owner);
        $payload = ['title' => 'Archivo HTTP', 'intent' => 'publish', 'uploads' => [$this->file()]]
            + ($kind === EditorialKind::Article ? ['body' => 'Texto de testing'] : ['description' => 'Documento de testing', 'resource_type' => 'document']);
        $id = $this->postJson('/'.$path, $payload)->assertCreated()->json('id');
        $file = Attachment::where('user_id', $owner->id)->sole();
        $this->assertSame(AttachmentStatus::Available, $file->status);
        $this->assertSame('private', $file->visibility);
        $this->assertNotNull($file->editorial_published_at);
        auth()->logout();
        $this->get('/'.$path.'/'.$id)->assertOk()->assertSee('prueba-&lt;script&gt;.txt', false)->assertDontSee($file->path)->assertDontSee($file->sha256)->assertDontSee($file->storage_name)->assertDontSee('<iframe', false);
        $response = $this->get('/'.$path.'/'.$id.'/archivos/'.$file->id)->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('attachments')->get($file->path), $response->streamedContent());
        app(EditorialService::class)->transition($kind, $owner, $id, EditorialStatus::Draft, 2);
        $this->get('/'.$path.'/'.$id.'/archivos/'.$file->id)->assertNotFound();
        $this->actingAs($owner)->get('/'.$path.'/'.$id.'/archivos/'.$file->id)->assertOk();
        $this->actingAs($this->writer())->get('/'.$path.'/'.$id.'/archivos/'.$file->id)->assertNotFound();
        $this->get('/'.$path.'/999999999/archivos/'.$file->id)->assertNotFound();
    }

    public function test_removed_current_files_stay_retained_in_revisions_without_historical_downloads(): void
    {
        $owner = $this->writer();
        $this->actingAs($owner);
        $id = $this->postJson('/articulos', ['title' => 'Retención', 'body' => 'Texto', 'intent' => 'publish', 'uploads' => [$this->file()]])->assertCreated()->json('id');
        $file = Attachment::where('user_id', $owner->id)->sole();
        $this->putJson('/articulos/'.$id, ['title' => 'Sin archivo actual', 'body' => 'Texto', 'intent' => 'save', 'version' => 2])->assertOk();
        $this->get('/articulos/'.$id.'/archivos/'.$file->id)->assertNotFound();
        $this->get('/articulos/'.$id.'/historial')->assertOk()->assertSee('1 archivos retenidos.')->assertDontSee('/archivos/'.$file->id);
        $this->assertSame(2, DB::table('content_revision_attachments')->where('attachment_id', $file->id)->count());
        try {
            app(AttachmentService::class)->requestDeletion($owner, $file->id);
            $this->fail('Historical references must block deletion.');
        } catch (AttachmentException) {
            $this->assertSame(AttachmentStatus::Available, $file->fresh()->status);
            Storage::disk('attachments')->assertExists($file->path);
        }
    }

    public function test_failed_publication_compensates_only_new_files_and_rolls_back_content_and_revisions(): void
    {
        $owner = $this->writer();
        $existing = app(AttachmentService::class)->store($owner, $this->file('conservar.txt'));
        $this->actingAs($owner)->postJson('/articulos', ['title' => 'Falla publicar', 'intent' => 'publish', 'uploads' => [$this->file()]])->assertUnprocessable();
        $this->assertSame(0, DB::table('articles')->where('user_id', $owner->id)->count());
        $this->assertSame(0, DB::table('content_revisions')->count());
        $new = Attachment::where('user_id', $owner->id)->where('id', '!=', $existing->id)->sole();
        $this->assertSame(AttachmentStatus::PendingDeletion, $new->status);
        $this->assertSame(AttachmentStatus::Available, $existing->fresh()->status);
        Storage::disk('attachments')->assertExists($existing->path);
    }

    public function test_revision_failure_after_upload_returns_sanitized_503_and_compensates_reservation(): void
    {
        $owner = $this->writer();
        Event::listen('eloquent.creating: '.ContentRevision::class, fn () => throw new QueryException('mysql', 'INSERT secret_payload', [], new \PDOException('secret binding')));
        $this->actingAs($owner)->postJson('/articulos', ['title' => 'Falla revisión', 'body' => 'Texto', 'intent' => 'publish', 'uploads' => [$this->file()]])->assertStatus(503)->assertDontSee('secret_payload')->assertDontSee('secret binding');
        $this->assertSame(0, DB::table('articles')->where('user_id', $owner->id)->count());
        $this->assertSame(AttachmentStatus::PendingDeletion, Attachment::where('user_id', $owner->id)->sole()->status);
    }

    public function test_partial_upload_failure_marks_only_the_completed_new_upload_for_deletion(): void
    {
        $owner = $this->writer();
        $real = app(AttachmentService::class);
        $mock = Mockery::mock(AttachmentService::class);
        $count = 0;
        $mock->shouldReceive('store')->twice()->andReturnUsing(function ($actor, $file) use ($real, &$count) {
            if (++$count === 2) {
                throw new AttachmentException;
            }

            return $real->store($actor, $file);
        });
        $mock->shouldReceive('requestDeletion')->once()->andReturnUsing(fn ($actor, $id) => $real->requestDeletion($actor, $id));
        $this->app->instance(AttachmentService::class, $mock);
        $this->actingAs($owner)->postJson('/articulos', ['title' => 'Dos cargas', 'intent' => 'save', 'uploads' => [$this->file('primero.txt'), $this->file('segundo.txt')]])->assertStatus(503);
        $this->assertSame(AttachmentStatus::PendingDeletion, Attachment::where('user_id', $owner->id)->sole()->status);
        $this->assertSame(0, DB::table('articles')->where('user_id', $owner->id)->count());
    }

    public function test_stale_or_unauthorized_uploads_are_rejected_before_any_physical_reservation(): void
    {
        $owner = $this->writer();
        $entry = app(EditorialService::class)->create(EditorialKind::Article, $owner, ['title' => 'Versión vigente']);
        app(EditorialService::class)->update(EditorialKind::Article, $owner, $entry->id, ['title' => 'Concurrente'], 1);
        $this->actingAs($owner)->putJson('/articulos/'.$entry->id, ['title' => 'No', 'version' => 1, 'intent' => 'save', 'uploads' => [$this->file()]])->assertConflict();
        $other = $this->writer();
        $this->actingAs($other)->putJson('/articulos/'.$entry->id, ['title' => 'No', 'version' => 2, 'intent' => 'save', 'uploads' => [$this->file()]])->assertForbidden();
        $this->assertSame(0, Attachment::whereIn('user_id', $this->users)->count());
    }

    public function test_foreign_or_retained_files_cannot_be_associated_and_context_hash_mismatches_are_safe(): void
    {
        $owner = $this->writer();
        $other = $this->writer();
        $foreign = app(AttachmentService::class)->store($other, $this->file());
        $retained = app(AttachmentService::class)->store($owner, $this->file('evidencia.txt'));
        $retained->forceFill(['verification_retained_at' => now()])->save();
        $this->actingAs($owner);
        foreach ([$foreign, $retained] as $file) {
            $this->postJson('/articulos', ['title' => 'No asociar', 'intent' => 'save', 'attachment_ids' => [$file->id]])->assertUnprocessable();
        }
        $id = $this->postJson('/articulos', ['title' => 'Integridad', 'body' => 'Texto', 'intent' => 'publish', 'uploads' => [$this->file('integridad.txt')]])->assertCreated()->json('id');
        $file = Attachment::where('user_id', $owner->id)->whereNull('verification_retained_at')->sole();
        Storage::disk('attachments')->put($file->path, 'alterado');
        $this->get('/articulos/'.$id.'/archivos/'.$file->id)->assertStatus(503)->assertDontSee($file->path)->assertDontSee($file->sha256);
        $this->assertSame(AttachmentStatus::Available, $foreign->fresh()->status);
    }

    public function test_bad_formats_mime_mismatches_and_category_sizes_leave_no_records(): void
    {
        $owner = $this->writer();
        $this->actingAs($owner);
        foreach (['malo.svg' => '<svg></svg>', 'falso.png' => 'Texto plano', 'office.docx' => 'PK document'] as $name => $content) {
            $this->postJson('/articulos', ['title' => 'No', 'intent' => 'save', 'uploads' => [UploadedFile::fake()->createWithContent($name, $content)]])->assertUnprocessable();
        }
        config(['attachments.max_bytes.document' => 10]);
        $this->postJson('/articulos', ['title' => 'Grande', 'intent' => 'save', 'uploads' => [$this->file()]])->assertUnprocessable();
        $this->assertSame(0, Attachment::where('user_id', $owner->id)->count());
    }

    public function test_manager_cannot_attach_their_own_uploads_to_another_authors_content(): void
    {
        $owner = $this->writer();
        $entry = app(EditorialService::class)->create(EditorialKind::Article, $owner, ['title' => 'Autor original']);
        $manager = $this->writer();
        $permission = Permission::firstOrCreate(['name' => 'articles.manage', 'guard_name' => 'web']);
        if ($permission->wasRecentlyCreated) {
            $this->permissions[] = $permission->id;
        }
        $manager->givePermissionTo($permission);
        $this->actingAs($manager)->get('/articulos/'.$entry->id.'/editar')->assertOk()->assertDontSee('data-editorial-files', false);
        $this->putJson('/articulos/'.$entry->id, ['title' => 'No adjuntar', 'version' => 1, 'intent' => 'save', 'uploads' => [$this->file()]])->assertUnprocessable();
        $this->assertSame(0, Attachment::where('user_id', $manager->id)->count());
        $this->assertSame('Autor original', $entry->fresh()->title);
    }
}
