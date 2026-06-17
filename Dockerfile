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
    imagemagick imagemagick-dev \
    libreoffice font-liberation ttf-freefont \
 && apk add --no-cache --virtual .build-deps \
    autoconf gcc g++ make \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
    pdo pdo_mysql pdo_pgsql pgsql pdo_sqlite \
    mbstring pcntl zip bcmath gd exif intl \
 && pecl install redis imagick \
 && docker-php-ext-enable redis imagick opcache \
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

# Setup Laravel runtime dirs and permissions
RUN rm -rf bootstrap/cache/*.php \
 && mkdir -p bootstrap/cache storage/logs storage/app/public \
 && rm -rf public/storage \
 && chown -R www-data:www-data bootstrap/cache storage \
 && chmod -R 775 bootstrap/cache storage \
 && php artisan config:clear \
 && composer dump-autoload --optimize \
 && php artisan storage:link \
 && chown -R www-data:www-data public/storage

# Configure PHP timezone
RUN echo "date.timezone = Asia/Jakarta" > /usr/local/etc/php/conf.d/timezone.ini

# Configure PHP upload limits
RUN echo "upload_max_filesize = 100M" > /usr/local/etc/php/conf.d/uploads.ini \
 && echo "post_max_size = 100M" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "max_input_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini

# Copy config files for nginx/supervisord/entrypoint
COPY docker/nginx-laravel.conf /etc/nginx/http.d/laravel.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh

# Nginx & Entrypoint
RUN rm -f /etc/nginx/http.d/default.conf \
 && mkdir -p /var/log/nginx /run/nginx \
 && chmod +x /entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
