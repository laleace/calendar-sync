#!/usr/bin/env bash
# Updates calendar-sync to the latest commit of the current branch.
# Run as root on the container:  bash /var/www/calendar-sync/deploy/update.sh
set -euo pipefail

APP_DIR=/var/www/calendar-sync
APP_USER=calsync
PHP_FPM=php8.4-fpm

# runuser keeps root's HOME: give composer and git the app user's home.
as_app() { runuser -u "$APP_USER" -- env HOME="/home/$APP_USER" "$@"; }

cd "$APP_DIR"

as_app php artisan down --retry=15 || true
trap 'as_app php artisan up' EXIT

as_app git pull --ff-only
as_app composer install --no-dev --optimize-autoloader --no-interaction
as_app php artisan migrate --force
as_app php artisan optimize
as_app php artisan filament:optimize

# Workers finish the current job and restart with the new code; OPcache is reset.
as_app php artisan queue:restart
systemctl reload "$PHP_FPM"

as_app php artisan up
trap - EXIT

as_app php artisan calsync:doctor || true
