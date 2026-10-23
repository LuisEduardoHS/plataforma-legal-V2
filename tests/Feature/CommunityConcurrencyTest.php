<?php

namespace Tests\Feature;

use App\Exceptions\CommunityException;
use App\Models\Post;
use App\Models\User;
use App\Services\Community\CommunityInteractionService;
use App\Services\Community\PostService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CommunityConcurrencyTest extends TestCase
{
    public function test_independent_mysql_connections_serialize_edits_and_reject_stale_versions(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('plataforma_legal_v2_test', DB::connection()->getDatabaseName());
        $this->assertSame('plataforma_legal_v2_test', DB::selectOne('SELECT DATABASE() db')->db);
        $default = DB::getDefaultConnection();
        $db = DB::connection();
        $owner = null;
        $post = null;
        $newPermissions = [];
        try {
            foreach (['posts.create', 'posts.update.own'] as $name) {
                $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                if ($permission->wasRecentlyCreated) {
                    $newPermissions[] = $permission->id;
                }
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $owner = User::factory()->create()->refresh();
            $owner->givePermissionTo(['posts.create', 'posts.update.own']);
            $service = app(PostService::class);
            $post = $service->create($owner, ['content' => 'Versión inicial']);
            config(['database.connections.community_race' => config('database.connections.'.$default)]);
            $other = DB::connection('community_race');
            $this->assertSame('plataforma_legal_v2_test', $other->selectOne('SELECT DATABASE() db')->db);
            $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $reportedCode = null;
            app(ExceptionHandler::class)->reportable(function (QueryException $error) use (&$reportedCode): void {
                $reportedCode = (int) ($error->errorInfo[1] ?? 0);
            });
            $db->beginTransaction();
            $service->update($owner, $post->id, ['content' => 'Primera edición'], 1);
            DB::setDefaultConnection('community_race');
            try {
                $service->update($owner, $post->id, ['content' => 'Segunda edición'], 1);
                $this->fail('La segunda conexión ignoró el bloqueo.');
            } catch (CommunityException) {
                $this->assertSame(1205, $reportedCode);
                $this->assertSame(0, $other->transactionLevel());
            } finally {
                DB::setDefaultConnection($default);
            }
            $db->commit();
            DB::setDefaultConnection('community_race');
            try {
                $service->update($owner, $post->id, ['content' => 'Edición obsoleta'], 1);
                $this->fail('Una edición obsoleta sobrescribió la versión confirmada.');
            } catch (ValidationException) {
                $this->assertSame('Primera edición', Post::findOrFail($post->id)->content);
                $this->assertSame(2, Post::findOrFail($post->id)->version);
            } finally {
                DB::setDefaultConnection($default);
            }
            $service->publish($owner, $post->id, 2);
            $other->beginTransaction();
            // Establish a REPEATABLE READ snapshot before another connection commits a like.
            $this->assertSame(0, $other->table('post_reactions')->where('post_id', $post->id)->count());
            $reaction = app(CommunityInteractionService::class)->react($owner, $post->id);
            DB::setDefaultConnection('community_race');
            try {
                $repeated = app(CommunityInteractionService::class)->react($owner, $post->id);
                $this->assertSame($reaction->id, $repeated->id);
                $other->commit();
            } finally {
                DB::setDefaultConnection($default);
                while ($other->transactionLevel() > 0) {
                    $other->rollBack();
                }
            }
        } finally {
            DB::setDefaultConnection($default);
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            DB::disconnect('community_race');
            if ($post !== null) {
                DB::table('posts')->where('id', $post->id)->delete();
            }
            if ($owner !== null) {
                DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $owner->id)->delete();
                DB::table('users')->where('id', $owner->id)->delete();
            }
            DB::table('permissions')->whereIn('id', $newPermissions)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
