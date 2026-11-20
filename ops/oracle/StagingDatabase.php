<?php

// Security boundary: approved release and root/mysql OS administrators are trusted.
final class StagingDatabase
{
    public static function identity(): array
    {
        $process = proc_open(['/usr/bin/python3', '-I', '-S', __DIR__.'/mysql-peer.py'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Identity check unavailable.');
        }
        $json = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Local identity not accredited.');
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function guard($app, bool $requireAppKey = true): array
    {
        $id = self::identity();
        $db = $app['db']->connection('mysql');
        if (! $app->environment('production') || config('app.debug') || config('database.default') !== 'mysql'
            || ($requireAppKey && ! config('app.key')) || config('lexa.enabled_generation') || config('queue.default') !== 'database'
            || $db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'lefenda_staging'
            || $db->getConfig('unix_socket') !== $id['socket'] || $db->getConfig('host') !== 'localhost'
            || config('session.driver') !== 'database' || config('cache.default') !== 'database'
            || config('session.secure') !== true || config('session.http_only') !== true || ! in_array(config('session.same_site'), ['lax', 'strict'], true)
            || ! str_starts_with((string) config('app.url'), 'https://')
            || config('attachments.disk') !== 'attachments' || config('filesystems.disks.attachments.driver') !== 'local'
            || config('filesystems.disks.attachments.visibility') !== 'private' || config('filesystems.disks.attachments.serve')
            || config('database.connections.mysql.read') || config('database.connections.mysql.write')
            || ! $db->getConfig('password') || $db->getConfig('url')) {
            throw new RuntimeException('Unapproved staging configuration.');
        }
        $db->setReconnector(fn () => throw new RuntimeException('Reconnection requires a new approval.'));
        $server = $db->selectOne('SELECT DATABASE() db, @@server_uuid uuid, @@datadir datadir, @@partial_revokes literal_grants, CURRENT_USER() account');
        if ((int) $server->literal_grants !== 1 || $server->db !== 'lefenda_staging' || $server->uuid !== $id['uuid'] || rtrim($server->datadir, '/') !== $id['datadir'] || self::identity() !== $id) {
            throw new RuntimeException('Effective MySQL server mismatch.');
        }

        return [$db, $id, $server->account];
    }

    public static function grants($db, string $role, string $account): void
    {
        $roles = [
            'runtime' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
            'migrator' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'INDEX', 'REFERENCES'],
            'backup' => ['SELECT', 'SHOW VIEW', 'TRIGGER', 'EVENT'],
        ];
        if ($account !== 'lefenda_'.$role.'@localhost' || ! isset($roles[$role])) {
            throw new RuntimeException('Unapproved account.');
        }
        $seen = [];
        $global = [];
        foreach ($db->select('SHOW GRANTS') as $row) {
            $grant = array_values((array) $row)[0];
            if (! preg_match('/^GRANT (.+) ON (.+) TO /', $grant, $m) || str_contains($grant, 'WITH GRANT OPTION')) {
                throw new RuntimeException('Unapproved grants.');
            }
            $privileges = explode(', ', $m[1]);
            $allowed = $m[2] === '*.*' ? ($role === 'backup' ? ['USAGE', 'SHOW_ROUTINE'] : ['USAGE']) : ($m[2] === '`lefenda_staging`.*' ? $roles[$role] : []);
            if ($m[2] === '*.*') {
                $global = array_merge($global, $privileges);
            } else {
                $seen = array_merge($seen, $privileges);
            }
            if (array_diff($privileges, $allowed)) {
                throw new RuntimeException('Excess or cross-schema privileges.');
            }
        }
        if (array_diff($roles[$role], $seen) || ($role === 'backup' && ! in_array('SHOW_ROUTINE', $global, true))) {
            throw new RuntimeException('Required least-privilege profile is incomplete.');
        }
    }
}
