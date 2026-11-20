# QA image only; no host .env, storage, private objects or databases copied.
FROM node:22-bookworm-slim AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN node --test --help >/dev/null && npm run build
FROM ubuntu:24.04
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-bcmath php8.3-gd nginx mysql-server mysql-client unzip git ca-certificates age && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes
COPY resources/views ./resources/views
COPY public/index.php public/.htaccess ./public/
COPY artisan ./artisan
COPY ops ./ops
COPY --from=assets /src/public/build ./public/build
RUN mkdir -p storage/framework/cache/data storage/framework/views storage/framework/sessions storage/logs && composer dump-autoload --no-dev --optimize --no-scripts && composer check-platform-reqs --no-dev && php -l ops/oracle/preflight.php && php-fpm8.3 -t && nginx -t
CMD ["sh", "-c", "test $(uname -m) = aarch64 && php -v && composer check-platform-reqs --no-dev"]
