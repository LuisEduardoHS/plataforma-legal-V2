<?php

namespace Tests\Feature;

use App\Enums\AttachmentStatus;
use App\Enums\EditorialKind as Kind;
use App\Enums\EditorialStatus as Status;
use App\Enums\SavedContentType as SavedType;
use App\Enums\VerificationEvidenceType;
use App\Exceptions\AttachmentException;
use App\Exceptions\ContentLibraryException;
use App\Models\Article;
use App\Models\ContentCategory;
use App\Models\ContentRevision;
use App\Models\EditorialEntry;
use App\Models\LegalResource;
use App\Models\LegalSpecialty;
use App\Models\SavedItem;
use App\Models\Tag;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Community\PostService;
use App\Services\ContentLibrary\EditorialMediaService;
use App\Services\ContentLibrary\EditorialQuery;
use App\Services\ContentLibrary\EditorialService;
use App\Services\ContentLibrary\SavedItemService;
use App\Services\ContentLibrary\SavedLibraryQuery;
use App\Services\Profiles\ProfileService;
use App\Services\Verification\VerificationService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EditorialPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

class ContentLibraryTest extends TestCase
{
    use VerificationFixtures;

    private bool $started = false;

    private EditorialService $editorial;

    private EditorialQuery $query;

    private SavedItemService $saved;

    private SavedLibraryQuery $library;

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
        $this->seed([RolePermissionSeeder::class, EditorialPermissionSeeder::class]);
        $this->editorial = app(EditorialService::class);
        $this->query = app(EditorialQuery::class);
        $this->saved = app(SavedItemService::class);
        $this->library = app(SavedLibraryQuery::class);
    }

    protected function tearDown(): void
    {
        try {
            Event::forget('eloquent.creating: '.ContentRevision::class);
            DB::disableQueryLog();
            if ($this->started) {
                DB::rollBack();
                Storage::disk('attachments')->delete(Storage::disk('attachments')->allFiles());
            }
        } finally {
            parent::tearDown();
        }
    }

    private function writer(): User
    {
        $user = User::factory()->create()->refresh();
        $user->givePermissionTo(['articles.create', 'articles.update.own', 'articles.publish.own', 'resources.create', 'resources.update.own', 'resources.publish.own']);

        return $user;
    }

    private function manager(): User
    {
        $user = User::factory()->create()->refresh();
        $user->givePermissionTo(['articles.manage', 'resources.manage']);

        return $user;
    }

    private function entry(User $owner, Kind $kind = Kind::Article, array $data = [], bool $publish = true): EditorialEntry
    {
        $defaults = $kind === Kind::Article ? ['title' => 'Derechos laborales', 'body' => 'Información jurídica de prueba.']
            : ['title' => 'Material de consulta', 'description' => 'Descripción educativa', 'resource_type' => 'guide', 'external_url' => 'https://www.gob.mx/'];
        $entry = $this->editorial->create($kind, $owner, $data + $defaults);

        return $publish ? $this->editorial->transition($kind, $owner, $entry->id, Status::Published, 1) : $entry;
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

    public function test_draft_edit_publish_and_revision_history_preserve_prior_text_and_slug(): void
    {
        $owner = $this->writer();
        $article = $this->editorial->create(Kind::Article, $owner, ['title' => '  Derechos en México  ']);
        $this->assertSame(Status::Draft, $article->status);
        $this->assertNull($article->published_at);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $article->slug);
        $this->assertSame('Derechos en México', $article->title);
        $this->rejects(ValidationException::class, fn () => $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Published, 1));
        $article = $this->editorial->update(Kind::Article, $owner, $article->id, ['body' => '<script>texto sin interpretar</script>'], 1);
        $article = $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Published, 2);
        $slug = $article->slug;
        $article = $this->editorial->update(Kind::Article, $owner, $article->id, ['title' => 'Título actualizado', 'body' => 'Texto nuevo'], 3);
        $this->assertSame($slug, $article->slug);
        $this->assertSame(4, $article->version);
        $this->assertNotNull($article->editorial_updated_at);
        $history = $this->query->revisions(Kind::Article, $owner, $article->id)->items();
        $this->assertCount(4, $history);
        $this->assertSame('Texto nuevo', $history[0]['snapshot']['body']);
        $this->assertSame('<script>texto sin interpretar</script>', $history[1]['snapshot']['body']);
        $this->assertNull($history[3]['snapshot']['body']);
        $this->assertTrue($owner->articles()->first()->is($article));
        $this->assertTrue($article->revisions()->first()->article->is($article));
    }

    public function test_permissions_are_explicit_and_professional_or_admin_roles_do_not_grant_editorial_access(): void
    {
        $admin = User::factory()->create()->refresh();
        $admin->assignRole('admin');
        [$professional] = $this->professional();
        foreach ([$admin, $professional] as $user) {
            foreach (Kind::cases() as $kind) {
                $this->rejects(AuthorizationException::class, fn () => $this->editorial->create($kind, $user, ['title' => 'No']));
            }
        }
        $owner = $this->writer();
        $article = $this->entry($owner, publish: false);
        $stranger = $this->writer();
        $this->rejects(AuthorizationException::class, fn () => $this->editorial->update(Kind::Article, $stranger, $article->id, ['title' => 'Ajeno'], 1));
        $this->rejects(AuthorizationException::class, fn () => $this->query->detail(Kind::Article, $article->id, $stranger));
        $this->rejects(AuthorizationException::class, fn () => $this->query->detail(Kind::Article, $article->id));
        $this->assertSame($article->id, $this->query->detail(Kind::Article, $article->id, $owner)['id']);
        $owner->revokePermissionTo('articles.publish.own');
        $this->rejects(AuthorizationException::class, fn () => $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Published, 1));
        $this->assertSame(Status::Published, $this->editorial->transition(Kind::Article, $this->manager(), $article->id, Status::Published, 1)->status);
    }

    public function test_hidden_articles_cannot_bypass_moderation_and_deleted_content_retains_revisions(): void
    {
        $owner = $this->writer();
        $manager = $this->manager();
        $article = $this->entry($owner);
        $article = $this->editorial->transition(Kind::Article, $manager, $article->id, Status::Hidden, 2);
        $article = $this->editorial->update(Kind::Article, $owner, $article->id, ['body' => 'Corrección'], 3);
        $this->assertSame(Status::Hidden, $article->status);
        $this->rejects(AuthorizationException::class, fn () => $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Published, 4));
        foreach ([Status::Draft, Status::Archived] as $target) {
            $this->rejects(ValidationException::class, fn () => $this->editorial->transition(Kind::Article, $owner, $article->id, $target, 4));
        }
        $article = $this->editorial->transition(Kind::Article, $manager, $article->id, Status::Published, 4);
        $article = $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Archived, 5);
        $this->assertSame([], $this->query->recent(Kind::Article)->items());
        $article = $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Draft, 6);
        $article = $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Deleted, 7);
        $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Deleted, 7);
        $this->assertNull(Article::find($article->id));
        $this->assertCount(8, $this->query->revisions(Kind::Article, $owner, $article->id)->items());
        $this->rejects(AuthorizationException::class, fn () => $this->query->revisions(Kind::Article, User::factory()->create()->refresh(), $article->id));
        $this->rejects(AuthorizationException::class, fn () => $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Draft, 8));
    }

    public function test_slugs_are_unique_stable_reserved_after_deletion_and_safe_for_nonlatin_titles(): void
    {
        $owner = $this->writer();
        $article = $this->entry($owner, data: ['slug' => 'consulta-laboral']);
        $this->rejects(ValidationException::class, fn () => $this->entry($owner, data: ['slug' => 'consulta-laboral']));
        $this->rejects(ValidationException::class, fn () => $this->editorial->update(Kind::Article, $owner, $article->id, ['slug' => 'otro'], 2));
        $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Deleted, 2);
        $this->rejects(ValidationException::class, fn () => $this->entry($owner, data: ['slug' => 'consulta-laboral']));
        $nonlatin = $this->entry($owner, data: ['title' => '⚖️⚖️']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $nonlatin->slug);
        $this->assertNotSame($nonlatin->slug, $this->entry($owner, data: ['title' => '⚖️⚖️'])->slug);
    }

    public function test_input_is_bounded_guarded_and_cannot_escalate_privileges_or_assign_status(): void
    {
        $owner = $this->writer();
        foreach ([['user_id' => $owner->id], ['status' => 'published'], ['version' => 10], ['is_official' => true], ['roles' => ['admin']], ['title' => ' '], ['title' => str_repeat('a', 201)], ['body' => "a\0b"], ['body' => str_repeat('á', 100001)], ['slug' => '../unsafe'], ['slug' => 'Upper'], ['tag_ids' => [1, 1]]] as $data) {
            $this->rejects(ValidationException::class, fn () => $this->editorial->create(Kind::Article, $owner, $data + ['title' => 'Prueba']));
        }
        foreach ([Article::class, LegalResource::class, ContentRevision::class, SavedItem::class] as $model) {
            $this->rejects(MassAssignmentException::class, fn () => $model::create(['user_id' => $owner->id]));
        }
        $this->assertSame(0, Article::count());
        $this->assertSame([], $owner->getRoleNames()->all());
    }

    public function test_existing_catalogs_have_active_new_associations_and_preserved_historical_classifications(): void
    {
        $owner = $this->writer();
        $category = ContentCategory::create(['name' => 'Guías', 'slug' => (string) Str::uuid()]);
        $tag = Tag::create(['name' => 'Contrato', 'slug' => (string) Str::uuid()]);
        $specialty = LegalSpecialty::create(['name' => 'Civil', 'slug' => (string) Str::uuid()]);
        $data = ['content_category_id' => $category->id, 'tag_ids' => [$tag->id], 'specialty_ids' => [$specialty->id]];
        foreach (Kind::cases() as $kind) {
            foreach ([$category, $tag, $specialty] as $item) {
                $item->update(['is_active' => true]);
            }
            $entry = $this->entry($owner, $kind, $data);
            foreach ([$category, $tag, $specialty] as $item) {
                $item->update(['is_active' => false]);
            }
            $entry = $this->editorial->update($kind, $owner, $entry->id, $data, 2);
            $this->assertSame([$tag->id], $entry->tags->modelKeys());
            $this->assertSame([$specialty->id], $entry->specialties->modelKeys());
            foreach (['category_id' => $category->id, 'tag_id' => $tag->id, 'specialty_id' => $specialty->id] as $filter => $id) {
                $this->assertSame([$entry->id], array_column($this->query->recent($kind, filters: [$filter => $id])->items(), 'id'));
            }
            foreach ($data as $field => $value) {
                $this->rejects(ValidationException::class, fn () => $this->entry($owner, $kind, [$field => $value]));
            }
            $entry = $this->editorial->update($kind, $owner, $entry->id, ['content_category_id' => null, 'tag_ids' => [], 'specialty_ids' => []], 3);
            $this->assertNull($entry->category);
            $this->assertCount(0, $entry->tags);
            $history = $this->query->revisions($kind, $owner, $entry->id)->items();
            $this->assertSame('Guías', $history[1]['snapshot']['category']['name']);
        }
    }

    public function test_resources_support_external_links_documents_and_configured_institutional_domains(): void
    {
        $owner = $this->writer();
        $resource = $this->entry($owner, Kind::Resource, ['resource_type' => 'institutional_link', 'external_url' => 'https://www.scjn.gob.mx/']);
        $this->assertSame(0, $resource->attachments()->count());
        $this->assertTrue($owner->legalResources()->first()->is($resource));
        $this->assertSame('Recurso aportado por usuario', $this->query->detail(Kind::Resource, $resource->id)['source_label']);
        $resource = $this->editorial->update(Kind::Resource, $owner, $resource->id, ['title' => 'Enlace actualizado'], 2);
        $this->assertSame('Enlace actualizado', $resource->title);
        foreach (['javascript:alert(1)', 'data:text/html,x', 'file:///tmp/test', 'http://www.gob.mx/', 'https://user:pass@www.gob.mx/', 'https://127.0.0.1/', 'https://localhost/', 'https://www.gob.mx.evil.com/', 'https://example.test/', 'https://www.gob.mx:8080/'] as $url) {
            $this->rejects(ValidationException::class, fn () => $this->entry($owner, Kind::Resource, ['resource_type' => 'institutional_link', 'external_url' => $url]));
        }
        $this->rejects(ValidationException::class, fn () => $this->entry($owner, Kind::Resource, ['resource_type' => 'document']));
        $file = $this->evidenceFile($owner);
        $document = $this->entry($owner, Kind::Resource, ['resource_type' => 'template', 'external_url' => null, 'attachment_ids' => [$file->id]]);
        $this->assertSame('template', $document->resource_type->value);
        $this->assertSame([$document->id], array_column($this->query->recent(Kind::Resource, filters: ['resource_type' => 'template'])->items(), 'id'));
    }

    public function test_public_queries_and_private_workspaces_respect_account_and_editorial_states(): void
    {
        $owner = $this->writer();
        $manager = $this->manager();
        $published = $this->entry($owner);
        $draft = $this->entry($owner, publish: false);
        $hidden = $this->entry($owner);
        $this->editorial->transition(Kind::Article, $manager, $hidden->id, Status::Hidden, 2);
        $this->assertSame([$published->id], array_column($this->query->recent(Kind::Article, filters: ['author_id' => $owner->id, 'title' => 'Derechos'])->items(), 'id'));
        $this->assertCount(3, $this->query->workspace(Kind::Article, $owner)->items());
        $this->assertCount(1, $this->query->workspace(Kind::Article, $owner, status: Status::Draft)->items());
        $this->assertCount(3, $this->query->workspace(Kind::Article, $manager, all: true)->items());
        $this->rejects(AuthorizationException::class, fn () => $this->query->workspace(Kind::Article, $owner, all: true));
        $this->rejects(AuthorizationException::class, fn () => $this->query->detail(Kind::Article, $draft->id));
        $this->assertSame([], $this->query->recent(Kind::Article, filters: ['title' => '%'])->items());
        $owner->forceFill(['status' => 'suspended'])->save();
        $this->assertSame([], $this->query->recent(Kind::Article)->items());
        $this->assertSame($hidden->id, $this->query->detail(Kind::Article, $hidden->id, $manager)['id']);
    }

    public function test_persisted_inactive_actors_cannot_write_or_access_their_library(): void
    {
        $owner = $this->writer();
        $entry = $this->entry($owner);
        foreach (['suspended', 'disabled', 'pending_deletion'] as $status) {
            DB::table('users')->where('id', $owner->id)->update(['status' => $status]);
            foreach ([fn () => $this->entry($owner), fn () => $this->editorial->update(Kind::Article, $owner, $entry->id, ['body' => 'No'], 2), fn () => $this->editorial->transition(Kind::Article, $owner, $entry->id, Status::Deleted, 2), fn () => $this->saved->save($owner, SavedType::Article, $entry->id), fn () => $this->saved->remove($owner, SavedType::Article, $entry->id), fn () => $this->library->library($owner), fn () => $this->query->workspace(Kind::Article, $owner)] as $operation) {
                $this->rejects(AuthorizationException::class, $operation);
            }
        }
    }

    public function test_private_attachments_are_owner_checked_ordered_and_authorized_by_live_content(): void
    {
        $owner = $this->writer();
        $stranger = $this->writer();
        $first = $this->evidenceFile($owner);
        $second = $this->evidenceFile($owner);
        $draft = $this->entry($owner, data: ['attachment_ids' => [$second->id, $first->id]], publish: false);
        $media = app(EditorialMediaService::class);
        $this->rejects(AuthorizationException::class, fn () => $media->download(Kind::Article, null, $draft->id, $first->id));
        $this->rejects(AuthorizationException::class, fn () => $media->download(Kind::Article, $stranger, $draft->id, $first->id));
        $response = $media->download(Kind::Article, $owner, $draft->id, $first->id);
        ob_start();
        $response->sendContent();
        $bytes = ob_get_clean();
        $this->assertSame(Storage::disk('attachments')->get($first->path), $bytes);
        $published = $this->editorial->transition(Kind::Article, $owner, $draft->id, Status::Published, 1);
        $this->assertNotNull($first->fresh()->editorial_published_at);
        $this->assertSame([$second->id, $first->id], array_column($this->query->detail(Kind::Article, $published->id)['attachments'], 'id'));
        $response = $media->download(Kind::Article, null, $published->id, $first->id);
        ob_start();
        $response->sendContent();
        ob_end_clean();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->editorial->transition(Kind::Article, $owner, $published->id, Status::Draft, 2);
        $this->rejects(AuthorizationException::class, fn () => $media->download(Kind::Article, null, $published->id, $first->id));
        $this->rejects(AttachmentException::class, fn () => $first->fresh()->forceFill(['editorial_published_at' => null])->save());
        $this->rejects(AuthorizationException::class, fn () => app(AttachmentService::class)->download($stranger, $first->id));
    }

    public function test_invalid_files_and_limits_are_rejected_without_partial_writes(): void
    {
        $owner = $this->writer();
        $foreign = $this->evidenceFile($this->writer());
        $pending = $this->evidenceFile($owner, status: AttachmentStatus::PendingDeletion);
        $retained = $this->evidenceFile($owner);
        $retained->forceFill(['verification_retained_at' => now()])->save();
        foreach ([$foreign, $pending, $retained] as $file) {
            $this->rejects(ValidationException::class, fn () => $this->entry($owner, data: ['attachment_ids' => [$file->id]]));
        }
        $file = $this->evidenceFile($owner);
        $this->rejects(ValidationException::class, fn () => $this->entry($owner, data: ['attachment_ids' => [$file->id, $file->id]]));
        config(['content-library.max_attachments' => 0]);
        $this->rejects(ValidationException::class, fn () => $this->entry($owner, data: ['attachment_ids' => [$file->id]]));
        $this->assertSame(0, Article::count());
        $this->assertSame(0, ContentRevision::count());
    }

    public function test_revision_references_protect_files_even_after_removal_or_logical_deletion(): void
    {
        $owner = $this->writer();
        $file = $this->evidenceFile($owner);
        $article = $this->entry($owner, data: ['attachment_ids' => [$file->id]]);
        $article = $this->editorial->update(Kind::Article, $owner, $article->id, ['attachment_ids' => []], 2);
        $this->assertSame(0, $article->attachments()->count());
        $this->rejects(AttachmentException::class, fn () => app(AttachmentService::class)->requestDeletion($owner, $file->id));
        $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Deleted, 3);
        $this->rejects(AttachmentException::class, fn () => app(AttachmentService::class)->purge($owner, $file->id));
        Storage::disk('attachments')->assertExists($file->path);
        $history = $this->query->revisions(Kind::Article, $owner, $article->id)->items();
        $this->assertSame([$file->id], $history[2]['attachment_ids']);
        $revision = ContentRevision::first();
        $this->rejects(ContentLibraryException::class, fn () => $revision->forceFill(['snapshot' => []])->save());
        $this->rejects(ContentLibraryException::class, fn () => $revision->delete());
    }

    public function test_verification_and_editorial_files_are_exclusive_including_draft_revisions(): void
    {
        [$owner,$profile] = $this->professional();
        $owner->givePermissionTo(['articles.create', 'articles.update.own', 'articles.publish.own']);
        $workflow = app(VerificationService::class);
        $request = $workflow->createDraft($owner, $profile->id);
        $evidence = $this->evidenceFile($owner);
        $workflow->addEvidence($owner, $request->id, $evidence->id, VerificationEvidenceType::ProfessionalLicense);
        $this->rejects(ValidationException::class, fn () => $this->entry($owner, data: ['attachment_ids' => [$evidence->id]]));
        $file = $this->evidenceFile($owner);
        $article = $this->entry($owner, data: ['attachment_ids' => [$file->id]], publish: false);
        $this->editorial->update(Kind::Article, $owner, $article->id, ['attachment_ids' => []], 1);
        $this->rejects(ValidationException::class, fn () => $workflow->addEvidence($owner, $request->id, $file->id, VerificationEvidenceType::Certificate));
        $this->assertSame(1, $request->evidences()->count());
    }

    public function test_download_rechecks_association_integrity_and_retention_without_path_leaks(): void
    {
        $owner = $this->writer();
        $file = $this->evidenceFile($owner);
        $extra = $this->evidenceFile($owner);
        $resource = $this->entry($owner, Kind::Resource, ['attachment_ids' => [$file->id]]);
        $media = app(EditorialMediaService::class);
        $this->rejects(AuthorizationException::class, fn () => $media->download(Kind::Resource, null, $resource->id, $extra->id));
        $this->assertArrayNotHasKey('path', $this->query->detail(Kind::Resource, $resource->id)['attachments'][0]);
        Storage::disk('attachments')->put($file->path, 'Modificado');
        $this->rejects(ContentLibraryException::class, fn () => $media->download(Kind::Resource, null, $resource->id, $file->id));
        DB::table('attachments')->where('id', $file->id)->update(['verification_retained_at' => now()]);
        $this->rejects(ValidationException::class, fn () => $media->download(Kind::Resource, null, $resource->id, $file->id));
        $this->assertSame([], $this->query->detail(Kind::Resource, $resource->id)['attachments']);
    }

    public function test_all_three_saved_types_are_idempotent_and_libraries_are_personal(): void
    {
        $owner = $this->writer();
        $viewer = $this->writer();
        $other = $this->writer();
        $owner->assignRole('user');
        $post = app(PostService::class)->create($owner, ['content' => 'Consulta', 'visibility' => 'public']);
        $post = app(PostService::class)->publish($owner, $post->id, 1);
        $article = $this->entry($owner);
        $resource = $this->entry($owner, Kind::Resource);
        foreach ([[SavedType::Post, $post], [SavedType::Article, $article], [SavedType::Resource, $resource]] as [$type,$target]) {
            $saved = $this->saved->save($viewer, $type, $target->id);
            $this->assertSame($saved->id, $this->saved->save($viewer, $type, $target->id)->id);
            $this->assertSame($saved->created_at->toDateTimeString(), $this->saved->save($viewer, $type, $target->id)->created_at->toDateTimeString());
            $this->assertCount(1, $this->library->library($viewer, $type)->items());
        }
        $this->assertCount(3, $viewer->savedItems);
        $this->assertSame([], $this->library->library($other)->items());
        $this->saved->remove($other, SavedType::Article, $article->id);
        $this->assertCount(3, $this->library->library($viewer)->items());
        $this->saved->remove($viewer, SavedType::Article, $article->id);
        $this->saved->remove($viewer, SavedType::Article, $article->id);
        $this->assertCount(2, $this->library->library($viewer)->items());
        $this->assertTrue(SavedItem::whereNotNull('post_id')->first()->post->is($post));
    }

    public function test_saved_visibility_is_revoked_on_unpublish_delete_private_post_or_inactive_author(): void
    {
        $owner = $this->writer();
        $viewer = $this->writer();
        $owner->assignRole('user');
        $posts = app(PostService::class);
        $post = $posts->create($owner, ['content' => 'Consulta', 'visibility' => 'public']);
        $post = $posts->publish($owner, $post->id, 1);
        $article = $this->entry($owner);
        $resource = $this->entry($owner, Kind::Resource);
        foreach ([[SavedType::Post, $post], [SavedType::Article, $article], [SavedType::Resource, $resource]] as [$type,$target]) {
            $this->saved->save($viewer, $type, $target->id);
        }
        $posts->update($owner, $post->id, ['visibility' => 'private'], 2);
        $this->rejects(AuthorizationException::class, fn () => $this->saved->save($viewer, SavedType::Post, $post->id));
        $this->assertCount(2, $this->library->library($viewer)->items());
        $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Draft, 2);
        $this->assertCount(1, $this->library->library($viewer)->items());
        $this->editorial->transition(Kind::Resource, $owner, $resource->id, Status::Deleted, 2);
        $this->assertSame([], $this->library->library($viewer)->items());
        $this->assertSame(3, SavedItem::where('user_id', $viewer->id)->count());
        $this->saved->save($owner, SavedType::Post, $post->id);
        $this->assertCount(1, $this->library->library($owner)->items());
        $article = $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Published, 3);
        $this->assertCount(1, $this->library->library($viewer)->items());
        $owner->forceFill(['status' => 'disabled'])->save();
        $this->assertSame([], $this->library->library($viewer)->items());
        $this->saved->remove($viewer, SavedType::Article, $article->id);
        $this->assertSame(2, SavedItem::where('user_id', $viewer->id)->count());
    }

    public function test_cursors_are_stable_and_queries_have_no_n_plus_one(): void
    {
        $owner = $this->writer();
        $viewer = $this->writer();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $entry = $this->entry($owner);
            $ids[] = $entry->id;
            $this->saved->save($viewer, SavedType::Article, $entry->id);
        }
        DB::table('articles')->whereIn('id', $ids)->update(['published_at' => '2026-01-01 12:00:00']);
        $page = $this->query->recent(Kind::Article, size: 2);
        $seen = array_column($page->items(), 'id');
        while ($cursor = $page->nextCursor()) {
            $page = $this->query->recent(Kind::Article, size: 2, cursor: $cursor->encode());
            $seen = array_merge($seen, array_column($page->items(), 'id'));
        }
        $this->assertSame(array_reverse($ids), $seen);
        $page = $this->library->library($viewer, size: 2);
        $saved = array_column($page->items(), 'target_id');
        while ($cursor = $page->nextCursor()) {
            $page = $this->library->library($viewer, size: 2, cursor: $cursor->encode());
            $saved = array_merge($saved, array_column($page->items(), 'target_id'));
        }
        $this->assertSame(array_reverse($ids), $saved);
        DB::enableQueryLog();
        $this->query->recent(Kind::Article, size: 1);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->query->recent(Kind::Article, size: 5);
        $this->assertSame($one, count(DB::getQueryLog()));
        $this->assertLessThanOrEqual(7, $one);
        DB::flushQueryLog();
        $this->library->library($viewer, size: 1);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->library->library($viewer, size: 5);
        $this->assertSame($one, count(DB::getQueryLog()));
        $this->assertLessThanOrEqual(7, $one);
        $this->rejects(ValidationException::class, fn () => $this->query->recent(Kind::Article, size: 51));
        $this->rejects(ValidationException::class, fn () => $this->library->library($viewer, cursor: base64_encode('null')));
        $this->rejects(ValidationException::class, fn () => $this->query->recent(Kind::Article, cursor: base64_encode('{"id":1}')));
    }

    public function test_mysql_constraints_enforce_explicit_targets_and_revision_versions(): void
    {
        $owner = $this->writer();
        $article = $this->entry($owner);
        $resource = $this->entry($owner, Kind::Resource);
        $saved = $this->saved->save($owner, SavedType::Article, $article->id);
        foreach ([
            fn () => DB::table('saved_items')->insert(['user_id' => $owner->id, 'created_at' => now()]),
            fn () => DB::table('saved_items')->insert(['user_id' => $owner->id, 'article_id' => $article->id, 'legal_resource_id' => $resource->id, 'created_at' => now()]),
            fn () => DB::table('saved_items')->insert(['user_id' => $owner->id, 'article_id' => $article->id, 'created_at' => now()]),
            fn () => DB::table('saved_items')->insert(['user_id' => $owner->id, 'post_id' => 999999999, 'created_at' => now()]),
            fn () => DB::table('content_revisions')->insert(['article_id' => $article->id, 'recorded_by' => $owner->id, 'version' => 1, 'snapshot' => '{}', 'created_at' => now()]),
            fn () => DB::table('content_revisions')->insert(['recorded_by' => $owner->id, 'version' => 100, 'snapshot' => '{}', 'created_at' => now()]),
            fn () => DB::table('articles')->where('id', $article->id)->update(['status' => 'deleted']),
            fn () => DB::table('articles')->where('id', $article->id)->delete(),
        ] as $operation) {
            $this->rejects(QueryException::class, $operation);
        }
        $this->assertTrue($saved->article->is($article));
    }

    public function test_revision_failure_rolls_back_content_classification_files_and_publication_marker(): void
    {
        $owner = $this->writer();
        $file = $this->evidenceFile($owner);
        $article = $this->entry($owner, data: ['attachment_ids' => [$file->id]], publish: false);
        ContentRevision::creating(function () {
            throw new \RuntimeException('Injected revision failure');
        });
        $this->rejects(\RuntimeException::class, fn () => $this->editorial->transition(Kind::Article, $owner, $article->id, Status::Published, 1));
        $this->assertSame(Status::Draft, $article->fresh()->status);
        $this->assertSame(1, $article->fresh()->version);
        $this->assertNull($file->fresh()->editorial_published_at);
        $this->assertSame(1, ContentRevision::count());
        $tag = Tag::create(['name' => 'Prueba', 'slug' => (string) Str::uuid()]);
        $this->rejects(\RuntimeException::class, fn () => $this->editorial->update(Kind::Article, $owner, $article->id, ['title' => 'No persistir', 'tag_ids' => [$tag->id], 'attachment_ids' => []], 1));
        $this->assertSame('Derechos laborales', $article->fresh()->title);
        $this->assertSame(0, $article->tags()->count());
        $this->assertSame([$file->id], $article->fresh()->attachments->modelKeys());
    }

    public function test_editorial_permission_seeding_is_additive_idempotent_and_does_not_assign_roles(): void
    {
        $user = $this->writer();
        $before = Permission::orderBy('id')->pluck('id', 'name')->all();
        $this->seed(DatabaseSeeder::class);
        $this->seed(EditorialPermissionSeeder::class);
        $after = Permission::orderBy('id')->pluck('id', 'name')->all();
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertSame(25, Permission::count());
        $this->assertTrue($user->fresh()->can('articles.publish.own'));
        $admin = User::factory()->create()->refresh();
        $admin->assignRole('admin');
        $this->assertFalse($admin->can('articles.manage'));
        $this->assertFalse($admin->can('resources.create'));
        $this->assertSame([], $user->getRoleNames()->all());
    }

    public function test_saved_private_posts_require_the_current_moderation_permission(): void
    {
        $owner = $this->writer();
        $owner->assignRole('user');
        $moderator = User::factory()->create()->refresh();
        $moderator->assignRole('moderator');
        $posts = app(PostService::class);
        $draft = $posts->create($owner, ['content' => 'Privado', 'visibility' => 'private']);
        $this->rejects(AuthorizationException::class, fn () => $this->saved->save($owner, SavedType::Post, $draft->id));
        $post = $posts->publish($owner, $draft->id, 1);
        $this->saved->save($moderator, SavedType::Post, $post->id);
        $this->assertCount(1, $this->library->library($moderator)->items());
        $moderator->removeRole('moderator');
        $this->assertSame([], $this->library->library($moderator)->items());
        $this->rejects(AuthorizationException::class, fn () => $this->saved->save($moderator, SavedType::Post, $post->id));
        $this->saved->remove($moderator, SavedType::Post, $post->id);
        $this->assertSame(0, SavedItem::count());
    }

    public function test_public_dtos_do_not_expose_private_aliases_credentials_or_draft_bodies(): void
    {
        $owner = $this->writer();
        app(ProfileService::class)->saveUserProfile($owner, $owner->id, ['display_name' => 'Alias privado', 'username' => 'alias_privado', 'visibility' => 'private']);
        $article = $this->entry($owner);
        $list = $this->query->recent(Kind::Article)->items();
        $detail = $this->query->detail(Kind::Article, $article->id);
        $json = json_encode([$list, $detail]);
        foreach ([$owner->email, 'Alias privado', 'alias_privado', 'password', 'license_number', 'request_hash', 'storage_name'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame('Usuario', $detail['author']['display_name']);
        $this->assertArrayNotHasKey('body', $list[0]);
        $this->assertSame('Información jurídica de prueba.', $detail['body']);
        app(ProfileService::class)->saveUserProfile($owner, $owner->id, ['display_name' => 'Alias público', 'visibility' => 'public']);
        $this->assertSame('Alias público', $this->query->detail(Kind::Article, $article->id)['author']['display_name']);
    }
}
