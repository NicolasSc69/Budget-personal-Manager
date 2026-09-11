FROM composer:2 AS composer_bin

FROM php:8.4-apache-bookworm AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libzip-dev \
        libsqlite3-dev \
        unzip \
        git \
    && docker-php-ext-install -j"$(nproc)" intl pdo_sqlite zip opcache \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && { \
        echo '<Directory ${APACHE_DOCUMENT_ROOT}>'; \
        echo '    AllowOverride None'; \
        echo '    Require all granted'; \
        echo '    FallbackResource /index.php'; \
        echo '</Directory>'; \
    } >> /etc/apache2/apache2.conf

COPY --from=composer_bin /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

ENV APP_ENV=prod \
    COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock symfony.lock ./
# Dev dependencies (PHPUnit) are kept in the image on purpose: the admin
# "Unit tests" page shells out to bin/phpunit at runtime to run the test
# suite from the app itself.
RUN composer install --no-scripts --no-autoloader --no-progress --no-interaction --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var translations config/packages/translation.yaml

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["apache2-foreground"]
