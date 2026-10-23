<?php

namespace Tests\Feature;

use App\Enums\EditorialKind as Kind;
use App\Enums\EditorialStatus as Status;
use App\Enums\SavedContentType;
use App\Exceptions\ContentLibraryException;
use App\Models\Article;
use App\Models\User;
use App\Services\ContentLibrary\EditorialService;
use App\Services\ContentLibrary\SavedItemService;
use App\Services\ContentLibrary\SavedLibraryQuery;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ContentLibraryConcurrencyTest extends TestCase
{
    public function test_mysql_serializes_slugs_publication_edits_and_saved_items_without_stale_access(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('plataforma_legal_v2_test', DB::connection()->getDatabaseName());
        $this->assertSame('plataforma_legal_v2_test', DB::selectOne('SELECT DATABASE() db')->db);
        $default = DB::getDefaultConnection();
        $db = DB::connection();
        $other = null;
        $users = [];
        $newPermissions = [];
        $article = null;
        try {
            foreach (['articles.create', 'articles.update.own', 'articles.publish.own'] as $name) {
                $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                if ($permission->wasRecentlyCreated) {
                    $newPermissions[] = $permission->id;
                }
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $owner = User::factory()->create()->refresh();
            $users[] = $owner->id;
            $reader = User::factory()->create()->refresh();
            $users[] = $reader->id;
            $owner->givePermissionTo(['articles.create', 'articles.update.own', 'articles.publish.own']);
            $reader->givePermissionTo('articles.create');
            config(['database.connections.content_race' => config('database.connections.'.$default)]);
            $other = DB::connection('content_race');
            $this->assertSame('plataforma_legal_v2_test', $other->selectOne('SELECT DATABASE() db')->db);
            $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $reportedCode = null;
            app(ExceptionHandler::class)->reportable(function (QueryException $error) use (&$reportedCode) {
                $reportedCode = (int) ($error->errorInfo[1] ?? 0);
            });
            $runOther = function (callable $operation) use ($default) {
                DB::setDefaultConnection('content_race');
                try {
                    return $operation();
                } finally {
                    DB::setDefaultConnection($default);
                }
            };
            $timesOut = function (callable $operation) use ($runOther, &$reportedCode, $other) {
                try {
                    $runOther($operation);
                    $this->fail('Se ignoró el bloqueo.');
                } catch (ContentLibraryException) {
                    $this->assertSame(1205, $reportedCode);
                    $this->assertSame(0, $other->transactionLevel());
                }
            };
            $editorial = app(EditorialService::class);
            $saved = app(SavedItemService::class);
            $library = app(SavedLibraryQuery::class);
            $data = ['title' => 'Artículo concurrente', 'slug' => 'concurrente-'.Str::lower((string) Str::ulid()), 'body' => 'Texto inicial'];
            $db->beginTransaction();
            $article = $editorial->create(Kind::Article, $owner, $data);
            $timesOut(fn () => $editorial->create(Kind::Article, $reader, $data));
            $db->commit();
            try {
                $runOther(fn () => $editorial->create(Kind::Article, $reader, $data));
                $this->fail('Se duplicó un slug.');
            } catch (ValidationException) {
                $this->assertSame(1, Article::where('slug', $article->slug)->count());
            }
            $db->beginTransaction();
            $editorial->transition(Kind::Article, $owner, $article->id, Status::Published, 1);
            $timesOut(fn () => $editorial->transition(Kind::Article, $owner, $article->id, Status::Published, 1));
            $db->commit();
            try {
                $runOther(fn () => $editorial->transition(Kind::Article, $owner, $article->id, Status::Published, 1));
                $this->fail('Se aceptó versión obsoleta.');
            } catch (ValidationException) {
                $this->assertSame(2, $article->revisions()->count());
            }
            $db->beginTransaction();
            $item = $saved->save($reader, SavedContentType::Article, $article->id);
            $timesOut(fn () => $saved->save($reader, SavedContentType::Article, $article->id));
            $db->commit();
            $this->assertSame($item->id, $runOther(fn () => $saved->save($reader, SavedContentType::Article, $article->id))->id);
            $db->beginTransaction();
            $editorial->update(Kind::Article, $owner, $article->id, ['body' => 'Texto confirmado'], 2);
            $timesOut(fn () => $editorial->update(Kind::Article, $owner, $article->id, ['body' => 'Texto competidor'], 2));
            $db->commit();
            try {
                $runOther(fn () => $editorial->update(Kind::Article, $owner, $article->id, ['body' => 'Texto obsoleto'], 2));
                $this->fail('Se sobrescribió una revisión.');
            } catch (ValidationException) {
                $this->assertSame('Texto confirmado', $article->fresh()->body);
            }

            $other->beginTransaction();
            $this->assertSame('published', $other->table('articles')->where('id', $article->id)->value('status'));
            $editorial->transition(Kind::Article, $owner, $article->id, Status::Draft, 3);
            $this->assertSame([], $runOther(fn () => $library->library($reader)->items()));
            $other->commit();
        } finally {
            DB::setDefaultConnection($default);
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($other) {
                while ($other->transactionLevel() > 0) {
                    $other->rollBack();
                }
            }
            DB::disconnect('content_race');
            if ($article) {
                DB::table('saved_items')->where('article_id', $article->id)->whereIn('user_id', $users)->delete();
                DB::table('content_revisions')->where('article_id', $article->id)->delete();
                DB::table('articles')->where('id', $article->id)->delete();
            }
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $users)->delete();
            DB::table('users')->whereIn('id', $users)->delete();
            DB::table('permissions')->whereIn('id', $newPermissions)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
