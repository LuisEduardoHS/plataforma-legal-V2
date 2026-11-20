#!/usr/bin/env bash
# Root-reviewed provisioning; no automatic execution by deployment or runtime.
set -euo pipefail
[[ $(id -u) == 0 && $# == 2 && $1 == --apply && $2 =~ ^[0-9a-f]{40}$ ]] || exit 2
release=/srv/lefenda/releases/$2
[[ -d "$release" && ! -L "$release" && $(realpath "$release") == "$release" ]] || exit 2
[[ -d "$release/public" && ! -L "$release/public" ]] || exit 2
for fixed in /srv/lefenda /srv/lefenda/releases /srv/lefenda/shared /srv/lefenda/shared/storage /var/backups/lefenda; do
 if [[ -e "$fixed" || -L "$fixed" ]]; then [[ ! -L "$fixed" && $(realpath "$fixed") == "$fixed" ]] || exit 2; fi
done
for user in deploy lefenda www-data lefenda-backup; do id "$user" >/dev/null; done
getent group lefenda-public >/dev/null
install -d -o root -g root -m 0755 /srv/lefenda
install -d -o deploy -g lefenda -m 0750 /srv/lefenda/releases
setfacl -m u:www-data:--x,u:lefenda-backup:--x /srv/lefenda/releases
find "$release" -type d -exec chown deploy:lefenda {} + -exec chmod 0750 {} +
find "$release" -type f -exec chown deploy:lefenda {} + -exec chmod 0640 {} +
setfacl -m u:www-data:--x "$release"
find "$release/public" -type d -exec chown deploy:lefenda-public {} + -exec chmod 0750 {} +
find "$release/public" -type f -exec chown deploy:lefenda-public {} + -exec chmod 0640 {} +
install -d -o root -g lefenda -m 0710 /srv/lefenda/shared
[[ -f /srv/lefenda/shared/.env && ! -L /srv/lefenda/shared/.env ]] || exit 2
chown deploy:lefenda /srv/lefenda/shared/.env
chmod 0640 /srv/lefenda/shared/.env
install -d -o lefenda -g lefenda -m 0750 /srv/lefenda/shared/storage
for path in framework/cache/data framework/views framework/sessions logs app/attachments app/private; do
 install -d -o lefenda -g lefenda -m 0750 /srv/lefenda/shared/storage/$path
done
# No nginx/backup ACL on private objects: Storage may enforce 0600, intentionally.
install -d -o root -g lefenda-backup -m 0750 /var/backups/lefenda
# Runtime can read but not modify precompiled cache manifests.
find "$release/bootstrap/cache" -type f -exec chown deploy:lefenda {} + -exec chmod 0640 {} +
# Backup exporter can read reviewed code/manifests, never the external runtime .env.
find "$release" -type d -exec setfacl -m u:lefenda-backup:r-x {} +
find "$release" -type f -exec setfacl -m u:lefenda-backup:r-- {} +
echo 'Reviewed Linux permissions applied; validate actual access before activation.'
