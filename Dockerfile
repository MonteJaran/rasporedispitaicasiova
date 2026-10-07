# Koristimo zvaničnu PHP 8.2 sliku sa Apache web serverom
FROM php:8.2-apache

# 1. Ažuriranje paketa i instalacija potrebnih zavisnosti
# DODATO: unzip i git (potrebni za Composer)
RUN apt-get update && apt-get install -y \
    default-jre \
    libpq-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql

# 2. Instalacija Composera
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Kopiranje svih fajlova projekta u kontejner
COPY . /var/www/html/

# 4. Postavljanje radnog direktorijuma
WORKDIR /var/www/html

# 5. INSTALACIJA PHP ZAVISNOSTI (Ovo je falilo)
# Ovo ce kreirati 'vendor' folder unutar kontejnera
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 6. Podešavanje Apache DocumentRoot-a na /public folder
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 7. Dozvole za upis (Permissions)
RUN chown -R www-data:www-data /var/www/html

# 8. Omogući mod_rewrite
RUN a2enmod rewrite

# 9. Skripta za pokretanje
RUN echo "#!/bin/bash" > /start.sh && \
    echo "echo \"DB_HOST=\${DB_HOST}\" > /var/www/html/.env" >> /start.sh && \
    echo "echo \"DB_PORT=\${DB_PORT}\" >> /var/www/html/.env" >> /start.sh && \
    echo "echo \"DB_NAME=\${DB_NAME}\" >> /var/www/html/.env" >> /start.sh && \
    echo "echo \"DB_USER=\${DB_USER}\" >> /var/www/html/.env" >> /start.sh && \
    echo "echo \"DB_PASS=\${DB_PASS}\" >> /var/www/html/.env" >> /start.sh && \
    echo "echo \"DB_SSLMODE=require\" >> /var/www/html/.env" >> /start.sh && \
    echo "echo \"DB_SSLROOTCERT=\" >> /var/www/html/.env" >> /start.sh && \
    echo "apache2-foreground" >> /start.sh && \
    chmod +x /start.sh

CMD ["/start.sh"]