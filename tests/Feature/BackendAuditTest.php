<?php

namespace Tests\Feature;

use App\Enums\EditorialKind;
use App\Exceptions\ContentLibraryException;
use App\Models\ContentRevision;
use App\Models\Post;
use App\Models\User;
use App\Services\Community\PostService;
use App\Services\ContentLibrary\EditorialService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EditorialPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BackendAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->seed([RolePermissionSeeder::class, EditorialPermissionSeeder::class]);
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_query_exception_reporting_never_logs_sql_bindings_message_or_trace(): void
    {
        $secret = 'private-message-password-license-token-/private/path';
        $error = new QueryException('private-connection', "insert into messages (content) values (?) /* {$secret} */", [$secret], new \RuntimeException($secret));
        $error->errorInfo = ['HY000', 1205, $secret];
        $this->assertStringContainsString($secret, $error->getMessage());

        Log::spy();
        report($error);
        Log::shouldHaveReceived('error')->once()->with('database.operation_failed', [
            'sqlstate' => 'HY000', 'driver_code' => 1205, 'operation' => 'INSERT',
        ]);
    }

    public function test_database_reporting_drops_unexpected_diagnostic_values(): void
    {
        $error = new QueryException('private', '/* secret */ select 1', ['secret'], new \RuntimeException('secret'));
        $error->errorInfo = ['private-state', 'private-code', 'secret'];
        Log::spy();
        report($error);
        Log::shouldHaveReceived('error')->once()->with('database.operation_failed', [
            'sqlstate' => null, 'driver_code' => null, 'operation' => 'OTHER',
        ]);
    }

    public function test_editorial_revisions_reject_silent_updates_deletes_and_increments(): void
    {
        $owner = User::factory()->create();
        $owner->givePermissionTo('articles.create');
        $article = app(EditorialService::class)->create(EditorialKind::Article, $owner, ['title' => 'Original']);
        $original = $article->revisions()->firstOrFail()->getRawOriginal();
        foreach (['saveQuietly', 'deleteQuietly', 'pushQuietly', 'saveOrFail', 'incrementQuietly', 'decrementQuietly', 'increment'] as $method) {
            $revision = ContentRevision::findOrFail($original['id']);
            $revision->forceFill(['snapshot' => ['title' => 'Changed']]);
            try {
                str_contains($method, 'crement') ? $revision->$method('version') : $revision->$method();
                $this->fail('Se modificó una revisión con '.$method);
            } catch (ContentLibraryException) {
                $this->assertSame($original, $revision->fresh()->getRawOriginal());
            }
        }
    }

    public function test_routine_seeding_preserves_revocations_extra_grants_and_identifiers(): void
    {
        $moderator = Role::findByName('moderator');
        $moderator->revokePermissionTo('users.suspend');
        $moderator->givePermissionTo('articles.create');
        $member = User::factory()->create();
        $member->assignRole($moderator);
        $ids = Permission::pluck('id', 'name')->all();

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($ids, array_intersect_key(Permission::pluck('id', 'name')->all(), $ids));
        $this->assertSame($moderator->id, Role::findByName('moderator')->id);
        $this->assertFalse($member->fresh()->can('users.suspend'));
        $this->assertTrue($member->fresh()->can('articles.create'));
        $this->assertFalse($member->fresh()->can('verification.decide'));
        $this->assertSame(25, Permission::count());
    }

    public function test_community_rejects_associative_sparse_and_nested_attachment_lists_without_writes(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('user');
        foreach ([['arbitrary' => 1], [1 => 1], [[1]]] as $ids) {
            try {
                app(PostService::class)->create($owner, ['content' => 'Test', 'attachment_ids' => $ids]);
                $this->fail('Se aceptó una lista de adjuntos inválida.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
                $this->assertSame(0, Post::count());
            }
        }
    }

    public static function invalidStatuses(): array
    {
        return [['unknown'], ['ACTIVE'], ['active '], [''], [null]];
    }

    #[DataProvider('invalidStatuses')]
    public function test_invalid_statuses_are_rejected_by_model_and_mysql(?string $status): void
    {
        $user = User::factory()->create();
        try {
            $user->status = $status;
            $user->saveQuietly();
            $this->fail('Se persistió un estado inválido.');
        } catch (ValidationException) {
            $this->assertSame('active', $user->fresh()->status);
        }
        try {
            User::factory()->create(['status' => $status]);
            $this->fail('Se creó una cuenta con estado inválido.');
        } catch (ValidationException) {
            $this->assertSame(1, User::count());
        }
        try {
            DB::table('users')->where('id', $user->id)->update(['status' => $status]);
            $this->fail('MySQL aceptó un estado inválido.');
        } catch (QueryException $error) {
            $this->assertContains((int) $error->errorInfo[1], [1048, 3819]);
            $this->assertSame('active', $user->fresh()->status);
        }
    }

    public function test_terminal_status_and_stale_instances_cannot_reactivate_or_reclassify_accounts(): void
    {
        $user = User::factory()->create();
        $stale = $user->fresh();
        foreach (['suspended', 'disabled', 'active', 'pending_deletion'] as $status) {
            $user->status = $status;
            $user->saveQuietly();
            $this->assertSame($status, $user->fresh()->status);
        }
        foreach (['active', 'suspended', 'disabled'] as $status) {
            $stale->status = $status;
            // active would not be dirty on the stale instance; explicitly test a current one too.
            $candidate = $status === 'active' ? $user->fresh() : $stale;
            $candidate->status = $status;
            try {
                $candidate->saveQuietly();
                $this->fail('Se abandonó el estado terminal.');
            } catch (ValidationException) {
                $this->assertSame('pending_deletion', $user->fresh()->status);
            }
        }
        $this->expectException(ValidationException::class);
        $user->incrementQuietly('id', 1, ['status' => 'active']);
    }
}
