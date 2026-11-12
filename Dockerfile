# syntax=docker/dockerfile:1
#
# calendar-sync image: FrankenPHP (Caddy + PHP 8.4) serving the panel on port 8080.
# The same image runs the three services of docker/compose.yaml: "web", "worker" and
# "scheduler" (see docker/entrypoint.sh).

FROM composer:2 AS composer

FROM dunglas/frankenphp:1-php8.4 AS app

# pcntl lets the queue worker stop a sync that runs past its timeout.
RUN install-php-extensions intl zip pdo_mysql pcntl opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-calendar-sync.ini"
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/calendar-sync

WORKDIR /app

# Dependencies first, so code changes reuse this layer.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction \
    && rm /usr/bin/composer \
    # Runs as www-data: the app writes only to storage/ and bootstrap/cache/, Caddy to
    # its own directories. Port 8080 needs no extra capability.
    && setcap -r /usr/local/bin/frankenphp \
    && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy

USER www-data

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database

# Credentials, the generated APP_KEY and the SQLite database (if used) live here.
VOLUME /app/storage/app

EXPOSE 8080

ENTRYPOINT ["calendar-sync"]
CMD ["web"]
