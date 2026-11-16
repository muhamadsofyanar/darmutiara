FROM php:8.5-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends nginx supervisor curl unzip libpq-dev libzip-dev libonig-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libxml2-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j2 pdo_pgsql mbstring zip gd pcntl \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction \
 && cp docker/nginx.conf /etc/nginx/sites-available/default \
 && cp docker/supervisord.conf /etc/supervisor/conf.d/dar.conf \
 && cp docker/php.ini /usr/local/etc/php/conf.d/dar.ini \
 && cp docker/fpm.conf /usr/local/etc/php-fpm.d/zz-dar.conf \
 && chmod +x docker/entrypoint.sh \
 && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
 && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 CMD curl -fsS http://127.0.0.1:8080/up || exit 1
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
