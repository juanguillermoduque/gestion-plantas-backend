# syntax=docker/dockerfile:1
FROM composer:2 AS composer

FROM php:8.4-fpm-bookworm
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       git unzip libpq-dev libonig-dev libzip-dev libicu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring zip intl bcmath pcntl opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
       storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY <<'INI' /usr/local/etc/php/conf.d/production.ini
expose_php=Off
display_errors=Off
log_errors=On
error_log=/proc/self/fd/2
memory_limit=256M
upload_max_filesize=5M
post_max_size=6M
opcache.enable=1
opcache.validate_timestamps=0
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
INI

COPY --chmod=755 <<'SH' /usr/local/bin/start-app
#!/bin/sh
set -eu
if [ "${1:-}" = "php-fpm" ]; then
    if [ -z "${APP_KEY:-}" ]; then
        printf '%s\n' 'APP_KEY no está configurada. Genera la clave antes de iniciar el servidor.' >&2
        exit 1
    fi
    php artisan config:cache
fi
exec "$@"
SH

USER www-data
EXPOSE 9000
ENTRYPOINT ["start-app"]
CMD ["php-fpm"]
