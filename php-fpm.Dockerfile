FROM composer:2.7 AS composer_bin

FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libzip-dev \
        ca-certificates \
        git \
        unzip \
    && docker-php-ext-install pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

# Composer is copied from the official composer image to avoid network install.
COPY --from=composer_bin /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

