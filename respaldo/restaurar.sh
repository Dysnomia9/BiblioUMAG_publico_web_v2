#!/bin/sh
# Restaura un respaldo en una base de datos NUEVA (no pisa la actual), para revisarlo
# o para reemplazar la original a mano si hace falta.
#
#   docker compose run --rm respaldo /restaurar.sh <archivo.dump> [base_destino]
#
# Ejemplo (el archivo se busca dentro de /respaldos):
#   docker compose run --rm respaldo /restaurar.sh diarios/biblioteca-2026-10-28_0500.dump biblioteca_restaurada
#
# Para dejarlo como base en uso: detener backend y scheduler, renombrar la base
# actual y la restaurada con ALTER DATABASE ... RENAME TO ..., y volver a levantarlos.
set -eu

ARCHIVO="${1:?Indica el archivo, por ejemplo diarios/biblioteca-2026-10-28_0500.dump}"
DESTINO="${2:-${PGDATABASE}_restaurada}"
RUTA="/respaldos/$ARCHIVO"

[ -f "$RUTA" ] || { echo "No existe $RUTA" >&2; exit 1; }
[ "$DESTINO" != "$PGDATABASE" ] || { echo "Por seguridad no se restaura sobre la base en uso ($PGDATABASE)." >&2; exit 1; }

echo "Creando la base $DESTINO..."
createdb "$DESTINO"
echo "Restaurando $ARCHIVO en $DESTINO..."
pg_restore --no-owner --dbname="$DESTINO" "$RUTA"
echo "Listo. Revisa la base $DESTINO antes de usarla."
