FROM php:8.2-apache

# Install MySQL extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Copy project files into Apache web root
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# Force cleanup conflicting MPM modules and start Apache at runtime
CMD ["bash", "-lc", " \
  set -eux; \
  a2dismod mpm_event mpm_worker || true; \
  rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* || true; \
  a2enmod mpm_prefork; \
  exec apache2-foreground \
"]
