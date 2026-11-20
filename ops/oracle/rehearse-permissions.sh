#!/usr/bin/env bash
set -euo pipefail
[[ -f /.dockerenv && ${DISPOSABLE_RESTORE:-} == YES ]] || exit 2
for user in deploy lefenda lefenda-backup; do id "$user" >/dev/null 2>&1 || useradd --system --no-create-home "$user"; done
getent group lefenda-public >/dev/null || groupadd --system lefenda-public
usermod -aG lefenda-public www-data
usermod -aG lefenda-public lefenda
sha=1111111111111111111111111111111111111111
release=/srv/lefenda/releases/$sha
mkdir -p "$release"/{public,app,bootstrap/cache} /srv/lefenda/shared
printf 'public synthetic asset\n' > "$release/public/probe.css"
printf '<?php // synthetic code\n' > "$release/app/probe.php"
printf '<?php return [];\n' > "$release/bootstrap/cache/probe.php"
printf 'SYNTHETIC_SECRET=not-real\n' > /srv/lefenda/shared/.env
bash ops/oracle/permissions.sh --apply "$sha"
ln -s "$release" /srv/lefenda/current
ln -s /srv/lefenda/shared/.env "$release/.env"
ln -s /srv/lefenda/shared/storage "$release/storage"
runuser -u lefenda -- sh -c 'umask 077; printf synthetic-private > /srv/lefenda/shared/storage/app/attachments/probe.txt'
allow() { runuser -u "$1" -- "$2" "${@:3}"; }
deny() { if runuser -u "$1" -- "$2" "${@:3}" >/dev/null 2>&1; then echo 'Linux forbidden access succeeded' >&2; exit 1; fi; }
allow www-data cat /srv/lefenda/current/public/probe.css >/dev/null
deny www-data cat /srv/lefenda/shared/.env
deny www-data cat /srv/lefenda/shared/storage/app/attachments/probe.txt
deny www-data cat "$release/app/probe.php"
allow lefenda cat /srv/lefenda/shared/.env >/dev/null
allow lefenda touch /srv/lefenda/shared/storage/logs/runtime.log
deny lefenda touch "$release/new-code.php"
deny lefenda sh -c "echo bad >> '$release/app/probe.php'"
deny lefenda sh -c "echo bad >> '$release/bootstrap/cache/probe.php'"
deny lefenda sh -c 'echo bad >> /srv/lefenda/shared/.env'
allow deploy test -w "$release/app/probe.php"
allow lefenda-backup cat "$release/app/probe.php" >/dev/null
deny lefenda-backup cat /srv/lefenda/shared/.env
deny lefenda-backup cat /srv/lefenda/shared/storage/app/attachments/probe.txt
deny lefenda-backup touch /var/backups/lefenda/overwrite
allow root cat /srv/lefenda/shared/storage/app/attachments/probe.txt >/dev/null
echo 'Linux access verified: nginx public-only, runtime read-code/write-storage, backup no secrets/raw objects, deploy owns release.'
