FROM serversideup/php:8.4-fpm-nginx

ENV PHP_MEMORY_LIMIT=512M

USER root

RUN apt update --allow-unauthenticated && apt install git screen curl -y --allow-unauthenticated
RUN curl -fsSL https://deb.nodesource.com/setup_24.x | bash - && \
    apt install nodejs -y --allow-unauthenticated
RUN install-php-extensions intl bcmath
USER www-data
WORKDIR /var/www/html
