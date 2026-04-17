#!/bin/bash
# video-dedup - Unified CLI for video deduplication
# Usage: video-dedup <path> [options]          (scan mode - default)
#        video-dedup delete <path> [options]
#        video-dedup restore <path> [options]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VIDEO_DEDUP_DIR="$SCRIPT_DIR"

# Check if first argument is a command
if [[ "$1" == "--delete" || "$1" == "--restore" || "$1" == "delete" || "$1" == "restore" ]]; then
    command="${1#--}"
    shift
else
    command="scan"
fi

# Get path - use current dir if not provided
if [ -z "$1" ] || [[ "$1" == -* ]]; then
    user_path="."
else
    user_path="$1"
    shift
fi

current_dir="$(pwd)"

# Translate . or ./ to current directory
if [ "$user_path" = "." ] || [ "$user_path" = "./" ]; then
    user_path="$current_dir"
fi

# Replace /var/www/html/volumes with /videos
translated_path="${user_path//\/var\/www\/html\/volumes/\/videos}"

# Execute inside container with the selected command
docker exec -i xclusive-video-dedup-1 \
    python /opt/video-dedup/src/dedup.py \
    "$translated_path" --"$command" "$@"

refresh_metadata