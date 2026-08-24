FROM php:8.3-cli

# Dependencias del sistema + extensiones PHP necesarias para Laravel + Postgres.
# libldap2-dev + ext-ldap: para directorytree/ldaprecord-laravel (login LDAP
# institucional, ver LdapAuthController) — --with-libdir es necesario en Debian,
# si no docker-php-ext-configure no encuentra libldap.so. Se resuelve con
# dpkg-architecture en vez de hardcodear x86_64: esta imagen se builds tanto en
# Apple Silicon (aarch64-linux-gnu) como en runners amd64 (x86_64-linux-gnu).
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libzip-dev libicu-dev libldap2-dev \
    && docker-php-ext-configure ldap --with-libdir=lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH) \
    && docker-php-ext-install pdo pdo_pgsql intl zip ldap \
    && rm -rf /var/lib/apt/lists/*

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
