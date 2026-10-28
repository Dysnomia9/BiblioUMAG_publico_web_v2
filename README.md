# BiblioUMAG

**Sistema de gestión de la Biblioteca de la Universidad de Magallanes.**

BiblioUMAG reemplaza a **Horizon** (SirsiDynix) por una plataforma propia,
moderna y 100% dockerizada: **Vue 3 + Tailwind** en el frontend y una API
**Laravel + PostgreSQL** en el backend. Cubre la operación diaria del mesón
(entradas, préstamos, salas, multas), la catalogación y un portal de
autoservicio para la comunidad universitaria.

> **Dos versiones, una por rama.** `main` usa **PostgreSQL 16**. La rama
> [`sql`](https://github.com/Dysnomia9/Bibliomag_publico_web_v2/tree/sql) tiene
> el mismo sistema sobre **MySQL 8.4**, para servidores que ya trabajan con
> MySQL. Las funciones son las mismas; cambian la base de datos y lo que depende
> de ella (búsquedas, índices y `docker-compose.yml`).

## Contenido

- [Inicio rápido](#inicio-rápido)
- [Funcionalidades](#funcionalidades)
- [Arquitectura](#arquitectura)
- [Datos de prueba y base vacía](#datos-de-prueba-y-base-vacía)
- [Producción](#producción)
- [Pruebas y rendimiento](#pruebas-y-rendimiento)
- [Migración desde Horizon](#migración-desde-horizon)
- [Deuda técnica conocida](#deuda-técnica-conocida)
- [Documentación](#documentación)

---

## Inicio rápido

Requisitos: **Docker** y **Docker Compose**.

```bash
git clone https://github.com/Dysnomia9/Bibliomag_publico_web_v2.git
cd Bibliomag_publico_web_v2
docker compose up --build
```

| Servicio | URL | Descripción |
|---|---|---|
| frontend | http://localhost:5173 | Vue 3 (Vite, recarga en caliente) |
| backend | http://localhost:8000 | API Laravel |
| db | localhost:5432 | PostgreSQL |

La primera construcción del backend tarda un par de minutos (`composer install`
corre en el build). Después, levantar y rearmar contenedores es rápido: el código
queda dentro de la imagen y no se reinstala nada al arrancar.

Los servicios se publican **solo en este computador** (`127.0.0.1`): con las
cuentas de prueba y el modo debug, abrirlos a la red dejaría entrar como
administrador a cualquiera en el mismo WiFi. Para probar desde un celular en una
red de confianza, crea un `.env` junto a `docker-compose.yml` con
`ESCUCHAR_EN=0.0.0.0` y vuelve a levantar; el frontend arma la URL de la API con
la IP del computador. Postgres sigue solo en `127.0.0.1`.

### Cuentas de prueba

Una por cada rol de fábrica:

| Rol | Correo | Contraseña |
|---|---|---|
| Administrador/a | `admin@umag.cl` | `admin123` |
| Bibliotecario/a | `bibliotecaria@umag.cl` | `biblio123` |
| Atención de público | `meson@umag.cl` | `meson123` |

Todos entran por el mismo login (`/login`). Los usuarios de ejemplo, con su RUT y
la contraseña `umag123`, llegan al portal.

Estos datos se cargan **solo la primera vez** (si la tabla `staff` está vacía);
después tus datos persisten entre arranques. En una base que ya existía, la
migración de roles deja a las cuentas `admin` como Administrador/a y al resto como
Atención de público; `bibliotecaria@` y `meson@` solo se crean con `mockup:datos`.

### Frontend fuera de Docker (opcional)

```bash
cd frontend
npm install
cp .env.example .env
npm run dev
```

---

## Funcionalidades

### Mesón y atención de público

- **Dashboard** con indicadores en tiempo real: usuarios activos, entradas de
  hoy, personas en sala, préstamos activos y atrasados.
- **Registro de entradas** por RUT, QR o manual, incluidos visitantes externos,
  de convenio y visitas. Cada persona queda presente **una sola vez por día**,
  marque donde marque (mesón, QR, portal o al llegar a una sala reservada), con
  bloqueo por persona para que dos escaneos simultáneos no pasen ambos
  (`EntradaService`). Los externos y las visitas también se registran **sin
  documento**, de a uno o un grupo completo indicando cuántos son: cada uno cuenta
  como una persona del día, aunque no se reconoce si vuelve a entrar. El historial
  se consulta por día o en modo búsqueda, por rango de fechas y RUT/nombre.
- **Código QR de acceso** compartido y regenerable, para que los usuarios marquen
  su entrada por su cuenta.
- **Documentos extranjeros**: estudiantes y visitantes de otros países se
  identifican con pasaporte o DNI (`usuarios.tipo_documento` y `pais`). Un
  selector «RUT | Pasaporte / DNI» indica qué se escribe: con RUT se exige el
  dígito verificador; con pasaporte o DNI se aceptan letras y números. El
  servidor normaliza puntos, guiones y espacios (`App\Support\Documento`), así
  que «12.345.678-5» y «123456785» son la misma persona y nadie queda registrado
  dos veces (índice único sobre el identificador normalizado).

### Préstamos, reservas y multas

- **Préstamos y devoluciones** de libros por código de barras de la copia física,
  y de **recursos de apoyo** (notebooks, audífonos, cargadores, bicicletas…) desde
  un inventario con control de disponibilidad. Las categorías de recursos se
  administran en Administración → Catálogos (`categorias_recurso`), con ícono,
  color, indicaciones y un máximo opcional por persona. En Recursos de apoyo se
  registran **varios a la vez** (una fila por recurso, pensado para el lector de
  códigos) y se dan de baja **con su motivo**, que queda en el inventario y en la
  bitácora. Al prestar, cada recuadro sugiere los recursos libres de su categoría,
  y un recurso escaneado por error en «Nuevo préstamo» pasa solo a su recuadro.
- **Uso en sala**: un libro se presta para leerlo en la biblioteca y se devuelve
  el mismo día antes del cierre (`prestamos.en_sala`). No pide fechas, no cuenta
  para el máximo de libros a domicilio y sirve para el material que no se presta
  a domicilio (referencia); si no vuelve ese día, queda atrasado y se multa.
- **Reglas de préstamo** (`reglas_prestamo`): plazo, cantidad máxima de libros y
  multa por tipo de usuario, tipo de material y ubicación. Se aplica la regla más
  específica que calce (en empate manda el material, luego la ubicación y luego
  el tipo de usuario); sin regla, un libro a la vez y `config/multas.php`
  (`App\Services\ReglaPrestamoService`).
- **Días sin atención** (`dias_sin_atencion`): feriados, vacaciones y cierres,
  globales o por ubicación. Ninguna devolución ni retiro cae en ellos y no se
  cobran como atraso.
- **Multas** en su propio módulo (`multas`): la de atraso se genera sola al
  devolver fuera de plazo ($15 por día, tope $15.000); las de deterioro, pérdida
  u otro motivo se cursan a mano. La llave de sala devuelta con más de 5 minutos
  de atraso muestra un aviso y el mesón decide si multa ($50 por minuto, tope
  $5.000, montos provisionales). Las multas se pagan o se anulan con motivo,
  nunca se borran. Con una multa pendiente la persona no puede llevarse libros ni
  recursos, reservar, ni obtener la Constancia de No Multa; al pagar o anular se
  desbloquea al instante. En «Deuda por usuario», **Ver detalle** muestra cada
  multa de la persona (fecha, motivo, el préstamo que la originó y si se importó
  de Horizon) y permite pagarla o anularla ahí mismo.
- **Reservas de libros**: retiro desde la pestaña Reservas («Entregar»); la que no
  se retira a tiempo se cancela sola esa noche y la copia pasa al siguiente en la
  fila.
- **Salas de estudio** (logias) en horario continuo dentro del horario de la
  biblioteca (lunes a viernes 08:45–20:30, sábado 09:00–14:00): inicio en pasos
  de 30 minutos, hasta 2 horas por reserva, 15 minutos para presentarse antes de
  que la sala se libere, cuota diaria de 4 horas por persona y **una sola reserva
  pendiente por persona**, con bloqueo para que dos reservas simultáneas no tomen
  el mismo tramo. Devolver la llave antes no tiene castigo: si quedan 5 minutos o
  más, el mesón puede **liberar el resto del bloque** para otro grupo, al
  registrar la devolución o después desde el detalle (`reservas.hora_fin_reservada`
  guarda hasta cuándo era), y la cuota del día cuenta solo lo que se usó.
- **Listado de reservas** de salas por estado (todas, activas, finalizadas, no se
  presentaron, canceladas; abre en «Todas») con búsqueda y filtros. Con `reservas.editar` se puede
  cambiar sala, día u horario, corregir integrantes o registrar una llave
  pendiente; todo queda en la bitácora con el antes y el después.

Préstamos y reservas comparten la disponibilidad del ejemplar como fuente de
verdad: no se puede reservar ni prestar algo ya ocupado.

### Catálogo

- **Múltiples copias por título**: el registro bibliográfico (`Libro`) se separa
  de cada copia física (`Ejemplar`), con su propio código de barras y estado;
  varios autores, categorías y carreras por libro; ISBN y generador de códigos.
- **Estado físico de cada copia** (inventario → en estante → de baja, colección
  móvil o estados personalizados), independiente de su disponibilidad, con
  cambio masivo e historial de cada cambio.
- **Fichas bibliográficas MARC** definidas por la biblioteca: cada tipo de
  material (Libro, Trabajo de titulación, Juego educativo, Publicación seriada)
  dice qué etiquetas se catalogan y cuáles son públicas
  (`app/Support/FichaBibliografica.php`).
- **Etiquetas para imprimir**: códigos de barras (Code128) y etiquetas de lomo
  con el **código de lomo** de 8 dígitos (`ejemplares.codigo_lomo`), que el lector
  reconoce igual que el código de 14 dígitos.
- **Listado de material** filtrable (estado, tipo, categoría, carrera) y exportable a
  Excel o CSV.
- Búsquedas sin distinguir tildes, ñ ni mayúsculas («calculo» encuentra
  «Cálculo I»).
- **Portada según el tipo de material** en las listas del catálogo: el libro con
  sus iniciales y el lomo del color de su área; la tesis, la revista, el juego
  educativo, el DVD y lo demás, con su propio ícono (`PortadaMaterial.vue`).

### Usuarios y estudiantes

- **Gestión de usuarios** con acceso a sus préstamos, Constancia de No Multa en
  PDF, botón **Renovar** (todo el personal) y **Desactivar** (solo
  Administrador/a).
- **Vigencia de las cuentas**: cada cuenta tiene una fecha de vencimiento
  (`usuarios.vigente_hasta`) que se fija al renovarla según su tipo de usuario.
  Por defecto es el **31 de marzo del año siguiente**, pero el Administrador/a
  la cambia por tipo (estudiantes, docentes, funcionarios) en Administración ›
  **Vigencia de cuentas**: fecha fija del año, N años desde la renovación o sin
  vencimiento (tabla `politicas_vigencia`; permiso `vigencia.gestionar`). En una
  carga masiva puede además elegir otra fecha para toda la carga, o traerla fila
  por fila en la columna «Vigente hasta». La fecha de los usuarios importados de
  Horizon sale de `borrower.expiration_date` (fase `vigencia` del ETL). Una cuenta vencida o desactivada no marca entrada,
  no entra al portal ni se lleva ni reserva material, pero sí puede devolver
  libros y pagar multas. Se renueva desde Usuarios o, en el momento, con el aviso
  **Renovar cuenta** que sale en Préstamos y en Entradas; la carga masiva
  renueva a quienes vienen en la planilla y no toca a los demás (vencen solos).
- **Altas de docentes y funcionarios**: además de «Nuevo estudiante» hay «Nuevo docente» y
  «Nuevo funcionario», con un formulario más corto: sin carrera ni año de ingreso, pero con
  unidad o departamento y cargo (columnas `usuarios.unidad` y `usuarios.cargo`, también en la
  carga masiva); en los reportes, «Por carrera» los agrupa por su unidad.
- **Más de un correo por usuario**: el correo principal (`usuarios.email`) recibe las
  notificaciones; los adicionales (`usuario_correos`) sirven para reconocer a la persona al
  entrar con Google o el directorio. Si la carga masiva trae un correo distinto al que ya tenía,
  se suma como correo adicional (+1) en vez de reemplazarlo.
- **Carga masiva de estudiantes** desde Excel o CSV en tres pasos: elegir el
  archivo, revisar qué pasará con cada fila (nueva, a actualizar con antes →
  después, al día, repetida o con errores) y confirmar. Lo vacío no borra nada,
  un correo ya usado no se pisa y los RUT que coinciden con un documento
  extranjero quedan para revisión manual. Acepta tal cual el **reporte de
  matrícula de pregrado** de la universidad (hasta 10.000 filas, UTF-8,
  Windows-1252 o Mac Roman). Hay una plantilla de ejemplo en
  [`docs/ejemplos/`](docs/ejemplos/).

### Administración, seguridad y reportes

- **Roles y permisos**: tres roles de fábrica (*Administrador/a*,
  *Bibliotecario/a*, *Atención de público*) y roles personalizados. Cada persona
  tiene su cuenta; las cuentas desactivadas se pueden eliminar sin perder su
  historial (`staff.deleted_at`). El menú y la API muestran y permiten solo lo
  que el rol autoriza.
- **Registro absoluto**: operaciones de los usuarios y **bitácora del personal**
  (quién hizo qué y cuándo, con el antes y el después), en la tabla `bitacora`,
  que no se edita ni se borra (`App\Support\Bitacora::registrar()`).
- **Reportes** por día, semana, mes, semestre o año, comparados con el período
  anterior: préstamos, ingresos, uso de salas y libros más prestados, con
  gráficos o tablas, filtros por carrera, año de ingreso y sexo, y exportación a
  Excel o CSV. El filtro **«Quiénes»** separa estudiantes, docentes, funcionarios,
  docentes y funcionarios juntos y, en Ingresos, externos y visitas, como se piden
  para la acreditación. Los préstamos de la colección se separan en a domicilio y
  en sala.
- **Panel de Administración**: firmante de la Constancia de No Multa, estados
  personalizados, ubicaciones, tipos de material, categorías de recursos y
  estudiantes.
- **Modo día y noche** con un interruptor arriba a la derecha (lo impreso sale
  siempre en claro; ver `docs/estilo-visual.md`, sección 1b).
- Confirmación con resumen en todas las acciones delicadas y guías «¿Cómo se usa
  esta pantalla?» para el personal nuevo.

### Portal de autoservicio

Para estudiantes, docentes y funcionarios, que entran por el mismo login que el
personal (con su correo o RUT) y llegan a su propia sesión, separada del panel:

- Marcar entrada y salida por RUT o escaneando el QR con la cámara.
- Consultar el catálogo (por título, autor, área o ISBN), ordenado de la A a la Z
  con los títulos que empiezan con un signo al final (`OrdenAlfabetico`), ver
  cuándo vuelve un libro prestado, reservarlo o unirse a la cola de espera; al
  liberarse, la reserva pasa sola al primero de la fila.
- Reservar salas para el mismo día, salir de una reserva grupal o cancelarla.
- Inicio con el resumen de la persona (ingreso de hoy, préstamos y vencimientos,
  reservas, multas), «Mi perfil», «Mis ingresos» y descarga de la Constancia de
  No Multa.
- Diseño de escritorio en computador y de una columna, con barra inferior, en
  teléfonos y tablets.

---

## Arquitectura

```
Bibliomag_publico_web_v2/
├── backend/            # Laravel (API-only): app/, config/, database/, routes/, tests/
│   ├── Dockerfile      # etapas app (PHP 8.3) y nginx
│   └── docker/         # ajustes de PHP-FPM, OPcache y nginx
├── frontend/           # Vue 3 + Vite + Tailwind + Pinia
│   ├── Dockerfile      # etapas desarrollo (Vite) y produccion (build + nginx)
│   └── nginx.conf
├── docs/               # manual de usuario, guía de estilo, handoff a Informática
├── docker-compose.yml       # desarrollo
└── docker-compose.prod.yml  # producción
```

### Backend

- **Autenticación** con Sanctum y dos guards independientes (`staff` y
  `usuario`). Los permisos del personal son granulares: el catálogo está en
  `app/Support/Permisos.php` (23 permisos en 5 grupos), cada rol guarda los suyos
  en `roles` y las rutas los exigen con `permiso:<clave>`. Las sesiones vencen a
  las 8 horas.
- **Tareas programadas**: el servicio `scheduler` corre `php artisan
  schedule:work` con las tareas de `routes/console.php` (limpieza de sesiones,
  vencimiento de reservas de libros a las 00:05 y cierre de reservas de sala sin
  asistencia a las 00:10).
- **Pensado para un catálogo grande**: listados paginados con filtros en el
  servidor, índices de trigramas (`pg_trgm`) y reportes calculados en la base.
  Medido con 20.000 títulos, 40.000 copias y 82.000 préstamos: ninguna pantalla
  pasa de 0,13 s ni de 46 MB por petición.
- **Límites de solicitudes** por cuenta + IP en los logins (6 por minuto) y por
  cuenta en el resto de la API (240 por minuto).
- **Notificaciones por correo** (reserva lista para retirar, multa generada) ya
  implementadas; sin SMTP configurado quedan en `storage/logs/laravel.log`.
  Activarlas es configurar `MAIL_MAILER`/`MAIL_HOST` en `.env`.
- **Un solo login** (`/login`) para el personal y los usuarios: correo
  institucional o RUT (también pasaporte o DNI) y contraseña, y el sistema lleva a
  cada uno al panel o al portal (`IngresoController`). Si la misma persona tiene
  cuenta del personal y de usuario con esos datos (una bibliotecaria que también
  estudia), elige con cuál entra. Google y LDAP institucional están en el mismo
  login, inactivos hasta configurar `GOOGLE_CLIENT_ID` o `LDAP_HOST` (ver
  `docs/login-externo-pendientes.md`). Las direcciones de antes (`/login/v2`,
  `/portal/login`) llevan a este.
- **Código propio de cada sala** (`salas.codigo`: L01 a L15 para las logias, SEM,
  POS y AGA para las salas con nombre propio): en el mesón se escribe el código o el
  número de la logia para entregar o recibir la llave, y Reportes sigue cada sala
  por su código (reservas, asistencia, horas de uso y bloques liberados).
- Compatibilidad con el código de puesto de trabajo de Horizon en las entradas, y
  mensajes de validación en español (`lang/es/`).

### Imagen Docker

`backend/Dockerfile` es un Dockerfile Laravel estándar: `composer install` corre
en el **build** (capa cacheada) y la imagen queda autocontenida. El
`docker-entrypoint.sh` solo hace trabajo de arranque: espera la base, corre
migraciones, carga datos de prueba si corresponde y levanta el servidor.

En desarrollo se usa `php artisan serve`; en producción (`SERVIDOR=fpm`) se
cachean configuración y rutas y atiende **PHP-FPM** detrás de **nginx** (servicio
`api`, puerto 8000). Versiones: PHP 8.3, nginx 1.30 y Node 24 (LTS). Los
docker-compose construyen con `pull: true`, así que cada `docker compose up -d
--build` toma las imágenes base con los últimos parches de seguridad.

### Módulos del panel

La columna «Permiso» es la clave de `app/Support/Permisos.php` que exige la ruta.

| Módulo | Ruta | Permiso | Backend |
|---|---|---|---|
| Dashboard | `/dashboard` | (cualquier cuenta) | `DashboardController` |
| Entrada | `/entrada` | `entradas.registrar` | `EntradaController` |
| Préstamos | `/prestamo` | `prestamos.gestionar` | `PrestamoController` |
| Salas (logias) | `/salas` | `salas.gestionar` (+ `salas.hora_pasada`) | `SalaController` |
| Reportes | `/reportes` | `reportes.ver` | `ReporteController` |
| Usuarios | `/usuarios` | `usuarios.ver` (+ `usuarios.habilitar`, `usuarios.desactivar`) | `UsuarioController` |
| Multas | `/multas` | `multas.ver` (+ `multas.cobrar`, `multas.crear`, `multas.anular`) | `MultaController`, `MultaService` |
| Listado de material | `/catalogo/listado` | `listados.ver` | `LibroController` |
| Catalogación | `/catalogo/catalogacion` | `libros.catalogar` | `LibroController`, `EjemplarController::store` |
| Estado de ejemplares | `/catalogo/estado` | `libros.estado` | `EjemplarController::cambiarEstado` |
| Cambio masivo de estado | `/catalogo/cambio-masivo` | `libros.cambio_masivo` | `EjemplarController::cambioMasivo*` |
| Etiquetas | `/catalogo/etiquetas` | `libros.catalogar` | `EjemplarController::etiquetasLomo` |
| Recursos de apoyo | `/equipos` | `equipos.gestionar` | `EquipoController`, `CategoriaRecursoController` |
| Listado de préstamos | `/prestamos/listado` | `listados.ver` | `PrestamoController` |
| Historial de estado | `/catalogo/historial-estado` | `historiales.ver` | `EjemplarController::historialEstado` |
| Historial de material | `/catalogo/historial-prestamos` | `historiales.ver` | `LibroController::historial` |
| Registro absoluto | `/registro-absoluto` | `registro_absoluto.ver` | `RegistroAbsolutoController`, `BitacoraController` |
| Código QR de acceso | `/codigo-qr` | `codigo_qr.ver` (+ `codigo_qr.regenerar`) | `CodigoAccesoController` |
| Administración | `/administracion` | `configuracion.editar` / `catalogos.gestionar` / `usuarios.gestionar` | `ConfiguracionInstitucionalController` y catálogos |
| Personal y roles | `/personal` | `personal.gestionar` (solo acceso total) | `PersonalController`, `RolController` |

---

## Datos de prueba y base vacía

### Regenerar datos de prueba

```bash
docker compose exec backend php artisan mockup:datos            # solo si no hay datos
docker compose exec backend php artisan mockup:datos --fresh    # borra todo y regenera
```

`mockup:datos` (`app/Console/Commands/SeedMockupData.php`) crea las 3 cuentas
de personal, 30 usuarios con RUT válido, carrera, año de ingreso y sexo, multas,
entradas y préstamos de los últimos días, 13 recursos de apoyo, reservas en las
18 salas, libros con varias copias y un **escenario de demostración fijo**: seis
usuarios con RUT fáciles de recordar (11.111.111-1 a 66.666.666-6), cada uno
preparado para mostrar una función, y dos estudiantes extranjeros (DNI argentino
38456123 y pasaporte brasileño FT482913).

Los datos de prueba solo se cargan con `APP_ENV=local` (lo pone
`docker-compose.yml`); en producción `mockup:datos` se niega a correr. Lo que el
sistema necesita para funcionar (roles, catálogos base y salas) lo crean las
migraciones.

### Empezar sin datos de prueba

Para ensayar la carga de datos reales en una base vacía, como la de producción:

```bash
docker compose exec backend php artisan sistema:iniciar --vaciar   # pide escribir VACIAR
```

Queda solo lo que crean las migraciones y la cuenta del administrador general,
`admin@umag.cl`, con una contraseña al azar que se muestra una vez (otro correo:
`sistema:iniciar <correo> --nombre="Nombre Apellido"`).

Para que una base nueva nazca vacía, sin pasar por los datos de prueba:

```bash
docker compose down -v
```

```bash
echo DATOS_DE_PRUEBA=no > .env
```

```bash
docker compose up -d --build
```

```bash
docker compose exec backend php artisan sistema:iniciar
```

---

## Producción

`docker-compose.yml` es un entorno de **desarrollo** (bind mount del frontend y
credenciales de Postgres en el propio archivo). Para producción está
`docker-compose.prod.yml`: el frontend se compila (`npm run build`) y lo sirve
nginx con cabeceras de protección (`frontend/nginx-cabeceras.conf`), y el backend
corre con PHP-FPM. Las variables van en `backend/.env.production.example`.

La primera cuenta con acceso total se crea con `php artisan sistema:iniciar`. Lo que
falta para publicar está en
[`docs/handoff-informatica.md`](docs/handoff-informatica.md).

---

## Pruebas y rendimiento

```bash
docker compose exec backend php artisan test
```

La suite de Feature tests (`backend/tests/Feature/`, 662 tests al 2026-10-28)
cubre autenticación y permisos por rol, entradas, préstamos y reservas (incluida
la protección contra condiciones de carrera con `lockForUpdate()`), salas,
multas y bloqueos, catalogación y estados de ejemplares, fichas bibliográficas,
carga masiva de estudiantes, documentos extranjeros, bitácora, notificaciones
(`Notification::fake()`) y restricciones de la base (CHECK y `RESTRICT`). Corre
contra una base Postgres dedicada (`biblioteca_test`) que `docker-entrypoint.sh`
crea al levantar el backend.

`php artisan benchmark:api` mide la latencia real de la API (promedio, mediana,
p95, máximo). Detalles en [`backend/README.md`](backend/README.md).

---

## Migración desde Horizon

La carga de usuarios, catálogo, préstamos, reservas y multas desde Horizon (SQL
Server) vive en un repositorio aparte:
[`horizonte-bibliomag-experimental`](https://github.com/Dysnomia9/horizonte-bibliomag-experimental).
Su ETL escribe directamente en la base de BiblioUMAG, es idempotente y se prueba
en CI contra la rama `main` de este repositorio.

---

## Deuda técnica conocida

- **Identificadores estilo Horizon**: las copias ya están separadas de la obra
  (`Ejemplar` / `Libro`), pero no se usan «Bib No.» / «Item No.» como
  identificadores formales; hoy es `libro_id` + `numero_copia`.
- **Tabla institucional de multas**: las multas de Horizon ya llegan con el ETL
  (`origen = importada`, con su `referencia_externa`); si la biblioteca entrega
  además su propia tabla de multas, se importa en `MultaService` con esas mismas
  columnas.
- **Credenciales de Postgres en `docker-compose.yml`**: aceptable en desarrollo;
  en producción usar `docker-compose.prod.yml` y `.env.production.example`.
- **`PortalController` concentra varias responsabilidades**: si crece, conviene
  separarlo por dominio.
- **Sin aviso de préstamo por vencer**: sería otra tarea en
  `routes/console.php`; fuera de alcance por ahora.
- **Notificaciones sin SMTP real**: funcionan, pero quedan en el log hasta
  configurar un servidor de correo.
- **Respaldos**: el servicio `respaldo` de Docker Compose saca todos los días
  (02:00 hora de Punta Arenas) un volcado de la base, comprueba que se pueda leer
  y guarda 14 diarios y 8 semanales (ver `respaldo/`). En desarrollo quedan en
  `./respaldos`; en producción, en la carpeta `RESPALDOS_DIR` del servidor, que
  debería estar en otro disco o copiarse a otra máquina. Se restauran con
  `docker compose run --rm respaldo /restaurar.sh diarios/<archivo>.dump <base_nueva>`.
  Incluye la bitácora de cambios, porque vive en la misma base.
- Los contadores históricos de préstamo no se guardan como columnas: se calculan
  desde `prestamos` (ver Historial de libros).

---

## Documentación

| Documento | Contenido |
|---|---|
| [`docs/manual-usuario/`](docs/manual-usuario/) | Manual de usuario (PDF, fuente LaTeX y capturas) |
| [`docs/estilo-visual.md`](docs/estilo-visual.md) | Guía de estilo de la interfaz |
| [`docs/handoff-informatica.md`](docs/handoff-informatica.md) | Lo que se necesita de Informática para producción |
| [`docs/login-externo-pendientes.md`](docs/login-externo-pendientes.md) | Cómo activar el login con Google y LDAP |
| [`backend/README.md`](backend/README.md) | Pruebas y benchmark del backend |

---

Desarrollado por **Ignacio Contreras Norambuena** para la Biblioteca de la
Universidad de Magallanes.
