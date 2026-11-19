# Backend: pruebas funcionales

Esta guía documenta cómo ejecutar las pruebas funcionales del backend en desarrollo.

## Requisitos previos

El sistema debe estar levantado con Docker Compose desde la raíz del repositorio:

```bash
docker compose up --build
```

Todos los comandos siguientes se ejecutan dentro del contenedor `backend`.

## Pruebas funcionales

### Base de datos de pruebas

Los tests de `tests/Feature` corren contra una base de datos **PostgreSQL de
prueba** separada de la de desarrollo (`biblioteca_test`, no `biblioteca`), porque
el sistema usa operadores específicos de Postgres (`ilike` en
`UsuarioController`/`PortalController::catalogo`) y columnas `date` cuyo formato de
almacenamiento de Eloquent solo el tipo `DATE` nativo de Postgres trunca
correctamente. SQLite en memoria no reproduce ese comportamiento, así que **no se
usa** para estos tests (ver `phpunit.xml`).

`docker-entrypoint.sh` crea `biblioteca_test` automáticamente (de forma
idempotente) cuando el contenedor `backend` arranca en desarrollo, así que no hace falta
ningún paso manual: basta con `docker compose up`.

Los tests usan una conexión de Eloquent dedicada, `pgsql_testing`
(`config/database.php`), en vez de reutilizar la conexión `pgsql` de siempre con
variables `DB_DATABASE=biblioteca_test` sobreescritas en `phpunit.xml`. Esto es
intencional y **no debe "simplificarse"**: `docker-compose.yml` ya define
`DB_HOST`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` como variables de entorno
reales del contenedor `backend` (apuntando a `biblioteca`), y Laravel/Dotenv no
permite que un `<env>` de `phpunit.xml` sobreescriba una variable de entorno que
el proceso ya tiene seteada, ni siquiera con `force="true"` (eso solo fuerza `getenv()`,
pero Laravel lee la config vía `$_ENV`/`$_SERVER`). Intentarlo así hace que los
tests terminen corriendo `migrate:fresh` contra `biblioteca` (la base real de
desarrollo) sin ningún aviso. `pgsql_testing` evita el problema de raíz usando
nombres de variable propios (`DB_TEST_*`) que Docker nunca define.

Por lo mismo, `docker-compose.yml` **no define `DB_CONNECTION`** (la toma del `.env`
de la imagen, que dice `pgsql`): si la definiera, el `<env name="DB_CONNECTION">` de
`phpunit.xml` dejaría de valer y los tests vaciarían `biblioteca`. Como red de
seguridad, `tests/TestCase.php` detiene los tests antes de tocar la base si la
conexión no es `pgsql_testing`.

### Ejecutar la suite

```bash
docker compose exec backend php artisan test
```

o, para el formato `testdox` (más legible, agrupado por caso):

```bash
docker compose exec backend vendor/bin/phpunit --testdox
```

`RefreshDatabase` migra `biblioteca_test` antes de cada clase de test y la limpia
después, así que no afecta los datos de `biblioteca` (la base de desarrollo/demo).

## Datos de demostración

El comando `mockup:datos` se registra únicamente con `APP_ENV=local` o
`APP_ENV=testing`. Su implementación está en
`app/Console/Development/SeedMockupData.php`, fuera del directorio de comandos
de registro automático. Admite `--fresh` para regenerar los datos de desarrollo.

En producción y en otros entornos no está disponible y no admite `--force`.
Para iniciar una instalación real se usa `php artisan sistema:iniciar`.
