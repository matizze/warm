# syntax=docker/dockerfile:1.7
# WARM — multi-stage image (composer vendors + Vite assets + php-fpm/nginx runtime).
#
# The SAME image runs three roles, switched by CONTAINER_MODE in the entrypoint:
#   app        -> nginx + php-fpm (web / webhook receiver)
#   worker     -> php artisan queue:work
#   scheduler  -> php artisan schedule:work

ARG PHP_VERSION=8.4

# Stage 1: Composer vendors
FROM composer:latest AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev --no-interaction --prefer-dist \
    --no-scripts --optimize-autoloader \
    --apcu-autoloader --ansi

# Stage 2: Frontend assets
FROM node:24-alpine AS assets
WORKDIR /src
COPY package*.json ./
# No lockfile committed yet; npm install keeps the build working. Switch to
# `npm ci` once a package-lock.json is added to the repository.
RUN npm install
COPY . .
RUN npm run build

# Stage 3: Runtime
FROM php:${PHP_VERSION}-fpm-alpine

ARG APP_USER=app
ARG WWWUSER=1000
ARG WWWGROUP=1000
ARG TZ=UTC

ENV DEBIAN_FRONTEND=noninteractive \
    CONTAINER_MODE=app \
    ROOT=/app \
    PORT=8000 \
    TZ=${TZ}

RUN apk add --no-cache \
        nginx supervisor tzdata poppler-utils \
        postgresql-dev libzip-dev oniguruma-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip opcache pcntl \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

WORKDIR ${ROOT}

# Copy application code, then the built artifacts from previous stages.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /src/public/build ./public/build

# Runtime configuration
COPY --chmod=755 deployment/runtime/entrypoint.sh /entrypoint.sh
COPY deployment/runtime/nginx.conf /etc/nginx/http.d/default.conf
COPY deployment/runtime/supervisord.conf /etc/supervisor.d/warm.ini
COPY deployment/runtime/fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf
COPY deployment/runtime/php.ini /usr/local/etc/php/conf.d/zz-warm.ini
COPY deployment/runtime/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

# Non-root user + writable dirs
RUN addgroup -g ${WWWGROUP} ${APP_USER} \
    && adduser -D -u ${WWWUSER} -G ${APP_USER} -s /bin/sh ${APP_USER} \
    && mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache \
    && rm -f bootstrap/cache/*.php \
    && chown -R ${APP_USER}:${APP_USER} storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

EXPOSE ${PORT}

ENTRYPOINT ["/entrypoint.sh"]
