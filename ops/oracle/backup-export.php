<?php

use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
require __DIR__.'/StagingDatabase.php';
$stage = 'credentials';
try {
    $path = $argv[1] ?? '';
    $stat = lstat($path);
    if (is_link($path) || ($stat['mode'] & 0777) !== 0600 || $stat['uid'] !== posix_geteuid()) {
        throw new RuntimeException('Credential custody mismatch.');
    }
    $cnf = parse_ini_file($path, true, INI_SCANNER_RAW);
    if (array_keys($cnf) !== ['client'] || array_diff(array_keys($cnf['client']), ['user', 'password'])
        || ($cnf['client']['user'] ?? '') !== 'lefenda_backup' || ! preg_match('/^[A-Za-z0-9_-]{32,128}$/', $cnf['client']['password'] ?? '')) {
        throw new RuntimeException('Only dedicated backup credentials are allowed, without transport options.');
    }
    if (is_link('/etc/lefenda/backup.env') || ! is_file('/etc/lefenda/backup.env')) {
        throw new RuntimeException('Backup profile unavailable.');
    }
    $profile = stat('/etc/lefenda/backup.env');
    if ($profile['uid'] !== 0 || ($profile['mode'] & 0022)) {
        throw new RuntimeException('Unprotected backup profile.');
    }
    $stage = 'bootstrap';
    $app = require getcwd().'/bootstrap/app.php';
    $app->useEnvironmentPath('/etc/lefenda')->loadEnvironmentFrom('backup.env');
    $app->make(Kernel::class)->bootstrap();
    config(['database.connections.mysql.username' => $cnf['client']['user'], 'database.connections.mysql.password' => $cnf['client']['password']]);
    $app['db']->purge('mysql');
    $stage = 'identity';
    [$db,$id,$account] = StagingDatabase::guard($app, false);
    $stage = 'grants';
    StagingDatabase::grants($db, 'backup', $account);
    $stage = 'dump';
    $process = proc_open(['/usr/bin/mysqldump', '--defaults-file='.realpath($path), '--protocol=SOCKET', '--host=localhost', '--socket='.$id['socket'],
        '--single-transaction', '--quick', '--routines', '--events', '--triggers', '--hex-blob', '--no-tablespaces', '--set-gtid-purged=OFF', 'lefenda_staging'],
        [1 => STDOUT, 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin', 'HOME' => '/nonexistent', 'MYSQL_TEST_LOGIN_FILE' => '/dev/null']);
    if (! is_resource($process)) {
        throw new RuntimeException('Exporter unavailable.');
    }
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Exporter failed.');
    }
    StagingDatabase::guard($app, false);
} catch (Throwable) {
    fwrite(STDERR, "Accredited backup export rejected/failed stage={$stage}.\n");
    exit(2);
}
