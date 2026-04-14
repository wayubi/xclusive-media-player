#!/bin/bash
if ! flock -n /var/lock/refresh_metadata.lock -c 'cd /var/www/html && /usr/local/bin/php scripts/refresh_metadata --all --include-audit-db --include-favorites-db' >> /var/log/cron.log 2>&1; then
    echo "$(date '+%Y-%m-%d %H:%M:%S'): refresh_metadata already running, skipped" >> /var/log/cron.log
fi
