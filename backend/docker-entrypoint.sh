#!/usr/bin/env bash
set -e

cd /var/www

# El .env con su APP_KEY ya se generó en tiempo de build (ver Dockerfile) —
# es estable mientras no se reconstruya la imagen. Si por algún motivo no
# existiera (ej. se corrió el contenedor a mano sobre otra imagen), se
# recrea acá para no romper el arranque.
if [ ! -f ".env" ]; then
  cp .env.example .env
  php artisan key:generate --no-interaction
fi

# Fuerza a Laravel a releer las variables de entorno reales del contenedor
# (DB_HOST, DB_DATABASE, etc., definidas en docker-compose.yml) en vez de
# quedarse con cualquier config cacheada en la imagen.
php artisan config:clear

# Reporte de vulnerabilidades conocidas en las dependencias de composer.lock
# (contra la base de advisories de Packagist). No bloquea el arranque si hay
# hallazgos o si no hay red — "composer audit" solo informa. Se guarda en
# storage/reports, mapeado a un volumen del host (ver docker-compose.yml)
# para poder revisarlo sin entrar al contenedor.
echo ">> Auditando dependencias de composer (vulnerabilidades conocidas)..."
mkdir -p storage/reports
composer audit --no-interaction --format=plain > storage/reports/composer-audit.log 2>&1 || true
echo "   Reporte: storage/reports/composer-audit.log"

echo ">> Esperando a la base de datos..."
until php artisan db:show > /dev/null 2>&1; do
  sleep 1
done

# Desarrollo hay que declararlo (docker-compose.yml pone APP_ENV=local): sin APP_ENV,
# como al correr la imagen a mano, se trata como producción y no se carga nada con
# contraseñas conocidas.
ES_DESARROLLO=false
if [ "${APP_ENV:-}" = "local" ]; then
  ES_DESARROLLO=true
fi

# La suite de tests (tests/Feature) corre contra una base MySQL de pruebas
# separada ('biblioteca_test'), no contra 'biblioteca' (desarrollo/demo) — ver
# phpunit.xml y backend/README.md. MySQL la crea al inicializar su volumen
# (backend/docker/mysql/01-biblioteca-test.sql); esto la crea si falta, por
# ejemplo en un volumen anterior a ese script. Sin permiso para crear bases no se
# cae el arranque: solo avisa.
# Solo en desarrollo: en producción no se corren tests.
if [ "$ES_DESARROLLO" = "true" ]; then
echo ">> Verificando base de datos de pruebas (biblioteca_test)..."
php -r '
$pdo = new PDO(
    "mysql:host=" . getenv("DB_HOST") . ";port=" . (getenv("DB_PORT") ?: 3306),
    getenv("DB_USERNAME"),
    getenv("DB_PASSWORD")
);
$existe = $pdo->query("SELECT 1 FROM information_schema.schemata WHERE schema_name = '\''biblioteca_test'\''")->fetchColumn();
if ($existe) {
    echo "   Ya existe.\n";
    exit;
}
try {
    $pdo->exec("CREATE DATABASE biblioteca_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "   Creada.\n";
} catch (PDOException $e) {
    echo "   No se pudo crear (el usuario no tiene permiso). Créala como root:\n";
    echo "   CREATE DATABASE biblioteca_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
    echo "   GRANT ALL PRIVILEGES ON biblioteca_test.* TO \x27" . getenv("DB_USERNAME") . "\x27@\x27%\x27;\n";
}
'
fi

echo ">> Corriendo migraciones..."
php artisan migrate --force

echo ">> Verificando datos de prueba..."
STAFF_COUNT=$(php artisan tinker --execute="echo \App\Models\Staff::count();" 2>/dev/null | tr -d '[:space:]')
if [ "$STAFF_COUNT" = "0" ] || [ -z "$STAFF_COUNT" ]; then
  if [ "$ES_DESARROLLO" = "true" ]; then
    echo ">> No hay datos de prueba aún, cargando mockup inicial (staff, usuarios, salas, entradas, préstamos)..."
    php artisan mockup:datos
  else
    # Los datos de prueba traen admin@umag.cl / admin123 con acceso total: fuera de
    # desarrollo la primera cuenta se crea a mano, con una contraseña propia.
    echo ">> Sin cuentas del personal y APP_ENV=${APP_ENV:-(sin definir)}: NO se cargan datos de prueba (solo con APP_ENV=local)."
    echo "   Crea la primera cuenta con acceso total con:"
    echo "   docker compose -f docker-compose.prod.yml exec backend php artisan personal:crear-admin <correo> --nombre=\"Nombre Apellido\""
  fi
else
  echo ">> Ya existen cuentas del personal ($STAFF_COUNT). En desarrollo, 'php artisan mockup:datos --fresh' regenera los datos de prueba."
fi

# Producción (docker-compose.prod.yml pone SERVIDOR=fpm): PHP-FPM con varios procesos,
# detrás del nginx del servicio "api". La configuración y las rutas se dejan en caché
# (las variables del contenedor ya están definidas a esta altura), y storage/ queda a
# nombre de www-data, el usuario con que corren los procesos de PHP-FPM.
if [ "${SERVIDOR:-}" = "fpm" ]; then
  php artisan config:cache
  php artisan route:cache
  chown -R www-data:www-data storage bootstrap/cache
  echo ">> Levantando PHP-FPM en el puerto 9000 (detrás del nginx de la API)"
  exec php-fpm -F
fi

# Desarrollo: el servidor de Laravel, un proceso, cómodo para trabajar.
# --no-reload: sin él, `artisan serve` le quita al proceso que atiende las variables
# del contenedor (DB_HOST, DB_DATABASE… de docker-compose.yml) y ese proceso lee
# solo el .env horneado en la imagen.
echo ">> Levantando servidor Laravel en 0.0.0.0:8000"
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
