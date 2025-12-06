# PHP-FPM
FROM php:8.4-fpm AS phpstage

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
 && docker-php-ext-install pdo pdo_mysql mysqli \
 && docker-php-ext-configure zip \
 && docker-php-ext-install zip \
 && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

RUN chown -R www-data:www-data /var/www/html



# -------------------
# NGINX STAGE
# -------------------
FROM nginx:1.25-alpine AS nginxstage

COPY docker/nginx.conf /etc/nginx/nginx.conf

WORKDIR /var/www/html
COPY --from=phpstage /var/www/html ./

RUN chown -R nginx:nginx /var/www/html

EXPOSE 80
