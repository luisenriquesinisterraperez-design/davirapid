#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/html"
cd "$APP_DIR"

echo "[entrypoint] Ensuring writable dirs..."
mkdir -p tmp/cache/models tmp/cache/persistent tmp/cache/views tmp/sessions logs
chown -R www-data:www-data tmp logs
chmod -R 0775 tmp logs

if [ ! -f config/app_local.php ]; then
    echo "[entrypoint] config/app_local.php missing; copying example."
    cp config/app_local.example.php config/app_local.php
fi

if [ "${CLEAR_CACHE:-1}" = "1" ]; then
    echo "[entrypoint] Clearing model/orm cache..."
    php bin/cake.php cache clear_all || true
fi

echo "[entrypoint] Starting: $*"
exec "$@"
