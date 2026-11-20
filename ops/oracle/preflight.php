<?php

use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
require __DIR__.'/StagingDatabase.php';
$stage = 'input';
try {
    if (($argv[1] ?? '') !== 'lefenda_staging') {
        throw new RuntimeException('Explicit staging target required.');
    }
    $stage = 'bootstrap';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $stage = 'identity';
    [$db, $identity, $account] = StagingDatabase::guard($app);
    $stage = 'grants';
    StagingDatabase::grants($db, 'runtime', $account);
    echo "Staging identity, transport, account and configuration verified.\n";
} catch (Throwable) {
    fwrite(STDERR, "Staging preflight rejected stage={$stage}; no authorization for DDL.\n");
    exit(2);
}
