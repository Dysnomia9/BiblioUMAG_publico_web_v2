# Traspasar el trabajo de BiblioUMAG a otro servidor

`sistema:exportar` guarda un JSON con el trabajo de un mes y las dependencias que
necesita. `sistema:importar` lo incorpora a otra instalación: crea lo que falta,
actualiza lo que corresponde al mismo registro y omite lo que ya está igual.
Se puede importar nuevamente un archivo o generar otro respaldo del mismo origen.

Este traspaso complementa el respaldo completo de PostgreSQL que ya realiza el
servicio `respaldo`. El JSON lleva datos personales y hashes de las contraseñas;
se crea con permisos `0600` y debe transportarse y guardarse de forma privada.
No se guarda en Git. Las contraseñas siguen siendo las mismas al entrar al servidor.

## Qué se incluye

| Datos | Selección |
| --- | --- |
| Préstamos, entradas/asistencia, reservas de salas y libros, multas y usos sin RUT | Creados **o actualizados** durante el mes. Las reservas se eligen por cuándo se registraron, aunque estén programadas para otro mes. |
| Bitácora e historial de estado de ejemplares | Registros del mes. |
| Libros, ejemplares, equipos, fascículos y fichas/préstamos interbibliotecarios | Creados durante el mes. Incluye autores vinculados, materias, carreras, roles de autor y relaciones necesarias. |
| Usuarios | Todos: documento, datos personales, correos adicionales, contraseña almacenada, activo/inactivo, vigencia y preferencias. No los renueva automáticamente. |
| Personal y roles | Todas las cuentas, también las desactivadas o dadas de baja, con sus permisos y contraseñas almacenadas. |
| Configuración y catálogos | Salas, carreras, materias, ubicaciones, tipos de material, estados personalizados, categorías de recursos, políticas de vigencia, reglas de préstamo, cierres y plazos de salas. |
| Dependencias antiguas | Solo las necesarias para vincular lo anterior; no se arrastra todo su historial. |

Se excluyen los movimientos y el catálogo identificados en `horizon_etl`, las
multas de origen `importada`, los fascículos con referencia externa y las tablas
de usos históricos de Horizon. La fecha `created_at` de una carga desde Horizon
puede ser reciente: por eso también se consulta su procedencia. Los libros y
ejemplares con `fecha_ingreso_horizon` tampoco se seleccionan como nuevos.

Cuando un movimiento nuevo necesita un libro antiguo, el archivo lo incluye como
referencia. Si ya existe en el servidor, se conserva su ficha bibliográfica; si
falta, se crea. La disponibilidad y el estado actual de las copias y equipos
referenciados se sincronizan para acompañar a los préstamos trasladados.

Los reportes se calculan desde los movimientos y usuarios importados. No se
copian PDF, Excel, archivos subidos ni reportes descargados. Tampoco se trasladan
sesiones, tokens, códigos de acceso, credenciales simuladas, trabajos de cola,
variables `.env` o claves de la aplicación.

## Preparar ambos sistemas

Usar la misma versión del backend, migraciones y zona horaria en ambas instalaciones.
Los comandos de este documento corresponden a PostgreSQL, rama `main`.

Como el código del backend está dentro de la imagen, después de actualizarlo:

```bash
docker compose up -d --build backend
docker compose up -d --force-recreate scheduler
docker compose exec backend php artisan migrate --force
```

El arranque habitual ya ejecuta las migraciones; el último comando comprueba que
no falte ninguna. En producción, usar `docker compose -f docker-compose.prod.yml`
en los comandos equivalentes, con su entorno configurado.

Si el servidor va a recibir también el catálogo y el historial de Horizon,
ejecutar primero esa carga con su ETL. Después importar este JSON. Así se pueden
reutilizar los libros y copias antiguos mediante sus claves de Horizon.
Los usuarios del JSON pueden incorporarse aunque el servidor todavía no tenga
la carga masiva de estudiantes.

## Exportar desde este computador

Por ejemplo, para noviembre de 2026:

```bash
docker compose exec backend php artisan sistema:exportar \
  --mes=2026-11 \
  --archivo=storage/app/traspasos/noviembre-2026.json
```

Sin `--mes`, usa el mes actual en la zona horaria de la biblioteca; sin `--archivo`,
genera un nombre con mes y fecha en `storage/app/traspasos/`. Rechaza sobrescribir
un archivo existente. Muestra las cantidades y cuántas filas son dependencias.

Sacar el archivo del contenedor antes de reconstruirlo o eliminarlo:

```bash
mkdir -p traspasos
docker compose cp backend:/var/www/storage/app/traspasos/noviembre-2026.json \
  traspasos/noviembre-2026.json
chmod 600 traspasos/noviembre-2026.json
```

La carpeta `traspasos/` está excluida de Git. El archivo dentro del contenedor
no tiene un volumen propio: la copia al computador es la que debes conservar.

## Importar en el servidor

Copiar el JSON al servidor por el mecanismo de transferencia que se utilice allí.
Desde la carpeta del proyecto en el servidor:

```bash
docker compose -f docker-compose.prod.yml cp traspasos/noviembre-2026.json \
  backend:/var/www/storage/app/noviembre-2026.json

docker compose -f docker-compose.prod.yml exec backend php artisan sistema:importar \
  storage/app/noviembre-2026.json --simular
```

La simulación muestra por tabla cuántas filas se crearían, actualizarían o
quedarían iguales, las referencias reutilizadas y las relaciones que se quitarían.
Prueba las restricciones reales dentro de una transacción que después revierte;
usa IDs temporales para no consumir secuencias. No guarda mapeos ni invalida sesiones.

Después de revisar el resultado, aplicar el mismo archivo:

```bash
docker compose -f docker-compose.prod.yml exec backend php artisan sistema:importar \
  storage/app/noviembre-2026.json
```

El traspaso completo es una transacción: un conflicto cancela todas sus escrituras.
Las tablas quedan bloqueadas para escritura durante la simulación/importación;
conviene ejecutarlas fuera del horario de uso. Una importación real cancelada
puede dejar saltos en las secuencias de PostgreSQL, sin guardar datos parciales.

## Identidad, reemplazos y conflictos

- Cada base de origen conserva un UUID en `traspaso_instancia`. Los mapeos
  `origen + tabla + ID original → ID del servidor` quedan en `traspaso_registros`.
  Esto permite que un préstamo que después se devuelve actualice el mismo
  préstamo del servidor en el siguiente respaldo. Conservar estas tablas.
- En la primera importación se usan las correspondencias de Horizon cuando están
  disponibles y claves como documento normalizado, correo del personal, código
  de sala, número de ficha o código de barras. Nunca se supone que los IDs
  numéricos de dos instalaciones distintas representan lo mismo.
- Los movimientos sin correspondencia solo se reconocen por coincidencia
  completa. Si el servidor ya tiene movimientos modificados de este mismo origen
  que se copiaron por otra vía, revisar ese caso antes del primer traspaso.
- Ante una ficha local que colisiona con una ficha de otra procedencia, una
  coincidencia ambigua, una nueva colisión de correo con personas del servidor, un recurso
  que quedaría con dos préstamos activos o una restricción de la base, se cancela
  la importación. El comando no decide por su cuenta que dos entidades diferentes
  son la misma.
- Los correos compartidos que ya venían en el origen (por ejemplo, desde Horizon)
  se conservan con sus mismos dueños. La importación no los corrige ni permite
  sumarles otro dueño distinto del servidor.
- Los usuarios y cuentas coincidentes reciben el estado exacto del archivo. Si
  cambia su acceso, contraseña, vigencia o identidad, se revocan sus sesiones
  locales para que vuelvan a autenticarse. No se envían notificaciones ni se
  generan movimientos nuevos durante la restauración.
- Se sincronizan los correos adicionales, autores/materias/carreras de los libros
  incluidos como nuevos y los integrantes de las reservas incluidas como completas.
  Se quitan las relaciones sobrantes de esos padres. No se borran personas,
  préstamos, libros o reservas del servidor por estar ausentes en el respaldo.
- Es una transferencia del computador de trabajo hacia el servidor: al aplicar
  otra vez un archivo antiguo, sus valores reemplazan los valores de las filas
  coincidentes. No es una sincronización bidireccional ni resuelve ediciones
  concurrentes. Al empezar a trabajar en el servidor, mantener allí la fuente de datos.

La prueba automatizada reproduce dos instalaciones con IDs distintos, importación
repetida, devoluciones posteriores, reportes, vigencias, simulación sin cambios,
reutilización de Horizon y reversión por conflictos:

```bash
docker compose exec backend php artisan test --filter=TraspasoSistemaTest
```
