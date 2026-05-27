# syntax=docker/dockerfile:1.6

# ---------------------------------------------------------------------------
# Stage 1: Composer dependencies (production only)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist \
        --ignore-platform-reqs

COPY . .

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---------------------------------------------------------------------------
# Stage 2: Runtime (Nginx + PHP-FPM + Supervisor)
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

ENV APP_ENV=production \
    DEBUG=false \
    PHP_MEMORY_LIMIT=256M \
    PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        tini \
        icu-libs \
        oniguruma \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        mysql-client \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_mysql \
        mbstring \
        zip \
        opcache \
        gd \
        bcmath \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/*

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html

COPY docker/php/php.ini       /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini   /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/www.conf      /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/nginx/nginx.conf  /etc/nginx/nginx.conf
COPY docker/supervisord.conf  /etc/supervisord.conf
COPY docker/entrypoint.sh     /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p \
        /var/www/html/tmp/cache/models \
        /var/www/html/tmp/cache/persistent \
        /var/www/html/tmp/cache/views \
        /var/www/html/tmp/sessions \
        /var/www/html/tmp/tests \
        /var/www/html/logs \
        /run/nginx \
        /var/log/supervisor \
    && chown -R www-data:www-data /var/www/html/tmp /var/www/html/logs \
    && chmod -R 0775 /var/www/html/tmp /var/www/html/logs

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/health || exit 1

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
