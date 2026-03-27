FROM php:8.4-fpm-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        git \
        libicu-dev \
        libzip-dev \
        unzip \
        zip && \
    docker-php-ext-install intl pcntl zip && \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php-fpm-entrypoint.sh /usr/local/bin/php-fpm-entrypoint

RUN chmod +x /usr/local/bin/php-fpm-entrypoint

WORKDIR /workspace/examples/laravel-app/app

ENTRYPOINT ["/usr/local/bin/php-fpm-entrypoint"]
CMD ["php-fpm", "-F"]
