FROM php:8.2-apache-bookworm

# PDO conecta PHP con PostgreSQL; mbstring valida textos en UTF-8.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev \
    && docker-php-ext-install pdo_pgsql mbstring \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/dispe.ini
COPY . .

EXPOSE 80
