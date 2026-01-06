FROM php:8.4-cli

# Install FFmpeg (and dependencies)
RUN apt-get update && \
    apt-get install -y ffmpeg && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html