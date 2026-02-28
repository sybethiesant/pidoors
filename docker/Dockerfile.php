FROM php:8.2-fpm-alpine

# Install PHP extensions needed by PiDoors
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Install curl extension
RUN apk add --no-cache libcurl curl-dev \
    && docker-php-ext-install curl

# Install mbstring
RUN apk add --no-cache oniguruma-dev \
    && docker-php-ext-install mbstring

# Enable output buffering (required for header() redirects after HTML output)
RUN echo "output_buffering = 4096" > /usr/local/etc/php/conf.d/output-buffering.ini

# Copy application files
COPY pidoorserv/ /var/www/pidoors/

# Remove any shipped config.php (use example + entrypoint to configure)
RUN rm -f /var/www/pidoors/includes/config.php

# Copy the entrypoint script
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Set permissions
RUN chown -R www-data:www-data /var/www/pidoors

WORKDIR /var/www/pidoors

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
