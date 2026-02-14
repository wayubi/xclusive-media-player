FROM php:8.4-cli

# Install FFmpeg, cron, and util-linux (for flock)
RUN apt-get update && \
    apt-get install -y ffmpeg cron util-linux && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Create log file for cron
RUN touch /var/log/cron.log

WORKDIR /var/www/html

# Copy crontab, entrypoint, and refresh script
COPY crontab /etc/cron.d/crontab
COPY entrypoint.sh /entrypoint.sh
COPY run-refresh-metadata.sh /usr/local/bin/run-refresh-metadata
RUN chmod 0644 /etc/cron.d/crontab && \
    chmod +x /entrypoint.sh && \
    chmod +x /usr/local/bin/run-refresh-metadata

ENTRYPOINT ["/entrypoint.sh"]