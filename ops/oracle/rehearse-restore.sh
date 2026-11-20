#!/usr/bin/env bash
# Only inside disposable QA container. Never mounts a host storage or MySQL socket.
set -euo pipefail
umask 077
[[ -f /.dockerenv && ${APP_ENV:-} == testing && ${DISPOSABLE_RESTORE:-} == YES ]] || exit 2
work=$(mktemp -d /tmp/lefenda-restore.XXXXXXXX)
pids=()
cleanup() {
  for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || true; done
  wait || true
  [[ "$work" == /tmp/lefenda-restore.* ]] && rm -rf -- "$work"
}
trap cleanup EXIT
for instance in source restore; do
  mkdir "$work/$instance"
  mysqld --no-defaults --initialize-insecure --user=root --datadir="$work/$instance" >"$work/$instance.init.log" 2>&1
  mysqld --no-defaults --user=root --datadir="$work/$instance" --socket="$work/$instance.sock" --pid-file="$work/$instance.pid" --skip-networking --mysqlx=0 --log-error="$work/$instance.log" &
  pids+=("$!")
  printf '[client]\nuser=root\nsocket=%s\n' "$work/$instance.sock" > "$work/$instance.cnf"
  ready=NO
  for attempt in $(seq 1 60); do
    if mysqladmin --defaults-extra-file="$work/$instance.cnf" ping >/dev/null 2>&1; then ready=YES; break; fi
    sleep 1
  done
  [[ "$ready" == YES ]] || exit 1
  mysql --defaults-extra-file="$work/$instance.cnf" -e 'CREATE DATABASE lefenda_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
done
# These isolated mysqld instances cannot reach any host DB; assert effective schema before fixtures.
[[ $(mysql --defaults-extra-file="$work/source.cnf" --database=lefenda_staging -Nse 'SELECT DATABASE()') == lefenda_staging ]]
mysql --defaults-extra-file="$work/source.cnf" --database=lefenda_staging <<'SQL'
CREATE TABLE owners(id BIGINT PRIMARY KEY, label VARCHAR(40)) ENGINE=InnoDB;
CREATE TABLE attachments(id BIGINT PRIMARY KEY, owner_id BIGINT NOT NULL, meta JSON, FOREIGN KEY(owner_id) REFERENCES owners(id)) ENGINE=InnoDB;
INSERT INTO owners VALUES(1,'synthetic');
INSERT INTO attachments VALUES(1,1,JSON_OBJECT('path','synthetic.txt'));
CREATE VIEW synthetic_view AS SELECT id FROM owners;
CREATE TRIGGER synthetic_trigger BEFORE INSERT ON owners FOR EACH ROW SET NEW.label=UPPER(NEW.label);
CREATE PROCEDURE synthetic_count() SELECT COUNT(*) FROM attachments;
CREATE EVENT synthetic_event ON SCHEDULE EVERY 1 DAY DO SELECT 1;
SQL
mkdir -p /srv/lefenda/shared/storage/app/attachments "$work/encrypted" "$work/unpacked"
printf 'synthetic private object\n' > /srv/lefenda/shared/storage/app/attachments/synthetic.txt
age-keygen -o "$work/key" 2> "$work/key.log"
recipient=$(age-keygen -y "$work/key")
# Structural fixture only. Accredited production backup is tested by rehearse-security.sh.
mkdir -p "$work/legacy-bundle/objects"
mysqldump --defaults-extra-file="$work/source.cnf" --single-transaction --routines --events --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF lefenda_staging > "$work/legacy-bundle/database.sql"
cp -a /srv/lefenda/shared/storage/app/attachments "$work/legacy-bundle/objects/"
(cd "$work/legacy-bundle"; sha256sum database.sql > database.sha256; find objects -type f -print0 | sort -z | xargs -0 -r sha256sum > objects.sha256)
tar -C "$work/legacy-bundle" -cf - . | age -r "$recipient" -o "$work/encrypted/staging.tar.age"
(cd "$work/encrypted"; sha256sum staging.tar.age > staging.tar.age.sha256)
(cd "$work/encrypted"; sha256sum -c staging.tar.age.sha256 >/dev/null)
age -d -i "$work/key" "$work/encrypted/staging.tar.age" | tar -xf - -C "$work/unpacked"
(cd "$work/unpacked"; sha256sum -c database.sha256 objects.sha256 >/dev/null)
mysql --defaults-extra-file="$work/restore.cnf" --database=lefenda_staging < "$work/unpacked/database.sql"
for instance in source restore; do
 mysql --defaults-extra-file="$work/$instance.cnf" --database=lefenda_staging -Nse "SELECT * FROM owners; SELECT * FROM attachments; SHOW CREATE TABLE attachments; SHOW CREATE VIEW synthetic_view; SHOW TRIGGERS; SHOW PROCEDURE STATUS WHERE Db=DATABASE(); SHOW EVENTS;" > "$work/$instance.state"
done
# SHOW status timestamps may differ: assert rows, FK, trigger, routine, event individually.
[[ $(mysql --defaults-extra-file="$work/restore.cnf" --database=lefenda_staging -Nse 'SELECT COUNT(*) FROM attachments WHERE owner_id=1') == 1 ]]
[[ $(mysql --defaults-extra-file="$work/restore.cnf" --database=lefenda_staging -Nse "SELECT COUNT(*) FROM information_schema.triggers WHERE TRIGGER_SCHEMA=DATABASE()") == 1 ]]
[[ $(mysql --defaults-extra-file="$work/restore.cnf" --database=lefenda_staging -Nse "SELECT COUNT(*) FROM information_schema.routines WHERE ROUTINE_SCHEMA=DATABASE()") == 1 ]]
[[ $(mysql --defaults-extra-file="$work/restore.cnf" --database=lefenda_staging -Nse "SELECT COUNT(*) FROM information_schema.events WHERE EVENT_SCHEMA=DATABASE()") == 1 ]]
for instance in source restore; do
 mysql --defaults-extra-file="$work/$instance.cnf" --database=lefenda_staging -Nse 'SELECT * FROM owners; SELECT * FROM attachments; SHOW CREATE TABLE attachments; SHOW CREATE VIEW synthetic_view;' > "$work/$instance.compare"
done
cmp "$work/source.compare" "$work/restore.compare"
cp -a "$work/unpacked/objects" "$work/restored-objects"
cmp /srv/lefenda/shared/storage/app/attachments/synthetic.txt "$work/restored-objects/attachments/synthetic.txt"
# Independent custody of configuration/key is a distinct encrypted artifact.
printf 'APP_KEY=synthetic-only\n' > "$work/secret.env"
age -r "$recipient" -o "$work/config.age" "$work/secret.env"
age -d -i "$work/key" -o "$work/config.restored" "$work/config.age"
cmp "$work/secret.env" "$work/config.restored"
echo 'Synthetic full SQL + objects + independent encrypted configuration restoration verified.'
