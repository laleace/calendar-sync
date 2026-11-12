#!/usr/bin/env bash
# Entry point of the calendar-sync image. The first argument picks the role:
#   web        the panel, served by FrankenPHP on port 8080; runs the migrations first
#   worker     the queue worker that runs the syncs
#   scheduler  the Laravel scheduler: queues the due jobs every minute
# Any other command runs as is, e.g. "php artisan calsync:doctor".
set -euo pipefail

cd /app

role="${1:-web}"
key_file=storage/app/app.key

# The data volume may come from an older image: recreate what the app expects.
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs

# APP_KEY encrypts the account credentials. If it is not set, the web container
# generates one at the first start and keeps it in the data volume.
if [[ -z "${APP_KEY:-}" ]]; then
    if [[ ! -s "$key_file" && "$role" == web ]]; then
        (umask 077 && php artisan key:generate --show --no-ansi > "$key_file.tmp" && mv "$key_file.tmp" "$key_file")
        echo "calendar-sync: generated APP_KEY in the data volume (/app/$key_file)."
        echo "calendar-sync: back it up, without it the stored credentials cannot be decrypted."
    fi

    for _ in $(seq 1 30); do
        [[ -s "$key_file" ]] && break
        echo "calendar-sync: waiting for the web container to generate APP_KEY..."
        sleep 2
    done

    if [[ ! -s "$key_file" ]]; then
        echo "calendar-sync: APP_KEY is not set and /app/$key_file does not exist." >&2
        exit 1
    fi

    APP_KEY="$(cat "$key_file")"
    export APP_KEY
fi

if [[ "${DB_CONNECTION:-}" == sqlite && -n "${DB_DATABASE:-}" && ! -e "$DB_DATABASE" ]]; then
    mkdir -p "$(dirname "$DB_DATABASE")"
    touch "$DB_DATABASE"
fi

# Config, routes, views and Filament components are cached per container.
prepare() {
    php artisan optimize --no-ansi > /dev/null
    php artisan filament:optimize --no-ansi > /dev/null
}

case "$role" in
    web)
        php artisan migrate --force --no-ansi
        prepare
        exec frankenphp run --config /etc/frankenphp/Caddyfile
        ;;
    worker)
        prepare
        # --timeout exceeds SyncJobRunner::$timeout (600 s); retry_after (900 s) exceeds both.
        exec php artisan queue:work database --queue=sync,default --tries=1 --timeout=660 --sleep=3 --memory=256
        ;;
    scheduler)
        prepare
        exec php artisan schedule:work
        ;;
    *)
        exec "$@"
        ;;
esac
