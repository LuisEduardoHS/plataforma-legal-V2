<?php

namespace Tests\Feature;

use App\Enums\AttachmentStatus;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Enums\VerificationEvidenceType;
use App\Exceptions\AttachmentException;
use App\Exceptions\CommunityException;
use App\Models\Comment;
use App\Models\ContentCategory;
use App\Models\LegalSpecialty;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\Tag;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Community\CommunityInteractionService;
use App\Services\Community\CommunityMediaService;
use App\Services\Community\CommunityQuery;
use App\Services\Community\PostService;
use App\Services\Profiles\ProfileService;
use App\Services\Verification\VerificationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VerificationPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

class CommunityTest extends TestCase
{
    use VerificationFixtures;

    private bool $started = false;

    private PostService $posts;

    private CommunityInteractionService $interactions;

    private CommunityQuery $query;

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
        $this->seed([RolePermissionSeeder::class, VerificationPermissionSeeder::class]);
        $this->posts = app(PostService::class);
        $this->interactions = app(CommunityInteractionService::class);
        $this->query = app(CommunityQuery::class);
    }

    protected function tearDown(): void
    {
        try {
            DB::disableQueryLog();
            if ($this->started) {
                DB::rollBack();
                Storage::disk('attachments')->delete(Storage::disk('attachments')->allFiles());
            }
        } finally {
            parent::tearDown();
        }
    }

    private function member(string $role = 'user'): User
    {
        $user = User::factory()->create()->refresh();
        $user->assignRole($role);

        return $user;
    }

    private function published(User $owner, array $data = []): Post
    {
        $post = $this->posts->create($owner, $data + ['content' => 'Consulta de derecho laboral', 'visibility' => 'public']);

        return $this->posts->publish($owner, $post->id, $post->version);
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

    public function test_draft_publish_edit_and_soft_delete_with_optimistic_versions(): void
    {
        $owner = $this->member();
        $draft = $this->posts->create($owner, []);
        $this->assertSame(PostStatus::Draft, $draft->status);
        $this->assertSame('private', $draft->visibility->value);
        $this->rejects(ValidationException::class, fn () => $this->posts->publish($owner, $draft->id, 1));
        $edited = $this->posts->update($owner, $draft->id, ['content' => '  <script>no ejecutar</script>  ', 'visibility' => 'public'], 1);
        $this->assertSame('<script>no ejecutar</script>', $edited->content);
        $this->assertNotNull($edited->edited_at);
        $post = $this->posts->publish($owner, $draft->id, 2);
        $this->assertSame(3, $post->version);
        $this->assertNotNull($post->published_at);
        $this->rejects(ValidationException::class, fn () => $this->posts->update($owner, $post->id, ['content' => 'stale'], 2));
        $this->rejects(ValidationException::class, fn () => $this->posts->update($owner, $post->id, ['content' => ''], 3));
        $this->assertSame(3, $post->fresh()->version);
        $this->posts->delete($owner, $post->id, 3);
        $this->posts->delete($owner, $post->id, 3);
        $this->assertNull(Post::find($post->id));
        $this->assertSame(PostStatus::Deleted, Post::withTrashed()->findOrFail($post->id)->status);
        $this->assertTrue($owner->posts()->withTrashed()->first()->trashed());
    }

    public function test_invalid_input_cannot_assign_ownership_status_roles_or_internal_fields(): void
    {
        $owner = $this->member();
        foreach ([['user_id' => $owner->id], ['status' => 'published'], ['version' => 5], ['roles' => ['admin']], ['visibility' => 'followers'], ['content' => "a\0b"], ['content' => str_repeat('á', 10001)], ['tag_ids' => [1, 1]]] as $data) {
            $this->rejects(ValidationException::class, fn () => $this->posts->create($owner, $data));
        }
        foreach ([Post::class, Comment::class, PostReaction::class] as $model) {
            $this->rejects(MassAssignmentException::class, fn () => $model::create(['user_id' => $owner->id]));
        }
        $this->assertSame(0, Post::count());
        $this->assertSame(['user'], $owner->getRoleNames()->all());
        $draft = $this->posts->create($owner, ['content' => "\u{200B}\u{00A0}"]);
        $this->rejects(ValidationException::class, fn () => $this->posts->publish($owner, $draft->id, 1));
    }

    public function test_authorization_visibility_and_moderation_do_not_depend_on_professional_status(): void
    {
        $owner = $this->member();
        $stranger = $this->member();
        $moderator = $this->member('moderator');
        $post = $this->published($owner, ['visibility' => 'private']);
        $this->rejects(AuthorizationException::class, fn () => $this->query->detail($post->id));
        $this->rejects(AuthorizationException::class, fn () => $this->query->detail($post->id, $stranger));
        $this->rejects(AuthorizationException::class, fn () => $this->posts->update($stranger, $post->id, ['content' => 'fraude'], 2));
        $this->rejects(AuthorizationException::class, fn () => $this->posts->delete($stranger, $post->id, 2));
        $this->assertSame($post->id, $this->query->detail($post->id, $owner)['id']);
        $this->assertSame($post->id, $this->query->detail($post->id, $moderator)['id']);
        $hidden = $this->posts->moderate($moderator, $post->id, true, 2);
        $edited = $this->posts->update($owner, $post->id, ['content' => 'Corrección'], $hidden->version);
        $this->assertSame(PostStatus::Hidden, $edited->status);
        $this->rejects(AuthorizationException::class, fn () => $this->posts->moderate($owner, $post->id, false, $edited->version));
        $this->assertSame(PostStatus::Published, $this->posts->moderate($moderator, $post->id, false, $edited->version)->status);
        [$professional] = $this->professional();
        $professional->assignRole('user');
        $this->rejects(AuthorizationException::class, fn () => $this->posts->update($professional, $post->id, ['content' => 'otro'], 5));
        $this->assertFalse($professional->can('posts.moderate'));
    }

    public function test_permissions_are_reloaded_and_required_for_create_and_publish(): void
    {
        $owner = $this->member();
        $draft = $this->posts->create($owner, ['content' => 'Consulta']);
        $owner->removeRole('user');
        $owner->givePermissionTo('posts.update.own');
        $this->rejects(AuthorizationException::class, fn () => $this->posts->create($owner, []));
        $this->rejects(AuthorizationException::class, fn () => $this->posts->publish($owner, $draft->id, 1));
        $this->assertSame(PostStatus::Draft, $draft->fresh()->status);
    }

    public function test_existing_catalogs_are_normalized_filterable_and_inactive_history_is_preserved(): void
    {
        $owner = $this->member();
        $category = ContentCategory::create(['name' => 'Consulta', 'slug' => (string) Str::uuid()]);
        $specialty = LegalSpecialty::create(['name' => 'Laboral', 'slug' => (string) Str::uuid()]);
        $tag = Tag::create(['name' => 'Despido', 'slug' => (string) Str::uuid()]);
        $data = ['content_category_id' => $category->id, 'legal_specialty_id' => $specialty->id, 'tag_ids' => [$tag->id]];
        $post = $this->published($owner, $data);
        $this->assertTrue($category->posts()->first()->is($post));
        $this->assertTrue($specialty->posts()->first()->is($post));
        $this->assertTrue($tag->posts()->first()->is($post));
        foreach ([$category, $specialty, $tag] as $catalog) {
            $catalog->update(['is_active' => false]);
        }
        $post = $this->posts->update($owner, $post->id, $data + ['content' => 'Actualizado'], 2);
        $this->assertSame([$tag->id], $post->tags->modelKeys());
        foreach (['category_id' => $category->id, 'specialty_id' => $specialty->id, 'tag_id' => $tag->id] as $key => $value) {
            $this->assertSame([$post->id], collect($this->query->recent(filters: [$key => $value])->items())->pluck('id')->all());
        }
        foreach ($data as $key => $value) {
            $this->rejects(ValidationException::class, fn () => $this->posts->create($owner, [$key => $value]));
        }
        $post = $this->posts->update($owner, $post->id, ['content_category_id' => null, 'legal_specialty_id' => null, 'tag_ids' => []], 3);
        $this->assertNull($post->category);
        $this->assertCount(0, $post->tags);
    }

    public function test_partial_post_and_relationship_updates_rollback_on_invalid_attachment(): void
    {
        $owner = $this->member();
        $tag = Tag::create(['name' => 'Contrato', 'slug' => (string) Str::uuid()]);
        $foreign = $this->evidenceFile($this->member());
        $post = $this->published($owner);
        $this->rejects(ValidationException::class, fn () => $this->posts->update($owner, $post->id, ['content' => 'No persistir', 'tag_ids' => [$tag->id], 'attachment_ids' => [$foreign->id]], 2));
        $this->assertSame('Consulta de derecho laboral', $post->fresh()->content);
        $this->assertSame(2, $post->fresh()->version);
        $this->assertSame(0, $post->tags()->count());
        $this->rejects(ValidationException::class, fn () => $this->posts->create($owner, ['tag_ids' => [$tag->id], 'attachment_ids' => [$foreign->id]]));
        $this->assertSame(1, Post::count());
        $this->assertSame(0, DB::table('post_tag')->count());
    }

    public function test_file_only_posts_order_limits_and_private_context_download(): void
    {
        $owner = $this->member();
        $first = $this->evidenceFile($owner);
        $second = $this->evidenceFile($owner);
        $post = $this->published($owner, ['content' => null, 'attachment_ids' => [$second->id, $first->id]]);
        $this->assertSame([$second->id, $first->id], $post->attachments->modelKeys());
        $this->assertNotNull($first->fresh()->community_published_at);
        $post = $this->posts->update($owner, $post->id, ['attachment_ids' => [$first->id, $second->id]], 2);
        $this->assertSame([$first->id, $second->id], $post->attachments->modelKeys());
        $response = app(CommunityMediaService::class)->download(null, $post->id, $first->id);
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        $this->assertSame(Storage::disk('attachments')->get($first->path), $content);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('private', $first->fresh()->visibility);
        $this->assertArrayNotHasKey('path', $this->query->detail($post->id)['attachments'][0]);
        config(['community.max_attachments' => 1]);
        $this->rejects(ValidationException::class, fn () => $this->posts->create($owner, ['attachment_ids' => [$first->id, $second->id]]));
        $this->rejects(ValidationException::class, fn () => $this->posts->create($owner, ['attachment_ids' => [$first->id, $first->id]]));
    }

    public function test_unavailable_unsupported_foreign_and_retained_files_cannot_be_attached(): void
    {
        $owner = $this->member();
        $files = [$this->evidenceFile($this->member()), $this->evidenceFile($owner, status: AttachmentStatus::PendingDeletion), $this->evidenceFile($owner, 'other')];
        $retained = $this->evidenceFile($owner);
        $retained->forceFill(['verification_retained_at' => now()])->save();
        $files[] = $retained;
        foreach ($files as $file) {
            $this->rejects(ValidationException::class, fn () => $this->posts->create($owner, ['attachment_ids' => [$file->id]]));
        }
        $this->assertSame(0, Post::count());
    }

    public function test_verification_and_community_files_are_mutually_exclusive_even_in_drafts(): void
    {
        [$owner, $profile] = $this->professional();
        $owner->assignRole('user');
        $workflow = app(VerificationService::class);
        $request = $workflow->createDraft($owner, $profile->id);
        $evidence = $this->evidenceFile($owner);
        $workflow->addEvidence($owner, $request->id, $evidence->id, VerificationEvidenceType::ProfessionalLicense);
        $this->rejects(ValidationException::class, fn () => $this->posts->create($owner, ['attachment_ids' => [$evidence->id]]));
        $file = $this->evidenceFile($owner);
        $draft = $this->posts->create($owner, ['attachment_ids' => [$file->id]]);
        $this->rejects(ValidationException::class, fn () => $workflow->addEvidence($owner, $request->id, $file->id, VerificationEvidenceType::Certificate));
        $post = $this->posts->publish($owner, $draft->id, 1);
        $this->posts->update($owner, $post->id, ['content' => 'Archivo retirado', 'attachment_ids' => []], 2);
        $this->rejects(ValidationException::class, fn () => $workflow->addEvidence($owner, $request->id, $file->id, VerificationEvidenceType::Certificate));
        $this->rejects(AttachmentException::class, fn () => $file->fresh()->forceFill(['community_published_at' => null])->save());
        $this->assertSame(1, $request->evidences()->count());
    }

    public function test_physical_deletion_requires_all_live_post_references_to_be_released(): void
    {
        $owner = $this->member();
        $file = $this->evidenceFile($owner);
        $first = $this->published($owner, ['attachment_ids' => [$file->id]]);
        $second = $this->posts->create($owner, ['attachment_ids' => [$file->id]]);
        $service = app(AttachmentService::class);
        $this->rejects(AttachmentException::class, fn () => $service->requestDeletion($owner, $file->id));
        $this->posts->delete($owner, $first->id, 2);
        $this->rejects(AttachmentException::class, fn () => $service->requestDeletion($owner, $file->id));
        $this->rejects(AttachmentException::class, fn () => $service->purge($owner, $file->id));
        Storage::disk('attachments')->assertExists($file->path);
        $this->posts->delete($owner, $second->id, 1);
        $service->requestDeletion($owner, $file->id);
        $service->purge($owner, $file->id);
        Storage::disk('attachments')->assertMissing($file->path);
        $this->assertSame(AttachmentStatus::Deleted, $file->fresh()->status);
    }

    public function test_media_requires_post_visibility_association_and_content_integrity(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $file = $this->evidenceFile($owner);
        $extra = $this->evidenceFile($owner);
        $post = $this->published($owner, ['visibility' => 'private', 'attachment_ids' => [$file->id]]);
        $media = app(CommunityMediaService::class);
        $this->interactions->follow($other, $owner->id);
        $this->rejects(AuthorizationException::class, fn () => $media->download($other, $post->id, $file->id));
        $this->rejects(AuthorizationException::class, fn () => $media->download(null, $post->id, $file->id));
        $this->rejects(AuthorizationException::class, fn () => $media->download($owner, $post->id, $extra->id));
        Storage::disk('attachments')->put($file->path, 'Archivo alterado');
        $this->rejects(CommunityException::class, fn () => $media->download($owner, $post->id, $file->id));
        DB::table('attachments')->where('id', $file->id)->update(['verification_retained_at' => now()]);
        $this->rejects(ValidationException::class, fn () => $media->download($owner, $post->id, $file->id));
        $this->assertSame([], $this->query->detail($post->id, $owner)['attachments']);
    }

    public function test_comments_support_one_reply_level_and_same_post_parent_only(): void
    {
        $owner = $this->member();
        $post = $this->published($owner);
        $otherPost = $this->published($owner);
        $root = $this->interactions->comment($owner, $post->id, ['content' => '  Respuesta  ']);
        $reply = $this->interactions->comment($owner, $post->id, ['content' => 'Réplica', 'parent_id' => $root->id]);
        $this->assertSame('Respuesta', $root->content);
        $this->assertTrue($reply->parent->is($root));
        $this->assertTrue($root->replies()->first()->is($reply));
        $this->rejects(ValidationException::class, fn () => $this->interactions->comment($owner, $post->id, ['content' => 'Nivel 3', 'parent_id' => $reply->id]));
        $this->rejects(ValidationException::class, fn () => $this->interactions->comment($owner, $otherPost->id, ['content' => 'Otro post', 'parent_id' => $root->id]));
        foreach (['', "\u{200B}\u{00A0}", "x\0y", str_repeat('a', 5001)] as $content) {
            $this->rejects(ValidationException::class, fn () => $this->interactions->comment($owner, $post->id, ['content' => $content]));
        }
        $this->rejects(ValidationException::class, fn () => $this->interactions->comment($owner, $post->id, ['content' => 'a', 'user_id' => $owner->id]));
        $this->assertCount(2, $this->query->comments($post->id)->items());
        $this->assertSame(2, $this->query->detail($post->id)['comments_count']);
    }

    public function test_comment_edits_moderation_and_parent_deletion_preserve_history(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $moderator = $this->member('moderator');
        $post = $this->published($owner);
        $root = $this->interactions->comment($other, $post->id, ['content' => 'Raíz']);
        $reply = $this->interactions->comment($owner, $post->id, ['content' => 'Respuesta', 'parent_id' => $root->id]);
        $this->rejects(AuthorizationException::class, fn () => $this->interactions->editComment($owner, $root->id, 'Ajeno', 1));
        $this->rejects(AuthorizationException::class, fn () => $this->interactions->deleteComment($owner, $root->id, 1));
        $edited = $this->interactions->editComment($other, $root->id, 'Corrección', 1);
        $this->assertNotNull($edited->edited_at);
        $this->rejects(ValidationException::class, fn () => $this->interactions->editComment($other, $root->id, 'stale', 1));
        $hidden = $this->interactions->moderateComment($moderator, $root->id, true, 2);
        $this->assertSame(CommentStatus::Hidden, $hidden->status);
        $this->assertSame([], $this->query->comments($post->id)->items());
        $edited = $this->interactions->editComment($other, $root->id, 'Sigue oculto', 3);
        $this->assertSame(CommentStatus::Hidden, $edited->status);
        $this->interactions->moderateComment($moderator, $root->id, false, 4);
        $this->interactions->deleteComment($other, $root->id, 5);
        $this->interactions->deleteComment($other, $root->id, 5);
        $this->assertSame([], $this->query->comments($post->id)->items());
        $this->assertNotNull(Comment::find($reply->id));
        $this->assertTrue(Comment::withTrashed()->find($root->id)->trashed());
    }

    public function test_reactions_are_idempotent_and_counts_exclude_inactive_users(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $post = $this->published($owner);
        $reaction = $this->interactions->react($other, $post->id);
        $this->assertSame($reaction->id, $this->interactions->react($other, $post->id)->id);
        $this->assertSame(1, $post->reactions()->count());
        $this->assertSame(1, $this->query->detail($post->id)['likes_count']);
        $other->forceFill(['status' => 'suspended'])->save();
        $this->assertSame(0, $this->query->detail($post->id)['likes_count']);
        $other->forceFill(['status' => 'active'])->save();
        $this->interactions->unreact($other, $post->id);
        $this->interactions->unreact($other, $post->id);
        $this->assertSame(0, $post->reactions()->count());
    }

    public function test_inaccessible_or_unpublished_posts_cannot_receive_interactions(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $moderator = $this->member('moderator');
        $draft = $this->posts->create($owner, ['content' => 'Borrador']);
        $private = $this->published($owner, ['visibility' => 'private']);
        $hidden = $this->published($owner);
        $this->posts->moderate($moderator, $hidden->id, true, 2);
        $deleted = $this->published($owner);
        $this->posts->delete($owner, $deleted->id, 2);
        foreach ([$draft, $private, $hidden, $deleted] as $post) {
            $this->rejects(AuthorizationException::class, fn () => $this->interactions->comment($other, $post->id, ['content' => 'No']));
            $this->rejects(AuthorizationException::class, fn () => $this->interactions->react($other, $post->id));
        }
        $this->rejects(AuthorizationException::class, fn () => $this->interactions->react($moderator, $hidden->id));
        $this->assertSame(0, Comment::count());
        $this->assertSame(0, PostReaction::count());
    }

    public function test_follow_idempotence_privacy_and_inactive_target_unfollow(): void
    {
        $owner = $this->member();
        $target = $this->member();
        $post = $this->published($target);
        $this->published($target, ['visibility' => 'private']);
        $this->interactions->follow($owner, $target->id);
        $this->interactions->follow($owner, $target->id);
        $this->assertSame(1, $owner->following()->count());
        $this->assertTrue($target->followers()->first()->is($owner));
        $this->assertSame([$post->id], collect($this->query->following($owner)->items())->pluck('id')->all());
        $this->assertCount(1, $this->query->connections($owner, $owner->id)->items());
        $this->rejects(AuthorizationException::class, fn () => $this->query->connections($target, $owner->id));
        $this->rejects(ValidationException::class, fn () => $this->interactions->follow($owner, $owner->id));
        app(ProfileService::class)->saveUserProfile($owner, $owner->id, ['visibility' => 'public', 'display_name' => 'Alias']);
        $this->assertCount(1, $this->query->connections($target, $owner->id)->items());
        $target->forceFill(['status' => 'disabled'])->save();
        $this->rejects(ValidationException::class, fn () => $this->interactions->follow($owner, $target->id));
        $this->assertSame([], $this->query->following($owner)->items());
        $this->assertSame([], $this->query->connections($owner, $owner->id)->items());
        $this->interactions->unfollow($owner, $target->id);
        $this->interactions->unfollow($owner, $target->id);
        $this->assertSame(0, $owner->following()->count());
    }

    public function test_stale_inactive_accounts_cannot_mutate_or_query_community(): void
    {
        $owner = $this->member();
        $target = $this->member();
        $post = $this->published($owner);
        $comment = $this->interactions->comment($owner, $post->id, ['content' => 'Comentario']);
        foreach (['suspended', 'disabled'] as $status) {
            DB::table('users')->where('id', $owner->id)->update(['status' => $status]);
            foreach ([
                fn () => $this->posts->create($owner, []),
                fn () => $this->posts->update($owner, $post->id, ['content' => 'no'], 2),
                fn () => $this->posts->delete($owner, $post->id, 2),
                fn () => $this->interactions->comment($owner, $post->id, ['content' => 'no']),
                fn () => $this->interactions->editComment($owner, $comment->id, 'no', 1),
                fn () => $this->interactions->deleteComment($owner, $comment->id, 1),
                fn () => $this->interactions->react($owner, $post->id),
                fn () => $this->interactions->unreact($owner, $post->id),
                fn () => $this->interactions->follow($owner, $target->id),
                fn () => $this->interactions->unfollow($owner, $target->id),
                fn () => $this->query->recent($owner),
            ] as $operation) {
                $this->rejects(AuthorizationException::class, $operation);
            }
            $this->assertSame([], $this->query->recent()->items());
            $this->rejects(AuthorizationException::class, fn () => $this->query->detail($post->id, $target));
        }
    }

    public function test_profile_feed_excludes_drafts_hidden_deleted_private_and_inactive_authors(): void
    {
        $owner = $this->member();
        $public = $this->published($owner);
        $private = $this->published($owner, ['visibility' => 'private']);
        $this->posts->create($owner, ['content' => 'Draft']);
        $hidden = $this->published($owner);
        $this->posts->moderate($this->member('moderator'), $hidden->id, true, 2);
        $deleted = $this->published($owner);
        $this->posts->delete($owner, $deleted->id, 2);
        $this->assertSame([$public->id], collect($this->query->profile($owner->id)->items())->pluck('id')->all());
        $this->assertSame([$private->id, $public->id], collect($this->query->profile($owner->id, $owner)->items())->pluck('id')->all());
        $owner->forceFill(['status' => 'suspended'])->save();
        $this->assertSame([], $this->query->profile($owner->id)->items());
    }

    public function test_cursor_pagination_is_stable_with_tied_dates_and_new_insertions(): void
    {
        $owner = $this->member();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->published($owner)->id;
        }
        DB::table('posts')->whereIn('id', $ids)->update(['published_at' => '2026-01-01 12:00:00']);
        $page = $this->query->recent(size: 2);
        $seen = collect($page->items())->pluck('id')->all();
        $this->assertSame(array_reverse(array_slice($ids, -2)), $seen);
        $this->published($owner);
        while ($cursor = $page->nextCursor()) {
            $page = $this->query->recent(size: 2, cursor: $cursor->encode());
            $seen = array_merge($seen, collect($page->items())->pluck('id')->all());
        }
        $this->assertSame(array_reverse($ids), $seen);
        $this->assertCount(5, array_unique($seen));
        $this->rejects(ValidationException::class, fn () => $this->query->recent(size: 51));
        $this->rejects(ValidationException::class, fn () => $this->query->recent(cursor: 'bad-cursor'));
        $this->rejects(ValidationException::class, fn () => $this->query->recent(filters: ['user_id' => $owner->id]));
    }

    public function test_feed_queries_are_bounded_and_public_dtos_do_not_expose_private_profiles(): void
    {
        $owner = $this->member();
        app(ProfileService::class)->saveUserProfile($owner, $owner->id, ['display_name' => 'Nombre privado', 'username' => 'privado_unico', 'visibility' => 'private']);
        for ($i = 0; $i < 6; $i++) {
            $this->published($owner);
        }
        DB::enableQueryLog();
        $this->query->recent(size: 1);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $many = $this->query->recent(size: 6)->items();
        $this->assertSame($one, count(DB::getQueryLog()));
        $this->assertLessThanOrEqual(8, $one);
        $json = json_encode($many);
        foreach ([$owner->email, 'Nombre privado', 'privado_unico', 'password', 'license_number', 'storage_name', 'sha256'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame('Usuario', $many[0]['author']['display_name']);
    }

    public function test_mysql_unique_foreign_keys_and_checks_enforce_structural_integrity(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $post = $this->published($owner);
        $second = $this->published($owner);
        $root = $this->interactions->comment($owner, $post->id, ['content' => 'Raíz']);
        $reaction = $this->interactions->react($owner, $post->id);
        $this->interactions->follow($owner, $other->id);
        $file = $this->evidenceFile($owner);
        $this->posts->update($owner, $post->id, ['attachment_ids' => [$file->id]], 2);
        foreach ([
            fn () => DB::table('post_reactions')->insert(['post_id' => $post->id, 'user_id' => $owner->id, 'type' => 'like']),
            fn () => DB::table('user_follows')->insert(['follower_id' => $owner->id, 'followed_id' => $owner->id]),
            fn () => DB::table('user_follows')->insert(['follower_id' => $owner->id, 'followed_id' => $other->id]),
            fn () => DB::table('comments')->insert(['post_id' => $second->id, 'user_id' => $owner->id, 'parent_id' => $root->id, 'content' => 'Inválido']),
            fn () => DB::table('posts')->where('id', $post->id)->update(['status' => 'deleted']),
            fn () => DB::table('comments')->where('id', $root->id)->update(['deleted_at' => now()]),
            fn () => DB::table('post_attachments')->insert(['post_id' => $post->id, 'attachment_id' => $file->id, 'position' => 1]),
            fn () => DB::table('attachments')->where('id', $file->id)->delete(),
            fn () => DB::table('users')->where('id', $owner->id)->delete(),
        ] as $operation) {
            $this->rejects(QueryException::class, $operation);
        }
        $this->assertTrue($reaction->fresh()->post->is($post));
        $this->assertTrue($root->post->is($post));
    }

    public function test_follow_foreign_keys_remove_edges_when_a_user_without_content_is_deleted(): void
    {
        $owner = $this->member();
        $target = $this->member();
        $this->interactions->follow($owner, $target->id);
        $target->delete();
        $this->assertSame(0, $owner->following()->count());
        $this->assertSame(0, DB::table('user_follows')->count());
    }

    public function test_comment_and_connection_cursors_preserve_order_and_reject_wrong_shapes(): void
    {
        $owner = $this->member();
        $post = $this->published($owner);
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->interactions->comment($owner, $post->id, ['content' => 'Respuesta '.$i])->id;
            $this->interactions->follow($owner, $this->member()->id);
        }
        $first = $this->query->comments($post->id, size: 2);
        $second = $this->query->comments($post->id, size: 2, cursor: $first->nextCursor()->encode());
        $this->assertSame($ids, collect(array_merge($first->items(), $second->items()))->pluck('id')->all());
        $first = $this->query->connections($owner, $owner->id, size: 2);
        $second = $this->query->connections($owner, $owner->id, size: 2, cursor: $first->nextCursor()->encode());
        $this->assertCount(3, array_unique(collect(array_merge($first->items(), $second->items()))->pluck('id')->all()));
        foreach (['null', 'true', '{}', '[]', '{"id":1}', '{"id":[],"_pointsToNextItems":true}', '{"id":1,"published_at":"invalid","_pointsToNextItems":true}'] as $json) {
            $this->rejects(ValidationException::class, fn () => $this->query->recent(cursor: base64_encode($json)));
        }
        $postCursor = new Cursor(['id' => $post->id, 'published_at' => $post->published_at->toDateTimeString()]);
        $this->rejects(ValidationException::class, fn () => $this->query->comments($post->id, cursor: $postCursor->encode()));
    }

    public function test_inactive_comment_authors_hide_their_threads_and_counts(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $post = $this->published($owner);
        $root = $this->interactions->comment($other, $post->id, ['content' => 'Raíz']);
        $this->interactions->comment($owner, $post->id, ['content' => 'Respuesta', 'parent_id' => $root->id]);
        $other->forceFill(['status' => 'disabled'])->save();
        $this->assertSame([], $this->query->comments($post->id)->items());
        $this->assertSame(0, $this->query->detail($post->id)['comments_count']);
        $this->rejects(ValidationException::class, fn () => $this->interactions->comment($owner, $post->id, ['content' => 'No', 'parent_id' => $root->id]));
    }

    public function test_explicit_permissions_are_required_without_automatic_role_assignment(): void
    {
        $owner = $this->member();
        $unprivileged = User::factory()->create()->refresh();
        $post = $this->published($owner);
        $this->rejects(AuthorizationException::class, fn () => $this->posts->create($unprivileged, ['content' => 'No']));
        $this->rejects(AuthorizationException::class, fn () => $this->interactions->comment($unprivileged, $post->id, ['content' => 'No']));
        $this->interactions->react($unprivileged, $post->id);
        $this->interactions->follow($unprivileged, $owner->id);
        $this->assertSame([], $unprivileged->getRoleNames()->all());
        $this->assertFalse($unprivileged->can('posts.moderate'));
        $this->assertFalse($unprivileged->can('verification.review'));
    }
}
