<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\Http;

require_once '/app/vendor/autoload.php';
if (! is_file('/.dockerenv') || getenv('APP_ENV') !== 'testing' || getenv('DISPOSABLE_RESTORE') !== 'YES'
    || getenv('EXPECTED_TEST_DB') !== 'lefenda_arm64_test' || getenv('DB_DATABASE') !== 'lefenda_arm64_test'
    || ! str_starts_with((string) getenv('DB_SOCKET'), '/tmp/lefenda-laravel.')
    || ! str_starts_with((string) getenv('LARAVEL_STORAGE_PATH'), '/tmp/lefenda-laravel.')) {
    throw new RuntimeException('Disposable container context required before DB access.');
}
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = $app['db']->connection();
if (! $app->environment('testing') || $db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'lefenda_arm64_test'
    || $db->getConfig('unix_socket') !== getenv('DB_SOCKET')
    || $db->selectOne('SELECT DATABASE() db')->db !== 'lefenda_arm64_test'
    || config('cache.default') !== 'array' || config('session.driver') !== 'array' || config('queue.default') !== 'sync'
    || realpath(storage_path()) !== realpath(getenv('LARAVEL_STORAGE_PATH'))) {
    throw new RuntimeException('Effective Laravel test isolation failed.');
}
Http::preventStrayRequests();
if (getenv('QA_PHPUNIT') === 'YES') {
    HandleExceptions::flushState();
}
