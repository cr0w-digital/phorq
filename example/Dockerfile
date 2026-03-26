FROM dunglas/frankenphp:latest

WORKDIR /app

# Install Redis extension
RUN install-php-extensions redis

# Copy application
COPY . /app

# Install Composer dependencies
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --no-interaction --prefer-dist

# Expose FrankenPHP port
EXPOSE 8080

CMD ["frankenphp", "php-server", "--listen", ":8080"]