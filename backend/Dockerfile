# Laravel: nginx + PHP-FPM in one container so Laravel files are available to both
FROM php:8.2-fpm-alpine AS base
RUN apk add --no-cache nginx wget libzip-dev icu-dev oniguruma-dev libpng-dev libjpeg-turbo-dev freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql zip intl mbstring exif pcntl bcmath gd opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
# Use our main config so the server block is always included (Alpine may have conf.d commented out)
COPY docker/nginx-main.conf /etc/nginx/nginx.conf

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader 2>/dev/null || composer update --no-dev --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize; \
    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

EXPOSE 80
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN sed -i 's/\r$//' /docker-entrypoint.sh && chmod +x /docker-entrypoint.sh
ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
