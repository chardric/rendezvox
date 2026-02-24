# ==========================================================
# iRadio — PHP-FPM 8.3
# ==========================================================
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        fcgi \
        ffmpeg \
        chromaprint \
        tini \
        tzdata \
        postgresql-dev && \
    docker-php-ext-install pdo pdo_pgsql && \
    apk del --no-cache postgresql-dev && \
    apk add --no-cache libpq && \
    printf '#!/bin/sh\nCGI_FCGI_CONNECT=127.0.0.1:9000 cgi-fcgi -bind -connect 127.0.0.1:9000 > /dev/null 2>&1\n' \
    > /usr/local/bin/php-fpm-healthcheck && \
    chmod +x /usr/local/bin/php-fpm-healthcheck

COPY php.ini /usr/local/etc/php/conf.d/iradio.ini
COPY fpm-pool.conf /usr/local/etc/php-fpm.d/zzz-iradio.conf
COPY crontab /etc/crontabs/www-data
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["entrypoint.sh"]
