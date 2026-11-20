<?php

namespace Tests\Feature;

use App\Enums\EditorialKind;
use App\Enums\EditorialStatus;
use App\Models\User;
use App\Services\Community\CommunityInteractionService;
use App\Services\Community\CommunityQuery;
use App\Services\Community\PostService;
use App\Services\ContentLibrary\EditorialQuery;
use App\Services\ContentLibrary\EditorialService;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingQuery;
use App\Services\Profiles\ProfileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TestingIsolation;
use Tests\TestCase;

class BackendAuditConcurrencyTest extends TestCase
{
    private string $default;

    private ?Connection $reader = null;

    private array $users = [];

    private array $permissions = [];

    private array $roles = [];

    private User $owner;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->default = DB::getDefaultConnection();
        foreach (['posts.create', 'posts.update.own', 'posts.delete.own', 'posts.moderate', 'comments.create', 'articles.create', 'articles.update.own', 'articles.publish.own'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if ($permission->wasRecentlyCreated) {
                $this->permissions[] = $permission->id;
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->owner = User::factory()->create();
        $this->users[] = $this->owner->id;
        $this->viewer = User::factory()->create();
        $this->users[] = $this->viewer->id;
        $this->owner->givePermissionTo(['posts.create', 'posts.update.own', 'posts.delete.own', 'comments.create', 'articles.create', 'articles.update.own', 'articles.publish.own']);
        $this->viewer->givePermissionTo('comments.create');
        app(ProfileService::class)->saveUserProfile($this->owner, $this->owner->id, ['visibility' => 'public', 'display_name' => 'Alias público anterior']);
        config(['database.connections.audit_reader' => config('database.connections.'.$this->default)]);
        $this->reader = DB::connection('audit_reader');
        $this->assertSame(TestingIsolation::database(), $this->reader->selectOne('SELECT DATABASE() db')->db);
        $this->assertSame('REPEATABLE-READ', $this->reader->selectOne('SELECT @@transaction_isolation level')->level);
        $this->reader->statement('SET SESSION innodb_lock_wait_timeout = 2');
    }

    protected function tearDown(): void
    {
        try {
            DB::setDefaultConnection($this->default);
            if ($this->reader) {
                while ($this->reader->transactionLevel() > 0) {
                    $this->reader->rollBack();
                }
            }
            DB::disconnect('audit_reader');
            // Only IDs created by this test; never truncate or rebuild a shared database.
            $posts = DB::table('posts')->whereIn('user_id', $this->users)->pluck('id');
            DB::table('comments')->whereIn('post_id', $posts)->whereNotNull('parent_id')->delete();
            DB::table('comments')->whereIn('post_id', $posts)->delete();
            DB::table('posts')->whereIn('id', $posts)->delete();
            $articles = DB::table('articles')->whereIn('user_id', $this->users)->pluck('id');
            DB::table('content_revisions')->whereIn('article_id', $articles)->delete();
            DB::table('articles')->whereIn('id', $articles)->delete();
            $chats = DB::table('conversations')->whereIn('user_low_id', $this->users)->pluck('id');
            DB::table('conversation_participants')->whereIn('conversation_id', $chats)->update(['last_read_message_id' => null, 'hidden_through_message_id' => null]);
            DB::table('message_user_hides')->whereIn('conversation_id', $chats)->delete();
            DB::table('messages')->whereIn('conversation_id', $chats)->delete();
            DB::table('conversation_participants')->whereIn('conversation_id', $chats)->delete();
            DB::table('conversations')->whereIn('id', $chats)->delete();
            DB::table('user_profiles')->whereIn('user_id', $this->users)->delete();
            DB::table('professional_profiles')->whereIn('user_id', $this->users)->delete();
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $this->users)->delete();
            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $this->users)->delete();
            DB::table('role_has_permissions')->whereIn('role_id', $this->roles)->delete();
            DB::table('roles')->whereIn('id', $this->roles)->delete();
            DB::table('users')->whereIn('id', $this->users)->delete();
            DB::table('permissions')->whereIn('id', $this->permissions)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } finally {
            parent::tearDown();
        }
    }

    private function onReader(callable $operation): mixed
    {
        DB::setDefaultConnection('audit_reader');
        try {
            return $operation();
        } finally {
            DB::setDefaultConnection($this->default);
        }
    }

    private function snapshot(callable $change, callable $assertions): void
    {
        $this->reader->beginTransaction();
        try {
            $this->reader->table('users')->whereIn('id', $this->users)->get();
            $change();
            $this->onReader($assertions);
        } finally {
            $this->reader->rollBack();
        }
    }

    private function denied(callable $operation, string $exception = AuthorizationException::class): void
    {
        try {
            $operation();
            $this->fail('Se permitió acceso revocado bajo un snapshot anterior.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);
        }
    }

    private function publishedPost(string $visibility = 'public')
    {
        $service = app(PostService::class);
        $post = $service->create($this->owner, ['content' => 'Contenido revocable', 'visibility' => $visibility]);

        return $service->publish($this->owner, $post->id, 1);
    }

    public function test_community_queries_revoke_post_and_connection_visibility_under_an_old_snapshot(): void
    {
        $post = $this->publishedPost();
        app(CommunityInteractionService::class)->follow($this->viewer, $this->owner->id);
        $this->snapshot(function () use ($post) {
            app(PostService::class)->update($this->owner, $post->id, ['visibility' => 'private'], 2);
            app(ProfileService::class)->saveUserProfile($this->owner, $this->owner->id, ['visibility' => 'private']);
        }, function () use ($post) {
            // Control: ordinary MySQL reads still see the revoked version.
            $this->assertSame('public', DB::table('posts')->where('id', $post->id)->value('visibility'));
            $query = app(CommunityQuery::class);
            $this->denied(fn () => $query->detail($post->id, $this->viewer));
            $this->denied(fn () => $query->comments($post->id, $this->viewer));
            $this->denied(fn () => $query->connections($this->viewer, $this->owner->id));
            $this->assertSame([], $query->recent($this->viewer)->items());
            $this->assertSame([], $query->following($this->viewer)->items());
            $this->assertSame([], $query->profile($this->owner->id, $this->viewer)->items());
        });
    }

    public function test_comment_threads_and_aliases_use_current_visibility(): void
    {
        $post = $this->publishedPost();
        $interactions = app(CommunityInteractionService::class);
        $root = $interactions->comment($this->owner, $post->id, ['content' => 'Raíz']);
        $interactions->comment($this->viewer, $post->id, ['content' => 'Respuesta', 'parent_id' => $root->id]);
        $this->snapshot(function () use ($root, $interactions) {
            $interactions->deleteComment($this->owner, $root->id, 1);
            app(ProfileService::class)->saveUserProfile($this->owner, $this->owner->id, ['visibility' => 'private']);
        }, function () use ($post, $root) {
            $this->assertSame('visible', DB::table('comments')->where('id', $root->id)->value('status'));
            $query = app(CommunityQuery::class);
            $this->assertSame([], $query->comments($post->id, $this->viewer)->items());
            $this->assertSame('Usuario', $query->detail($post->id)['author']['display_name']);
        });
    }

    public function test_shared_public_reads_do_not_serialize_readers(): void
    {
        $post = $this->publishedPost();
        $this->reader->beginTransaction();
        try {
            $first = $this->onReader(fn () => app(CommunityQuery::class)->detail($post->id, $this->viewer));
            $second = app(CommunityQuery::class)->detail($post->id, $this->viewer);
            $this->assertSame($first, $second);
        } finally {
            $this->reader->rollBack();
        }
    }

    public function test_suspended_authors_disappear_from_public_queries_under_an_old_snapshot(): void
    {
        $post = $this->publishedPost();
        app(ProfileService::class)->saveProfessionalProfile($this->owner, $this->owner->id, ['visibility' => 'public']);
        $editorial = app(EditorialService::class);
        $article = $editorial->create(EditorialKind::Article, $this->owner, ['title' => 'Revocable', 'body' => 'Texto']);
        $editorial->transition(EditorialKind::Article, $this->owner, $article->id, EditorialStatus::Published, 1);
        $this->snapshot(function () {
            $this->owner->status = 'suspended';
            $this->owner->save();
        }, function () use ($post, $article) {
            $community = app(CommunityQuery::class);
            $this->assertSame([], $community->recent()->items());
            $this->denied(fn () => $community->detail($post->id));
            $editorial = app(EditorialQuery::class);
            $this->assertSame([], $editorial->recent(EditorialKind::Article)->items());
            $this->denied(fn () => $editorial->detail(EditorialKind::Article, $article->id));
            $profiles = app(ProfileService::class);
            $this->denied(fn () => $profiles->publicUserProfile($this->owner->id), ModelNotFoundException::class);
            $this->denied(fn () => $profiles->publicProfessionalProfile($this->owner->id), ModelNotFoundException::class);
        });
    }

    public function test_public_profiles_and_private_profile_data_do_not_use_old_snapshots(): void
    {
        $profiles = app(ProfileService::class);
        $professional = $profiles->saveProfessionalProfile($this->owner, $this->owner->id, ['visibility' => 'public', 'headline' => 'Anterior']);
        $this->snapshot(function () use ($profiles) {
            $profiles->saveUserProfile($this->owner, $this->owner->id, ['visibility' => 'private']);
            $profiles->saveProfessionalProfile($this->owner, $this->owner->id, ['visibility' => 'private', 'headline' => 'Vigente']);
        }, function () use ($profiles, $professional) {
            $this->assertSame('public', DB::table('professional_profiles')->where('id', $professional->id)->value('visibility'));
            $this->denied(fn () => $profiles->publicUserProfile($this->owner->id), ModelNotFoundException::class);
            $this->denied(fn () => $profiles->publicProfessionalProfile($this->owner->id), ModelNotFoundException::class);
            $this->assertSame('Vigente', $profiles->professionalProfile($this->owner, $this->owner->id)->headline);
        });
    }

    public function test_inbox_latest_message_and_unread_counts_respect_current_redaction_and_hides(): void
    {
        $chat = app(ConversationService::class)->start($this->owner, $this->viewer->id);
        $messages = app(MessageService::class);
        $first = $messages->send($this->owner, $chat->id, ['content' => 'Texto ocultable']);
        $last = $messages->send($this->owner, $chat->id, ['content' => 'Texto eliminable']);
        $this->snapshot(function () use ($first, $last, $messages) {
            $messages->deleteForEveryone($this->owner, $last->id, 1);
            $messages->hideForMe($this->viewer, $first->id);
        }, function () use ($last) {
            $this->assertSame('Texto eliminable', DB::table('messages')->where('id', $last->id)->value('content'));
            $query = app(MessagingQuery::class);
            $inbox = $query->inbox($this->viewer)->items();
            $this->assertCount(1, $inbox);
            $this->assertNull($inbox[0]['last_message']['content']);
            $this->assertSame('deleted', $inbox[0]['last_message']['status']);
            $this->assertSame(0, $inbox[0]['unread_count']);
            $this->assertSame(0, $query->unreadCount($this->viewer));
        });
        $this->snapshot(fn () => app(ConversationService::class)->hide($this->viewer, $chat->id), function () {
            $this->assertSame([], app(MessagingQuery::class)->inbox($this->viewer)->items());
        });
    }

    public function test_editorial_lists_and_workspace_use_current_states_and_private_aliases(): void
    {
        $editorial = app(EditorialService::class);
        $first = $editorial->create(EditorialKind::Article, $this->owner, ['title' => 'Retirable', 'body' => 'Contenido']);
        $first = $editorial->transition(EditorialKind::Article, $this->owner, $first->id, EditorialStatus::Published, 1);
        $second = $editorial->create(EditorialKind::Article, $this->owner, ['title' => 'Visible', 'body' => 'Contenido']);
        $editorial->transition(EditorialKind::Article, $this->owner, $second->id, EditorialStatus::Published, 1);
        $this->snapshot(function () use ($editorial, $first) {
            $editorial->transition(EditorialKind::Article, $this->owner, $first->id, EditorialStatus::Draft, 2);
            app(ProfileService::class)->saveUserProfile($this->owner, $this->owner->id, ['visibility' => 'private']);
        }, function () use ($first) {
            $query = app(EditorialQuery::class);
            $this->denied(fn () => $query->detail(EditorialKind::Article, $first->id, $this->viewer));
            $recent = $query->recent(EditorialKind::Article, $this->viewer)->items();
            $this->assertCount(1, $recent);
            $this->assertSame('Usuario', $recent[0]['author']['display_name']);
            $workspace = $query->workspace(EditorialKind::Article, $this->owner)->items();
            $this->assertSame('draft', collect($workspace)->firstWhere('id', $first->id)['status']);
        });
    }

    public static function inactiveStatuses(): array
    {
        return [['suspended'], ['disabled'], ['pending_deletion']];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_persisted_account_revocation_blocks_read_entry_points(string $status): void
    {
        app(ProfileService::class)->saveUserProfile($this->viewer, $this->viewer->id, []);
        $this->snapshot(function () use ($status) {
            $this->viewer->status = $status;
            $this->viewer->save();
        }, function () {
            $this->assertSame('active', DB::table('users')->where('id', $this->viewer->id)->value('status'));
            $this->denied(fn () => app(CommunityQuery::class)->recent($this->viewer));
            $this->denied(fn () => app(ProfileService::class)->userProfile($this->viewer, $this->viewer->id));
            $this->denied(fn () => app(MessagingQuery::class)->inbox($this->viewer));
            $this->denied(fn () => app(MessagingQuery::class)->unreadCount($this->viewer));
            $this->denied(fn () => app(EditorialQuery::class)->recent(EditorialKind::Article, $this->viewer));
        });
    }

    public static function grants(): array
    {
        return [['direct'], ['role']];
    }

    #[DataProvider('grants')]
    public function test_current_role_and_direct_permission_membership_revoke_private_access(string $grant): void
    {
        $post = $this->publishedPost('private');
        if ($grant === 'direct') {
            $this->viewer->givePermissionTo('posts.moderate');
        } else {
            $role = Role::create(['name' => 'audit-'.Str::uuid(), 'guard_name' => 'web']);
            $this->roles[] = $role->id;
            $role->givePermissionTo('posts.moderate');
            $this->viewer->assignRole($role);
        }
        $this->snapshot(function () use ($grant) {
            if ($grant === 'direct') {
                $this->viewer->revokePermissionTo('posts.moderate');
            } else {
                $this->viewer->removeRole(Role::findOrFail($this->roles[0]));
            }
        }, function () use ($post) {
            $this->denied(fn () => app(CommunityQuery::class)->detail($post->id, $this->viewer));
        });
    }
}
