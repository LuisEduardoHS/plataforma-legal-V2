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

    protected function setUp(): void
    {
        parent::setUp();

        // Stop before any subclass creates fixtures, even with stale config or DB_URL.
        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'plataforma_legal_v2_test'
            || $connection->selectOne('SELECT DATABASE() db')->db !== 'plataforma_legal_v2_test') {
            throw new \RuntimeException('Las pruebas requieren exclusivamente plataforma_legal_v2_test en MySQL.');
        }
    }
}
