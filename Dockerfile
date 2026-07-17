FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .

RUN composer dump-autoload --no-dev --optimize

FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions pdo_pgsql opcache zip \
    && printf '%s\n' \
        'opcache.validate_timestamps=0' \
        'opcache.revalidate_freq=0' \
        'memory_limit=512M' \
        'post_max_size=2048M' \
        'upload_max_filesize=2048M' \
        > /usr/local/etc/php/conf.d/zz-production-opcache.ini

WORKDIR /app

COPY --from=vendor /app /app
COPY deploy/Caddyfile /etc/caddy/Caddyfile

RUN mkdir -p \
        /config \
        /data \
        storage/app/private \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data /config /data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    CACHE_STORE=database \
    LOG_CHANNEL=stderr \
    QUEUE_CONNECTION=sync \
    SERVER_NAME=:8080 \
    SESSION_DRIVER=database

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["php", "-r", "$context = stream_context_create(['http' => ['timeout' => 2]]); exit(@file_get_contents('http://127.0.0.1:8080/up', false, $context) === false ? 1 : 0);"]

USER www-data
