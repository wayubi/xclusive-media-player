#!/bin/bash
set -e

mkdir -p /var/lock /var/log/php-fpm /run/php-fpm

touch /var/log/cron.log
tail -f /var/log/cron.log &

service cron start

exec php-fpm --allow-to-run-as-root
