#!/bin/bash

echo "Fixing Docker container permissions for Laravel..."

# Stop existing containers
docker-compose down

# Rebuild with proper permissions
docker-compose build --no-cache

# Start containers
docker-compose up -d

# Wait for containers to be ready
sleep 10

# Fix permissions inside the running container
docker-compose exec app bash -c "
    chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
    chmod -R 775 /var/www/storage /var/www/bootstrap/cache
    chmod -R 777 /var/www/storage/logs
    chmod -R 777 /var/www/storage/framework/cache
    chmod -R 777 /var/www/storage/framework/sessions
    chmod -R 777 /var/www/storage/framework/views
    chmod -R 777 /var/www/bootstrap/cache
"

# Clear Laravel caches inside container
docker-compose exec app php artisan cache:clear
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan route:clear
docker-compose exec app php artisan view:clear

# Recreate caches with proper permissions
docker-compose exec app php artisan config:cache
docker-compose exec app php artisan route:cache
docker-compose exec app php artisan view:cache

echo "Docker permissions fixed!"
