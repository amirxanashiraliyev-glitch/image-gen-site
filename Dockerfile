FROM php:8.2-apache

# PHP extensions
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libssl-dev \
    && docker-php-ext-install pdo pdo_mysql curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable mod_rewrite
RUN a2enmod rewrite

# Copy all files
COPY . /var/www/html/

# Permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Apache config
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

EXPOSE 80
