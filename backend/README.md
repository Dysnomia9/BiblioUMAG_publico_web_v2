# Backend — pruebas y benchmark de rendimiento

Esta guía documenta cómo reproducir, durante la defensa de la tesis, los resultados
de las pruebas funcionales (Cuadro 9.1) y de latencia de la API (Cuadro 10.2).

## Requisitos previos

El sistema debe estar levantado con Docker Compose desde la raíz del repositorio:

```bash
docker compose up --build
```

Todos los comandos siguientes se ejecutan dentro del contenedor `backend`.

## Pruebas funcionales (Cuadro 9.1)

### Base de datos de pruebas

Los tests de `tests/Feature` corren contra una base de datos **PostgreSQL de
prueba** separada de la de desarrollo (`biblioteca_test`, no `biblioteca`), porque
el sistema usa operadores específicos de Postgres (`ilike` en
`UsuarioController`/`PortalController::catalogo`) y columnas `date` cuyo formato de
almacenamiento de Eloquent solo el tipo `DATE` nativo de Postgres trunca
correctamente. SQLite en memoria no reproduce ese comportamiento, así que **no se
usa** para estos tests (ver `phpunit.xml`).

`docker-entrypoint.sh` crea `biblioteca_test` automáticamente (de forma
idempotente) cada vez que el contenedor `backend` arranca, así que no hace falta
ningún paso manual — basta con `docker compose up`.

Los tests usan una conexión de Eloquent dedicada, `pgsql_testing`
(`config/database.php`), en vez de reutilizar la conexión `pgsql` de siempre con
variables `DB_DATABASE=biblioteca_test` sobreescritas en `phpunit.xml`. Esto es
intencional y **no debe "simplificarse"**: `docker-compose.yml` ya define
`DB_HOST`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` como variables de entorno
reales del contenedor `backend` (apuntando a `biblioteca`), y Laravel/Dotenv no
permite que un `<env>` de `phpunit.xml` sobreescriba una variable de entorno que
el proceso ya tiene seteada — ni con `force="true"` (eso solo fuerza `getenv()`,
pero Laravel lee la config vía `$_ENV`/`$_SERVER`). Intentarlo así hace que los
tests terminen corriendo `migrate:fresh` contra `biblioteca` (la base real de
desarrollo) sin ningún aviso. `pgsql_testing` evita el problema de raíz usando
nombres de variable propios (`DB_TEST_*`) que Docker nunca define.

### Ejecutar la suite

```bash
docker compose exec backend php artisan test
```

o, para el formato `testdox` (más legible, agrupado por caso):

```bash
docker compose exec backend vendor/bin/phpunit --testdox
```

`RefreshDatabase` migra `biblioteca_test` antes de cada clase de test y la limpia
después — no afecta los datos de `biblioteca` (la base de desarrollo/demo).

## Benchmark de latencia de la API (Cuadro 10.2)

El comando `benchmark:api` autentica como staff contra el backend ya levantado (vía
HTTP real, no en proceso) y mide la latencia de:

- `GET /api/usuarios`
- `POST /api/entrada` (cada request se cierra de inmediato con
  `PATCH /entrada/{id}/salida` para no dejar la entrada activa antes de la
  siguiente iteración; esa llamada de cierre no se incluye en la medición)
- `GET /api/reportes/resumen`

Crea un usuario temporal ("Benchmark API") para el endpoint de entradas y lo
elimina al finalizar (elimina en cascada sus entradas de prueba) — no deja datos
residuales en `biblioteca`.

### Ejecutar el benchmark

```bash
docker compose exec backend php artisan benchmark:api
```

Opciones disponibles (todas con valor por defecto razonable):

```bash
docker compose exec backend php artisan benchmark:api \
  --host=http://localhost:8000 \
  --n=100 \
  --email=admin@umag.cl \
  --password=admin123
```

- `--host`: URL base del backend a medir. Por defecto apunta al propio backend
  dentro del contenedor (`http://localhost:8000`, donde corre
  `php artisan serve`). Si se ejecuta desde fuera de Docker contra el puerto
  publicado, sigue siendo `http://localhost:8000`.
- `--n`: cantidad de requests por endpoint (100 para el Cuadro 10.2 de la tesis).
- `--email` / `--password`: credenciales de staff usadas para autenticar.

El comando imprime directamente una tabla en formato Markdown con promedio,
mediana, p95 y máximo (en ms) por endpoint, lista para pegar como Cuadro 10.2.
