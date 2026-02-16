FROM php:8.2-apache

# Install system deps needed to build pgsql extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get purge -y --auto-remove libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite (useful for clean URLs if needed)
RUN a2enmod rewrite

# Copy your project into Apache web root
COPY . /var/www/html/

# (Optional) recommended permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
