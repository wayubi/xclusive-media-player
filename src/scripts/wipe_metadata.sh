#!/usr/bin/env bash
set -e

# Ensure the directory exists
if [ ! -d "/tmp/.metadata" ]; then
  echo "Directory /tmp/.metadata does not exist."
  exit 1
fi

# Log the action
echo "Removing files in /tmp/.metadata..."

# Remove files
rm /tmp/.metadata/*