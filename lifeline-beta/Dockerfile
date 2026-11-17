FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli

RUN rm -f /etc/apache2/mods-enabled/mpm_event.* \
    && rm -f /etc/apache2/mods-enabled/mpm_worker.* \
    && a2enmod mpm_prefork

# Copy files directly from the current root build context
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["bash", "-lc", " \
  set -eux; \
  a2dismod mpm_event mpm_worker || true; \
  rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* || true; \
  a2enmod mpm_prefork; \
  exec apache2-foreground \
"]
