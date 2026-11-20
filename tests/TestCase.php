<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    public function be(Authenticatable $user, $guard = null)
    {
        // actingAs normally skips SessionGuard::login, including its native stamp.
        $driver = $guard ?? config('auth.defaults.guard');
        $this->withSession(['password_hash_'.$driver => $this->app['auth']->guard($driver)->hashPasswordForCookie($user->getAuthPassword())]);

        return parent::be($user, $guard);
    }

    protected function setUpTraits()
    {
        $this->assertIsolatedTestEnvironment();

        return parent::setUpTraits();
    }

    private function assertIsolatedTestEnvironment(): void
    {
        $storagePath = (string) (getenv('LARAVEL_STORAGE_PATH') ?: env('LARAVEL_STORAGE_PATH', ''));
        if ($storagePath !== '') {
            $this->app->useStoragePath($storagePath);
        }

        // Stop before RefreshDatabase or any subclass trait runs destructive migrations.
        $expectedDb = (string) (getenv('EXPECTED_TEST_DB') ?: env('EXPECTED_TEST_DB', env('DB_DATABASE', '')));
        $connection = DB::connection();
        $actualDb = $connection->selectOne('SELECT DATABASE() db')->db ?? null;

        if (! app()->environment('testing')
            || $connection->getDriverName() !== 'mysql'
            || $expectedDb === ''
            || $expectedDb === 'plataforma_legal_v2'
            || ! str_ends_with($expectedDb, '_test')
            || $connection->getDatabaseName() !== $expectedDb
            || $actualDb !== $expectedDb) {
            throw new \RuntimeException(
                sprintf(
                    'Aislamiento de testing inválido: esperado=[%s], config=[%s], SELECT DATABASE()=[%s].',
                    $expectedDb,
                    $connection->getDatabaseName(),
                    (string) $actualDb
                )
            );
        }

        if (config('cache.default') !== 'array'
            || config('session.driver') !== 'array'
            || config('queue.default') !== 'sync') {
            throw new \RuntimeException('Las pruebas requieren cache=array, session=array y queue=sync aislados.');
        }
    }
}
