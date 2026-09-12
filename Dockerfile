# 1. Usar la imagen oficial de PHP con Apache
FROM php:8.5-apache

# 2. Habilitar mod_rewrite para que funcionen las rutas de Laravel
RUN a2enmod rewrite

# 3. Apuntar la raíz de Apache a la carpeta /public de Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 4. Instalar dependencias del sistema necesarias
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# Limpiar caché para reducir el tamaño de la imagen
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 5. Instalar extensiones de PHP fundamentales para el framework
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# 6. Instalar Composer copiándolo desde su imagen oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 7. Establecer el directorio de trabajo
WORKDIR /var/www/html

# 8. Copiar todos los archivos del proyecto al contenedor
COPY . .

# 9. Instalar dependencias de PHP optimizadas para producción
RUN composer install --no-dev --optimize-autoloader

# 10. Otorgar permisos de escritura a las carpetas críticas
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# 11. Exponer el puerto por el que escuchará el contenedor
EXPOSE 80
