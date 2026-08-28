#!/bin/sh
set -e

cd /var/www/html

# Ensure an .env exists (mounted source normally provides one; fall back to example)
if [ ! -f .env ]; then
    echo "No .env found - copying .env.example"
    cp .env.example .env
fi

# Install PHP dependencies if they are missing (first run / fresh clone).
# vendor/ itself is a named volume, so test for the autoloader, not the dir.
if [ ! -f vendor/autoload.php ]; then
    echo "Installing composer dependencies..."
    composer install --no-interaction --prefer-dist
fi

# Generate an app key if it is not set
if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

# Wait for PostgreSQL to accept connections
echo "Waiting for PostgreSQL at ${DB_HOST:-postgres}:${DB_PORT:-5432}..."
until php -r "exit(@fsockopen(getenv('DB_HOST') ?: 'postgres', (int)(getenv('DB_PORT') ?: 5432)) ? 0 : 1);"; do
    sleep 2
done
echo "PostgreSQL is up."

# Run migrations. --isolated takes a cache lock (shared via the bind-mounted
# storage/) so the backend and scheduler containers starting together never
# migrate concurrently. Safe no-op when already migrated.
php artisan migrate --force --isolated || true

php artisan config:clear

exec "$@"
