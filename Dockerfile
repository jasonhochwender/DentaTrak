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
    curl \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install gd mysqli pdo pdo_mysql zip opcache \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

# ---------- Install Composer ----------
RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer

# ---------- Environment ----------
ENV PORT=8080

# ---------- Apache must listen on $PORT ----------
RUN sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf \
 && sed -i "s/:80/:${PORT}/g" /etc/apache2/sites-enabled/000-default.conf

# ---------- Apache document root (adjust only if needed) ----------
# If your app uses /public, keep this. Otherwise remove both lines.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

# ---------- Enable Apache modules ----------
RUN a2enmod rewrite headers expires

# ---------- PHP upload/size limits for large dental scan files (STL, etc.) ----------
COPY php.ini /usr/local/etc/php/conf.d/99-custom.ini

# ---------- Copy application files ----------
WORKDIR /var/www/html
COPY . /var/www/html

# ---------- Install PHP dependencies ----------
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

# ---------- Permissions ----------
RUN chown -R www-data:www-data /var/www/html

# ---------- Cloud SQL directory ----------
RUN mkdir -p /cloudsql

# ---------- Expose Cloud Run port ----------
EXPOSE 8080

# ---------- Start Apache ----------
CMD ["apachectl", "-D", "FOREGROUND"]
