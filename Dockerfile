# PHP-FPM + Nginx (production-focused)
FROM php:8.3-fpm-alpine

# Set timezone for PHP stage
RUN apk add --no-cache tzdata \
 && cp /usr/share/zoneinfo/Asia/Jakarta /etc/localtime \
 && echo "Asia/Jakarta" > /etc/timezone \
 && apk del tzdata

# Install system packages & PHP extensions
RUN apk add --no-cache \
    nginx supervisor git unzip postgresql-client \
    libpq-dev oniguruma-dev libxml2-dev libzip-dev \
    libjpeg-turbo-dev libpng-dev freetype-dev icu-dev \
    sqlite-dev \
 && apk add --no-cache --virtual .build-deps \
    autoconf gcc g++ make \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
    pdo pdo_mysql pdo_pgsql pgsql pdo_sqlite \
    mbstring pcntl zip bcmath gd exif intl \
 && pecl install redis \
 && docker-php-ext-enable redis opcache \
 && apk del .build-deps \
 && rm -rf /var/cache/apk/*

WORKDIR /var/www/html

# Composer from official image for caching
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy composer files and install deps (cached layer)
COPY composer.json composer.lock ./
RUN composer install --optimize-autoloader --no-interaction --prefer-dist --no-scripts \
 && composer clear-cache

# Copy application code
COPY . .

# Copy environment file used inside container
# Ensure you provide .env-docker at build time (this file will be copied to .env)
COPY .env-docker .env

# Copy config files for nginx/supervisord/entrypoint
COPY docker/nginx-laravel.conf /etc/nginx/http.d/laravel.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh

# Setup Laravel runtime dirs and permissions
RUN rm -rf bootstrap/cache/*.php \
 && mkdir -p bootstrap/cache storage/logs storage/app/public public/storage \
 && chown -R www-data:www-data bootstrap/cache storage \
 && chmod -R 775 bootstrap/cache storage \
 && php artisan config:clear || true \
 && composer dump-autoload --optimize || true \
 && php artisan storage:link || true \
 && chown -R www-data:www-data public/storage || true

# Configure PHP timezone
RUN echo "date.timezone = Asia/Jakarta" > /usr/local/etc/php/conf.d/timezone.ini

# Nginx & Entrypoint
RUN rm -f /etc/nginx/http.d/default.conf \
 && mkdir -p /var/log/nginx /run/nginx \
 && chmod +x /entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
