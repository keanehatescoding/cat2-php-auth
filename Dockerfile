FROM php:8.3-apache

# PDO MySQL driver so PHP can talk to MariaDB
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html
