<?php

use Illuminate\Contracts\Console\Kernel;

require '/app/vendor/autoload.php';
require __DIR__.'/StagingDatabase.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$db,$id,$account] = StagingDatabase::guard($app);
$role = $argv[1];
StagingDatabase::grants($db, $role, $account);
$denials = 0;
function denied(callable $operation): void
{
    global $denials;
    try {
        $operation();
    } catch (Throwable) {
        $denials++;

        return;
    }
    throw new RuntimeException('Forbidden operation succeeded');
}
if ($role === 'runtime') {
    $db->insert('INSERT INTO permission_probe(id) VALUES(2)');
    $db->update('UPDATE permission_probe SET id=3 WHERE id=2');
    $db->delete('DELETE FROM permission_probe WHERE id=3');
    denied(fn () => $db->statement('CREATE TABLE denied_runtime(id INT)'));
    denied(fn () => $db->statement('ALTER TABLE permission_probe ADD denied INT'));
    denied(fn () => $db->statement('DROP TABLE permission_probe'));
    denied(fn () => $db->select('SELECT * FROM not_staging.permission_probe'));
    denied(fn () => $db->statement("CREATE USER 'denied'@'localhost'"));
} elseif ($role === 'migrator') {
    $db->statement('CREATE TABLE ddl_probe(id INT PRIMARY KEY)');
    $db->statement('ALTER TABLE ddl_probe ADD synthetic INT');
    $db->statement('DROP TABLE ddl_probe');
    denied(fn () => $db->statement('CREATE TABLE not_staging.denied_table(id INT)'));
    denied(fn () => $db->statement("GRANT SELECT ON lefenda_staging.* TO 'lefenda_runtime'@'localhost'"));
} elseif ($role === 'backup') {
    $db->select('SELECT * FROM permission_probe');
    denied(fn () => $db->insert('INSERT INTO permission_probe(id) VALUES(8)'));
    denied(fn () => $db->delete('DELETE FROM permission_probe'));
    denied(fn () => $db->statement('CREATE TABLE denied_backup(id INT)'));
    denied(fn () => $db->select('SELECT * FROM not_staging.permission_probe'));
}
denied(fn () => $db->select('SELECT * FROM lefendaXstaging.permission_probe'));
config(['database.connections.mysql.database' => 'not_staging', 'database.connections.mysql.host' => 'remote.invalid']);
if ($db->selectOne('SELECT DATABASE() db')->db !== 'lefenda_staging') {
    throw new RuntimeException('Configuration switch changed active connection');
}
$db->disconnect();
denied(fn () => $db->select('SELECT 1'));
echo 'Role '.$role.' allowed/denied operations verified; denials='.$denials."\n";
