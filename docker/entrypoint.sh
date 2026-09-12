#!/bin/sh
set -e

if [ ! -L public/storage ]; then
    php artisan storage:link --quiet || true
fi

php artisan migrate --force --no-interaction

exec /usr/bin/supervisord -c /etc/supervisord.conf