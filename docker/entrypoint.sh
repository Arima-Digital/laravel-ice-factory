#!/bin/sh
set -e

if [ ! -L public/storage ]; then
    php artisan storage:link --quiet || true
fi

if [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] APP_KEY is empty. Generating an ephemeral key for this boot."
    export APP_KEY="$(php artisan key:generate --show)"
fi

attempt=1
migrate_ok=0
while [ "$attempt" -le 10 ]; do
    echo "[entrypoint] Running migrations (attempt ${attempt}/10)..."
    if php artisan migrate --force --no-interaction; then
        migrate_ok=1
        break
    fi
    echo "[entrypoint] Migration attempt ${attempt} failed, retrying in 5s..."
    sleep 5
    attempt=$((attempt + 1))
done

if [ "$migrate_ok" != "1" ]; then
    echo "[entrypoint] !!!!! MIGRATIONS FAILED AFTER RETRIES — starting services anyway for inspection. Check DB_HOST/credentials. !!!!!"
fi

echo "[entrypoint] Starting supervisord..."
exec /usr/bin/supervisord -c /etc/supervisord.conf