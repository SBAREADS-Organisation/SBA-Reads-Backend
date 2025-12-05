#!/bin/bash

echo "Fixing Laravel permissions..."

# Set proper ownership (assuming ubuntu user and www-data group)
sudo chown -R ubuntu:www-data /home/ubuntu/SBA-Reads-Backend

# Set directory permissions
sudo find /home/ubuntu/SBA-Reads-Backend -type d -exec chmod 755 {} \;

# Set file permissions
sudo find /home/ubuntu/SBA-Reads-Backend -type f -exec chmod 644 {} \;

# Special permissions for storage and cache directories
sudo chmod -R 775 /home/ubuntu/SBA-Reads-Backend/storage
sudo chmod -R 775 /home/ubuntu/SBA-Reads-Backend/bootstrap/cache

# Make artisan executable
sudo chmod +x /home/ubuntu/SBA-Reads-Backend/artisan

# Create necessary directories if they don't exist
mkdir -p /home/ubuntu/SBA-Reads-Backend/storage/logs
mkdir -p /home/ubuntu/SBA-Reads-Backend/storage/framework/cache
mkdir -p /home/ubuntu/SBA-Reads-Backend/storage/framework/sessions
mkdir -p /home/ubuntu/SBA-Reads-Backend/storage/framework/views
mkdir -p /home/ubuntu/SBA-Reads-Backend/bootstrap/cache

# Clear Laravel caches
cd /home/ubuntu/SBA-Reads-Backend
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

echo "Permissions fixed successfully!"
