FROM composer:2.11.1 AS composer
FROM mlocati/php-extension-installer:2.13.2 AS php-extension-installer
FROM dunglas/frankenphp:1.13.5-php8.5.12-alpine AS php

COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
    gd \
    pdo_mysql \
    sockets \
    zip \
    opcache

# poppler-utils: pdfinfo and pdftoppm turn PDF pages into images (PdfRasterizer); DejaVu for PDFs without embedded fonts.
# supervisor runs FrankenPHP and the background job consumer side by side (docker/supervisord.conf).
RUN apk add --no-cache curl tzdata poppler-utils font-dejavu supervisor

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

COPY ./bin /app/bin
COPY ./examples /app/examples
COPY ./migrations /app/migrations
COPY ./src /app/src
COPY ./translations /app/translations
COPY ./Caddyfile /etc/frankenphp/Caddyfile
COPY ./docker/supervisord.conf /etc/supervisord.conf
COPY ./docker/supervisor-fatal.sh /usr/local/bin/supervisor-fatal.sh
COPY ./docker/php-uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY ./docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod 0755 /usr/local/bin/docker-entrypoint.sh /usr/local/bin/supervisor-fatal.sh
COPY ./composer.json ./composer.lock* /app/

WORKDIR /app/

RUN rm -rf /app/vendor \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-progress --classmap-authoritative -d /app/ \
    && composer clear-cache

# Generated ORM hydrators/extractors, loaded by DbContext under opcache instead of an attribute scan.
RUN php bin/console orm:schema-dump

# Tracy's log directory, bind-mounted from the host's ./log in docker-compose.yml.
RUN mkdir -p /app/log

# The entrypoint applies pending migrations (the seed data lives in them), then execs the CMD.
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
