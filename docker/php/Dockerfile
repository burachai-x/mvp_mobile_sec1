# syntax=docker/dockerfile:1.7
#
# image เดียวใช้กับทั้ง api / portal / worker / scheduler
# ต่างกันแค่ APP_ROLE + secret ที่ mount + network (ADR 0007)
#
# ห้ามใส่ secret ใน ARG/ENV — ติดอยู่ใน image history ถาวร (CLAUDE.md §5)

ARG PHP_VERSION=8.4

# ──────────────────────────────────────────────────────────────
FROM php:${PHP_VERSION}-fpm-alpine AS base

# runtime lib ต้องติดตั้งแยกจาก -dev เพราะ `apk del .build-deps`
# จะลบ lib ที่ติดมาเป็น dependency ของ -dev ไปด้วย แล้ว PHP จะหา .so ไม่เจอตอนรัน
RUN apk add --no-cache \
        libpq icu-libs libzip libpng libjpeg-turbo freetype \
        shadow tini \
    # $PHPIZE_DEPS จำเป็นสำหรับ pecl/phpize — alpine ไม่ได้ให้มาในตัว image
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev icu-dev libzip-dev oniguruma-dev \
        libpng-dev libjpeg-turbo-dev freetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql bcmath intl zip opcache pcntl gd \
    # ext-redis ใช้กับ Valkey ได้ตรงๆ โปรโตคอลเข้ากันได้ (CLAUDE.md §4)
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del --no-network .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/*

COPY docker/php/php.ini      /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini  /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/www.conf     /usr/local/etc/php-fpm.d/zz-www.conf

WORKDIR /var/www/html

# tini เก็บ zombie process จาก queue:work
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php-fpm"]

# ──────────────────────────────────────────────────────────────
# dev — bind-mount source, uid ตรงกับ host
#
# 🔴 map www-data ให้ตรง uid/gid ของ host
#    php-fpm worker รันเป็น www-data ส่วน `docker exec` เข้าเป็น root
#    ถ้า uid ไม่ตรง ไฟล์ใน bind mount จะเป็นของ root แล้วทั้ง worker และ host แก้ไม่ได้
#    → 500 touch(): Utime failed (architecture.md §12.6)
FROM base AS dev

ARG UID=1000
ARG GID=1000
RUN if [ "$(id -u www-data)" != "${UID}" ]; then \
        groupmod -o -g "${GID}" www-data && \
        usermod  -o -u "${UID}" -g "${GID}" www-data; \
    fi

RUN apk add --no-cache $PHPIZE_DEPS linux-headers git \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del $PHPIZE_DEPS linux-headers \
    && rm -rf /tmp/pear
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ──────────────────────────────────────────────────────────────
# vendor — ติดตั้ง dependency แยก layer เพื่อให้ cache ทำงาน
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install \
        --no-dev --no-scripts --no-autoloader \
        --prefer-dist --no-interaction --no-progress

# ──────────────────────────────────────────────────────────────
# prod — โค้ดอบใน image ไม่ bind-mount
FROM base AS prod

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /var/www/html/vendor ./vendor
COPY apps/api/ ./

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && rm -f /usr/bin/composer \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# php-fpm master ต้องเป็น root เพื่อ bind socket แล้วค่อย drop เป็น www-data
# (กำหนดใน www.conf) — worker ไม่เคยรันเป็น root
USER root
