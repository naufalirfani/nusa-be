#!/bin/sh
set -e

cd /var/www/html

# Fix permissions for mounted volumes
echo "👉 Fixing storage and bootstrap/cache permissions..."
mkdir -p storage/logs storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views
mkdir -p bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
echo "✅ Permissions fixed!"

# Function to wait for PostgreSQL to be ready
wait_for_postgres() {
    echo "👉 Waiting for PostgreSQL to be ready..."

    DB_HOST=${DB_HOST:-pgsql}
    DB_PORT=${DB_PORT:-5432}
    DB_DATABASE=${DB_DATABASE:-psdm}
    DB_USERNAME=${DB_USERNAME:-postgres}

    until pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" > /dev/null 2>&1; do
        echo "PostgreSQL ($DB_HOST:$DB_PORT/$DB_DATABASE) is unavailable - sleeping for 2 seconds..."
        sleep 2
    done

    echo "✅ PostgreSQL is ready at $DB_HOST:$DB_PORT/$DB_DATABASE!"
}

wait_for_postgres

# Generate APP_KEY if not present
if ! grep -q "^APP_KEY=" .env || grep -q "^APP_KEY=$" .env; then
    echo "👉 APP_KEY belum ada, generate baru..."
    php artisan key:generate --force
fi

# Ensure storage link exists
if [ ! -L /var/www/html/public/storage ] && [ ! -d /var/www/html/public/storage ]; then
    echo "👉 Running: php artisan storage:link"
    php artisan storage:link || true
else
    echo "👉 Storage link already exists, skipping..."
fi

# Run migrations
echo "👉 Running: php artisan migrate"
php artisan migrate --force

# Clear and optimize
echo "👉 Clearing Laravel cache"
php artisan optimize:clear || true

echo "👉 Optimizing Laravel caches"
php artisan optimize || true

exec "$@"
