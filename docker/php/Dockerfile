# ==========================================================
# RendezVox — PHP-FPM 8.3
# ==========================================================
FROM php:8.5-fpm-alpine

RUN echo "https://dl-cdn.alpinelinux.org/alpine/edge/testing" >> /etc/apk/repositories && \
    echo "https://dl-cdn.alpinelinux.org/alpine/edge/community" >> /etc/apk/repositories && \
    apk add --no-cache \
        fcgi \
        ffmpeg \
        chromaprint \
        piper-tts \
        tini \
        tzdata \
        postgresql-dev && \
    docker-php-ext-install pdo pdo_pgsql && \
    apk del --no-cache postgresql-dev && \
    apk add --no-cache libpq && \
    printf '#!/bin/sh\nCGI_FCGI_CONNECT=127.0.0.1:9000 cgi-fcgi -bind -connect 127.0.0.1:9000 > /dev/null 2>&1\n' \
    > /usr/local/bin/php-fpm-healthcheck && \
    chmod +x /usr/local/bin/php-fpm-healthcheck

# Download Piper TTS voice models (male + female)
RUN mkdir -p /usr/local/share/piper-voices && \
    wget -q "https://huggingface.co/rhasspy/piper-voices/resolve/v1.0.0/en/en_US/lessac/medium/en_US-lessac-medium.onnx" \
         -O /usr/local/share/piper-voices/en_US-lessac-medium.onnx && \
    wget -q "https://huggingface.co/rhasspy/piper-voices/resolve/v1.0.0/en/en_US/lessac/medium/en_US-lessac-medium.onnx.json" \
         -O /usr/local/share/piper-voices/en_US-lessac-medium.onnx.json && \
    wget -q "https://huggingface.co/rhasspy/piper-voices/resolve/v1.0.0/en/en_US/amy/medium/en_US-amy-medium.onnx" \
         -O /usr/local/share/piper-voices/en_US-amy-medium.onnx && \
    wget -q "https://huggingface.co/rhasspy/piper-voices/resolve/v1.0.0/en/en_US/amy/medium/en_US-amy-medium.onnx.json" \
         -O /usr/local/share/piper-voices/en_US-amy-medium.onnx.json

COPY php.ini /usr/local/etc/php/conf.d/rendezvox.ini
COPY fpm-pool.conf /usr/local/etc/php-fpm.d/zzz-rendezvox.conf
COPY crontab /etc/crontabs/www-data
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["entrypoint.sh"]
