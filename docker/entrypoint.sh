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

# Configure upload limits so Laravel receives larger multipart requests.
if [ ! -f /usr/local/etc/php/conf.d/upload-limits.ini ]; then
    cat > /usr/local/etc/php/conf.d/upload-limits.ini << EOF
upload_max_filesize = 15M
post_max_size = 20M
EOF
    echo "✅ PHP upload limits configured!"
else
    echo "✅ PHP upload limits already configured!"
fi

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