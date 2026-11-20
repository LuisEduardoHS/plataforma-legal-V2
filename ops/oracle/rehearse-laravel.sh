#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ -f /.dockerenv && ${APP_ENV:-} == testing && ${DISPOSABLE_RESTORE:-} == YES && $(uname -m) == aarch64 ]] || exit 2
work=$(mktemp -d /tmp/lefenda-laravel.XXXXXXXX)
pids=()
cleanup() { for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || true; done; wait || true; [[ "$work" == /tmp/lefenda-laravel.* ]] && rm -rf -- "$work"; }
trap cleanup EXIT
for instance in source restore; do
 mkdir "$work/$instance"
 mysqld --no-defaults --initialize-insecure --user=root --datadir="$work/$instance" > "$work/$instance.init.log" 2>&1
 mysqld --no-defaults --user=root --datadir="$work/$instance" --socket="$work/$instance.sock" --pid-file="$work/$instance.pid" --skip-networking --mysqlx=0 --log-error="$work/$instance.log" &
 pids+=("$!")
 printf '[client]\nuser=root\nsocket=%s\n' "$work/$instance.sock" > "$work/$instance.cnf"
 ready=NO
 for attempt in $(seq 1 90); do if mysqladmin --defaults-extra-file="$work/$instance.cnf" ping >/dev/null 2>&1; then ready=YES; break; fi; sleep 1; done
 [[ "$ready" == YES ]] || exit 1
 mysql --defaults-extra-file="$work/$instance.cnf" -e 'CREATE DATABASE lefenda_arm64_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
done
export DB_CONNECTION=mysql DB_HOST=localhost DB_PORT=3306 DB_DATABASE=lefenda_arm64_test EXPECTED_TEST_DB=lefenda_arm64_test DB_USERNAME=root DB_PASSWORD='' DB_URL='' DB_SOCKET="$work/source.sock"
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array LEXA_GENERATION_ENABLED=false LEXA_PROVIDER=null GEMINI_API_KEY='' BCRYPT_ROUNDS=4
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export LARAVEL_STORAGE_PATH="$work/storage" QA_STATE_PATH="$work/fixture.json"
for root in "$work/storage" "$work/restored-storage"; do mkdir -p "$root"/{framework/{cache/data,views,sessions,testing},logs,app/attachments}; done
mkdir "$work/cache"
export APP_CONFIG_CACHE="$work/cache/config.php" APP_PACKAGES_CACHE="$work/cache/packages.php" APP_SERVICES_CACHE="$work/cache/services.php" APP_ROUTES_CACHE="$work/cache/routes.php" APP_EVENTS_CACHE="$work/cache/events.php"
php ops/oracle/laravel-qa-state.php migrate
# Related suites have configurable DEV-01 guard and do not require a legacy database name.
if [[ ${QA_SKIP_RELATED:-NO} != YES ]]; then
# Select files explicitly: a substring filter also matches unrelated test method names.
printf '<phpunit bootstrap="/app/ops/oracle/laravel-qa-bootstrap.php"><testsuites><testsuite name="ARM64 related">' > "$work/selected.xml"
for file in /app/tests/Feature/Lexa*Test.php /app/tests/Feature/CommunityMediaIntegrationTest.php /app/tests/Feature/MessagingIntegrationTest.php /app/tests/Feature/EditorialMediaIntegrationTest.php /app/tests/Feature/ProfileIntegrationTest.php; do
 printf '<file>%s</file>' "$file" >> "$work/selected.xml"
done
printf '</testsuite></testsuites></phpunit>' >> "$work/selected.xml"
QA_PHPUNIT=YES php vendor/phpunit/phpunit/phpunit -c "$work/selected.xml"
fi
QA_PHASE=seed QA_PHPUNIT=YES php vendor/phpunit/phpunit/phpunit --bootstrap ops/oracle/laravel-qa-bootstrap.php ops/oracle/RestoredApplicationTest.php
php ops/oracle/laravel-qa-state.php snapshot "$work/before.json"
mkdir "$work/bundle" "$work/bundle/objects" "$work/unpacked"
mysqldump --defaults-extra-file="$work/source.cnf" --single-transaction --quick --routines --events --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF lefenda_arm64_test > "$work/bundle/database.sql"
cp -a "$work/storage/app/attachments" "$work/bundle/objects/"
(cd "$work/bundle"; sha256sum database.sql > database.sha256; find objects -type f -print0 | sort -z | xargs -0 -r sha256sum > objects.sha256)
age-keygen -o "$work/data-key" 2>/dev/null
age-keygen -o "$work/config-key" 2>/dev/null
tar -C "$work/bundle" -cf - . | age -r "$(age-keygen -y "$work/data-key")" -o "$work/backup.age"
printf 'APP_KEY=%s\n' "$APP_KEY" > "$work/config.env"
age -r "$(age-keygen -y "$work/config-key")" -o "$work/config.age" "$work/config.env"
age -d -i "$work/config-key" "$work/config.age" > "$work/recovered.env"
cmp "$work/config.env" "$work/recovered.env"
age -d -i "$work/data-key" "$work/backup.age" | tar -xf - -C "$work/unpacked"
(cd "$work/unpacked"; sha256sum -c database.sha256 objects.sha256 >/dev/null)
[[ $(mysql --defaults-extra-file="$work/restore.cnf" --database=lefenda_arm64_test -Nse 'SELECT DATABASE()') == lefenda_arm64_test ]]
mysql --defaults-extra-file="$work/restore.cnf" --database=lefenda_arm64_test < "$work/unpacked/database.sql"
cp -a "$work/unpacked/objects/attachments" "$work/restored-storage/app/"
export DB_SOCKET="$work/restore.sock" LARAVEL_STORAGE_PATH="$work/restored-storage"
export APP_KEY="$(sed -n 's/^APP_KEY=//p' "$work/recovered.env")"
php ops/oracle/laravel-qa-state.php snapshot "$work/after.json"
php -r '
$a=json_decode(file_get_contents($argv[1]),true);$b=json_decode(file_get_contents($argv[2]),true);$failed=false;
foreach($a as &$item)unset($item["raw_definition"]);unset($item);foreach($b as &$item)unset($item["raw_definition"]);unset($item);
foreach($a as $table=>$state){if($state!==($b[$table]??null)){echo "Snapshot difference: ".$table."\n";foreach($state as $key=>$value){if($value!==($b[$table][$key]??null)){echo "  ".$key." differs\n";if($key==="definition"){echo $value."\nRESTORED:\n".$b[$table][$key]."\n";}}}$failed=true;}}
if(array_keys($a)!==array_keys($b))$failed=true;exit($failed?1:0);
' "$work/before.json" "$work/after.json"
(cd "$work/unpacked"; sha256sum -c objects.sha256 >/dev/null)
QA_PHASE=restore QA_PHPUNIT=YES php vendor/phpunit/phpunit/phpunit --bootstrap ops/oracle/laravel-qa-bootstrap.php ops/oracle/RestoredApplicationTest.php
printf 'Full Laravel synthetic schema/data/objects/citations/authorization restore verified on %s.\n' "$(uname -m)"
