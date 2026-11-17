# syntax=docker/dockerfile:1
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-req=php

FROM composer:2 AS vendor-dev
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-interaction --prefer-dist --ignore-platform-req=php

FROM php:8.4-apache AS base
LABEL org.opencontainers.image.version="1.0.0" \
      org.opencontainers.image.title="mu-linux-browser" \
      org.opencontainers.image.source="https://github.com/EmanuelCatania/Mu-Linux-Browser-Text-Game"
RUN docker-php-ext-install pdo_mysql opcache \
 && a2enmod headers \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && sed -i "s/^Listen 80$/Listen 8080/" /etc/apache2/ports.conf \
 && mkdir -p /var/lib/php/sessions && chown www-data:www-data /var/lib/php/sessions && chmod 700 /var/lib/php/sessions \
 && mkdir -p /var/lib/mu-browser && chmod 700 /var/lib/mu-browser
COPY docker/php.ini $PHP_INI_DIR/conf.d/app.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/app
COPY bin ./bin
COPY data ./data
COPY lang ./lang
COPY migrations ./migrations
COPY public ./public
COPY src ./src
COPY templates ./templates
# Imágenes que se suben desde el panel: van a un volumen (ver docker-compose.yml)
RUN mkdir -p public/uploads/news && chown -R www-data:www-data public/uploads && chmod -R 755 public/uploads
EXPOSE 8080

# Imagen de tests: mismas fuentes + dependencias de desarrollo.
FROM base AS test
COPY --from=vendor-dev /app/vendor ./vendor
COPY tests ./tests
COPY phpunit.xml composer.json ./
CMD ["vendor/bin/phpunit"]

FROM base AS app
COPY --from=vendor /app/vendor ./vendor
COPY docker/entrypoint.sh /usr/local/bin/mu-entrypoint
RUN chmod +x /usr/local/bin/mu-entrypoint
ENTRYPOINT ["mu-entrypoint"]
CMD ["apache2-foreground"]
