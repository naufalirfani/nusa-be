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

# Configure PHP upload and memory limits (1024M memory limit to prevent exhaustion on heavy PDF/exports)
RUN echo "upload_max_filesize = 500M" > /usr/local/etc/php/conf.d/uploads.ini \
 && echo "post_max_size = 500M" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "memory_limit = 1024M" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "max_input_time = 600" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "max_input_vars = 10000" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "realpath_cache_size = 4096K" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "realpath_cache_ttl = 600" >> /usr/local/etc/php/conf.d/uploads.ini

# Configure OPcache for maximum production performance
RUN echo "opcache.enable = 1" > /usr/local/etc/php/conf.d/opcache.ini \
 && echo "opcache.enable_cli = 0" >> /usr/local/etc/php/conf.d/opcache.ini \
 && echo "opcache.memory_consumption = 256" >> /usr/local/etc/php/conf.d/opcache.ini \
 && echo "opcache.interned_strings_buffer = 32" >> /usr/local/etc/php/conf.d/opcache.ini \
 && echo "opcache.max_accelerated_files = 30000" >> /usr/local/etc/php/conf.d/opcache.ini \
 && echo "opcache.validate_timestamps = 0" >> /usr/local/etc/php/conf.d/opcache.ini \
 && echo "opcache.revalidate_freq = 0" >> /usr/local/etc/php/conf.d/opcache.ini \
 && echo "opcache.save_comments = 1" >> /usr/local/etc/php/conf.d/opcache.ini \
 && echo "opcache.fast_shutdown = 1" >> /usr/local/etc/php/conf.d/opcache.ini \
 && echo "opcache.jit = tracing" >> /usr/local/etc/php/conf.d/opcache.ini \
 && echo "opcache.jit_buffer_size = 64M" >> /usr/local/etc/php/conf.d/opcache.ini

# Configure PHP-FPM pool concurrency & auto-recycle to prevent memory leaks and request queuing
RUN sed -i "s/^pm.max_children = .*/pm.max_children = 50/" /usr/local/etc/php-fpm.d/www.conf 2>/dev/null || true \
 && sed -i "s/^;*listen.backlog = .*/listen.backlog = 4096/" /usr/local/etc/php-fpm.d/www.conf 2>/dev/null || true \
 && echo "[www]" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
 && echo "listen = 127.0.0.1:9000" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
 && echo "listen.backlog = 4096" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
 && echo "pm = dynamic" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
 && echo "pm.max_children = 50" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
 && echo "pm.start_servers = 10" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
 && echo "pm.min_spare_servers = 5" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
 && echo "pm.max_spare_servers = 20" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
 && echo "pm.max_requests = 1000" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
 && echo "pm.process_idle_timeout = 10s" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
 && echo "request_terminate_timeout = 600s" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
 && echo "rlimit_files = 65535" >> /usr/local/etc/php-fpm.d/zz-docker.conf

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
