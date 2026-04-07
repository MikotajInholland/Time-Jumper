#!/bin/sh
set -e

cd /var/www/html

# Ensure .env exists so Laravel can bootstrap (use env vars from Docker)
if [ ! -f .env ]; then
  touch .env
  echo "APP_KEY=${APP_KEY:-}" >> .env
  echo "APP_ENV=${APP_ENV:-production}" >> .env
  echo "DB_CONNECTION=${DB_CONNECTION:-mysql}" >> .env
  echo "DB_HOST=${DB_HOST:-mysql}" >> .env
  echo "DB_PORT=${DB_PORT:-3306}" >> .env
  echo "DB_DATABASE=${DB_DATABASE:-timejumper}" >> .env
  echo "DB_USERNAME=${DB_USERNAME:-timejumper}" >> .env
  echo "DB_PASSWORD=${DB_PASSWORD:-secret}" >> .env
fi

# Ensure vendor exists (volume may be empty on first run)
if [ ! -f vendor/autoload.php ]; then
  composer install --no-dev --no-interaction || true
fi

# Generate Laravel key if missing or placeholder
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:placeholder" ]; then
  php artisan key:generate --force || true
fi

# Wait for DB then migrate (allow failure so container still starts)
for i in 1 2 3 4 5 6 7 8 9 10; do
  php artisan migrate --force && break || true
  sleep 2
done

# Start PHP-FPM in background (do not exit if it fails so we can still try nginx)
php-fpm -D || true

# Nginx in foreground (keeps container alive)
exec nginx -g "daemon off;"
