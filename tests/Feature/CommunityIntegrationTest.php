<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Exceptions\CommunityException;
use App\Models\Comment;
use App\Models\ContentCategory;
use App\Models\LegalSpecialty;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\Tag;
use App\Models\User;
use App\Services\Community\CommunityInteractionService;
use App\Services\Community\CommunityQuery;
use App\Services\Community\PostService;
use App\Services\Profiles\ProfileService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EnforcedCsrfToken;
use Tests\TestCase;

class CommunityIntegrationTest extends TestCase
{
    private bool $started = false;

    private PostService $posts;

    private CommunityInteractionService $interactions;

    protected function setUp(): void
    {
        parent::setUp(); // The shared guard verifies configured AND actual MySQL testing DB.
        DB::beginTransaction();
        $this->started = true;
        $this->seed(RolePermissionSeeder::class);
        $this->posts = app(PostService::class);
        $this->interactions = app(CommunityInteractionService::class);
    }

    protected function tearDown(): void
    {
        try {
            DB::disableQueryLog();
            if ($this->started) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function member(string $role = 'user'): User
    {
        $user = User::factory()->create()->refresh();
        if ($role !== '') {
            $user->assignRole($role);
        }

        return $user;
    }

    private function published(User $owner, array $data = []): Post
    {
        $post = $this->posts->create($owner, $data + ['content' => 'Contenido real de Comunidad', 'visibility' => 'public']);

        return $this->posts->publish($owner, $post->id, $post->version);
    }

    public function test_guest_feed_and_detail_use_public_dtos_and_hide_personal_information(): void
    {
        $owner = $this->member();
        app(ProfileService::class)->saveUserProfile($owner, $owner->id, ['display_name' => 'Identidad privada', 'visibility' => 'private']);
        $post = $this->published($owner, ['content' => '<script>alert(1)</script>']);
        foreach (['/comunidad', '/comunidad/publicaciones/'.$post->id] as $url) {
            $this->get($url)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
                ->assertDontSee($owner->email)->assertDontSee('Identidad privada')->assertDontSee($owner->password)->assertDontSee('María Hernández');
        }
        $this->get('/comunidad')->assertHeader('Cache-Control', 'no-store, private');
        app(ProfileService::class)->saveUserProfile($owner, $owner->id, ['display_name' => 'Alias público', 'visibility' => 'public']);
        $this->get('/comunidad')->assertSee('Alias público')->assertSee(route('profiles.show', $owner->id));
    }

    public function test_guest_write_and_nonpublic_feeds_require_authentication(): void
    {
        foreach (['/comunidad/crear', '/comunidad?feed=following', '/comunidad?feed=mine', '/comunidad?feed=moderation', '/comunidad/usuarios/1/conexiones'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->postJson('/comunidad/publicaciones', [])->assertUnauthorized();
        $this->putJson('/comunidad/publicaciones/1/reaccion')->assertUnauthorized();
        $this->putJson('/comunidad/usuarios/1/seguir')->assertUnauthorized();
        $this->postJson('/comunidad/publicaciones/1/comentarios', ['content' => 'Hola'])->assertUnauthorized();
        $this->get('/comunidad/publicaciones/999999999999999999999999')->assertNotFound();
    }

    public function test_empty_draft_is_optional_and_publishing_failure_does_not_leave_partial_rows(): void
    {
        $owner = $this->member();
        $this->actingAs($owner)->get('/comunidad/crear')->assertOk();
        $this->assertSame(0, Post::count());
        $this->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'publish'])->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->assertSame(0, Post::count());
        $this->postJson('/comunidad/publicaciones', ['visibility' => 'private', 'intent' => 'draft'])->assertCreated()->assertJsonPath('status', 'draft')->assertJsonStructure(['id', 'version', 'redirect']);
        $this->assertSame(PostStatus::Draft, Post::firstOrFail()->status);
        $this->get('/comunidad?feed=mine')->assertOk()->assertSee('Borrador');
        $this->get('/comunidad')->assertSee('No hay publicaciones');
    }

    public function test_create_edit_publish_and_logical_delete_with_original_versions(): void
    {
        $owner = $this->member();
        $response = $this->actingAs($owner)->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'draft', 'content' => 'Borrador propio'])->assertCreated();
        $id = $response->json('id');
        $this->putJson('/comunidad/publicaciones/'.$id, ['visibility' => 'public', 'intent' => 'save', 'content' => 'Edición propia', 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->postJson('/comunidad/publicaciones/'.$id.'/publicar', ['version' => 2])->assertOk()->assertJsonPath('version', 3);
        $this->get('/comunidad')->assertSee('Edición propia');
        $this->deleteJson('/comunidad/publicaciones/'.$id, ['version' => 3])->assertOk();
        $this->deleteJson('/comunidad/publicaciones/'.$id, ['version' => 3])->assertOk();
        $this->assertTrue(Post::withTrashed()->findOrFail($id)->trashed());
        $this->get('/comunidad/publicaciones/'.$id)->assertNotFound();
    }

    public function test_edit_and_publish_are_atomic_and_never_override_moderation(): void
    {
        $owner = $this->member();
        $moderator = $this->member('moderator');
        $draft = $this->posts->create($owner, ['content' => 'Antes', 'visibility' => 'public']);
        $this->actingAs($owner)->putJson('/comunidad/publicaciones/'.$draft->id, ['content' => 'Después', 'visibility' => 'public', 'intent' => 'publish', 'version' => 1])->assertOk()->assertJsonPath('version', 3);
        $post = $draft->fresh();
        $this->posts->moderate($moderator, $post->id, true, $post->version);
        $this->putJson('/comunidad/publicaciones/'.$post->id, ['content' => 'Sigue oculto', 'visibility' => 'public', 'intent' => 'save', 'version' => 4])->assertOk();
        $this->assertSame(PostStatus::Hidden, $post->fresh()->status);
        $this->putJson('/comunidad/publicaciones/'.$post->id, ['content' => 'No guardar', 'visibility' => 'public', 'intent' => 'publish', 'version' => 5])->assertUnprocessable();
        $this->assertSame('Sigue oculto', $post->fresh()->content);
    }

    public static function internalFields(): array
    {
        return [['user_id', 1], ['author_id', 1], ['roles', ['admin']], ['permissions', ['posts.moderate']], ['status', 'published'], ['likes_count', 99], ['hidden', false], ['skip_version', true], ['published_at', '2026-11-14']];
    }

    #[DataProvider('internalFields')]
    public function test_post_forms_reject_internal_and_unknown_fields(string $field, mixed $value): void
    {
        $owner = $this->member();
        $post = $this->published($owner);
        $this->actingAs($owner)->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'content' => 'No', 'intent' => 'draft', $field => $value])->assertUnprocessable()->assertJsonValidationErrors('form');
        $this->putJson('/comunidad/publicaciones/'.$post->id, ['visibility' => 'public', 'content' => 'No', 'intent' => 'save', 'version' => $post->version, $field => $value])->assertUnprocessable()->assertJsonValidationErrors('form');
        $this->assertSame(1, Post::count());
        $this->assertSame('Contenido real de Comunidad', $post->fresh()->content);
    }

    public function test_foreign_edits_and_missing_permissions_are_denied_without_granting_privileges(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $unprivileged = $this->member('');
        $post = $this->published($owner);
        $this->actingAs($other)->putJson('/comunidad/publicaciones/'.$post->id, ['visibility' => 'public', 'content' => 'Ataque', 'intent' => 'save', 'version' => $post->version])->assertForbidden();
        $this->deleteJson('/comunidad/publicaciones/'.$post->id, ['version' => $post->version])->assertForbidden();
        $this->actingAs($unprivileged)->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'publish', 'content' => 'Sin permiso'])->assertForbidden();
        $this->postJson('/comunidad/publicaciones/'.$post->id.'/comentarios', ['content' => 'Sin permiso'])->assertForbidden();
        $this->assertSame([], $unprivileged->getRoleNames()->all());
    }

    public function test_stale_and_forged_versions_fail_with_409_without_reloading_a_new_version(): void
    {
        $owner = $this->member();
        $post = $this->published($owner);
        $this->posts->update($owner, $post->id, ['content' => 'Versión confirmada'], $post->version);
        $this->actingAs($owner)->putJson('/comunidad/publicaciones/'.$post->id, ['visibility' => 'public', 'intent' => 'save', 'content' => 'No guardar', 'version' => $post->version])->assertConflict();
        $this->deleteJson('/comunidad/publicaciones/'.$post->id, ['version' => 999999])->assertConflict();
        $this->deleteJson('/comunidad/publicaciones/'.$post->id, ['version' => 'not-a-version'])->assertUnprocessable();
        $this->from('/comunidad/publicaciones/'.$post->id.'/editar')->put('/comunidad/publicaciones/'.$post->id, ['visibility' => 'public', 'intent' => 'save', 'content' => 'No guardar', 'version' => 2])->assertConflict()->assertSee('Conflicto de versión');
        $this->assertSame('Versión confirmada', $post->fresh()->content);
    }

    public function test_catalogs_tags_and_compatible_filters_include_retained_history(): void
    {
        $owner = $this->member();
        $category = ContentCategory::create(['name' => 'Laboral', 'slug' => 'laboral-test', 'is_active' => true]);
        $specialty = LegalSpecialty::create(['name' => 'Civil', 'slug' => 'civil-test', 'is_active' => true]);
        $tag = Tag::create(['name' => 'Consulta', 'slug' => 'consulta-test', 'is_active' => true]);
        $this->actingAs($owner)->postJson('/comunidad/publicaciones', ['content' => 'Caso catalogado', 'visibility' => 'public', 'intent' => 'publish', 'content_category_id' => $category->id, 'legal_specialty_id' => $specialty->id, 'tag_ids' => [$tag->id]])->assertCreated();
        $post = Post::firstOrFail();
        foreach (['category_id' => $category->id, 'specialty_id' => $specialty->id, 'tag_id' => $tag->id] as $field => $id) {
            $this->get('/comunidad?'.$field.'='.$id)->assertOk()->assertSee('Caso catalogado')->assertViewHas('posts', fn ($items) => count($items) === 1);
        }
        $category->update(['is_active' => false]);
        $tag->update(['is_active' => false]);
        $this->get('/comunidad/publicaciones/'.$post->id.'/editar')->assertOk()->assertSee('histórica');
        $this->putJson('/comunidad/publicaciones/'.$post->id, ['content' => 'Historia', 'visibility' => 'public', 'intent' => 'save', 'version' => 2, 'content_category_id' => $category->id, 'tag_ids' => [$tag->id]])->assertOk();
        $this->putJson('/comunidad/publicaciones/'.$post->id, ['visibility' => 'public', 'intent' => 'save', 'version' => 3, 'content_category_id' => null, 'replace_tags' => true])->assertOk();
        $this->putJson('/comunidad/publicaciones/'.$post->id, ['visibility' => 'public', 'intent' => 'save', 'version' => 4, 'content_category_id' => $category->id])->assertUnprocessable();
        $this->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'draft', 'tag_ids' => [$tag->id]])->assertUnprocessable();
        $this->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'draft', 'content_category_id' => 999999])->assertUnprocessable();
    }

    public function test_validation_and_old_input_are_safe_for_malformed_fields(): void
    {
        $owner = $this->member();
        $this->actingAs($owner)->post('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'draft', 'content' => ['bad'], 'tag_ids' => [['bad']]])->assertRedirect(route('community.create'))->assertSessionHasErrors(['content', 'tag_ids.0']);
        $this->get('/comunidad/crear')->assertOk();
        $this->postJson('/comunidad/publicaciones', ['visibility' => 'invalid', 'intent' => 'draft', 'content' => str_repeat('x', 10001)])->assertUnprocessable();
        $this->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'draft', 'content' => "Te\0xto"])->assertUnprocessable();
    }

    public function test_private_drafts_hidden_and_inactive_authors_are_not_public_even_when_followed(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $moderator = $this->member('moderator');
        $private = $this->published($owner, ['visibility' => 'private', 'content' => 'Privado']);
        $draft = $this->posts->create($owner, ['content' => 'Borrador']);
        $hidden = $this->published($owner, ['content' => 'Oculto']);
        $this->posts->moderate($moderator, $hidden->id, true, $hidden->version);
        $this->interactions->follow($other, $owner->id);
        $this->actingAs($other)->get('/comunidad?feed=following')->assertDontSee('Privado')->assertDontSee('Borrador')->assertDontSee('Oculto');
        foreach ([$private, $draft, $hidden] as $post) {
            $this->get('/comunidad/publicaciones/'.$post->id)->assertNotFound();
        }
        $this->actingAs($owner)->get('/comunidad?feed=mine')->assertSee('Privado')->assertSee('Borrador')->assertSee('Oculto');
        $this->actingAs($moderator)->get('/comunidad?feed=moderation')->assertSee('Privado')->assertSee('Borrador')->assertSee('Oculto');
        $this->actingAs($other)->get('/comunidad?feed=moderation')->assertForbidden();
        $public = $this->published($owner, ['content' => 'Autor inactivo']);
        DB::table('users')->where('id', $owner->id)->update(['status' => 'suspended']);
        $this->get('/comunidad')->assertDontSee('Autor inactivo');
        $this->get('/comunidad/publicaciones/'.$public->id)->assertNotFound();
    }

    public function test_moderation_requires_existing_permission_and_preserves_post_versions(): void
    {
        $owner = $this->member();
        $moderator = $this->member('moderator');
        $post = $this->published($owner);
        $this->actingAs($owner)->postJson('/comunidad/publicaciones/'.$post->id.'/moderacion', ['reason' => 'Decisión sintética de moderación.', 'version' => 2, 'action' => 'hide'])->assertForbidden();
        $this->actingAs($moderator)->postJson('/comunidad/publicaciones/'.$post->id.'/moderacion', ['reason' => 'Decisión sintética de moderación.', 'version' => 2, 'action' => 'hide'])->assertOk()->assertJsonPath('version', 3);
        $this->postJson('/comunidad/publicaciones/'.$post->id.'/moderacion', ['reason' => 'Decisión sintética de moderación.', 'version' => 2, 'action' => 'show'])->assertConflict();
        $this->postJson('/comunidad/publicaciones/'.$post->id.'/moderacion', ['reason' => 'Decisión sintética de moderación.', 'version' => 3, 'action' => 'show'])->assertOk();
        $this->assertSame(PostStatus::Published, $post->fresh()->status);
    }

    public function test_comments_and_single_level_replies_are_real_scoped_and_versioned(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $post = $this->published($owner);
        $root = $this->actingAs($other)->postJson('/comunidad/publicaciones/'.$post->id.'/comentarios', ['content' => 'Comentario real'])->assertCreated()->json('id');
        $reply = $this->postJson('/comunidad/publicaciones/'.$post->id.'/comentarios', ['content' => 'Respuesta real', 'parent_id' => $root])->assertCreated()->json('id');
        $this->postJson('/comunidad/publicaciones/'.$post->id.'/comentarios', ['content' => 'Nieto', 'parent_id' => $reply])->assertUnprocessable();
        $foreign = $this->published($owner);
        $this->postJson('/comunidad/publicaciones/'.$foreign->id.'/comentarios', ['content' => 'Cruce', 'parent_id' => $root])->assertUnprocessable();
        $this->putJson('/comunidad/comentarios/'.$root, ['content' => 'Edición', 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->putJson('/comunidad/comentarios/'.$root, ['content' => 'Obsoleto', 'version' => 1])->assertConflict();
        $this->get('/comunidad/comentarios/'.$root.'/editar')->assertOk()->assertSee('Edición');
        $this->actingAs($owner)->putJson('/comunidad/comentarios/'.$root, ['content' => 'Ajeno', 'version' => 2])->assertForbidden();
        $this->actingAs($other)->deleteJson('/comunidad/comentarios/'.$root, ['version' => 2])->assertOk();
        $this->deleteJson('/comunidad/comentarios/'.$root, ['version' => 2])->assertOk();
        $this->get('/comunidad/publicaciones/'.$post->id)->assertDontSee('Respuesta real')->assertViewHas('post', fn ($p) => $p['comments_count'] === 0);
    }

    public function test_comment_moderation_is_private_and_hidden_threads_can_be_reviewed_and_restored(): void
    {
        $owner = $this->member();
        $moderator = $this->member('moderator');
        $other = $this->member();
        $post = $this->published($owner);
        $root = $this->interactions->comment($owner, $post->id, ['content' => 'Raíz oculta']);
        $this->interactions->comment($other, $post->id, ['content' => 'Respuesta oculta', 'parent_id' => $root->id]);
        $this->actingAs($moderator)->postJson('/comunidad/comentarios/'.$root->id.'/moderacion', ['reason' => 'Decisión sintética de moderación.', 'version' => 1, 'action' => 'hide'])->assertOk();
        $this->actingAs($other)->get('/comunidad/publicaciones/'.$post->id)->assertDontSee('Raíz oculta')->assertDontSee('Respuesta oculta');
        $this->get('/comunidad/publicaciones/'.$post->id.'?review=1')->assertNotFound();
        $this->actingAs($moderator)->get('/comunidad/publicaciones/'.$post->id.'?review=1')->assertOk()->assertSee('Raíz oculta')->assertSee('Respuesta oculta');
        $this->postJson('/comunidad/comentarios/'.$root->id.'/moderacion', ['reason' => 'Decisión sintética de moderación.', 'version' => 2, 'action' => 'show'])->assertOk();
        $this->get('/comunidad/publicaciones/'.$post->id)->assertSee('Respuesta oculta');
    }

    public function test_comments_cannot_target_inaccessible_posts_or_forge_internal_relationships(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $private = $this->published($owner, ['visibility' => 'private']);
        $this->actingAs($other)->postJson('/comunidad/publicaciones/'.$private->id.'/comentarios', ['content' => 'No'])->assertForbidden();
        $public = $this->published($owner);
        $this->postJson('/comunidad/publicaciones/'.$public->id.'/comentarios', ['content' => 'No', 'user_id' => $owner->id])->assertUnprocessable();
        $this->postJson('/comunidad/publicaciones/'.$public->id.'/comentarios', ['content' => " \u{200B} "])->assertUnprocessable();
        $this->assertSame(0, Comment::count());
    }

    public function test_like_add_remove_and_current_state_are_idempotent_with_sql_counts(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $post = $this->published($owner);
        $this->actingAs($viewer)->putJson('/comunidad/publicaciones/'.$post->id.'/reaccion')->assertOk()->assertJsonPath('liked', true);
        $this->putJson('/comunidad/publicaciones/'.$post->id.'/reaccion')->assertOk();
        $this->get('/comunidad/publicaciones/'.$post->id)->assertViewHas('post', fn ($p) => $p['liked'] && $p['likes_count'] === 1);
        $this->putJson('/comunidad/publicaciones/'.$post->id.'/reaccion', ['type' => 'love'])->assertUnprocessable();
        $this->deleteJson('/comunidad/publicaciones/'.$post->id.'/reaccion')->assertOk()->assertJsonPath('liked', false);
        $this->deleteJson('/comunidad/publicaciones/'.$post->id.'/reaccion')->assertOk();
        $this->assertSame(0, PostReaction::count());
    }

    public function test_following_is_idempotent_private_and_does_not_change_access(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $post = $this->published($owner);
        $private = $this->published($owner, ['content' => 'No compartir', 'visibility' => 'private']);
        $this->actingAs($viewer)->putJson('/comunidad/usuarios/'.$owner->id.'/seguir')->assertOk();
        $this->putJson('/comunidad/usuarios/'.$owner->id.'/seguir')->assertOk();
        $this->assertSame(1, DB::table('user_follows')->count());
        $this->get('/comunidad?feed=following')->assertSee('Contenido real')->assertDontSee('No compartir')->assertViewHas('posts', fn ($p) => $p[0]['following']);
        $this->get('/comunidad/publicaciones/'.$private->id)->assertNotFound();
        $this->get('/comunidad/usuarios/'.$owner->id.'/conexiones')->assertNotFound();
        app(ProfileService::class)->saveUserProfile($owner, $owner->id, ['visibility' => 'public', 'display_name' => 'Público']);
        $this->get('/comunidad/usuarios/'.$owner->id.'/conexiones?followers=1')->assertOk()->assertViewHas('summary', fn ($s) => $s['followers'] === 1 && $s['is_following']);
        $this->putJson('/comunidad/usuarios/'.$viewer->id.'/seguir')->assertUnprocessable();
        DB::table('users')->where('id', $owner->id)->update(['status' => 'disabled']);
        $this->putJson('/comunidad/usuarios/'.$owner->id.'/seguir')->assertUnprocessable();
        $this->deleteJson('/comunidad/usuarios/'.$owner->id.'/seguir')->assertOk();
        $this->deleteJson('/comunidad/usuarios/'.$owner->id.'/seguir')->assertOk();
        $this->assertSame(0, DB::table('user_follows')->count());
    }

    public function test_connections_counts_and_profiles_exclude_inactive_or_private_identities(): void
    {
        $owner = $this->member();
        $private = $this->member();
        $inactive = $this->member();
        $this->interactions->follow($owner, $private->id);
        $this->interactions->follow($owner, $inactive->id);
        DB::table('users')->where('id', $inactive->id)->update(['status' => 'suspended']);
        $this->actingAs($owner)->get('/comunidad/usuarios/'.$owner->id.'/conexiones')->assertOk()->assertDontSee($private->email)->assertDontSee($private->name)->assertViewHas('summary', fn ($s) => $s['following'] === 1)->assertViewHas('page', fn ($p) => count($p) === 1);
        $this->get('/perfil')->assertOk()->assertSee(route('community.connections', $owner->id));
    }

    public function test_cursor_pagination_remains_stable_and_http_queries_are_bounded(): void
    {
        $owner = $this->member();
        $this->published($owner);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $small = $this->get('/comunidad')->assertOk();
        $smallCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 20; $i++) {
            $this->published($owner, ['content' => 'Publicación '.$i]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $large = $this->get('/comunidad')->assertOk();
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($smallCount, $largeCount);
        $this->assertLessThanOrEqual(15, $largeCount);
        $page = $large->viewData('page');
        $this->assertCount(20, $page);
        $ids = array_column($page->items(), 'id');
        $cursor = $page->nextCursor()->encode();
        $this->published($owner, ['content' => 'Nuevo posterior']);
        $next = $this->get('/comunidad?cursor='.urlencode($cursor))->assertOk()->viewData('page');
        $this->assertCount(1, $next);
        $this->assertSame([], array_intersect($ids, array_column($next->items(), 'id')));
    }

    public function test_comment_pagination_and_own_draft_cursors_work(): void
    {
        $owner = $this->member();
        $post = $this->published($owner);
        for ($i = 0; $i < 21; $i++) {
            $this->interactions->comment($owner, $post->id, ['content' => 'Comentario '.$i]);
            $this->posts->create($owner, ['content' => 'Borrador '.$i]);
        }
        $this->actingAs($owner);
        $response = $this->get('/comunidad/publicaciones/'.$post->id)->assertOk();
        $comments = $response->viewData('comments');
        $this->assertCount(20, $comments);
        $this->get('/comunidad/publicaciones/'.$post->id.'?comment_cursor='.$comments->nextCursor()->encode())->assertOk()->assertViewHas('comments', fn ($p) => count($p) === 1);
        $mine = $this->get('/comunidad?feed=mine')->assertOk()->viewData('page');
        $this->get('/comunidad?feed=mine&cursor='.$mine->nextCursor()->encode())->assertOk()->assertViewHas('page', fn ($p) => count($p) === 2);
        $this->get('/comunidad?cursor=bad')->assertUnprocessable()->assertSee('Revisa los filtros');
        $this->get('/comunidad?category_id[]=bad')->assertUnprocessable();
        $this->get('/comunidad?category_id=-1')->assertUnprocessable();
    }

    #[DataProvider('inactiveStates')]
    public function test_persisted_inactive_state_blocks_stale_sessions(string $status): void
    {
        $owner = $this->member();
        $post = $this->published($owner);
        DB::table('users')->where('id', $owner->id)->update(['status' => $status]);
        $this->actingAs($owner)->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'draft', 'content' => 'No'])->assertUnauthorized();
        $this->get('/comunidad')->assertOk()->assertDontSee($post->content);
    }

    public static function inactiveStates(): array
    {
        return [['suspended'], ['disabled'], ['pending_deletion']];
    }

    public function test_mutations_enforce_csrf(): void
    {
        $owner = $this->member();
        $post = $this->published($owner);
        $comment = $this->interactions->comment($owner, $post->id, ['content' => 'Comentario']);
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $this->actingAs($owner);
        foreach ([['post', '/comunidad/publicaciones'], ['put', '/comunidad/publicaciones/'.$post->id], ['delete', '/comunidad/publicaciones/'.$post->id], ['post', '/comunidad/publicaciones/'.$post->id.'/publicar'], ['post', '/comunidad/publicaciones/'.$post->id.'/moderacion'], ['post', '/comunidad/publicaciones/'.$post->id.'/comentarios'], ['put', '/comunidad/comentarios/'.$comment->id], ['delete', '/comunidad/comentarios/'.$comment->id], ['post', '/comunidad/comentarios/'.$comment->id.'/moderacion'], ['put', '/comunidad/publicaciones/'.$post->id.'/reaccion'], ['delete', '/comunidad/publicaciones/'.$post->id.'/reaccion'], ['put', '/comunidad/usuarios/'.$owner->id.'/seguir'], ['delete', '/comunidad/usuarios/'.$owner->id.'/seguir']] as [$method,$url]) {
            $this->$method($url)->assertStatus(419);
        }
    }

    public function test_sql_read_errors_and_domain_failures_are_sanitized_even_in_debug(): void
    {
        config(['app.debug' => true]);
        $this->mock(CommunityQuery::class)->shouldReceive('recent')->once()->andThrow(new QueryException('mysql', 'SELECT secret FROM credentials', [], new \PDOException('private SQL detail')));
        $this->get('/comunidad')->assertStatus(503)->assertDontSee('credentials')->assertDontSee('private SQL detail')->assertDontSee('Stack trace');
    }

    public function test_failed_service_write_does_not_expose_internal_errors(): void
    {
        $owner = $this->member();
        $this->mock(PostService::class)->shouldReceive('create')->once()->andThrow(new CommunityException);
        $this->actingAs($owner)->postJson('/comunidad/publicaciones', ['visibility' => 'public', 'intent' => 'draft', 'content' => 'No guardar'])->assertStatus(503)->assertJsonStructure(['message']);
        $this->assertSame(0, Post::count());
    }

    public function test_post_rate_limit_prevents_an_additional_creation(): void
    {
        $owner = $this->member();
        $this->actingAs($owner);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/comunidad/publicaciones', ['visibility' => 'private', 'intent' => 'draft'])->assertCreated();
        }
        $this->postJson('/comunidad/publicaciones', ['visibility' => 'private', 'intent' => 'draft'])->assertStatus(429);
        $this->assertSame(10, Post::count());
    }

    public function test_connections_use_cursor_pagination_and_only_expose_public_identity(): void
    {
        $owner = $this->member();
        for ($i = 0; $i < 21; $i++) {
            $target = $this->member();
            $this->interactions->follow($owner, $target->id);
        }
        $page = $this->actingAs($owner)->get('/comunidad/usuarios/'.$owner->id.'/conexiones')->assertOk()->viewData('page');
        $this->assertCount(20, $page);
        $this->get('/comunidad/usuarios/'.$owner->id.'/conexiones?cursor='.$page->nextCursor()->encode())
            ->assertOk()->assertViewHas('page', fn ($p) => count($p) === 1)->assertDontSee($target->name)->assertDontSee($target->email);
    }

    public function test_read_and_interaction_limits_apply_without_mutating_counters(): void
    {
        $owner = $this->member();
        $post = $this->published($owner);
        $this->actingAs($owner);
        for ($i = 0; $i < 60; $i++) {
            $this->putJson('/comunidad/publicaciones/'.$post->id.'/reaccion')->assertOk();
        }
        $this->deleteJson('/comunidad/publicaciones/'.$post->id.'/reaccion')->assertStatus(429);
        $this->assertSame(1, PostReaction::count());
        for ($i = 0; $i < 120; $i++) {
            $this->get('/comunidad')->assertOk();
        }
        $this->get('/comunidad')->assertStatus(429);
    }

    public function test_validation_retry_preserves_original_post_and_comment_versions(): void
    {
        $owner = $this->member();
        $post = $this->published($owner);
        $this->posts->update($owner, $post->id, ['content' => 'Edición concurrente confirmada'], 2);
        $this->actingAs($owner)->put('/comunidad/publicaciones/'.$post->id, [
            'content' => 'Mi texto anterior', 'visibility' => 'public', 'intent' => 'save', 'content_category_id' => -1, 'version' => 2,
        ])->assertRedirect(route('community.edit', $post->id))->assertSessionHas('_old_input.version', '2');
        $this->get('/comunidad/publicaciones/'.$post->id.'/editar')->assertOk()->assertSee('name="version" value="2"', false)->assertSee('Mi texto anterior');
        $this->put('/comunidad/publicaciones/'.$post->id, [
            'content' => 'Mi texto anterior', 'visibility' => 'public', 'intent' => 'save', 'version' => 2,
        ])->assertConflict();
        $this->assertSame('Edición concurrente confirmada', $post->fresh()->content);

        $comment = $this->interactions->comment($owner, $post->id, ['content' => 'Original']);
        $this->interactions->editComment($owner, $comment->id, 'Comentario concurrente', 1);
        $this->put('/comunidad/comentarios/'.$comment->id, ['content' => ['invalid'], 'version' => true])
            ->assertRedirect(route('community.comments.edit', $comment->id))->assertSessionHas('_old_input.version', '1');
        $this->get('/comunidad/comentarios/'.$comment->id.'/editar')->assertOk()->assertSee('name="version" value="1"', false);
        $this->put('/comunidad/comentarios/'.$comment->id, ['content' => 'No guardar', 'version' => 1])->assertConflict();
        $this->assertSame('Comentario concurrente', $comment->fresh()->content);
        $this->put('/comunidad/comentarios/'.$comment->id, ['content' => 'No guardar', 'version' => 'invalid'])->assertUnprocessable();
    }
}
