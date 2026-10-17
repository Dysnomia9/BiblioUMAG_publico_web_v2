# nginx de la API en producción (servicio "api" de docker-compose.prod.yml): recibe
# las peticiones en el puerto 8000 y se las pasa a PHP-FPM del servicio "backend".
# Va primero: la etapa que se construye cuando no se indica otra es la última (app).
FROM nginx:1.27-alpine AS nginx
COPY docker/nginx.conf.template /etc/nginx/templates/default.conf.template
ENV PHP_FPM_HOST=backend
# 8000: artisan serve (desarrollo). 9000: PHP-FPM (producción, detrás del nginx "api").
EXPOSE 8000 9000

# La aplicación. Base php-fpm: en producción atiende PHP-FPM con varios procesos
# (SERVIDOR=fpm); en desarrollo, `php artisan serve` (ver docker-entrypoint.sh).
FROM php:8.3-fpm AS app

# Dependencias del sistema + extensiones PHP necesarias para Laravel + MySQL.
# libldap2-dev + ext-ldap: para directorytree/ldaprecord-laravel (login LDAP
# institucional, ver LdapAuthController) — --with-libdir es necesario en Debian,
# si no docker-php-ext-configure no encuentra libldap.so. Se resuelve con
# dpkg-architecture en vez de hardcodear x86_64: esta imagen se builds tanto en
# Apple Silicon (aarch64-linux-gnu) como en runners amd64 (x86_64-linux-gnu).
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libicu-dev libldap2-dev \
    && docker-php-ext-configure ldap --with-libdir=lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH) \
    && docker-php-ext-install pdo pdo_mysql intl zip ldap opcache \
    && rm -rf /var/lib/apt/lists/*

# Límite de memoria, OPcache y los procesos de PHP-FPM (ver docker/).
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-biblioteca.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-biblioteca.conf

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Composer 2.8+ audita advisories de seguridad por defecto y puede bloquear
# instalaciones legítimas de Laravel; lo desactivamos a nivel de imagen.
ENV COMPOSER_NO_AUDIT=1

WORKDIR /var/www

# Dependencias primero: capa cacheada por Docker, solo se reinstalan si
# composer.json/composer.lock cambian — no en cada cambio de código propio.
# --no-autoloader porque app/ (mapeado en el autoload psr-4) todavía no
# existe en esta capa; se genera el real después de copiar el proyecto.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

# Resto del proyecto: Laravel completo, ya armado (app/, config/, routes/,
# migrations/, tests/, etc.) — no hay overlay que aplicar en el arranque.
COPY . .

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && cp .env.example .env \
    && php artisan key:generate --no-interaction \
    && chmod +x docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["./docker-entrypoint.sh"]
