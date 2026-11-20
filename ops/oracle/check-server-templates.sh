#!/usr/bin/env bash
set -euo pipefail
[[ -f /.dockerenv && ${APP_ENV:-} == testing && ${DISPOSABLE_RESTORE:-} == YES ]] || exit 2
# Synthetic TLS certificate inside disposable container; never a production certificate.
id lefenda >/dev/null 2>&1 || useradd --system --no-create-home lefenda
mkdir -p /srv/lefenda/shared/storage/logs /etc/letsencrypt/live/lefenda-qa.invalid /run/php
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=lefenda-qa.invalid -keyout /etc/letsencrypt/live/lefenda-qa.invalid/privkey.pem -out /etc/letsencrypt/live/lefenda-qa.invalid/fullchain.pem >/dev/null 2>&1
chmod 600 /etc/letsencrypt/live/lefenda-qa.invalid/privkey.pem
sed s/STAGING_HOST/lefenda-qa.invalid/g /app/ops/oracle/nginx.conf.template > /etc/nginx/conf.d/lefenda.conf
nginx -t
cp /app/ops/oracle/php-fpm.conf /etc/php/8.3/fpm/pool.d/lefenda.conf
php-fpm8.3 -t
mysqld --defaults-file=/app/ops/oracle/mysql.cnf --validate-config
echo 'Server-template parsing verified; no listeners or production TLS started.'
