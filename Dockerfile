# Dockerfile

FROM php:8.3-apache

# Refuse to build with vulnerable PHP runtime. 
RUN php -r 'exit(version_compare(PHP_VERSION, "8.3.37", ">=") ? 0 : 1);'

# Install system deps & PHP extensions
RUN apt-get update && apt-get install -y \
        git unzip libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo pdo_mysql mysqli mbstring xml zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Bring in composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy app source
COPY src/ /var/www/html/

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader || true

# Permissions
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

# Enforce the HTTP allowlist outside the bind-mounted application source.
COPY apache/web-access.conf /etc/apache2/conf-available/santa-web-access.conf
RUN a2enconf santa-web-access

EXPOSE 80

CMD ["apache2-foreground"]

