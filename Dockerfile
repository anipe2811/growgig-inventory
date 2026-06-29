FROM php:8.3-apache

# MySQL driver + common Apache modules the app uses.
RUN docker-php-ext-install pdo_mysql mysqli \
    && a2enmod rewrite headers

# App code (build context excludes deploy/, .git, etc. via .dockerignore).
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
