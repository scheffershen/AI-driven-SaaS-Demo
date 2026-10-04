#!/bin/sh
set -eu
mkdir -p var/data var/cache var/log
if [ ! -f var/data/application-secret ]; then
    php -r 'file_put_contents("var/data/application-secret", bin2hex(random_bytes(32)));'
    chmod 600 var/data/application-secret
fi
export APP_SECRET="$(cat var/data/application-secret)"
chown -R www-data:www-data var/data var/cache var/log
exec "$@"
