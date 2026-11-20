#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ -f /.dockerenv && ${APP_ENV:-} == testing && ${DISPOSABLE_RESTORE:-} == YES ]] || exit 2
work=$(mktemp -d /tmp/lefenda-security.XXXXXXXX)
pid=''
cleanup() { [[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; wait || true; [[ "$work" == /tmp/lefenda-security.* ]] && rm -rf -- "$work"; }
trap cleanup EXIT
chmod 0755 "$work"
mkdir "$work/db"; chown mysql:mysql "$work/db"
install -d -o root -g root -m 0755 /run/lefenda-mysql-qa /etc/lefenda
chown mysql:mysql /run/lefenda-mysql-qa
mysqld --no-defaults --initialize-insecure --user=mysql --datadir="$work/db" > "$work/init.log" 2>&1
mysqld --no-defaults --user=mysql --datadir="$work/db" --socket=/run/lefenda-mysql-qa/mysql.sock --pid-file=/run/lefenda-mysql-qa/mysql.pid --skip-networking --mysqlx=0 --partial-revokes=ON --log-error="$work/db/server.log" &
pid=$!
printf '[client]\nuser=root\nsocket=/run/lefenda-mysql-qa/mysql.sock\n' > "$work/root.cnf"
ready=NO
for i in $(seq 1 90); do if mysqladmin --defaults-extra-file="$work/root.cnf" ping >/dev/null 2>&1; then ready=YES; break; fi; sleep 1; done
[[ "$ready" == YES ]]
# Attestation directory is root-owned; mysqld already bound its socket.
chown root:root /run/lefenda-mysql-qa
python3 ops/oracle/mysql-peer.py enroll /run/lefenda-mysql-qa/mysql.sock "$work/db"
cp /etc/lefenda/mysql-identity.json "$work/identity.json"
secret=$(php -r 'echo bin2hex(random_bytes(32));')
mysql --defaults-extra-file="$work/root.cnf" <<SQL
CREATE DATABASE lefenda_staging;
CREATE DATABASE not_staging;
CREATE DATABASE lefendaXstaging;
CREATE TABLE lefendaXstaging.permission_probe(id INT);
CREATE TABLE lefenda_staging.permission_probe(id INT PRIMARY KEY);
INSERT INTO lefenda_staging.permission_probe VALUES(1);
CREATE TABLE not_staging.permission_probe(id INT);
CREATE VIEW lefenda_staging.permission_view AS SELECT id FROM lefenda_staging.permission_probe;
CREATE TRIGGER lefenda_staging.synthetic_trigger BEFORE INSERT ON lefenda_staging.permission_probe FOR EACH ROW SET NEW.id=NEW.id;
CREATE PROCEDURE lefenda_staging.synthetic_count() SELECT COUNT(*) FROM lefenda_staging.permission_probe;
CREATE EVENT lefenda_staging.synthetic_event ON SCHEDULE EVERY 1 DAY DO SELECT 1;
CREATE USER 'lefenda_runtime'@'localhost' IDENTIFIED BY '$secret';
CREATE USER 'lefenda_migrator'@'localhost' IDENTIFIED BY '$secret';
CREATE USER 'lefenda_backup'@'localhost' IDENTIFIED BY '$secret';
SQL
mysql --defaults-extra-file="$work/root.cnf" < ops/oracle/grants.sql.template
export APP_ENV=production APP_DEBUG=false APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" DB_CONNECTION=mysql DB_HOST=localhost DB_SOCKET=/run/lefenda-mysql-qa/mysql.sock DB_DATABASE=lefenda_staging DB_PASSWORD="$secret" DB_URL='' CACHE_STORE=database SESSION_DRIVER=database SESSION_SECURE_COOKIE=true SESSION_HTTP_ONLY=true SESSION_SAME_SITE=lax APP_URL=https://lefenda-qa.invalid QUEUE_CONNECTION=database MAIL_MAILER=array LEXA_GENERATION_ENABLED=false
# Only synthetic fixture migration; NEVER the 39 application migrations.
find /app/database/migrations -maxdepth 1 -name '*.php' -delete
cat > /app/database/migrations/2099_01_01_000001_security_probe.php <<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
 public function up():void { Illuminate\Support\Facades\Schema::create('approved_probe',fn($t)=>$t->id()); }
 public function down():void { throw new RuntimeException('No automatic rollback'); }
};
PHP
DB_USERNAME=lefenda_runtime php ops/oracle/preflight.php lefenda_staging
for role in runtime migrator backup; do DB_USERNAME=lefenda_$role php ops/oracle/security-checks.php "$role"; done
expect_fail() { if "$@" > "$work/rejected.log" 2>&1; then echo 'Negative security scenario unexpectedly accepted' >&2; exit 1; fi; }
expect_fail env DB_USERNAME=lefenda_runtime APP_ENV=local php ops/oracle/preflight.php lefenda_staging
expect_fail env DB_USERNAME=lefenda_runtime APP_DEBUG=true php ops/oracle/preflight.php lefenda_staging
expect_fail env DB_USERNAME=lefenda_runtime DB_DATABASE=not_staging php ops/oracle/preflight.php lefenda_staging
expect_fail env DB_USERNAME=lefenda_runtime DB_HOST=remote.invalid php ops/oracle/preflight.php lefenda_staging
expect_fail env DB_USERNAME=lefenda_runtime DB_SOCKET=/tmp/unapproved.sock php ops/oracle/preflight.php lefenda_staging
expect_fail env DB_USERNAME=lefenda_runtime QUEUE_CONNECTION=sync php ops/oracle/preflight.php lefenda_staging
expect_fail env DB_USERNAME=lefenda_runtime LEXA_GENERATION_ENABLED=true php ops/oracle/preflight.php lefenda_staging
expect_fail env DB_USERNAME=lefenda_runtime SESSION_SECURE_COOKIE=false php ops/oracle/preflight.php lefenda_staging
expect_fail env DB_USERNAME=lefenda_runtime APP_KEY='' php ops/oracle/preflight.php lefenda_staging

python3 -c 'import json;p="/etc/lefenda/mysql-identity.json";d=json.load(open(p));d["pid"]+=1;json.dump(d,open(p,"w"))'
expect_fail env DB_USERNAME=lefenda_runtime php ops/oracle/preflight.php lefenda_staging
cp "$work/identity.json" /etc/lefenda/mysql-identity.json
chmod 0666 /etc/lefenda/mysql-identity.json
expect_fail env DB_USERNAME=lefenda_runtime php ops/oracle/preflight.php lefenda_staging
chmod 0644 /etc/lefenda/mysql-identity.json
python3 -c 'import json;p="/etc/lefenda/mysql-identity.json";d=json.load(open(p));d["uuid"]="wrong";json.dump(d,open(p,"w"))'
expect_fail env DB_USERNAME=lefenda_runtime php ops/oracle/preflight.php lefenda_staging
cp "$work/identity.json" /etc/lefenda/mysql-identity.json
mysql --defaults-extra-file="$work/root.cnf" -e "GRANT FILE ON *.* TO 'lefenda_runtime'@'localhost'"
expect_fail env DB_USERNAME=lefenda_runtime php ops/oracle/preflight.php lefenda_staging
mysql --defaults-extra-file="$work/root.cnf" -e "REVOKE FILE ON *.* FROM 'lefenda_runtime'@'localhost'"
mysql --defaults-extra-file="$work/root.cnf" -e "REVOKE ALTER ON lefenda_staging.* FROM 'lefenda_migrator'@'localhost'"
expect_fail env DB_USERNAME=lefenda_migrator php ops/oracle/migrate-approved.php plan
mysql --defaults-extra-file="$work/root.cnf" -e "GRANT ALTER ON lefenda_staging.* TO 'lefenda_migrator'@'localhost'"
echo 'Wrong environment/debug/database/transport/UUID/registry permissions/excess or missing grants rejected.'
DB_USERNAME=lefenda_migrator php ops/oracle/migrate-approved.php plan > "$work/plan.json"
digest=$(sha256sum "$work/plan.json" | cut -d' ' -f1)
expect_fail env DB_USERNAME=lefenda_migrator QUIESCED_STAGING=YES php ops/oracle/migrate-approved.php apply "$work/plan.json" invalid
expect_fail env DB_USERNAME=lefenda_migrator php ops/oracle/migrate-approved.php fresh
cp /app/database/migrations/2099_01_01_000001_security_probe.php "$work/fixture.php"
echo '// changed plan' >> /app/database/migrations/2099_01_01_000001_security_probe.php
expect_fail env DB_USERNAME=lefenda_migrator QUIESCED_STAGING=YES php ops/oracle/migrate-approved.php apply "$work/plan.json" "$digest"
cp "$work/fixture.php" /app/database/migrations/2099_01_01_000001_security_probe.php
[[ $(mysql --defaults-extra-file="$work/root.cnf" -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA='lefenda_staging' AND TABLE_NAME='approved_probe'") == 0 ]]
# This is the only synthetic migration applied; no application migrations executed.
DB_USERNAME=lefenda_migrator QUIESCED_STAGING=YES php ops/oracle/migrate-approved.php apply "$work/plan.json" "$digest"
expect_fail env DB_USERNAME=lefenda_migrator QUIESCED_STAGING=YES php ops/oracle/migrate-approved.php apply "$work/plan.json" "$digest"
echo 'Synthetic-only migration approval, changed plan and replay gates verified.'
bash ops/oracle/rehearse-permissions.sh
chmod 0644 /app/bootstrap/cache/*.php
runuser -u lefenda -- env DB_USERNAME=lefenda_runtime php ops/oracle/preflight.php lefenda_staging
expect_fail env DB_USERNAME=lefenda_runtime php ops/oracle/migrate-approved.php plan
# Backup profile has no access to the runtime .env or APP_KEY.
printf '[client]\nuser=lefenda_backup\npassword=%s\n' "$secret" > /etc/lefenda/backup.cnf
chown lefenda-backup:lefenda-backup /etc/lefenda/backup.cnf; chmod 0600 /etc/lefenda/backup.cnf
expect_fail runuser -u lefenda -- cat /etc/lefenda/backup.cnf
expect_fail runuser -u lefenda -- sh -c 'echo forbidden >> /etc/lefenda/mysql-identity.json'
expect_fail runuser -u deploy -- sh -c 'echo forbidden >> /etc/lefenda/mysql-identity.json'
sed 's|/run/mysqld/mysqld.sock|/run/lefenda-mysql-qa/mysql.sock|;s|/srv/lefenda/current/bootstrap/cache/|/app/bootstrap/cache/|g' ops/oracle/backup.env.example > /etc/lefenda/backup.env
chown root:lefenda-backup /etc/lefenda/backup.env; chmod 0640 /etc/lefenda/backup.env
chmod 0644 /app/bootstrap/cache/*.php
mkdir /var/backups/lefenda/synthetic; chown root:lefenda-backup /var/backups/lefenda/synthetic; chmod 0750 /var/backups/lefenda/synthetic
age-keygen -o "$work/age-key" 2>/dev/null
QUIESCED_STAGING=YES bash ops/oracle/backup.sh /etc/lefenda/backup.cnf /var/backups/lefenda/synthetic "$(age-keygen -y "$work/age-key")"
mkdir "$work/recovered"
age -d -i "$work/age-key" /var/backups/lefenda/synthetic/staging.tar.age | tar -xf - -C "$work/recovered"
(cd "$work/recovered"; sha256sum -c database.sha256 objects.sha256 >/dev/null)
expect_fail runuser -u lefenda-backup -- touch /var/backups/lefenda/synthetic/no-write
runuser -u lefenda-backup -- cat /var/backups/lefenda/synthetic/staging.tar.age >/dev/null
printf '\nhost=remote.invalid\n' >> /etc/lefenda/backup.cnf
expect_fail runuser -u lefenda-backup -- env -i PATH=/usr/bin:/bin php ops/oracle/backup-export.php /etc/lefenda/backup.cnf
echo 'SECURITY QA PASSED: isolated MySQL identities/grants, synthetic migration, Linux denials, accredited encrypted backup.'
