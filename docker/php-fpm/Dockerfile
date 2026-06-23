FROM php:8.4-fpm

RUN apt-get update && \
    apt-get install -y ffmpeg cron util-linux xxhash xxd docker-cli && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pcntl || true

RUN touch /var/log/cron.log && \
    mkdir -p /var/log/php-fpm /run/php-fpm

WORKDIR /var/www/html

COPY conf.d/ /usr/local/etc/php/conf.d/
COPY www.conf /usr/local/etc/php-fpm.d/www.conf
COPY crontab /etc/cron.d/crontab
COPY entrypoint.sh /entrypoint.sh
COPY run-refresh-metadata.sh /usr/local/bin/run-refresh-metadata
RUN chmod 0644 /etc/cron.d/crontab && \
    chmod +x /entrypoint.sh && \
    chmod +x /usr/local/bin/run-refresh-metadata

ENTRYPOINT ["/entrypoint.sh"]