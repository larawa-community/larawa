FROM node:22-bookworm AS assets
WORKDIR /app
COPY package*.json ./
RUN npm install --ignore-scripts
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM php:8.4-fpm-bookworm AS app
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libsqlite3-dev \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libpng-dev \
    && docker-php-ext-install intl opcache pdo pdo_sqlite pdo_pgsql pdo_mysql zip bcmath gd pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

COPY . .
COPY --from=assets /app/public/build ./public/build
COPY docker/laravel/larawa.ini /usr/local/etc/php/conf.d/larawa.ini
COPY docker/laravel/entrypoint.sh /usr/local/bin/larawa-entrypoint
COPY docker/laravel/healthcheck.sh /usr/local/bin/larawa-healthcheck
RUN chmod +x /usr/local/bin/larawa-entrypoint /usr/local/bin/larawa-healthcheck \
    && mkdir -p /var/www/html/public.dist \
    && cp -a public/. /var/www/html/public.dist/ \
    && mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache database \
    && composer dump-autoload --no-dev --optimize \
    && chown -R www-data:www-data storage bootstrap/cache database public.dist \
    && chmod -R a+rX public.dist

ENTRYPOINT ["larawa-entrypoint"]
CMD ["php-fpm"]
