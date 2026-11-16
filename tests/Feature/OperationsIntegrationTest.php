<?php

namespace Tests\Feature;

use App\Models\OperationsEvent;
use App\Models\User;
use App\Services\Community\CommunityInteractionService;
use App\Services\Community\CommunityPresentation;
use App\Services\Community\CommunityQuery;
use App\Services\Community\PostService;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use App\Services\Operations\AccountOperations;
use App\Services\Operations\OperationsAccess;
use Database\Seeders\EditorialPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VerificationPermissionSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\EnforcedCsrfToken;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

class OperationsIntegrationTest extends TestCase
{
    use VerificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments');
        $this->assertStringContainsString('framework/testing/disks/attachments', str_replace('\\', '/', Storage::disk('attachments')->path('')));
        DB::beginTransaction();
        $this->seed([RolePermissionSeeder::class, EditorialPermissionSeeder::class, VerificationPermissionSeeder::class]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Event::forget('eloquent.creating: '.OperationsEvent::class);
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function operator(array $permissions = ['operations.accounts']): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        return $user;
    }

    private function version(User $actor, User $target): string
    {
        return $this->actingAs($actor)->get(route('operations.user', $target->id))->assertOk()->viewData('account')['version'];
    }

    private function state(User $target, string $version, string $next = 'suspended'): array
    {
        return ['version' => $version, 'status' => $next, 'reason' => 'Motivo sintético para esta operación.'];
    }

    private function grant(User $target, string $version, string $permission = 'articles.create', string $action = 'grant'): array
    {
        return ['version' => $version, 'permission' => $permission, 'action' => $action, 'reason' => 'Motivo sintético para esta concesión.'];
    }

    public function test_guest_member_and_admin_role_do_not_gain_account_administration(): void
    {
        $this->get(route('operations.index'))->assertRedirect(route('login'));
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('operations.index'))->assertForbidden();
        $this->get('/inicio')->assertDontSee('>Operaciones<', false);
        $this->get('/perfil')->assertOk()->assertDontSee('Acceder a Operaciones');
        $user->assignRole('admin');
        $this->actingAs($user)->get(route('operations.users'))->assertForbidden();
        $this->get(route('operations.index'))->assertOk()->assertSee('Moderación de Comunidad')->assertDontSee('Usuarios y permisos');
        $this->assertFalse(app(OperationsAccess::class)->can($user, 'operations.accounts'));
    }

    public function test_account_directory_is_bounded_filtered_and_contains_only_required_fields(): void
    {
        $actor = $this->operator();
        $target = User::factory()->create(['name' => '<script>Persona %_</script>']);
        $this->actingAs($actor)->get('/perfil')->assertOk()->assertSee('Acceder a Operaciones');
        $response = $this->actingAs($actor)->get(route('operations.users', ['q' => '%_']));
        $response->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>Persona', false)->assertDontSee($target->password);
        $this->assertSame(20, $response->viewData('users')->perPage());
        $this->assertSame(['id', 'name', 'email', 'status'], array_keys($response->viewData('users')->items()[0]));
        $this->getJson(route('operations.users', ['q' => str_repeat('a', 101)]))->assertUnprocessable();
        $this->getJson(route('operations.users', ['include' => 'password']))->assertUnprocessable();
    }

    public function test_state_changes_are_audited_idempotent_and_reject_stale_updates(): void
    {
        $actor = $this->operator(['operations.accounts', 'operations.audit']);
        $target = User::factory()->create();
        $version = $this->version($actor, $target);
        $url = route('operations.state', $target->id);
        $this->postJson($url, $this->state($target, $version))->assertOk()->assertJsonPath('changed', true);
        $this->assertSame('suspended', $target->fresh()->status);
        $this->postJson($url, $this->state($target, $version))->assertOk()->assertJsonPath('changed', false);
        $this->postJson($url, $this->state($target, $version, 'active'))->assertConflict();
        $event = OperationsEvent::where('target_user_id', $target->id)->sole();
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame(['status' => 'active'], $event->before_state);
        $this->assertSame(['status' => 'suspended'], $event->after_state);
        $this->get(route('operations.audit'))->assertOk()->assertSee('account_status_changed');
        $new = $this->version($actor, $target);
        $this->postJson($url, $this->state($target, $new, 'active'))->assertOk();
        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_self_operator_and_terminal_accounts_cannot_be_changed(): void
    {
        $actor = $this->operator();
        $other = $this->operator();
        $terminal = User::factory()->create(['status' => 'pending_deletion']);
        foreach ([$actor, $other, $terminal] as $target) {
            $version = $this->version($actor, $target);
            $this->postJson(route('operations.state', $target->id), $this->state($target, $version))->assertUnprocessable();
        }
        $this->assertSame(0, OperationsEvent::count());
    }

    public function test_same_second_state_round_trip_cannot_resurrect_an_old_version(): void
    {
        $this->freezeTime();
        $actor = $this->operator();
        $target = User::factory()->create();
        $original = $this->version($actor, $target);
        $url = route('operations.state', $target->id);
        $this->postJson($url, $this->state($target, $original))->assertOk();
        $this->postJson($url, $this->state($target, $this->version($actor, $target), 'active'))->assertOk();
        $this->assertNotSame($original, $this->version($actor, $target));
        $this->postJson($url, $this->state($target, $original, 'disabled'))->assertConflict();
        $this->assertSame('active', $target->fresh()->status);
        $this->assertSame(2, OperationsEvent::where('target_user_id', $target->id)->count());
    }

    public function test_permission_scope_and_self_escalation_are_enforced_on_the_server(): void
    {
        $actor = $this->operator(['operations.permissions.editorial']);
        $target = User::factory()->create();
        $version = $this->version($actor, $target);
        $url = route('operations.permission', $target->id);
        $this->postJson($url, $this->grant($target, $version, 'verification.review'))->assertForbidden();
        $this->postJson($url, $this->grant($target, $version, 'operations.accounts'))->assertUnprocessable();
        $this->postJson(route('operations.permission', $actor->id), $this->grant($actor, $this->version($actor, $actor)))->assertUnprocessable();
        $this->assertSame(0, OperationsEvent::count());
        $this->assertFalse($target->fresh()->can('verification.review'));
    }

    public function test_direct_permission_grants_revoke_only_the_selected_permission_and_are_idempotent(): void
    {
        $actor = $this->operator(['operations.permissions.editorial']);
        $target = $this->operator(['resources.create']);
        $version = $this->version($actor, $target);
        $url = route('operations.permission', $target->id);
        $this->postJson($url, $this->grant($target, $version))->assertOk()->assertJsonPath('changed', true);
        $this->postJson($url, $this->grant($target, $version))->assertOk()->assertJsonPath('changed', false);
        $this->postJson($url, $this->grant($target, $version, 'articles.manage'))->assertConflict();
        $this->postJson($url, $this->grant($target, $this->version($actor, $target), action: 'revoke'))->assertOk();
        $this->assertFalse($target->fresh()->can('articles.create'));
        $this->assertTrue($target->fresh()->can('resources.create'));
        $this->assertSame(2, OperationsEvent::where('target_user_id', $target->id)->count());
    }

    public function test_inherited_permission_cannot_be_falsely_reported_revoked(): void
    {
        $actor = $this->operator(['operations.permissions.moderation']);
        $target = User::factory()->create();
        $target->assignRole('moderator');
        $this->postJson(route('operations.permission', $target->id), $this->grant($target, $this->version($actor, $target), 'posts.moderate', 'revoke'))->assertUnprocessable();
        $this->assertTrue($target->fresh()->can('posts.moderate'));
        $this->assertSame(0, OperationsEvent::count());
    }

    public function test_administrative_and_review_duties_cannot_be_combined(): void
    {
        $actor = $this->operator(['operations.permissions.verification']);
        $operator = $this->operator(['operations.accounts']);
        $target = User::factory()->create();
        $this->postJson(route('operations.permission', $operator->id), $this->grant($operator, $this->version($actor, $operator), 'verification.review'))->assertUnprocessable();
        $this->postJson(route('operations.permission', $target->id), $this->grant($target, $this->version($actor, $target), 'verification.review'))->assertOk();
        $this->actingAs($target)->get(route('operations.index'))->assertForbidden();
        $actor->givePermissionTo('verification.review');
        $this->actingAs($actor)->get(route('operations.users'))->assertForbidden();
    }

    public function test_suspension_takes_effect_on_next_request_and_does_not_unlock_private_resources(): void
    {
        $actor = $this->operator();
        $target = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $conversation = app(ConversationService::class)->start($a, $b->id);
        $message = app(MessageService::class)->send($a, $conversation->id, ['content' => 'Contenido privado ajeno', 'client_token' => (string) Str::uuid()]);
        [$owner, $profile, $dossier, $evidence] = $this->submitted();
        $this->actingAs($actor)->getJson(route('messages.show', $conversation->id))->assertNotFound()->assertDontSee($message->content);
        $this->getJson(route('verification.download', [$dossier->id, $evidence->id]))->assertNotFound();
        $version = $this->version($actor, $target);
        $this->postJson(route('operations.state', $target->id), $this->state($target, $version))->assertOk();
        $this->actingAs($target)->getJson('/inicio')->assertUnauthorized();
    }

    public function test_account_and_permission_writes_roll_back_when_audit_insert_fails(): void
    {
        $actor = $this->operator(['operations.accounts', 'operations.permissions.editorial']);
        $target = User::factory()->create();
        $version = $this->version($actor, $target);
        Event::listen('eloquent.creating: '.OperationsEvent::class, function () {
            throw new QueryException('mysql', 'INSERT private-secret', ['binding-secret'], new \RuntimeException('synthetic'));
        });
        $this->postJson(route('operations.state', $target->id), $this->state($target, $version))->assertStatus(503)->assertDontSee('binding-secret');
        $this->assertSame('active', $target->fresh()->status);
        $this->postJson(route('operations.permission', $target->id), $this->grant($target, $version))->assertStatus(503)->assertDontSee('binding-secret');
        $this->assertFalse($target->fresh()->can('articles.create'));
        $this->assertSame(0, OperationsEvent::count());
    }

    public function test_csrf_strict_fields_and_rate_limiting_protect_operations(): void
    {
        $actor = $this->operator();
        $target = User::factory()->create();
        $version = $this->version($actor, $target);
        $url = route('operations.state', $target->id);
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $this->postJson($url, $this->state($target, $version))->assertStatus(419);
        $this->withSession(['_token' => 'ops-csrf']);
        $this->postJson($url, $this->state($target, $version) + ['_token' => 'ops-csrf', 'roles' => ['admin']])->assertUnprocessable();
        for ($i = 0; $i < 19; $i++) {
            $this->postJson($url, $this->state($target, $version) + ['_token' => 'ops-csrf'])->assertOk();
        }
        $this->postJson($url, $this->state($target, $version) + ['_token' => 'ops-csrf'])->assertStatus(429);
    }

    public function test_bootstrap_is_dry_by_default_requires_confirmation_and_only_happens_once(): void
    {
        $target = User::factory()->create();
        $args = ['user' => (string) $target->id, '--capability' => ['operations.accounts'], '--reason' => 'Primer operador sintético de pruebas.'];
        $this->artisan('operations:bootstrap', $args)->assertSuccessful();
        $this->assertSame(0, OperationsEvent::count());
        $this->assertFalse(app(OperationsAccess::class)->can($target, 'operations.accounts'));
        $this->artisan('operations:bootstrap', $args + ['--apply' => true])->assertFailed();
        $this->artisan('operations:bootstrap', $args + ['--apply' => true, '--confirm-user' => (string) $target->id])->assertSuccessful();
        $this->assertTrue(app(OperationsAccess::class)->can($target->fresh(), 'operations.accounts'));
        $this->assertNull(OperationsEvent::sole()->actor_id);
        $this->assertSame('console', OperationsEvent::sole()->source);
        $this->artisan('operations:bootstrap', $args + ['--apply' => true, '--confirm-user' => (string) $target->id])->assertFailed();
        $this->seed([RolePermissionSeeder::class, EditorialPermissionSeeder::class, VerificationPermissionSeeder::class]);
        $this->assertFalse(Role::findByName('admin')->hasPermissionTo('operations.accounts'));
        $this->assertFalse($target->fresh()->can('verification.review'));
    }

    public function test_audit_models_refuse_quiet_mutations_and_deletion(): void
    {
        $actor = $this->operator();
        $target = User::factory()->create();
        app(AccountOperations::class)->state($actor, $target->id, 'disabled', $this->version($actor, $target), 'Motivo sintético de desactivación.');
        $event = OperationsEvent::sole();
        foreach ([fn () => $event->forceFill(['reason' => 'tampered'])->saveQuietly(), fn () => $event->deleteQuietly(), fn () => $event->increment('id')] as $mutation) {
            try {
                $mutation();
                $this->fail('La auditoría permitió una mutación.');
            } catch (\LogicException) {
                $this->assertDatabaseHas('operations_events', ['id' => $event->id, 'reason' => 'Motivo sintético de desactivación.']);
            }
        }
    }

    public function test_moderation_reuses_domain_versions_requires_reason_and_records_both_resources(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('user');
        $moderator = $this->operator(['posts.moderate', 'comments.moderate']);
        $post = app(PostService::class)->create($owner, ['content' => 'Publicación para moderar', 'visibility' => 'public']);
        $post = app(PostService::class)->publish($owner, $post->id, $post->version);
        $comment = app(CommunityInteractionService::class)->comment($owner, $post->id, ['content' => 'Comentario para moderar']);
        $this->actingAs($moderator)->get(route('operations.moderation'))->assertOk()->assertSee('Publicación para moderar');
        $url = route('community.comments.moderate', $comment->id);
        $payload = ['version' => $comment->version, 'action' => 'hide', 'reason' => 'Ocultación sintética de prueba.'];
        $this->postJson($url, array_diff_key($payload, ['reason' => true]))->assertUnprocessable();
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertConflict();
        $this->postJson(route('community.posts.moderate', $post->id), ['version' => $post->version, 'action' => 'hide', 'reason' => $payload['reason']])->assertOk();
        $this->assertSame(2, OperationsEvent::where('action', 'content_moderated')->count());
        $this->assertDatabaseHas('operations_events', ['resource_type' => 'comment', 'resource_id' => $comment->id, 'actor_id' => $moderator->id, 'target_user_id' => $owner->id]);
        $this->get(route('operations.users'))->assertForbidden();
        $this->get(route('operations.audit'))->assertForbidden();
    }

    public function test_moderation_rolls_back_if_its_audit_fails(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('user');
        $post = app(PostService::class)->create($owner, ['content' => 'Prueba atómica de moderación', 'visibility' => 'public']);
        $post = app(PostService::class)->publish($owner, $post->id, $post->version);
        $moderator = $this->operator(['posts.moderate']);
        Event::listen('eloquent.creating: '.OperationsEvent::class, fn () => throw new QueryException('mysql', 'INSERT confidential', [], new \RuntimeException('synthetic')));
        $this->actingAs($moderator)->postJson(route('community.posts.moderate', $post->id), ['version' => $post->version, 'action' => 'hide', 'reason' => 'Motivo sintético de ocultación.'])->assertStatus(503);
        $this->assertSame('published', $post->fresh()->status->value);
        $this->assertSame($post->version, $post->fresh()->version);
    }

    private function moderationPage(int $postCount = 1, int $commentCount = 0): array
    {
        $owner = User::factory()->create();
        $owner->assignRole('user');
        $moderator = $this->operator(['posts.moderate', 'comments.moderate']);
        $posts = [];
        for ($i = 0; $i < $postCount; $i++) {
            $post = app(PostService::class)->create($owner, ['content' => 'Publicación sintética '.$i, 'visibility' => 'public']);
            $posts[] = app(PostService::class)->publish($owner, $post->id, $post->version);
        }
        $comments = [];
        for ($i = 0; $i < $commentCount; $i++) {
            $comments[] = app(CommunityInteractionService::class)->comment($owner, $posts[0]->id, ['content' => 'Comentario sintético '.$i]);
        }
        $this->actingAs($moderator);

        return [$posts, $comments, $moderator];
    }

    private function moderationDom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $element) {
            $ids[] = $element->getAttribute('id');
        }
        $this->assertSame(count($ids), count(array_unique($ids)), 'La página renderizada contiene IDs repetidos.');
        $this->assertSame(0, $xpath->query('//textarea[@name="reason" and not(ancestor::form[contains(@action,"/moderacion")])]')->length);

        return $xpath;
    }

    private function assertModerationForm(DOMXPath $xpath, string $url, string $suffix, int $version, bool $invalid = false): DOMElement
    {
        $forms = $xpath->query('//form[@action="'.$url.'"]');
        $this->assertSame(1, $forms->length);
        $form = $forms->item(0);
        $this->assertSame('POST', strtoupper($form->getAttribute('method')));
        $fields = $xpath->query('.//*[@name="reason"]', $form);
        $this->assertSame(1, $fields->length, 'Cada formulario debe contener exactamente un motivo.');
        $field = $fields->item(0);
        $this->assertSame('textarea', $field->tagName);
        $this->assertSame('moderation-reason-'.$suffix, $field->getAttribute('id'));
        $this->assertTrue($field->hasAttribute('required'));
        $this->assertSame('10', $field->getAttribute('minlength'));
        $this->assertSame('2000', $field->getAttribute('maxlength'));
        $this->assertSame(1, $xpath->query('.//label[@for="'.$field->getAttribute('id').'"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="version" and @value="'.$version.'"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="action" and (@value="hide" or @value="show")]', $form)->length);
        $tokens = $xpath->query('.//input[@name="_token"]', $form);
        $this->assertSame(1, $tokens->length);
        $this->assertNotSame('', $tokens->item(0)->getAttribute('value'));
        foreach (explode(' ', $field->getAttribute('aria-describedby')) as $id) {
            $this->assertNotSame('', $id);
            $this->assertSame(1, $xpath->query('.//*[@id="'.$id.'"]', $form)->length);
        }
        if ($invalid) {
            $this->assertSame('true', $field->getAttribute('aria-invalid'));
            $errorId = 'moderation-error-'.$suffix;
            $this->assertContains($errorId, explode(' ', $field->getAttribute('aria-describedby')));
            $this->assertSame(1, $xpath->query('.//*[@id="'.$errorId.'" and @role="alert"]', $form)->length);
        } else {
            $this->assertFalse($field->hasAttribute('aria-invalid'));
        }

        return $form;
    }

    private function renderModerationCards(array $posts, User $moderator): string
    {
        $query = app(CommunityQuery::class);
        $presentation = app(CommunityPresentation::class);
        $cards = $presentation->posts(array_map(fn ($post) => $query->detail($post->id, $moderator), $posts), $moderator);

        return Blade::render('@foreach ($cards as $card)<x-community.post-card :post="$card" :detail="true" />@endforeach', ['cards' => $cards]);
    }

    public function test_authorized_moderation_card_renders_one_reason_inside_its_post_form(): void
    {
        [$posts, , $moderator] = $this->moderationPage();
        $xpath = $this->moderationDom($this->renderModerationCards($posts, $moderator));
        $this->assertModerationForm($xpath, route('community.posts.moderate', $posts[0]->id), 'post-'.$posts[0]->id, $posts[0]->version);
    }

    public function test_multiple_real_moderation_cards_keep_unique_ids_and_one_reason_per_form(): void
    {
        [$posts, , $moderator] = $this->moderationPage(postCount: 3);
        $xpath = $this->moderationDom($this->renderModerationCards($posts, $moderator));
        foreach ($posts as $post) {
            $this->assertModerationForm($xpath, route('community.posts.moderate', $post->id), 'post-'.$post->id, $post->version);
        }
        $this->assertSame(3, $xpath->query('//textarea[@name="reason"]')->length);
        // Actual feeds keep their existing card behavior: moderation is in detail.
        $feed = $this->get(route('community', ['feed' => 'moderation']))->assertOk();
        $feedDom = $this->moderationDom($feed->getContent());
        $this->assertSame(3, $feedDom->query('//article[contains(@class,"community-post ")]')->length);
        $this->assertSame(0, $feedDom->query('//textarea[@name="reason"]')->length);
    }

    public function test_post_detail_and_multiple_comments_render_independent_moderation_forms(): void
    {
        [$posts, $comments] = $this->moderationPage(commentCount: 2);
        $page = $this->get(route('community.show', ['post' => $posts[0]->id, 'review' => 1]))->assertOk();
        $xpath = $this->moderationDom($page->getContent());
        $this->assertModerationForm($xpath, route('community.posts.moderate', $posts[0]->id), 'post-'.$posts[0]->id, $posts[0]->version);
        foreach ($comments as $comment) {
            $this->assertModerationForm($xpath, route('community.comments.moderate', $comment->id), 'comment-'.$comment->id, $comment->version);
        }
        $this->assertSame(3, $xpath->query('//textarea[@name="reason"]')->length);
    }

    public function test_rendered_forms_submit_reason_version_action_and_csrf_to_existing_moderation_routes(): void
    {
        [$posts, $comments] = $this->moderationPage(commentCount: 1);
        $post = $posts[0];
        $comment = $comments[0];
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        foreach ([['comment', $comment], ['post', $post]] as [$type, $model]) {
            $xpath = $this->moderationDom($this->get(route('community.show', ['post' => $post->id, 'review' => 1]))->assertOk()->getContent());
            $url = route($type === 'post' ? 'community.posts.moderate' : 'community.comments.moderate', $model->id);
            $form = $this->assertModerationForm($xpath, $url, $type.'-'.$model->id, $model->version);
            $payload = [];
            foreach ($xpath->query('.//input[@name]', $form) as $input) {
                $payload[$input->getAttribute('name')] = $input->getAttribute('value');
            }
            $reason = 'Decisión desde formulario renderizado: '.$type;
            $payload['reason'] = $reason;
            $this->post($form->getAttribute('action'), $payload)->assertRedirect();
            $this->assertSame('hidden', $model->fresh()->status->value);
            $this->assertSame($model->version + 1, $model->fresh()->version);
            $this->assertDatabaseHas('operations_events', ['resource_type' => $type, 'resource_id' => $model->id, 'reason' => $reason]);
        }
    }

    public function test_reason_validation_errors_are_associated_only_with_the_submitted_moderation_form(): void
    {
        [$posts, $comments] = $this->moderationPage(commentCount: 2);
        $post = $posts[0];
        foreach ([['post', $post], ['comment', $comments[0]]] as [$type, $model]) {
            $url = route($type === 'post' ? 'community.posts.moderate' : 'community.comments.moderate', $model->id);
            $reason = '<script>';
            $redirect = $type === 'post' ? route('community.show', $post->id) : route('community.show', ['post' => $post->id, 'review' => 1]);
            $rejected = $this->post($url, ['version' => $model->version, 'action' => 'hide', 'reason' => $reason])->assertRedirect($redirect)->assertSessionHasErrors('reason');
            $page = $this->get($rejected->headers->get('Location'))->assertOk()->assertDontSee('<script>', false);
            $xpath = $this->moderationDom($page->getContent());
            $this->assertModerationForm($xpath, $url, $type.'-'.$model->id, $model->version, invalid: true);
            $this->assertSame(1, $xpath->query('//textarea[@name="reason" and @aria-invalid="true"]')->length);
            $this->assertSame($reason, $xpath->query('//*[@id="moderation-reason-'.$type.'-'.$model->id.'"]')->item(0)->textContent);
            if ($type === 'post') {
                $this->assertSame(1, $xpath->query('//details[@open]//textarea[@id="moderation-reason-post-'.$post->id.'"]')->length);
            }
            $this->assertSame($model->version, $model->fresh()->version);
            $this->assertSame(0, OperationsEvent::where('action', 'content_moderated')->count());
            $normal = $this->moderationDom($this->get($redirect)->assertOk()->getContent());
            $this->assertSame(0, $normal->query('//textarea[@name="reason" and @aria-invalid="true"]')->length);
        }
    }

    public function test_current_sql_grants_override_cached_role_relations_after_revocation(): void
    {
        $actor = $this->operator();
        $actor->load('permissions', 'roles.permissions');
        $target = User::factory()->create();
        $version = $this->version($actor, $target);
        DB::table('model_has_permissions')->where('model_type', $actor->getMorphClass())->where('model_id', $actor->id)->delete();
        $this->postJson(route('operations.state', $target->id), $this->state($target, $version))->assertForbidden();
        $this->assertSame('active', $target->fresh()->status);
        $this->assertSame(0, OperationsEvent::count());
    }

    public function test_inactive_targets_cannot_receive_grants_and_reviewers_cannot_bootstrap(): void
    {
        $actor = $this->operator(['operations.permissions.editorial']);
        $target = User::factory()->create(['status' => 'disabled']);
        $this->postJson(route('operations.permission', $target->id), $this->grant($target, $this->version($actor, $target)))->assertUnprocessable();
        $reviewer = $this->reviewer();
        // Existing operator or reviewer are independently incompatible with bootstrap.
        $this->artisan('operations:bootstrap', ['user' => (string) $reviewer->id, '--capability' => ['operations.audit'], '--reason' => 'Prueba de separación de funciones.', '--apply' => true, '--confirm-user' => (string) $reviewer->id])->assertFailed();
        $this->assertFalse(app(OperationsAccess::class)->can($reviewer, 'operations.audit'));
        $this->assertSame(0, OperationsEvent::count());
    }
}
