#!/bin/bash
set -e

mkdir -p /var/lock /var/log/php-fpm /run/php-fpm

touch /var/log/cron.log
tail -f /var/log/cron.log &

if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S'): PHP dependencies missing, running composer install..."
    if ! composer install --working-dir=/var/www/html --no-dev --prefer-dist --no-interaction --no-progress >> /var/log/cron.log 2>&1; then
        echo "$(date '+%Y-%m-%d %H:%M:%S'): WARNING: composer install failed, metadata scanning will not work until dependencies are installed" >> /var/log/cron.log
    fi
fi

service cron start

exec php-fpm --allow-to-run-as-root
