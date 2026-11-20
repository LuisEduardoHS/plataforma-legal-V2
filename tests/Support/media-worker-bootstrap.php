<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestingIsolation;

// Standalone worker uses the same environment as PHPUnit, without a developer-specific runner.
require_once dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($root = getenv('LARAVEL_STORAGE_PATH')) {
    $app->useStoragePath($root);
}
TestingIsolation::assertSafe($app);
Http::preventStrayRequests();
