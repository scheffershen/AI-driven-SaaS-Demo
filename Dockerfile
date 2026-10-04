FROM composer:2 AS composer
FROM php:8.4-apache
RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get -o Acquire::Retries=2 -o Acquire::https::Timeout=30 update \
    && apt-get -o Acquire::Retries=2 -o Acquire::https::Timeout=30 install -y --no-install-recommends libsqlite3-dev libicu-dev libonig-dev libxml2-dev ffmpeg unzip \
    && docker-php-ext-install pdo_sqlite intl mbstring dom \
    && a2enmod rewrite headers && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && printf 'upload_max_filesize=20M\npost_max_size=22M\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/demo.ini
WORKDIR /var/www/html
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-progress
COPY . .
RUN mkdir -p var/sample && ffmpeg -hide_banner -loglevel error -f lavfi -i 'testsrc2=size=1280x720:rate=24' \
    -f lavfi -i 'sine=frequency=440:sample_rate=48000' -t 8 -c:v libx264 -pix_fmt yuv420p -c:a aac -movflags +faststart var/sample/welcome.mp4 \
    && chmod +x docker/entrypoint.sh
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["apache2-foreground"]
