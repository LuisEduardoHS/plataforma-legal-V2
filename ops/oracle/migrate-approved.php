<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require getcwd().'/vendor/autoload.php';
require __DIR__.'/StagingDatabase.php';
try {
    $args = array_slice($argv, 1);
    if (! in_array($args[0] ?? '', ['plan', 'apply'], true) || count($args) !== (($args[0] ?? '') === 'plan' ? 1 : 3)) {
        throw new RuntimeException('Invalid operation.');
    }
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    [$db, $identity, $account] = StagingDatabase::guard($app);
    StagingDatabase::grants($db, 'migrator', $account);
    if ((int) $db->selectOne("SELECT GET_LOCK('lefenda_staging_deploy',0) locked")->locked !== 1) {
        throw new RuntimeException('Deployment already active.');
    }
    $repositoryExists = $db->getSchemaBuilder()->hasTable('migrations');
    $ledger = $repositoryExists ? $db->table('migrations')->orderBy('id')->get()->toJson() : '[]';
    $ran = $repositoryExists ? $db->table('migrations')->pluck('migration')->all() : [];
    $files = [];
    foreach (glob(getcwd().'/database/migrations/*.php') as $file) {
        if (! in_array(basename($file, '.php'), $ran, true)) {
            $files[basename($file)] = hash_file('sha256', $file);
        }
    }
    ksort($files);
    $plan = ['release' => realpath(getcwd()), 'connection_config' => hash('sha256', json_encode(array_intersect_key($db->getConfig(), array_flip(['host', 'unix_socket', 'database', 'prefix', 'charset', 'collation', 'strict', 'engine'])))), 'repository_exists' => $repositoryExists, 'database' => 'lefenda_staging', 'uuid' => $identity['uuid'], 'socket' => $identity['socket'], 'pid' => $identity['pid'], 'ledger' => hash('sha256', $ledger), 'files' => $files];
    $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    $digest = hash('sha256', $json);
    if ($args[0] === 'plan') {
        echo $json;
    } else {
        $approved = file_get_contents($args[1]);
        if (! hash_equals($digest, $args[2]) || ! hash_equals($json, $approved) || getenv('QUIESCED_STAGING') !== 'YES' || ! $files) {
            throw new RuntimeException('Approval or plan mismatch.');
        }
        StagingDatabase::guard($app);
        $paths = array_map(fn ($name) => 'database/migrations/'.$name, array_keys($files));
        $code = Artisan::call('migrate', ['--database' => 'mysql', '--path' => $paths, '--force' => true]);
        StagingDatabase::guard($app);
        if ($code !== 0) {
            throw new RuntimeException('Migration failed; manual recovery required.');
        }
        echo 'Approved migrations completed plan='.$digest.' count='.count($files)."\n";
    }
    $db->selectOne("SELECT RELEASE_LOCK('lefenda_staging_deploy')");
} catch (Throwable) {
    fwrite(STDERR, "Approved migration operation rejected/failed; inspect sanitized application logs; do not auto-rollback.\n");
    exit(2);
}
