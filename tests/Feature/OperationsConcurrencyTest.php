<?php

namespace Tests\Feature;

use App\Models\OperationsEvent;
use App\Models\User;
use App\Services\Auth\PasswordRecovery;
use App\Services\Operations\AccountOperations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OperationsConcurrencyTest extends TestCase
{
    public function test_independent_connections_serialize_accounts_and_single_use_password_tokens(): void
    {
        Storage::fake('attachments');
        $default = DB::getDefaultConnection();
        $db = DB::connection();
        $this->assertSame('plataforma_legal_v2_test', $db->selectOne('SELECT DATABASE() db')->db);
        $users = [];
        $permission = null;
        $createdPermission = false;
        try {
            $permission = Permission::firstOrCreate(['name' => 'operations.accounts', 'guard_name' => 'web']);
            $createdPermission = $permission->wasRecentlyCreated;
            $actor = User::factory()->create();
            $users[] = $actor->id;
            $actor->givePermissionTo($permission);
            $target = User::factory()->create();
            $users[] = $target->id;
            $account = app(AccountOperations::class);
            $version = $account->detail($actor, $target->id)['account']['version'];
            config(['database.connections.operations_race' => config('database.connections.'.$default)]);
            $other = DB::connection('operations_race');
            $this->assertSame('plataforma_legal_v2_test', $other->selectOne('SELECT DATABASE() db')->db);
            $other->statement('SET SESSION innodb_lock_wait_timeout = 1');

            $db->beginTransaction();
            $account->state($actor, $target->id, 'suspended', $version, 'Primera decisión sintética concurrente.');
            DB::setDefaultConnection('operations_race');
            try {
                $account->state($actor, $target->id, 'disabled', $version, 'Segunda decisión sintética concurrente.');
                $this->fail('La segunda conexión ignoró el bloqueo.');
            } catch (QueryException $error) {
                $this->assertSame(1205, (int) $error->errorInfo[1]);
                $this->assertSame(0, $other->transactionLevel());
            } finally {
                DB::setDefaultConnection($default);
            }
            $db->commit();
            DB::setDefaultConnection('operations_race');
            try {
                $account->state($actor, $target->id, 'disabled', $version, 'Reintento sintético con versión obsoleta.');
                $this->fail('Se sobrescribió un estado con versión obsoleta.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            } finally {
                DB::setDefaultConnection($default);
            }
            $this->assertSame('suspended', $target->fresh()->status);
            $this->assertSame(1, OperationsEvent::where('target_user_id', $target->id)->count());

            // Use a separate active synthetic account for the broker race.
            $owner = User::factory()->create();
            $users[] = $owner->id;
            $token = Password::broker()->createToken($owner);
            $credentials = ['email' => $owner->email, 'token' => $token, 'password' => 'ContraseñaConcurrente42', 'password_confirmation' => 'ContraseñaConcurrente42'];
            $db->beginTransaction();
            $this->assertTrue(app(PasswordRecovery::class)->reset($credentials));
            DB::setDefaultConnection('operations_race');
            try {
                app(PasswordRecovery::class)->reset($credentials);
                $this->fail('El segundo consumo ignoró el bloqueo.');
            } catch (QueryException $error) {
                $this->assertSame(1205, (int) $error->errorInfo[1]);
            } finally {
                DB::setDefaultConnection($default);
            }
            $db->commit();
            DB::setDefaultConnection('operations_race');
            try {
                $this->assertFalse(app(PasswordRecovery::class)->reset($credentials));
            } finally {
                DB::setDefaultConnection($default);
            }
            $this->assertTrue(Hash::check($credentials['password'], $owner->fresh()->password));
            $this->assertSame(1, OperationsEvent::where('target_user_id', $owner->id)->where('action', 'password_reset')->count());
        } finally {
            DB::setDefaultConnection($default);
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if (isset($other)) {
                while ($other->transactionLevel() > 0) {
                    $other->rollBack();
                }
            }
            DB::disconnect('operations_race');
            $emails = DB::table('users')->whereIn('id', $users)->pluck('email');
            DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
            DB::table('operations_events')->whereIn('target_user_id', $users)->delete();
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $users)->delete();
            DB::table('users')->whereIn('id', $users)->delete();
            if ($createdPermission) {
                DB::table('permissions')->where('id', $permission->id)->delete();
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
