# Biblioteca UMAG en Vue 3 + Laravel

Sistema de biblioteca universitaria que reemplaza a **Horizon** (SirsiDynix)
por una plataforma propia: **Vue 3 + Tailwind** (frontend) y **Laravel +
PostgreSQL** (backend API), 100% dockerizada.

## Qué se puede hacer

**Personal de biblioteca (staff)**

- Dashboard con indicadores en tiempo real: usuarios activos, entradas de
  hoy, personas en sala, préstamos activos y atrasados.
- Registro de entradas por RUT, QR o manual — incluye visitantes externos,
  de convenio y visitas. El historial se puede consultar por día exacto
  (uso diario de mesón) o en modo búsqueda, por rango de fechas y/o RUT/
  nombre — útil para auditar cuándo vino una persona puntual, no solo el
  tráfico de hoy.
- Préstamos y devoluciones de libros (por código de barras de la copia
  física, con fecha de préstamo/devolución acordada) y de equipos —
  audífonos, notebooks y cargadores — también por código de barras real,
  desde un inventario real con control de disponibilidad.
- Multas automáticas por atraso al devolver un libro: tarifa configurable
  en `config/multas.php` ($15/día), aviso al staff si el usuario ya tiene
  deuda pendiente al crear un préstamo nuevo, y una vista "Multas
  Pendientes" con el total adeudado por usuario.
- Reservas de salas de estudio (logias, horario continuo: inicio libre en
  pasos de 30 minutos y duración de hasta 2 horas por reserva, con menú de
  confirmación de asistencia: 15 minutos para presentarse antes de que la
  sala se libere sola, y una cuota diaria de 4 horas por persona, sin
  importar en cuántas salas distintas se reparta) y de libros del catálogo
  para retiro — ambas comparten la disponibilidad del ejemplar como fuente
  de verdad: no se puede reservar ni prestar algo ya ocupado.
- Catalogación de libros con soporte real para **múltiples copias del
  mismo título** (registro bibliográfico separado de cada copia física,
  con su propio código de barras y estado), autor/categoría/carrera
  múltiples por libro, ISBN, generador de código de barras, y gestión del
  estado físico de cada copia (inventario → en estante → de baja, colección
  móvil, o un estado personalizado administrable), como eje independiente
  de su disponibilidad.
- Cambio masivo de estado de copias (por ubicación/estado actual/tipo/
  categoría, con confirmación seria) e historial de cada cambio de estado,
  además de un historial de préstamos por libro/copia.
- Gestión de usuarios (con botón de acceso a la base de datos digital
  externa y generación de Constancia de No Multa en PDF), equipos
  (agregar/dar de baja), listado de préstamos y listado de libros —
  agrupados en el menú "Gestiones Admin".
- Reportes con gráficos (préstamos, ingresos, uso de logias, top de libros
  más prestados, heatmap de horas más solicitadas por sala) filtrables por
  período, carrera, sexo y tipo de usuario.
- Código QR de acceso compartido y regenerable, para que los usuarios
  marquen su entrada por su cuenta.
- Panel de Administración (solo admin): nombre/cargo de quien firma la
  Constancia de No Multa, catálogo de estados personalizados de ejemplar,
  catálogo de ubicaciones físicas, y catálogo de tipos de material (antes
  un enum fijo de 5 valores, ahora administrable como los otros dos).

**Portal virtual de autoservicio (usuarios finales, sin ser staff)**

- Login propio, independiente del panel de personal, con pantalla de
  ingreso claramente diferenciada de la del staff.
- Marcar entrada/salida por RUT o escaneando el QR con la cámara.
- Consultar el catálogo de libros disponibles, y acceder a la base de datos
  digital externa de la universidad.
- Reservar salas de estudio — solo para el mismo día (no se puede elegir
  fecha futura), con la misma ventana de 15 minutos para confirmar
  asistencia que ve el staff.
- Reservar un libro por su cuenta, o unirse a la cola de espera si ya está
  prestado/reservado por otra persona — cuando se libera, se promueve
  automáticamente al primero en la fila (sin tener que ir a mesón).
- Descargar su propia Constancia de No Multa en PDF, sin pasar por mesón
  (bloqueada si tiene una multa pendiente).

**Backend / integraciones**

- Dos guards de autenticación independientes vía Sanctum (`staff` y
  `usuario`), más un rol `admin` dentro de `staff` que restringe acciones
  como la catalogación de libros y la administración de catálogos.
- Compatibilidad con los lectores de código de barras de Horizon para
  logias y puestos de trabajo (sin sincronizar datos con Horizon — ver
  Deuda técnica).
- Notificaciones por correo (reserva de libro lista para retirar, multa
  generada al devolver atrasado) ya implementadas y disparándose solas
  desde el flujo real — pero sin servidor SMTP configurado todavía, así
  que hoy quedan escritas en `storage/logs/laravel.log` en vez de salir
  por correo real. Activarlas es solo configurar `MAIL_MAILER`/`MAIL_HOST`
  en `.env`, sin tocar código.
- Login unificado (`/login/v2`) con Google y LDAP institucional además del
  login local — un solo email/RUT ya autentica indistintamente contra
  `staff` o `usuario` según a quién pertenezca. El bind LDAP (patrón
  "search + bind", sin asumir un formato de DN fijo) se probó de punta a
  punta contra un servidor OpenLDAP real; Google usa Socialite y quedó
  verificado hasta donde se puede sin una cuenta de Google Cloud real.
  Ninguno de los dos está activo sin configurar `.env` (`GOOGLE_CLIENT_ID`
  o `LDAP_HOST`) — mismo criterio que las notificaciones: código real,
  inactivo hasta tener las credenciales.

## Estructura

```
biblioteca-vue-laravel/
├── backend/              # Proyecto Laravel completo y normal (API-only)
│   ├── Dockerfile
│   ├── docker-entrypoint.sh
│   ├── app/               # Models, Controllers, Middleware, Services, Console/Commands
│   ├── config/ database/ routes/ tests/ bootstrap/  # lo que editamos habitualmente
│   └── public/ resources/ storage/ artisan composer.json  # esqueleto estándar de Laravel
├── frontend/              # Vue 3 + Vite + Tailwind + Pinia
│   └── src/
└── docker-compose.yml
```

| Módulo | Ruta staff | Backend |
|---|---|---|
| Dashboard | `/dashboard` | `DashboardController` |
| Usuarios | `/usuarios` (dropdown "Gestiones Admin") | `UsuarioController` |
| Entrada | `/entrada` | `EntradaController` |
| Préstamos | `/prestamo`, `/prestamos/listado` (dropdown) | `PrestamoController` |
| Equipos | `/equipos` (dropdown, **solo admin**) | `EquipoController` |
| Salas (logias) | `/salas` | `SalaController` |
| Multas Pendientes | `/reportes/multas-pendientes` (dropdown) | `ReporteController::multasPendientes` |
| Reportes | `/reportes` | `ReporteController` |
| Código QR de acceso | `/codigo-qr` (dropdown) | `CodigoAccesoController` |
| Listado de libros | `/libros/listado` (dropdown) | `LibroController` |
| Catalogación de libros | `/libros/catalogacion` (dropdown, **solo admin**) | `LibroController::store/update`, `EjemplarController::store` |
| Estado de libro | `/libros/estado` (dropdown) | `EjemplarController::cambiarEstado` |
| Cambio masivo de estado | `/libros/cambio-masivo` (dropdown, **solo admin**) | `EjemplarController::cambioMasivo*` |
| Historial de estado | `/libros/historial-estado` (dropdown) | `EjemplarController::historialEstado` |
| Historial de libros | `/libros/historial-prestamos` (dropdown) | `LibroController::historial` |
| Administración | `/administracion` (dropdown, **solo admin**) | `ConfiguracionInstitucionalController`, `EstadoLibroPersonalizadoController`, `UbicacionController`, `TipoMaterialController` |

### Cómo se arma la imagen del backend

`backend/Dockerfile` es un Dockerfile Laravel estándar: `composer install`
corre en tiempo de **build** (capa cacheada por Docker — solo se reinstala
si `composer.json`/`composer.lock` cambian) y `COPY . .` copia el proyecto
completo, ya armado, a la imagen. La imagen queda autocontenida: no depende
de ningún volumen para tener el código, y el primer arranque no necesita
red para instalar nada.

`docker-entrypoint.sh` solo hace trabajo de **runtime**: esperar a que la
base de datos esté lista, correr migraciones, cargar datos de prueba si
hace falta, y levantar el servidor.

(Hasta el 2026-07-17 esto se resolvía distinto — un "overlay" que se
aplicaba en el primer arranque del contenedor sobre un Laravel instalado en
un volumen. Se simplificó a un Dockerfile normal porque no aportaba
ninguna ventaja real sobre el patrón estándar y hacía la imagen depender de
red/volumen en el primer arranque — mala base para un despliegue real.)

## Cómo levantar el proyecto

Requisitos: Docker y Docker Compose instalados.

```bash
cd biblioteca-vue-laravel
docker compose up --build
```

Esto levanta:

| Servicio  | URL                          | Descripción                          |
|-----------|-------------------------------|---------------------------------------|
| frontend  | http://localhost:5173         | Vue 3 (Vite dev server, hot reload)  |
| backend   | http://localhost:8000         | Laravel API                          |
| db        | localhost:5432                | PostgreSQL                           |

**Primer arranque:** la imagen del backend puede tardar un par de minutos en
construirse la primera vez (`composer install` corre en el build). Una vez
construida, arrancar y rearmar contenedores es rápido — el código ya está
horneado en la imagen, no se reinstala nada al levantar.

> Este `docker-compose.yml` es un entorno de **desarrollo** (bind mount del
> frontend, credenciales de Postgres hardcodeadas en el propio archivo). No
> está pensado como configuración de despliegue en producción.

### Credenciales de prueba

```
Email:    admin@umag.cl
Password: admin123
```

Estas credenciales, junto con usuarios, salas y movimientos de ejemplo, se cargan
automáticamente **solo la primera vez** que levantas el proyecto (si la tabla `staff`
está vacía). En arranques posteriores tus datos persisten — `docker compose up` ya
no borra la base cada vez.

## Cargar / regenerar datos de prueba manualmente

```bash
docker compose exec backend php artisan mockup:datos            # solo si no hay datos aún
docker compose exec backend php artisan mockup:datos --fresh    # borra todo y regenera desde cero
```

Este comando (`app/Console/Commands/SeedMockupData.php`) genera:

- 1 usuario `staff` (admin)
- 30 `usuarios` con RUT válido (con dígito verificador calculado), carrera (de las
  8 carreras UMAG), año de ingreso y sexo
- Entradas y préstamos distribuidos en los últimos días, con sesgo horario
  (más tráfico 10–13h y 15–18h), incluyendo ejemplos de entrada externa, de
  convenio y de visita para hoy
- 10 equipos (audífonos, notebooks y cargadores, cada uno con nombre
  legible y código de barras propio) y sus préstamos asociados
- 18 salas: 15 logias de estudio (capacidades variables, cada una con su
  propio `codigo_barras` inventado — Horizon aún no entrega los reales) más
  Sala de Seminarios, Sala de Postgrado y Sala AGACI (apoyo a la inclusión) —
  y reservas de los últimos días, con sus participantes reales
- Libros catalogados con autor(es)/categoría(s)/carrera(s), algunos con más
  de una copia física (ejemplares con su propio código de barras y estado)

Si necesitas empezar completamente de cero (esquema incluido):

```bash
docker compose down -v
docker compose up --build
```

## Desarrollo del frontend fuera de Docker (opcional)

```bash
cd frontend
npm install
cp .env.example .env
npm run dev
```

## Tests y benchmark de rendimiento

Hay una suite de Feature tests (`backend/tests/Feature/`, 231 tests al
2026-08-21) que cubre login de staff/usuario, registro de entradas (incluido
el modo búsqueda por rango de fechas y RUT/nombre) y su historial, reservas
de sala en horario continuo (inicio libre + duración de hasta 2 horas,
solapamiento y validación grupal, cuota diaria de 4 horas por participante
sin importar en cuántas salas distintas se reparta, restricción a "solo
hoy" para alumnos, y el flujo completo de confirmación de asistencia con
liberación automática por no-show), préstamos
y reservas de libros y equipos por código de barras (incluida la protección
contra condición de carrera con `DB::transaction()`+`lockForUpdate()`),
catalogación y cambio de estado de ejemplares (incluido el cambio masivo y
su historial), el catálogo administrable de tipos de material, CHECK
constraints de las columnas tipo enum, cascadas de borrado `RESTRICT` en el
historial, atribución real de staff en préstamos, cálculo/cobro de multas
por atraso y su reporte consolidado, las notificaciones por correo (reserva
lista para retirar, multa generada — vía `Notification::fake()`, sin
depender de un servidor de correo real), configuración institucional, la
Constancia de No Multa en autoservicio desde el portal, y la separación de
middlewares `staff`/`usuario`. Corre contra una base Postgres de pruebas
dedicada (`biblioteca_test`, separada de `biblioteca`), que
`docker-entrypoint.sh` crea automáticamente al levantar el backend.

```bash
docker compose exec backend php artisan test
```

También hay un comando (`php artisan benchmark:api`) que mide latencia real
de la API vía HTTP (promedio, mediana, p95, máximo). Ver
[`backend/README.md`](backend/README.md) para el detalle de ambos, incluidas
las opciones disponibles y por qué se usa Postgres en vez de SQLite para los
tests.

## Deuda técnica conocida

- **Múltiples copias de un mismo libro: ya resuelto (2026-08-15)** — el
  modelo `Ejemplar` separa la copia física (código de barras, estado,
  disponibilidad) del registro bibliográfico `Libro` (obra). Lo que sigue
  fuera de alcance es la separación estilo Horizon "Bib No." / "Item No."
  como identificadores formales — hoy es simplemente `libro_id` +
  `numero_copia`.
- **Multas sin bloqueo duro**: se avisa al staff al crear un préstamo si el
  usuario tiene deuda pendiente, y existe una vista consolidada por usuario,
  pero ningún préstamo se rechaza por eso — es una decisión deliberada, no
  un bug.
- **Contadores históricos de préstamo** (cantidad, última fecha) no se
  guardan como columnas — son derivables por query sobre `prestamos`, y ya
  hay una vista dedicada para verlos on-demand (Historial de Libros).
- **Credenciales de Postgres hardcodeadas** en `docker-compose.yml` —
  aceptable en desarrollo local, a propósito. Para un despliegue real usar
  `docker-compose.prod.yml` + `backend/.env.production.example` (plantillas,
  sin valores reales).
- **`PortalController` concentra varias responsabilidades** (estado/aforo,
  entrada/salida, catálogo, salas y reservas del usuario). Si crece más,
  conviene separar por dominio en vez de agregar más métodos ahí.
- **Sin aviso de préstamo por vencer**: ya existen notificaciones por
  correo para "reserva lista para retirar" y "multa generada" (ver
  Backend/integraciones), disparadas por eventos que ya ocurren en el
  código. Falta el aviso "tu préstamo vence mañana", que necesitaría un
  job programado (`Schedule::` diario) — no hay ningún proceso de cron
  corriendo en `docker-compose.yml` hoy, así que quedó fuera de alcance
  por ahora, no es un olvido.
- **Notificaciones sin servidor SMTP real**: funcionan y están probadas,
  pero sin credenciales de un servidor de correo configuradas quedan
  escritas en el log del backend en vez de enviarse de verdad — ver
  Backend/integraciones para cómo activarlas.
- **Sin backups automatizados** — decisión de alcance deliberada por ahora,
  no un olvido. La integridad de datos (transacciones, locks, CHECK
  constraints, cascadas `RESTRICT`, baja lógica en la mayoría de las
  entidades) ya está resuelta.
