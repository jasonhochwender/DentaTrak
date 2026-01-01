# ---------- Base image ----------
FROM php:8.2-apache

# ---------- Enable needed PHP extensions ----------
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libzip-dev \
    zip \
    unzip \
    default-mysql-client \
    curl \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install gd mysqli pdo pdo_mysql zip opcache \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

# ---------- Install Composer ----------
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# ---------- Environment ----------
ENV PORT=8080

# ---------- Apache must listen on $PORT ----------
RUN sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf \
 && sed -i "s/:80/:${PORT}/g" /etc/apache2/sites-enabled/000-default.conf

# ---------- Copy application files ----------
WORKDIR /var/www/html
COPY . /var/www/html

# ---------- Install PHP dependencies ----------
RUN composer install --no-dev --optimize-autoloader --no-interaction

# ---------- Apache configuration ----------
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN a2enmod rewrite

# ---------- Permissions ----------
RUN chown -R www-data:www-data /var/www/html

# ---------- Cloud SQL ----------
RUN mkdir -p /cloudsql
ENV CLOUD_SQL_CONNECTION_NAME=dtk-prod-core:us-east1:dtk-sql-prod
ENV DB_NAME=dental_case_tracker

# ---------- Expose Cloud Run port ----------
EXPOSE 8080

# ---------- Start Apache ----------
CMD ["apachectl", "-D", "FOREGROUND"]
