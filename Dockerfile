# syntax directive removed: hosted builder parses plain Dockerfile instructions only.
# The API and Next.js UI are separate services; no .env or user SQLite file is copied.

# ---------- Shared PHP 8.3 runtime ----------
FROM php:8.3-cli-alpine AS php-base

RUN apk add --no-cache --virtual .ext-build-deps \
        $PHPIZE_DEPS \
        libzip-dev \
        libxml2-dev \
        oniguruma-dev \
    && apk add --no-cache libzip libxml2 oniguruma \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        xml \
        zip \
    && apk del .ext-build-deps

# ---------- Laravel dependency build (same PHP/extension platform as runtime) ----------
FROM php-base AS backend-builder

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
WORKDIR /app/backend
COPY backend/composer.json backend/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-autoloader

COPY backend/ ./
# Never carry development package-discovery manifests into the production image.
# They can reference dev-only providers (for example Laravel Pail) excluded by
# composer install --no-dev and prevent Laravel from booting at runtime.
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && composer dump-autoload --no-dev --no-interaction --optimize \
    && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && rm -rf tests phpunit.xml .phpunit.result.cache

# ---------- Isolated backend test runner (never used for production) ----------
FROM php:8.3-cli-alpine AS backend-test
RUN apk add --no-cache libzip \
    && apk add --no-cache --virtual .test-build-deps $PHPIZE_DEPS sqlite-dev libzip-dev libxml2-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath exif mbstring opcache pcntl pdo_mysql pdo_sqlite xml zip \
    && apk del .test-build-deps
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
WORKDIR /app/backend
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist
COPY backend/ ./
COPY docs/ /app/docs/
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && composer dump-autoload --no-interaction --optimize \
    && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
CMD ["php", "artisan", "test"]

# ---------- Next.js production build ----------
FROM node:22-alpine AS frontend-builder

WORKDIR /app/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./

# NEXT_PUBLIC_* values are inlined into browser assets at build time.
ARG NEXT_PUBLIC_API_URL=http://localhost:8081/api/v1
ENV NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL}
ENV NEXT_TELEMETRY_DISABLED=1

RUN npm run build \
    && mkdir -p .next/standalone/.next \
    && cp -R .next/static .next/standalone/.next/static \
    && cp -R public .next/standalone/public

# ---------- Minimal Next.js runtime ----------
FROM node:22-alpine AS frontend-runtime

WORKDIR /app
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0

COPY --from=frontend-builder --chown=node:node /app/frontend/.next/standalone ./
USER node
EXPOSE 3000
CMD ["node", "server.js"]

# ---------- Laravel API runtime (default Docker target) ----------
FROM php-base AS runtime

WORKDIR /var/www/html
COPY --from=backend-builder /app/backend/ ./

RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# APP_KEY and database credentials must be provided by the runtime environment.
# Do not bake secrets into an image or generate a shared key while building it.
USER www-data
EXPOSE 8000
# Run idempotent production migrations at container boot; `artisan serve` then
# takes over. Failed migrations keep the old image running (blitz keeps the
# previous container until a new one is proven).
CMD ["sh", "-c", "php artisan migrate --force && exec php artisan serve --host=0.0.0.0 --port=8000"]
