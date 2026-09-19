FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    bash \
    git \
    curl \
    unzip \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    postgresql-dev \
    linux-headers \
    $PHPIZE_DEPS

RUN docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) \
    intl \
    mbstring \
    pdo \
    pdo_pgsql \
    zip \
    opcache

RUN pecl install redis \
 && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-custom.ini

RUN addgroup -g 1000 -S www \
 && adduser -u 1000 -S www -G www

USER www
WORKDIR /var/www/html