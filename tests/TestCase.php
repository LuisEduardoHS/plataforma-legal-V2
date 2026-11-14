<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
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
