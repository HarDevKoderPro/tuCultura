FROM php:8.3-apache

RUN docker-php-ext-install mysqli pdo_mysql

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
