FROM php:8.3-apache

# GD con JPEG, PNG y WebP: optimizacion de fotos de productos (05-tienda/php/imagenes-productos.php)
RUN apt-get update \
  && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev \
  && docker-php-ext-configure gd --with-jpeg --with-webp \
  && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql gd \
  && rm -rf /var/lib/apt/lists/*

COPY docker/php/tucultura.ini /usr/local/etc/php/conf.d/zz-tucultura.ini
COPY docker/apache/tucultura.conf /etc/apache2/conf-available/tucultura.conf
RUN a2enmod remoteip headers && a2enconf tucultura

# Sesiones PHP en un volumen (sobreviven a reconstrucciones del contenedor)
RUN mkdir -p /var/lib/php/sessions \
  && chown www-data:www-data /var/lib/php/sessions \
  && chmod 1733 /var/lib/php/sessions \
  && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf

COPY . /var/www/html/

# Archivos de infraestructura no deben quedar servidos por Apache
RUN rm -rf /var/www/html/docker
