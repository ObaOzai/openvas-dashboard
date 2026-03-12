FROM php:8.2-apache

# Enable required PHP extensions
RUN docker-php-ext-install sockets

# Apache config: allow .htaccess, set document root
RUN a2enmod rewrite headers

# Copy app
COPY index.php /var/www/html/index.php

# Apache: expose port 80
RUN groupmod -g 140 www-data && usermod -aG 140 www-data
EXPOSE 80

# Healthcheck
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
  CMD curl -sf http://localhost/ || exit 1
