#!/bin/bash
set -e

echo "Waiting for database to be ready..."
until php bin/console doctrine:database:create --if-not-exists --no-interaction 2>&1; do
    echo "Database not ready, waiting..."
    sleep 3
done

echo "Database is ready!"

echo "Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "Clearing cache..."
php bin/console cache:clear --no-interaction

echo "Starting FrankenPHP..."
exec frankenphp run --config /etc/caddy/Caddyfile
