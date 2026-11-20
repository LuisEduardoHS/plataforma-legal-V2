# Extended, disposable testing image only; no secrets or host data copied.
FROM node:22-bookworm-slim AS frontend
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY tests/frontend ./tests/frontend
COPY vite.config.js ./
RUN node --test tests/frontend/*.test.mjs && npm run build
FROM lefenda-arm64-qa
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-scripts && composer check-platform-reqs
COPY tests ./tests
COPY phpunit.xml ./
COPY public ./public
COPY ops ./ops
COPY --from=frontend /src/public/build ./public/build
RUN composer dump-autoload --optimize --no-scripts
CMD ["bash", "ops/oracle/rehearse-laravel.sh"]
