#!/usr/bin/env bash
# Run only during an authorized quiesced staging window, not against development.
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo "Root orchestration required; exporter runs as backup user" >&2; exit 2; }
umask 077
script=$(realpath "${BASH_SOURCE[0]}")
[[ $(stat -c %u "$script") == 0 ]] || exit 2
check=$script
while [[ "$check" != / ]]; do
 [[ $(stat -c %u "$check") == 0 ]] || exit 2
 mode=$(stat -c %a "$check"); (( (8#$mode & 8#022) == 0 )) || exit 2
 check=$(dirname "$check")
done
script_dir=$(dirname "$script")
[[ $# == 3 ]] || { echo 'Usage: backup.sh CLIENT_CNF EXISTING_EMPTY_OUTPUT AGE_RECIPIENT' >&2; exit 2; }
cnf=$(realpath "$1"); out=$(realpath "$2"); recipient=$3
[[ "$out" == /var/backups/lefenda/* && $(stat -c %u "$out") == 0 && $(stat -c %a "$out") == 750 ]] || exit 2
[[ ${QUIESCED_STAGING:-} == YES && -f "$cnf" && -d "$out" && -z $(ls -A "$out") ]] || exit 2
[[ $(stat -c %a "$cnf") == 600 ]] || exit 2
# Protected local identity and dedicated backup grants are checked by PHP.
# Export uses explicit socket transport; CNF cannot select a host or another account.
app_dir=${LEFENDA_APP_DIR:-/srv/lefenda/current}
if [[ ! -f "$app_dir/bootstrap/app.php" && -f "$PWD/bootstrap/app.php" ]]; then
 app_dir=$PWD
fi
[[ -f "$app_dir/bootstrap/app.php" && -f "$app_dir/vendor/autoload.php" ]] || { echo "Release directory unavailable" >&2; exit 2; }
work=$(mktemp -d); trap 'rm -rf -- "$work"' EXIT
(cd "$app_dir" && runuser -u lefenda-backup -- env -i PATH=/usr/bin:/bin php "$script_dir/backup-export.php" "$cnf") > "$work/database.sql"
[[ -z $(find /srv/lefenda/shared/storage/app/attachments -type l -print -quit) ]] || { echo "Unexpected attachment symlink" >&2; exit 2; }
mkdir "$work/objects"
cp -a /srv/lefenda/shared/storage/app/attachments "$work/objects/"
(cd "$work"; find objects -type f -print0 | sort -z | xargs -0 -r sha256sum > objects.sha256; sha256sum database.sql > database.sha256)
tar -C "$work" -cf - . | age -r "$recipient" -o "$out/staging.tar.age"
sha256sum "$out/staging.tar.age" > "$out/staging.tar.age.sha256"
echo 'Encrypted backup created; NOT verified until restore rehearsal succeeds.'
chown root:lefenda-backup "$out/staging.tar.age" "$out/staging.tar.age.sha256"
chmod 0640 "$out/staging.tar.age" "$out/staging.tar.age.sha256"
