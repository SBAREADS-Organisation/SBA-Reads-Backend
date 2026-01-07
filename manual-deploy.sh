#!/bin/bash

# Manual deployment script for SBA Reads
# Run this on your server to deploy the latest changes

echo "Starting manual deployment..."

# Go to app directory
cd /var/www/html

# Create backup
echo "Creating backup..."
sudo cp -r /var/www/html /var/www/html_backup_$(date +%Y%m%d_%H%M%S)

# Pull latest code
echo "Pulling latest code..."
git fetch origin
git reset --hard origin/main

# Install PHP dependencies
echo "Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

# Install Node dependencies and build
echo "Installing Node dependencies..."
npm install
npm run build

# Clear and cache Laravel
echo "Optimizing Laravel..."
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
php artisan view:cache

# Set permissions
echo "Setting permissions..."
sudo chown -R www-data:www-data /var/www/html
sudo find /var/www/html -type f -exec chmod 644 {} \;
sudo find /var/www/html -type d -exec chmod 755 {} \;
sudo chmod -R 775 /var/www/html/storage
sudo chmod -R 775 /var/www/html/bootstrap/cache

# Restart services
echo "Restarting services..."
sudo systemctl reload nginx
sudo systemctl restart php8.2-fpm

# Clear caches
echo "Clearing caches..."
php artisan cache:clear

echo "Deployment completed successfully!"
echo "Test the ping endpoint: curl https://api-prod.sbareads.com/ping"
