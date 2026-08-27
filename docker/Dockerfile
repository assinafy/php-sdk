FROM php:8.5-fpm-bookworm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        libonig-dev \
        unzip; \
    docker-php-ext-install -j"$(nproc)" mbstring; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.10.2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

ENV COMPOSER_HOME=/tmp/composer

RUN mkdir -p /tmp/composer \
    && chmod 1777 /tmp/composer \
    && chown -R www-data:www-data /var/www

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
