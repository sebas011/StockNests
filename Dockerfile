FROM php:8.4-apache AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libonig-dev libicu-dev unzip git curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_pgsql bcmath mbstring intl zip gd pcntl opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html

FROM php-base AS source
COPY . .
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts \
    && php artisan package:discover --ansi \
    && php artisan wayfinder:generate --with-form

FROM node:24-bookworm-slim AS assets
WORKDIR /app
COPY --from=source /var/www/html/package*.json ./
RUN npm ci --no-audit --no-fund
COPY --from=source /var/www/html/resources ./resources
COPY --from=source /var/www/html/public ./public
COPY --from=source /var/www/html/vite.config.ts /var/www/html/tsconfig.json ./
ENV STOCKNEST_SKIP_WAYFINDER=true
ENV VITE_APP_NAME=StockNest
RUN npm run build

FROM php-base AS production-dependencies
COPY --from=source /var/www/html .
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader --no-scripts \
    && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && php artisan package:discover --ansi

FROM php-base AS runtime
COPY --from=production-dependencies /var/www/html .
# Leave the shared libraries installed while removing tools and development
# headers used only to compile extensions or resolve Composer dependencies.
RUN apt-get update && apt-get purge -y \
    git unzip libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
    libfreetype6-dev libonig-dev libicu-dev $PHPIZE_DEPS \
    && rm -f /usr/local/bin/composer \
    && rm -rf /var/lib/apt/lists/*
COPY --from=assets /app/public/build ./public/build
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/stocknest.ini
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/stocknest-entrypoint
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 80
ENTRYPOINT ["stocknest-entrypoint"]
CMD ["apache2-foreground"]
