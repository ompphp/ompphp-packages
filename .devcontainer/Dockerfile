FROM mcr.microsoft.com/devcontainers/php:1-8.2-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*
