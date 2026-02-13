#!/usr/bin/env bash

DB_PATH="/var/www/db/metadata.db"

# Check if the database file exists before attempting to remove it
if [[ -f "$DB_PATH" ]]; then
  rm "$DB_PATH"
  echo "$(date '+%Y-%m-%d %H:%M:%S') - Metadata database wiped successfully."
else
  echo "Metadata database does not exist at $DB_PATH."
fi