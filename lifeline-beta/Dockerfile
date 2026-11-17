FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli

# Force disable conflicting MPM modules and enable prefork
RUN rm -f /etc/apache2/mods-enabled/mpm_event.* \
    && rm -f /etc/apache2/mods-enabled/mpm_worker.* \
    && a2enmod mpm_prefork

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
