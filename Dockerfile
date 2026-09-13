FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs

COPY . .
RUN composer install --no-dev --optimize-autoloader --no-scripts --ignore-platform-reqs

FROM php:8.2-fpm-alpine

RUN set -eux; \
    apk add --no-cache bash nginx supervisor \
        $PHPIZE_DEPS \
        pkgconf \
        freetype-dev libjpeg-turbo-dev libpng-dev libwebp-dev \
        icu-dev oniguruma-dev libzip-dev \
        zip unzip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j$(nproc) pdo_mysql mbstring exif zip intl bcmath gd opcache

WORKDIR /var/www/html

COPY --from=vendor /app ./

RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && php artisan package:discover --ansi || true \
    && php artisan view:cache --ansi || true \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

CMD ["/usr/local/bin/entrypoint.sh"]