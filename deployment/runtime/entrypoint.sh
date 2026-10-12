#!/bin/sh
set -e

PORT="${PORT:-8000}"
APP_ROOT="${ROOT:-/app}"
APP_USER="${APP_USER:-app}"

cd "$APP_ROOT" || { echo "ERROR: Failed to cd to $APP_ROOT"; exit 1; }

if [ ! -f "artisan" ]; then
    echo "ERROR: artisan file not found in $(pwd)"
    ls -la "$APP_ROOT" 2>/dev/null || echo "Directory not accessible"
    exit 1
fi

# Ensure writable dirs exist and are owned by the app user even when the
# container is recreated with fresh volumes.
mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache
chown -R "$APP_USER:$APP_USER" storage bootstrap/cache 2>/dev/null || true

CONTAINER_MODE="${CONTAINER_MODE:-app}"

# Laravel caches. Requires APP_KEY; without it we fail fast because cached
# config would be broken.
if [ -z "${APP_KEY:-}" ]; then
    echo "ERROR: APP_KEY is not defined; refusing to start."
    exit 1
fi

echo "Caching Laravel configuration..."
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache

case "$CONTAINER_MODE" in
    worker)
        echo "Mode: Worker"
        COMMAND="${COMMAND:-php artisan queue:work --verbose --tries=3 --timeout=300 --queue=default}"
        echo "Executing command: $COMMAND"
        exec $COMMAND
        ;;
    scheduler)
        echo "Mode: Scheduler"
        COMMAND="${COMMAND:-php artisan schedule:work}"
        echo "Executing command: $COMMAND"
        exec $COMMAND
        ;;
    app|*)
        if [ "${RUN_MIGRATIONS}" = "true" ]; then
            echo "Running migrations..."
            php artisan migrate --force
        fi

        if [ "${RUN_SEEDERS}" = "true" ]; then
            echo "Running seeders..."
            php artisan db:seed --force
        fi

        echo "Mode: App (nginx + php-fpm on port ${PORT})"
        # Inject the runtime port into the nginx server block.
        sed -i "s/__PORT__/${PORT}/g" /etc/nginx/http.d/default.conf
        exec /usr/bin/supervisord -c /etc/supervisord.conf -n
        ;;
esac
