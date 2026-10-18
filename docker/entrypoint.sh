#!/bin/sh
set -eu

# Repair writable files left by earlier root-run workers before the dependent
# unprivileged worker containers start. No application or database data is reset.
if [ "$(id -u)" = '0' ]; then
    mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs
    chown -R www-data:www-data storage bootstrap/cache
fi

exec docker-php-entrypoint "$@"
