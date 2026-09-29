FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
        libpq-dev libzip-dev libpng-dev libonig-dev git unzip \
    && docker-php-ext-install pdo_pgsql pgsql bcmath zip gd exif \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
