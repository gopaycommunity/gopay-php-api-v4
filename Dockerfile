FROM php:8.1-cli

RUN apt-get update && \
    apt-get install -y --no-install-recommends git unzip zip libzip-dev && \
    docker-php-ext-install zip && \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_HOME=/tmp/composer

RUN useradd -r -m -u 1000 appuser && chown appuser:appuser /app
USER appuser
