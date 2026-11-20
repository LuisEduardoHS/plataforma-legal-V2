<?php

use Illuminate\Support\Facades\Artisan;

function qaCanonicalDdl(string $ddl): string
{
    // MySQL may print inherited charset explicitly after dump/import.
    // Keep COLLATE and additionally compare effective column charset metadata.
    return preg_replace('/ CHARACTER SET ([a-z0-9_]+)(?= COLLATE \1_[a-z0-9_]+)/i', '', $ddl);
}
if (($argv[1] ?? '') === 'selftest') {
    $a = 'text COLLATE utf8mb4_unicode_ci';
    $b = 'text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    if (qaCanonicalDdl($a) !== qaCanonicalDdl($b)
        || qaCanonicalDdl($a) === qaCanonicalDdl('text CHARACTER SET latin1 COLLATE latin1_bin')
        || qaCanonicalDdl('AUTO_INCREMENT=10') === qaCanonicalDdl('AUTO_INCREMENT=20')
        || qaCanonicalDdl('CHECK (a>0)') === qaCanonicalDdl('CHECK (a>1)')) {
        throw new RuntimeException('DDL canonicalization regression');
    }
    echo "DDL comparison selftest: 4 checks passed.\n";
    exit(0);
}
require __DIR__.'/laravel-qa-bootstrap.php';
if (($argv[1] ?? '') === 'migrate') {
    if (count($db->select('SHOW TABLES')) !== 0) {
        throw new RuntimeException('Only a new empty disposable schema can be migrated.');
    }
    $code = Artisan::call('migrate', ['--force' => true]);
    echo Artisan::output();
    exit($code);
}
$snapshot = [];
foreach ($db->select('SHOW TABLES') as $row) {
    $table = array_values((array) $row)[0];
    if (! preg_match('/^[a-z_]+$/', $table)) {
        throw new RuntimeException('Unexpected table name');
    }
    $definition = array_values((array) $db->selectOne('SHOW CREATE TABLE `'.$table.'`'))[1];
    $rows = array_map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), $db->select('SELECT * FROM `'.$table.'`'));
    sort($rows);
    $columns = $db->select('SELECT ORDINAL_POSITION,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]);
    $canonical = qaCanonicalDdl($definition);
    $snapshot[$table] = ['raw_definition' => $definition, 'definition' => $canonical, 'ddl' => hash('sha256', $canonical), 'columns' => $columns, 'count' => count($rows), 'data' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
}
ksort($snapshot);
file_put_contents($argv[2], json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Schema/data snapshot: '.count($snapshot).' tables, '.$db->table('migrations')->count()." migrations.\n";
