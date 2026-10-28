#!/bin/sh
# Respaldo automático de la base de datos (libros, préstamos, multas, bitácora de
# cambios y todo lo demás viven ahí). Corre en un contenedor aparte: todos los días
# a la hora indicada hace un pg_dump, comprueba que el archivo se pueda leer y borra
# los respaldos viejos.
#
# Variables (las pone docker-compose):
#   PGHOST, PGUSER, PGPASSWORD, PGDATABASE   la conexión a Postgres
#   RESPALDO_HORA          hora UTC del respaldo diario, 00-23 (por defecto 05 = 02:00 en Punta Arenas)
#   RESPALDO_DIAS          cuántos días se guardan los respaldos diarios (por defecto 14)
#   RESPALDO_SEMANAS       cuántas semanas se guardan los de los domingos (por defecto 8)
#   RESPALDAR_AL_INICIAR   si vale 1, hace un respaldo apenas arranca el contenedor
set -eu

DESTINO=/respaldos
HORA="${RESPALDO_HORA:-05}"
DIAS="${RESPALDO_DIAS:-14}"
SEMANAS="${RESPALDO_SEMANAS:-8}"

mkdir -p "$DESTINO/diarios" "$DESTINO/semanales"

respaldar() {
  sello=$(date -u +%Y-%m-%d_%H%M)
  archivo="$DESTINO/diarios/${PGDATABASE}-${sello}.dump"
  parcial="${archivo}.parcial"

  echo "[$(date -u +%FT%TZ)] Respaldando ${PGDATABASE}..."
  # Formato custom (-Fc): comprimido y restaurable por partes con pg_restore.
  pg_dump -Fc --no-owner --file="$parcial"

  # Un respaldo que no se puede leer no sirve: si falla, se descarta y se avisa.
  if ! pg_restore --list "$parcial" > /dev/null; then
    echo "[$(date -u +%FT%TZ)] ERROR: el respaldo salió dañado, se descarta." >&2
    rm -f "$parcial"
    return 1
  fi
  mv "$parcial" "$archivo"
  echo "[$(date -u +%FT%TZ)] Listo: $archivo ($(du -h "$archivo" | cut -f1))"

  # Los domingos se guarda además una copia semanal, que dura más.
  if [ "$(date -u +%u)" = "7" ]; then
    cp "$archivo" "$DESTINO/semanales/"
  fi

  # Rotación: borra lo que pasó el plazo, pero nunca el último respaldo.
  find "$DESTINO/diarios" -name '*.dump' -mtime +"$DIAS" -delete
  find "$DESTINO/semanales" -name '*.dump' -mtime +$((SEMANAS * 7)) -delete
  find "$DESTINO" -name '*.parcial' -mmin +60 -delete
}

until pg_isready -q; do
  echo "Esperando a la base de datos..."
  sleep 3
done

if [ "${RESPALDAR_AL_INICIAR:-0}" = "1" ]; then
  respaldar || true
fi

# Una vez por hora comprueba si ya es la hora y si hoy ya hay respaldo.
while true; do
  if [ "$(date -u +%H)" = "$HORA" ] && ! ls "$DESTINO/diarios/${PGDATABASE}-$(date -u +%Y-%m-%d)_"*.dump > /dev/null 2>&1; then
    respaldar || true
  fi
  sleep 600
done
