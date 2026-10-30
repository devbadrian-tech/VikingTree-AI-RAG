# Same PHP version as UAT (git.vocus.com.au:4567/docker/php:8.2.35).
FROM git.vocus.com.au:4567/docker/php:8.2.35
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev libonig-dev \
    && docker-php-ext-install pdo_sqlite mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY . .
RUN mkdir -p data && chown -R www-data:www-data data \
    && printf 'display_errors=Off\nlog_errors=On\n' > /usr/local/etc/php/conf.d/arbor.ini
EXPOSE 80
CMD ["apache2-foreground"]
