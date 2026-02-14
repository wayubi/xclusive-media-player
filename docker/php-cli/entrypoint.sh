#!/bin/bash
set -e

# Ensure lock directory exists
mkdir -p /var/lock

# Ensure cron log file exists and tail it to Docker logs
touch /var/log/cron.log
tail -f /var/log/cron.log &

# Start cron service
service cron start

# Run PHP built-in server in foreground
# This keeps the container running and handles signals properly
exec php -S 0.0.0.0:8080 api.php
