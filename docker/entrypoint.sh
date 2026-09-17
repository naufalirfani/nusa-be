#!/bin/sh
set -e

cd /var/www/html

# Configure PHP security settings to hide version information
echo "👉 Configuring PHP security settings..."
if [ ! -f /usr/local/etc/php/conf.d/security-headers.ini ]; then
    cat > /usr/local/etc/php/conf.d/security-headers.ini << EOF
; Hide PHP version information
expose_php = Off
; Hide PHP from Server header
; Disable detailed error messages in production
display_errors = Off
log_errors = On
error_log = /var/log/php-errors.log
EOF
    echo "✅ PHP security settings configured!"
else
    echo "✅ PHP security settings already configured!"
fi

# Dynamic PHP & FPM limits from environment variables
PHP_MEMORY_LIMIT=${PHP_MEMORY_LIMIT:-1024M}
PHP_FPM_MAX_CHILDREN=${PHP_FPM_MAX_CHILDREN:-50}
PHP_FPM_START_SERVERS=${PHP_FPM_START_SERVERS:-10}
PHP_FPM_MIN_SPARE_SERVERS=${PHP_FPM_MIN_SPARE_SERVERS:-5}
PHP_FPM_MAX_SPARE_SERVERS=${PHP_FPM_MAX_SPARE_SERVERS:-20}
PHP_FPM_BACKLOG=${PHP_FPM_BACKLOG:-4096}

cat > /usr/local/etc/php/conf.d/upload-limits.ini << EOF
upload_max_filesize = 500M
post_max_size = 500M
memory_limit = ${PHP_MEMORY_LIMIT}
max_execution_time = 600
max_input_time = 600
EOF

# Update www.conf if present
if [ -f /usr/local/etc/php-fpm.d/www.conf ]; then
    sed -i "s/^pm.max_children = .*/pm.max_children = ${PHP_FPM_MAX_CHILDREN}/" /usr/local/etc/php-fpm.d/www.conf 2>/dev/null || true
    sed -i "s/^;*listen.backlog = .*/listen.backlog = ${PHP_FPM_BACKLOG}/" /usr/local/etc/php-fpm.d/www.conf 2>/dev/null || true
fi

cat > /usr/local/etc/php-fpm.d/zz-docker.conf << EOF
[www]
listen = 127.0.0.1:9000
listen.backlog = ${PHP_FPM_BACKLOG}
pm = dynamic
pm.max_children = ${PHP_FPM_MAX_CHILDREN}
pm.start_servers = ${PHP_FPM_START_SERVERS}
pm.min_spare_servers = ${PHP_FPM_MIN_SPARE_SERVERS}
pm.max_spare_servers = ${PHP_FPM_MAX_SPARE_SERVERS}
pm.max_requests = 1000
pm.process_idle_timeout = 10s
request_terminate_timeout = 600s
rlimit_files = 65535
catch_workers_output = yes
decorate_workers_output = no
EOF

echo "✅ PHP upload & memory limits configured (memory_limit = ${PHP_MEMORY_LIMIT}, FPM max_children = ${PHP_FPM_MAX_CHILDREN}, listen.backlog = ${PHP_FPM_BACKLOG})!"

# Fix permissions untuk mounted volumes (karena volume mount override Dockerfile permissions)
echo "👉 Fixing storage and bootstrap/cache permissions..."
mkdir -p storage/logs storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views
mkdir -p bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
echo "✅ Permissions fixed!"

# Function to wait for PostgreSQL to be ready
wait_for_postgres() {
    echo "👉 Waiting for PostgreSQL to be ready..."
    
    # Get database connection details from environment (set by .env-docker)
    DB_HOST=${DB_HOST:-pgsql}
    DB_PORT=${DB_PORT:-5432}
    DB_DATABASE=${DB_DATABASE:-psdm}
    DB_USERNAME=${DB_USERNAME:-postgres}
    
    # Wait for PostgreSQL to accept connections
    until pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" > /dev/null 2>&1; do
        echo "PostgreSQL ($DB_HOST:$DB_PORT/$DB_DATABASE) is unavailable - sleeping for 2 seconds..."
        sleep 2
    done
    
    echo "✅ PostgreSQL is ready at $DB_HOST:$DB_PORT/$DB_DATABASE!"
}

# Wait for database before running migrations
wait_for_postgres

# Generate APP_KEY kalau belum ada
if ! grep -q "^APP_KEY=" .env || grep -q "^APP_KEY=$" .env; then
    echo "👉 APP_KEY belum ada, generate baru..."
    php artisan key:generate --force
fi

# Pastikan storage:link ada (skip jika sudah exists)
if [ ! -L /var/www/html/public/storage ] && [ ! -d /var/www/html/public/storage ]; then
    echo "👉 Running: php artisan storage:link"
    php artisan storage:link || true
else
    echo "👉 Storage link already exists, skipping..."
fi

echo "👉 Running: php artisan migrate"
php artisan migrate --force

# Clear cache setiap kali start container
echo "👉 Clearing Laravel cache"
php artisan optimize:clear

echo "👉 Optimizing Laravel caches"
php artisan optimize

exec "$@"