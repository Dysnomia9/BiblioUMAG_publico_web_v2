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

echo ">> Esperando a la base de datos..."
until php artisan db:show > /dev/null 2>&1; do
  sleep 1
done

# La suite de tests (tests/Feature) corre contra una base Postgres de pruebas
# separada ('biblioteca_test'), no contra 'biblioteca' (desarrollo/demo) — ver
# phpunit.xml y backend/README.md. Se crea aquí de forma idempotente para que
# "docker compose up" deje todo listo para `php artisan test` sin pasos manuales.
echo ">> Verificando base de datos de pruebas (biblioteca_test)..."
php -r '
$pdo = new PDO(
    "pgsql:host=" . getenv("DB_HOST") . ";port=" . (getenv("DB_PORT") ?: 5432) . ";dbname=" . getenv("DB_DATABASE"),
    getenv("DB_USERNAME"),
    getenv("DB_PASSWORD")
);
$exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = '\''biblioteca_test'\''")->fetchColumn();
if (! $exists) {
    $pdo->exec("CREATE DATABASE biblioteca_test");
    echo "   Creada.\n";
} else {
    echo "   Ya existe.\n";
}
'

echo ">> Corriendo migraciones..."
php artisan migrate --force

echo ">> Verificando datos de prueba..."
STAFF_COUNT=$(php artisan tinker --execute="echo \App\Models\Staff::count();" 2>/dev/null | tr -d '[:space:]')
if [ "$STAFF_COUNT" = "0" ] || [ -z "$STAFF_COUNT" ]; then
  echo ">> No hay datos de prueba aún, cargando mockup inicial (staff, usuarios, salas, entradas, préstamos)..."
  php artisan mockup:datos
else
  echo ">> Ya existen datos de prueba ($STAFF_COUNT staff). Usa 'php artisan mockup:datos --fresh' para regenerarlos."
fi

echo ">> Levantando servidor Laravel en 0.0.0.0:8000"
exec php artisan serve --host=0.0.0.0 --port=8000
