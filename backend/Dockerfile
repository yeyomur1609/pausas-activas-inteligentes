# ARCHIVO: backend/Dockerfile
# Imagen de producción para Render Free (Web Service Docker):
# PHP 8.2 + Apache + Microsoft ODBC Driver 18 + pdo_sqlsrv/sqlsrv + Composer (PHPMailer)
FROM php:8.2-apache-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    APACHE_DOCUMENT_ROOT=/var/www/html/public

# 1. Dependencias del sistema y repositorio oficial de Microsoft para ODBC Driver 18 (Azure SQL)
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg2 \
        libcurl4-openssl-dev \
        libonig-dev \
        libzip-dev \
        unixodbc-dev \
        unzip \
    && mkdir -p /usr/share/keyrings \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && echo "deb [arch=amd64,arm64,armhf signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
        > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && docker-php-ext-install -j"$(nproc)" mbstring zip opcache \
    && pecl install sqlsrv pdo_sqlsrv \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# 2. Módulos de Apache necesarios para rutas amigables, cabeceras de seguridad, paso de Authorization y compresión
RUN a2enmod rewrite headers deflate expires setenvif

# 3. Configurar DocumentRoot en /var/www/html/public con AllowOverride All, CGIPassAuth y endurecimiento de Apache
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/000-default.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf \
    && printf '<Directory /var/www/html/public>\n    Options -Indexes -MultiViews\n    AllowOverride All\n    CGIPassAuth On\n    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1\n    Require all granted\n</Directory>\nServerTokens Prod\nServerSignature Off\n' \
        > /etc/apache2/conf-available/pai-public.conf \
    && a2enconf pai-public

# 4. Configuración PHP optimizada para producción en contenedores (logs hacia stderr de Render)
RUN printf 'expose_php = Off\ndisplay_errors = Off\ndisplay_startup_errors = Off\nlog_errors = On\nerror_log = /proc/self/fd/2\nmemory_limit = 128M\nmax_execution_time = 60\nupload_max_filesize = 2M\npost_max_size = 4M\nsession.use_strict_mode = 1\nsession.use_only_cookies = 1\nsession.cookie_httponly = 1\nsession.gc_maxlifetime = 604800\nopcache.enable = 1\nopcache.memory_consumption = 64\nopcache.max_accelerated_files = 4000\nopcache.validate_timestamps = 0\n' \
    > /usr/local/etc/php/conf.d/zz-pai-production.ini

WORKDIR /var/www/html

# 5. Instalar Composer e instalar dependencias PHP (phpmailer/phpmailer) con caché de capas Docker
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# 6. Copiar el código del backend y optimizar el autoloader PSR-4
COPY . /var/www/html
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# 7. Preparar directorios efímeros con permisos de escritura para www-data y entrypoint dinámico para $PORT
RUN mkdir -p /var/www/html/storage/cache /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 775 /var/www/html/storage \
    && cp /var/www/html/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh \
    && sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
