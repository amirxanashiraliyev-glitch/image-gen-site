FROM php:8.2-apache

# FIX: faqat bitta MPM ishlasin
RUN a2dismod mpm_event || true \
    && a2dismod mpm_worker || true \
    && a2enmod mpm_prefork

# Install PostgreSQL extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libcurl4-openssl-dev \
    libssl-dev \
    && docker-php-ext-install pdo pdo_pgsql curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable rewrite
RUN a2enmod rewrite

# Copy project
COPY . /var/www/html/

# Permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
