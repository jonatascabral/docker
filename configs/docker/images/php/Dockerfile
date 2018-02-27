# From php 7.1 with FPM
FROM php:7.1-fpm

# Install the required dependencies for the extensions
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libmcrypt-dev \
    libpng-dev \
    libicu-dev \
    libsqlite3-dev \
    libxml2-dev

# Install the php extensions
RUN docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/ \
    && \
    docker-php-ext-install -j$(nproc) \
        bcmath \
        calendar \
        gd \
        gettext \
        intl \
        mcrypt \
        mysqli \
        pdo \
        pdo_mysql \
        pdo_sqlite \
        soap

# Clear the repositories
RUN apt-get clean -y && \
    apt-get autoremove -y && \
    rm -rf /var/lib/apt/lists/*
