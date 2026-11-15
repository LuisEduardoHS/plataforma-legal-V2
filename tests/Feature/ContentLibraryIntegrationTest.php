<?php

namespace Tests\Feature;

use App\Enums\EditorialKind as Kind;
use App\Enums\EditorialStatus as Status;
use App\Enums\SavedContentType;
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
use App\Services\Community\PostService;
use App\Services\ContentLibrary\EditorialQuery;
use App\Services\ContentLibrary\EditorialService;
use App\Services\ContentLibrary\SavedItemService;
use App\Services\Profiles\ProfileService;
use Database\Seeders\EditorialPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\EnforcedCsrfToken;
use Tests\TestCase;

class ContentLibraryIntegrationTest extends TestCase
{
    private bool $started = false;

    protected function setUp(): void
    {
        parent::setUp(); // Checks environment, configured database AND SELECT DATABASE().
        DB::beginTransaction();
        $this->started = true;
        $this->seed([RolePermissionSeeder::class, EditorialPermissionSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        try {
            Event::forget('eloquent.creating: '.ContentRevision::class);
            DB::disableQueryLog();
            if ($this->started) {
                DB::rollBack();
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } finally {
            parent::tearDown();
        }
    }

    private function member(string $role = 'user', bool $writer = false): User
    {
        $user = User::factory()->create()->refresh();
        $user->assignRole($role);
        if ($writer) {
            $user->givePermissionTo(['articles.create', 'articles.update.own', 'articles.publish.own', 'resources.create', 'resources.update.own', 'resources.publish.own']);
        }

        return $user;
    }

    private function entry(User $owner, Kind $kind = Kind::Article, bool $published = true, array $data = []): EditorialEntry
    {
        $fields = $kind === Kind::Article ? ['body' => 'Cuerpo sintético de prueba', 'summary' => 'Resumen de prueba']
            : ['description' => 'Descripción sintética de prueba', 'resource_type' => 'guide', 'external_url' => 'https://www.unam.mx/'];
        $entry = app(EditorialService::class)->create($kind, $owner, $data + ['title' => 'Contenido sintético'] + $fields);

        return $published ? app(EditorialService::class)->transition($kind, $owner, $entry->id, Status::Published, $entry->version) : $entry;
    }

    public static function kinds(): array
    {
        return [['articulos', Kind::Article], ['recursos', Kind::Resource]];
    }

    #[DataProvider('kinds')]
    public function test_public_catalogs_and_details_hide_drafts_hidden_deleted_inactive_authors_and_private_metadata(string $path, Kind $kind): void
    {
        $owner = $this->member(writer: true);
        $public = $this->entry($owner, $kind, data: ['title' => 'Visible <script>alert(1)</script>']);
        $draft = $this->entry($owner, $kind, false, ['title' => 'Borrador secreto']);
        $hidden = $this->entry($owner, $kind, data: ['title' => 'Oculto secreto']);
        $owner->givePermissionTo($kind->permission().'.manage');
        app(EditorialService::class)->transition($kind, $owner, $hidden->id, Status::Hidden, $hidden->version);
        $deleted = $this->entry($owner, $kind, data: ['title' => 'Eliminado secreto']);
        app(EditorialService::class)->transition($kind, $owner, $deleted->id, Status::Deleted, $deleted->version);
        $inactive = $this->member(writer: true);
        $unavailable = $this->entry($inactive, $kind, data: ['title' => 'Autor suspendido']);
        $inactive->forceFill(['status' => 'suspended'])->save();
        $response = $this->get('/'.$path)->assertOk()->assertSee($public->title)->assertDontSee('Borrador secreto')->assertDontSee('Oculto secreto')->assertDontSee('Eliminado secreto')->assertDontSee('Autor suspendido')->assertDontSee($owner->email)->assertDontSee($owner->name);
        $this->assertCount(1, $response->viewData('page'));
        $this->get('/'.$path.'/'.$public->id)->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee($owner->email)->assertDontSee('Historial de revisiones');
        foreach ([$draft, $hidden, $deleted, $unavailable] as $entry) {
            $this->get('/'.$path.'/'.$entry->id)->assertNotFound();
        }
        $this->get('/'.$path.'/'.$public->id.'/historial')->assertRedirect(route('login'));
    }

    public function test_empty_catalog_library_and_home_never_present_demo_articles_as_published(): void
    {
        $this->get('/articulos')->assertOk()->assertSee('No hay artículos disponibles')->assertDontSee('Popular en este momento');
        $this->get('/recursos')->assertOk()->assertSee('No hay recursos disponibles');
        $this->get('/guardados')->assertRedirect(route('login'));
        $this->actingAs($this->member())->get('/guardados')->assertOk()->assertSee('No hay guardados disponibles')->assertSee('Vinculada a tu cuenta')->assertDontSee('En este dispositivo');
        $this->get('/inicio')->assertOk()->assertSee('No hay artículos publicados')->assertDontSee('¿Qué hacer si no recibes tu finiquito correctamente?');
    }

    public function test_public_head_requests_keep_read_authorization_and_validation_semantics(): void
    {
        $this->call('HEAD', '/articulos')->assertOk();
        $this->call('HEAD', '/recursos')->assertOk();
        $this->call('HEAD', '/articulos?cursor=bad')->assertUnprocessable();
    }

    #[DataProvider('kinds')]
    public function test_filters_use_existing_classification_literal_title_prefix_and_resource_type(string $path, Kind $kind): void
    {
        $owner = $this->member(writer: true);
        $category = ContentCategory::create(['name' => 'Prueba categoría', 'slug' => 'http-category', 'is_active' => true]);
        $tag = Tag::create(['name' => 'Prueba etiqueta', 'slug' => 'http-tag', 'is_active' => true]);
        $specialty = LegalSpecialty::create(['name' => 'Prueba especialidad', 'slug' => 'http-specialty', 'is_active' => true]);
        $entry = $this->entry($owner, $kind, data: ['title' => '100% literal', 'content_category_id' => $category->id, 'tag_ids' => [$tag->id], 'specialty_ids' => [$specialty->id]]);
        $this->entry($owner, $kind, data: ['title' => 'Sin clasificación']);
        $filters = ['title' => '100%', 'author_id' => $owner->id, 'category_id' => $category->id, 'tag_id' => $tag->id, 'specialty_id' => $specialty->id];
        if ($kind === Kind::Resource) {
            $filters['resource_type'] = 'guide';
        }
        $response = $this->get('/'.$path.'?'.http_build_query($filters))->assertOk();
        $this->assertSame([$entry->id], array_column($response->viewData('page')->items(), 'id'));
        $this->get('/'.$path.'?title=%25')->assertOk()->assertViewHas('page', fn ($p) => count($p) === 0);
        $this->get('/'.$path.'?cursor=invalid')->assertUnprocessable();
        $this->get('/'.$path.'?title[]=hostile')->assertUnprocessable();
        $this->get('/'.$path.'?owner_id=1')->assertUnprocessable();
    }

    #[DataProvider('kinds')]
    public function test_cursor_pages_are_stable_when_new_content_is_inserted(string $path, Kind $kind): void
    {
        $owner = $this->member(writer: true);
        for ($i = 0; $i < 21; $i++) {
            $this->entry($owner, $kind, data: ['title' => 'Paginado '.$i]);
        }
        $page = $this->get('/'.$path)->assertOk()->viewData('page');
        $this->assertCount(20, $page);
        $ids = array_column($page->items(), 'id');
        $this->entry($owner, $kind, data: ['title' => 'Posterior']);
        $next = $this->get('/'.$path.'?cursor='.$page->nextCursor()->encode())->assertOk()->viewData('page');
        $this->assertCount(1, $next);
        $this->assertSame([], array_intersect($ids, array_column($next->items(), 'id')));
    }

    public function test_editorial_permissions_remain_unassigned_for_members_professionals_and_admins(): void
    {
        foreach (['user', 'professional', 'admin'] as $role) {
            $actor = $this->member($role === 'professional' ? 'user' : $role);
            if ($role === 'professional') {
                app(ProfileService::class)->saveProfessionalProfile($actor, $actor->id, ['headline' => 'Profesional de testing']);
            }
            foreach (['articles', 'resources'] as $prefix) {
                foreach (['create', 'update.own', 'publish.own', 'manage'] as $permission) {
                    $this->assertFalse($actor->can($prefix.'.'.$permission));
                }
            }
            $this->actingAs($actor);
            foreach (['articulos', 'recursos'] as $path) {
                $this->get('/'.$path.'/crear')->assertForbidden();
                $this->postJson('/'.$path, ['title' => 'No permitido', 'intent' => 'save'] + ($path === 'recursos' ? ['resource_type' => 'guide'] : []))->assertForbidden();
            }
        }
        $this->assertSame(0, Article::count());
        $this->assertSame(0, LegalResource::count());
    }

    #[DataProvider('kinds')]
    public function test_authorized_html_forms_create_edit_publish_unpublish_archive_and_keep_history(string $path, Kind $kind): void
    {
        $writer = $this->member(writer: true);
        $this->actingAs($writer)->get('/'.$path.'/crear')->assertOk()->assertSee('Guardar borrador');
        $data = ['title' => 'Nuevo por HTTP', 'intent' => 'save'] + ($kind === Kind::Article ? ['body' => 'Texto plano <script>hostil</script>'] : ['description' => 'Recurso de testing', 'resource_type' => 'guide', 'external_url' => 'https://www.unam.mx/']);
        $created = $this->postJson('/'.$path, $data)->assertCreated()->assertJsonPath('status', 'draft');
        $id = $created->json('id');
        $this->get('/'.$path.'/gestion')->assertOk()->assertSee('Nuevo por HTTP');
        $this->get('/'.$path.'/'.$id.'/editar')->assertOk();
        $version = $this->putJson('/'.$path.'/'.$id, $data + ['version' => 1])->assertOk()->json('version');
        foreach (['published', 'draft', 'archived', 'draft'] as $target) {
            $version = $this->postJson('/'.$path.'/'.$id.'/estado', ['version' => $version, 'status' => $target])->assertOk()->assertJsonPath('status', $target)->json('version');
        }
        $this->get('/'.$path.'/'.$id.'/historial')->assertOk()->assertSee('Historial de revisiones')->assertViewHas('page', fn ($p) => count($p) === 6);
        $this->postJson('/'.$path.'/'.$id.'/estado', ['version' => $version, 'status' => 'deleted'])->assertOk();
        $this->get('/'.$path.'/'.$id)->assertNotFound();
        $this->get('/'.$path.'/'.$id.'/historial')->assertOk();
    }

    #[DataProvider('kinds')]
    public function test_other_authors_cannot_edit_audit_or_moderate_and_managers_need_explicit_grants(string $path, Kind $kind): void
    {
        $owner = $this->member(writer: true);
        $entry = $this->entry($owner, $kind);
        $other = $this->member(writer: true);
        $this->actingAs($other)->get('/'.$path.'/'.$entry->id.'/editar')->assertForbidden();
        $this->get('/'.$path.'/'.$entry->id.'/historial')->assertNotFound();
        $this->postJson('/'.$path.'/'.$entry->id.'/estado', ['version' => 2, 'status' => 'hidden'])->assertForbidden();
        $this->actingAs($owner)->postJson('/'.$path.'/'.$entry->id.'/estado', ['version' => 2, 'status' => 'hidden'])->assertForbidden();
        $other->givePermissionTo($kind->permission().'.manage');
        $this->actingAs($other)->postJson('/'.$path.'/'.$entry->id.'/estado', ['version' => 2, 'status' => 'hidden'])->assertOk();
        $this->get('/'.$path.'/gestion?all=1&status=hidden')->assertOk()->assertSee($entry->title);
        $this->get('/'.$path.'/'.$entry->id.'/historial')->assertOk();
        $other->revokePermissionTo($kind->permission().'.manage');
        $this->postJson('/'.$path.'/'.$entry->id.'/estado', ['version' => 3, 'status' => 'published'])->assertForbidden();
        $this->get('/'.$path.'/gestion?all=1')->assertForbidden();
        $this->get('/'.$path.'/'.$entry->id)->assertNotFound();
    }

    public function test_publish_permission_alone_does_not_allow_create_or_update_and_missing_publish_grant_rolls_back(): void
    {
        $actor = $this->member();
        $actor->givePermissionTo('articles.publish.own');
        $this->actingAs($actor)->postJson('/articulos', ['title' => 'No', 'body' => 'Texto', 'intent' => 'publish'])->assertForbidden();
        $actor->givePermissionTo('articles.create');
        $this->postJson('/articulos', ['title' => 'No', 'body' => 'Texto', 'intent' => 'publish'])->assertForbidden();
        $actor->givePermissionTo('articles.update.own');
        $id = $this->postJson('/articulos', ['title' => 'Sí', 'body' => 'Texto', 'intent' => 'publish'])->assertCreated()->assertJsonPath('status', 'published')->json('id');
        $actor->revokePermissionTo('articles.publish.own');
        $this->putJson('/articulos/'.$id, ['title' => 'Cambio denegado', 'body' => 'Texto', 'version' => 2, 'intent' => 'save'])->assertForbidden();
        $this->assertSame('Sí', Article::findOrFail($id)->title);
        $this->assertSame(2, ContentRevision::count());
    }

    public function test_stale_versions_conflict_and_invalid_html_does_not_upgrade_the_submitted_version(): void
    {
        $owner = $this->member(writer: true);
        $entry = $this->entry($owner, published: false);
        app(EditorialService::class)->update(Kind::Article, $owner, $entry->id, ['title' => 'Concurrente'], 1);
        $this->actingAs($owner)->put('/articulos/'.$entry->id, ['version' => 1, 'title' => '', 'body' => 'Mi texto anterior', 'intent' => 'save'])->assertRedirect(route('articles.edit', $entry->id))->assertSessionHasErrors('title')->assertSessionHasInput('version', '1');
        $this->get('/articulos/'.$entry->id.'/editar')->assertOk()->assertSee('name="version" value="1"', false)->assertSee('Mi texto anterior');
        $this->putJson('/articulos/'.$entry->id, ['version' => 1, 'title' => 'No sobreescribir', 'body' => 'Texto', 'intent' => 'save'])->assertConflict();
        $this->postJson('/articulos/'.$entry->id.'/estado', ['version' => 1, 'status' => 'published'])->assertConflict();
        $this->putJson('/articulos/'.$entry->id, ['version' => ['mal'], 'title' => 'No', 'intent' => 'save'])->assertUnprocessable();
        $this->assertSame('Concurrente', $entry->fresh()->title);
        $this->assertSame(2, $entry->fresh()->version);
    }

    public function test_classification_can_be_removed_and_existing_inactive_associations_survive_edits(): void
    {
        $owner = $this->member(writer: true);
        $tag = Tag::create(['name' => 'Histórica', 'slug' => 'http-historical', 'is_active' => true]);
        $entry = $this->entry($owner, published: false, data: ['tag_ids' => [$tag->id]]);
        $tag->update(['is_active' => false]);
        $this->actingAs($owner)->get('/articulos/'.$entry->id.'/editar')->assertOk()->assertSee('Histórica');
        $this->putJson('/articulos/'.$entry->id, ['title' => 'Conservar', 'intent' => 'save', 'version' => 1, 'tag_ids' => [$tag->id]])->assertOk();
        $this->putJson('/articulos/'.$entry->id, ['title' => 'Retirar', 'intent' => 'save', 'version' => 2])->assertOk();
        $this->assertCount(0, $entry->fresh()->tags);
        $this->putJson('/articulos/'.$entry->id, ['title' => 'No reponer', 'intent' => 'save', 'version' => 3, 'tag_ids' => [$tag->id]])->assertUnprocessable();
        $this->get('/articulos/'.$entry->id.'/historial')->assertOk()->assertSee('Histórica');
    }

    public function test_all_three_saved_types_are_private_persistent_idempotent_and_use_real_community_buttons(): void
    {
        $owner = $this->member(writer: true);
        $article = $this->entry($owner);
        $resource = $this->entry($owner, Kind::Resource);
        $post = app(PostService::class)->create($owner, ['content' => 'Guardable de Comunidad', 'visibility' => 'public']);
        $post = app(PostService::class)->publish($owner, $post->id, $post->version);
        $viewer = $this->member();
        $other = $this->member();
        $this->actingAs($viewer)->get('/comunidad')->assertOk()->assertSee('data-save-type="post"', false)->assertSee('Guardar contenido');
        foreach (['post' => $post, 'article' => $article, 'resource' => $resource] as $type => $entry) {
            $this->putJson('/guardados/'.$type.'/'.$entry->id)->assertOk()->assertJsonPath('saved', true);
            $first = SavedItem::where('user_id', $viewer->id)->where(SavedContentType::from($type)->key(), $entry->id)->firstOrFail();
            $this->putJson('/guardados/'.$type.'/'.$entry->id)->assertOk();
            $this->assertSame($first->id, SavedItem::where('user_id', $viewer->id)->where(SavedContentType::from($type)->key(), $entry->id)->sole()->id);
            $this->assertSame($first->created_at->toDateTimeString(), $first->fresh()->created_at->toDateTimeString());
            $this->get('/guardados?type='.$type)->assertOk()->assertViewHas('page', fn ($p) => count($p) === 1);
        }
        $this->actingAs($viewer->fresh())->get('/guardados')->assertOk()->assertViewHas('page', fn ($p) => count($p) === 3);
        $this->get('/articulos/'.$article->id)->assertOk()->assertSee('aria-pressed="true"', false);
        $this->get('/comunidad/publicaciones/'.$post->id)->assertOk()->assertSee('Quitar de Guardados');
        $this->actingAs($other)->get('/guardados')->assertOk()->assertViewHas('page', fn ($p) => count($p) === 0);
        $this->get('/guardados?user_id='.$viewer->id)->assertUnprocessable();
        $this->deleteJson('/guardados/article/'.$article->id)->assertOk();
        $this->assertSame(3, SavedItem::where('user_id', $viewer->id)->count());
        $this->actingAs($viewer)->deleteJson('/guardados/article/'.$article->id)->assertOk()->assertJsonPath('saved', false);
        $this->deleteJson('/guardados/article/'.$article->id)->assertOk();
        $this->assertSame(2, SavedItem::where('user_id', $viewer->id)->count());
    }

    public function test_saved_visibility_is_rechecked_after_unpublish_inactive_author_private_post_and_revoked_moderation(): void
    {
        $owner = $this->member(writer: true);
        $article = $this->entry($owner);
        $resource = $this->entry($owner, Kind::Resource);
        $post = app(PostService::class)->create($owner, ['content' => 'Privado posterior', 'visibility' => 'public']);
        $post = app(PostService::class)->publish($owner, $post->id, $post->version);
        $viewer = $this->member('admin');
        $this->actingAs($viewer);
        foreach (['article' => $article, 'resource' => $resource, 'post' => $post] as $type => $entry) {
            $this->putJson('/guardados/'.$type.'/'.$entry->id)->assertOk();
        }
        app(EditorialService::class)->transition(Kind::Article, $owner, $article->id, Status::Draft, 2);
        app(PostService::class)->update($owner, $post->id, ['visibility' => 'private'], 2);
        $this->get('/guardados')->assertOk()->assertViewHas('page', fn ($p) => count($p) === 2);
        $viewer->removeRole('admin');
        $this->get('/guardados')->assertOk()->assertViewHas('page', fn ($p) => count($p) === 1);
        $this->get('/comunidad/publicaciones/'.$post->id)->assertNotFound();
        $this->putJson('/guardados/article/'.$article->id)->assertNotFound();
        $owner->forceFill(['status' => 'disabled'])->save();
        $this->get('/guardados')->assertOk()->assertViewHas('page', fn ($p) => count($p) === 0);
        $this->deleteJson('/guardados/resource/'.$resource->id)->assertOk();
        $this->assertSame(2, SavedItem::where('user_id', $viewer->id)->count());
    }

    public function test_library_cursor_is_stable_and_saving_drafts_is_denied_even_for_the_author(): void
    {
        $owner = $this->member(writer: true);
        $this->actingAs($owner);
        $draft = $this->entry($owner, published: false);
        $this->putJson('/guardados/article/'.$draft->id)->assertNotFound();
        for ($i = 0; $i < 21; $i++) {
            $entry = $this->entry($owner);
            app(SavedItemService::class)->save($owner, SavedContentType::Article, $entry->id);
        }
        $page = $this->get('/guardados?type=article')->assertOk()->viewData('page');
        $this->assertCount(20, $page);
        $next = $this->get('/guardados?type=article&cursor='.$page->nextCursor()->encode())->assertOk()->viewData('page');
        $this->assertCount(1, $next);
        $this->assertSame([], array_intersect(array_column($page->items(), 'id'), array_column($next->items(), 'id')));
        $this->get('/guardados?cursor=bad')->assertUnprocessable();
        $this->get('/guardados?type=unknown')->assertUnprocessable();
    }

    public function test_malicious_fields_and_unsafe_external_links_do_not_mutate_content(): void
    {
        // Exercise validation independently of the separate limiter regression.
        RateLimiter::for('content.upload', fn () => Limit::none());
        $owner = $this->member(writer: true);
        $this->actingAs($owner);
        foreach ([['user_id' => $owner->id], ['status' => 'published'], ['roles' => ['admin']], ['slug' => 'override'], ['attachment_ids' => ['1 OR 1=1']], ['tag_ids' => [1, 1]], ['title' => ['array']]] as $extra) {
            $this->postJson('/articulos', $extra + ['title' => 'Seguro', 'intent' => 'save'])->assertUnprocessable();
        }
        foreach (['javascript:alert(1)', 'http://www.unam.mx/', 'https://user:pass@www.unam.mx/', 'https://127.0.0.1/', 'https://localhost/', 'https://www.unam.mx:444/'] as $url) {
            $this->postJson('/recursos', ['title' => 'Rechazado', 'description' => 'Texto', 'intent' => 'publish', 'resource_type' => 'guide', 'external_url' => $url])->assertUnprocessable();
        }
        $this->assertSame(0, Article::count());
        $this->assertSame(0, LegalResource::count());
        $this->putJson('/guardados/article/1', ['owner_id' => 1])->assertUnprocessable();
        $this->putJson('/guardados/unknown/1')->assertNotFound();
    }

    public function test_institutional_links_are_labeled_without_certification_and_require_configured_hosts(): void
    {
        $owner = $this->member(writer: true);
        $this->actingAs($owner)->postJson('/recursos', ['title' => 'Host no admitido', 'intent' => 'publish', 'resource_type' => 'institutional_link', 'description' => 'Texto', 'external_url' => 'https://example.com/'])->assertUnprocessable();
        $id = $this->postJson('/recursos', ['title' => 'Prueba de enlace', 'intent' => 'publish', 'resource_type' => 'institutional_link', 'description' => 'Texto sintético', 'external_url' => 'https://www.unam.mx/'])->assertCreated()->json('id');
        auth()->logout();
        $this->get('/recursos/'.$id)->assertOk()->assertSee('Recurso aportado por usuario')->assertSee('rel="noopener noreferrer nofollow"', false)->assertSee('no certifica')->assertSee('Enlace institucional');
    }

    public static function inactiveStates(): array
    {
        return [['suspended'], ['disabled'], ['pending_deletion']];
    }

    #[DataProvider('inactiveStates')]
    public function test_persisted_account_state_invalidates_stale_editorial_and_saved_sessions(string $state): void
    {
        $owner = $this->member(writer: true);
        $entry = $this->entry($owner);
        DB::table('users')->where('id', $owner->id)->update(['status' => $state]);
        $this->actingAs($owner)->putJson('/guardados/article/'.$entry->id)->assertUnauthorized();
        $this->actingAs($owner)->postJson('/articulos', ['title' => 'No', 'intent' => 'save'])->assertUnauthorized();
        $this->actingAs($owner)->get('/guardados')->assertRedirect(route('login'));
    }

    public function test_editorial_and_saved_writes_enforce_csrf_and_guest_authentication(): void
    {
        foreach (['articulos', 'recursos'] as $path) {
            $this->postJson('/'.$path, ['title' => 'No', 'intent' => 'save'])->assertUnauthorized();
        }
        $this->putJson('/guardados/article/1')->assertUnauthorized();
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $this->actingAs($this->member(writer: true));
        foreach ([['post', '/articulos'], ['put', '/articulos/1'], ['post', '/articulos/1/estado'], ['post', '/recursos'], ['put', '/recursos/1'], ['post', '/recursos/1/estado'], ['put', '/guardados/article/1'], ['delete', '/guardados/post/1']] as [$method, $url]) {
            $this->$method($url)->assertStatus(419);
        }
    }

    public function test_rate_limit_bindings_protect_reads_uploads_transitions_and_saved_writes(): void
    {
        RateLimiter::for('content.read', fn () => Limit::perMinute(1)->by('content-read-test'));
        $this->get('/articulos')->assertOk();
        $this->get('/recursos')->assertStatus(429);
        $owner = $this->member(writer: true);
        $entry = $this->entry($owner);
        $this->actingAs($owner);
        RateLimiter::for('content.upload', fn () => Limit::perMinute(1)->by('content-upload-test'));
        $this->postJson('/articulos', ['title' => 'Primero', 'intent' => 'save'])->assertCreated();
        $this->postJson('/articulos', ['title' => 'Limitado', 'intent' => 'save'])->assertStatus(429);
        RateLimiter::for('content.write', fn () => Limit::perMinute(1)->by('content-write-test'));
        $this->putJson('/guardados/article/'.$entry->id)->assertOk();
        $this->deleteJson('/guardados/article/'.$entry->id)->assertStatus(429);
        $this->postJson('/articulos/'.$entry->id.'/estado', ['status' => 'draft', 'version' => 2])->assertStatus(429);
    }

    public function test_sql_read_errors_are_sanitized_in_debug_and_failed_revision_writes_roll_back(): void
    {
        config(['app.debug' => true]);
        $this->mock(EditorialQuery::class)->shouldReceive('recent')->once()->andThrow(new QueryException('mysql', 'SELECT secret FROM credentials', [], new \PDOException('private SQL detail')));
        $this->get('/articulos')->assertStatus(503)->assertDontSee('credentials')->assertDontSee('private SQL detail')->assertDontSee('Stack trace');
        $owner = $this->member(writer: true);
        Event::listen('eloquent.creating: '.ContentRevision::class, fn () => throw new QueryException('mysql', 'INSERT private_revision', [], new \PDOException('private binding')));
        $this->actingAs($owner)->postJson('/articulos', ['title' => 'Revertido', 'body' => 'Texto', 'intent' => 'publish'])->assertStatus(503)->assertDontSee('private_revision')->assertDontSee('private binding');
        $this->assertSame(0, Article::count());
        $this->assertSame(0, ContentRevision::count());
    }

    public function test_domain_failures_return_safe_errors_without_model_serialization(): void
    {
        $owner = $this->member(writer: true);
        $this->mock(EditorialService::class)->shouldReceive('create')->once()->andThrow(new ContentLibraryException);
        $this->actingAs($owner)->postJson('/articulos', ['title' => 'No guardar', 'intent' => 'save'])->assertStatus(503)->assertJsonStructure(['message'])->assertDontSee($owner->email);
        $this->assertSame(0, Article::count());
    }

    public function test_native_html_submission_redirects_and_failed_publication_keeps_unchecked_groups_empty(): void
    {
        $owner = $this->member(writer: true);
        $tag = Tag::create(['name' => 'Etiqueta de formulario', 'slug' => 'native-http-tag', 'is_active' => true]);
        $this->actingAs($owner)->post('/articulos', ['title' => 'Formulario nativo', 'intent' => 'save', 'tag_ids' => [$tag->id]])->assertRedirect();
        $entry = Article::where('user_id', $owner->id)->sole();
        $this->from(route('articles.edit', $entry->id))->put('/articulos/'.$entry->id, ['title' => 'Sin cuerpo', 'intent' => 'publish', 'version' => 1])->assertRedirect()->assertSessionHasErrors('body')->assertSessionHasInput('tag_ids', []);
        $this->get('/articulos/'.$entry->id.'/editar')->assertOk()->assertSee('value="'.$tag->id.'" >', false);
        $this->assertSame(1, $entry->fresh()->version);
        $this->assertCount(1, $entry->fresh()->tags);
        $this->put('/articulos/'.$entry->id, ['title' => 'Publicado nativo', 'body' => 'Texto', 'intent' => 'publish', 'version' => 1])->assertRedirect(route('articles.show', $entry->id))->assertSessionHas('status');
        $this->assertSame(Status::Published, $entry->fresh()->status);
        $this->assertCount(0, $entry->fresh()->tags);
    }

    public function test_history_has_cursor_pagination_and_private_attachments_are_not_links_in_snapshots(): void
    {
        $owner = $this->member(writer: true);
        $entry = $this->entry($owner, published: false);
        for ($version = 1; $version < 22; $version++) {
            app(EditorialService::class)->update(Kind::Article, $owner, $entry->id, ['title' => 'Revisión '.$version], $version);
        }
        $page = $this->actingAs($owner)->get('/articulos/'.$entry->id.'/historial')->assertOk()->viewData('page');
        $this->assertCount(20, $page);
        $next = $this->get('/articulos/'.$entry->id.'/historial?cursor='.$page->nextCursor()->encode())->assertOk()->viewData('page');
        $this->assertCount(2, $next);
        $this->assertSame([], array_intersect(array_column($page->items(), 'id'), array_column($next->items(), 'id')));
        $this->actingAs($this->member())->get('/articulos/'.$entry->id.'/historial')->assertNotFound();
    }

    public function test_authenticated_catalog_queries_remain_bounded_and_home_uses_published_content_only(): void
    {
        $owner = $this->member(writer: true);
        $visible = $this->entry($owner);
        $this->actingAs($owner)->get('/inicio')->assertOk()->assertSee(route('articles.show', $visible->id))->assertSee('Artículo publicado');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/articulos')->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 5; $i++) {
            $this->entry($owner);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/articulos')->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(25, $large);
    }
}
