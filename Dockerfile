FROM php:8.2-fpm

# Set working directory
WORKDIR /var/www

# Install dependencies
RUN apt-get update && apt-get install -y \
    build-essential \
    libpng-dev \
    libjpeg-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    curl \
    git \
    libpq-dev \
    libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql mbstring zip exif pcntl bcmath gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy project files
COPY . .

# Trade with caution: This will remove the vendor directory and composer.lock file
# This is useful if you want to ensure a fresh install of dependencies.
RUN rm -rf vendor/ composer.lock

# Install PHP dependencies
#  --no-dev --optimize-autoloader
RUN composer install

# Laravel optimizations
RUN php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache

# Set permissions
RUN chown -R www-data:www-data /var/www && chmod -R 755 /var/www

# Generate key (optional here)
# RUN php artisan key:generate

# Optional: Artisan commands to run app
# # Expose port
EXPOSE 80

# # Start Laravel server
CMD php artisan migrate --force && php artisan db:seed --force && php artisan serve --host=0.0.0.0 --port=80

# PROD Run App
# # Copy nginx config
# COPY docker/nginx.conf /etc/nginx/sites-available/default

# # Copy supervisor config
# COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# # Expose port 80
# EXPOSE 80

# # Final start command
# CMD ["/usr/bin/supervisord"]

# USING FPM
# Expose PHP-FPM port
# EXPOSE 9000

# CMD ["php-fpm"]
