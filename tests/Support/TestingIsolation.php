<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

final class TestingIsolation
{
    public static function database(): string
    {
        $expected = (string) (getenv('EXPECTED_TEST_DB') ?: env('EXPECTED_TEST_DB', env('DB_DATABASE', '')));
        if ($expected === '' || $expected === 'plataforma_legal_v2' || ! str_ends_with($expected, '_test')) {
            throw new RuntimeException('An explicit isolated testing database is required.');
        }

        return $expected;
    }

    public static function assertSafe(Application $app): void
    {
        $expected = self::database();
        $db = $app['db']->connection();
        $root = realpath($app->storagePath());
        $base = realpath($app->basePath());
        if (! $app->environment('testing') || $db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== $expected
            || config('cache.default') !== 'array' || config('session.driver') !== 'array' || config('queue.default') !== 'sync'
            || ! $root || ! $base || $root === $base || str_starts_with(str_replace('\\', '/', $root), str_replace('\\', '/', $base).'/')) {
            throw new RuntimeException('Testing requires an isolated database, external storage and array cache/sessions with sync queue.');
        }
        if (($db->selectOne('SELECT DATABASE() db')->db ?? null) !== $expected) {
            throw new RuntimeException('Effective testing database does not match the expected target.');
        }
    }
}
