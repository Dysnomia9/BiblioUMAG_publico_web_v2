# Biblioteca UMAG en Vue 3 + Laravel

> **Dos versiones, una por rama.** Estás en `main`, que usa **PostgreSQL 16**.
> La rama [`sql`](https://github.com/Dysnomia9/Bibliomag_publico_web_v2/tree/sql) tiene el mismo sistema sobre **MySQL 8.4**, para
> servidores que ya trabajan con MySQL. Las funciones son las mismas; cambian
> la base de datos y lo que depende de ella (búsquedas, índices y `docker-compose.yml`).

Sistema de biblioteca universitaria que reemplaza a **Horizon** (SirsiDynix)
por una plataforma propia: **Vue 3 + Tailwind** (frontend) y **Laravel +
PostgreSQL** (backend API), 100% dockerizada.

## Qué se puede hacer

**Personal de biblioteca (staff)**

- Dashboard con indicadores en tiempo real: usuarios activos, entradas de
  hoy, personas en sala, préstamos activos y atrasados.
- Registro de entradas por RUT, QR o manual, incluidos visitantes externos,
  de convenio y visitas. Cada persona queda presente **una sola vez por día**:
  si ya marcó su entrada (en el mesón, con el QR, desde el portal o al llegar
  a una sala reservada), el sistema lo avisa y no crea otra (`EntradaService`,
  con bloqueo por persona para que dos escaneos simultáneos no pasen ambos).
  El historial se puede consultar por día exacto (uso diario de mesón) o en
  modo búsqueda, por rango de fechas y/o RUT/nombre, útil para auditar
  cuándo vino una persona puntual, no solo el tráfico de hoy.
- **Estudiantes y visitantes extranjeros** se identifican con pasaporte o
  DNI/cédula de su país en vez de RUT (`usuarios.tipo_documento` y `pais`).
  En el mesón, el préstamo, las salas, las multas y el login y la entrada del
  portal, un selector «RUT | Pasaporte / DNI» junto al campo dice qué se está
  escribiendo: con RUT se exige el dígito verificador y se formatea; con
  pasaporte o DNI se aceptan letras y números sin formato
  (`components/ui/SelectorDocumento.vue`, `composables/useModoDocumento.ts`).
  El servidor compara igual sin puntos,
  guiones ni espacios (`App\Support\Documento`), así que «12.345.678-5» y
  «123456785» son la misma persona, y nadie puede quedar registrado dos veces
  escrito de distinta forma (índice único sobre el identificador normalizado).
- Préstamos y devoluciones de libros (por código de barras de la copia
  física, con fecha de préstamo/devolución acordada) y de **recursos de
  apoyo** (recursos no bibliográficos: notebooks, audífonos, cargadores,
  bicicletas…), en su propia pestaña de Préstamos, también por código de
  barras real y desde un inventario con control de disponibilidad. Las
  categorías de recursos no están fijas en el código: se crean, editan y
  desactivan en Administración → Catálogos (`categorias_recurso`), cada una
  con su ícono, color, indicaciones para quien se lo lleva y un máximo
  opcional por persona (las bicicletas vienen con 1).
- Multas en su propia tabla (`multas`), con un módulo dedicado: la multa
  por atraso se genera sola al devolver un libro fuera de plazo (tarifa en
  `config/multas.php`: $15 por día, tope de $15.000), y las de deterioro,
  pérdida u otro motivo se cursan a mano. Se marcan pagadas o se anulan
  con motivo, nunca se borran, y queda registrado quién hizo cada cosa.
  Mientras una persona tenga multas pendientes no puede llevarse libros ni
  recursos de apoyo, ni reservar libros (en mesón ni en el portal), y tampoco obtener
  la Constancia de No Multa; al pagar o anular se desbloquea al instante. El
  módulo Multas muestra la deuda por usuario y el listado completo con
  filtros.
- Retiro de reservas: cuando un libro reservado queda listo, se le presta a
  quien lo reservó desde la pestaña Reservas ("Entregar"), y la reserva
  queda como retirada. Ni la fecha de devolución de un préstamo ni la de
  retiro de una reserva pueden caer en un día sin atención; el plazo de
  retiro que pone el sistema se corre al siguiente día con atención.
- Reservas de salas de estudio (logias, horario continuo dentro del horario
  de atención de la biblioteca (lunes a viernes 08:45–20:30, sábado
  09:00–14:00; los domingos no atiende): inicio libre en
  pasos de 30 minutos y duración de hasta 2 horas por reserva, con menú de
  confirmación de asistencia: 15 minutos para presentarse antes de que la
  sala se libere sola, una cuota diaria de 4 horas por persona, sin
  importar en cuántas salas distintas se reparta, y **una sola reserva
  pendiente por persona**: para entrar en otra, primero usa o cancela la que
  tiene, en el mesón, en el portal y al sumar integrantes; con bloqueo de la
  sala y de los integrantes para que dos reservas simultáneas no tomen el
  mismo tramo ni pongan a alguien en dos salas a la vez) y de libros del catálogo
  para retiro (la que no se retira hasta su fecha límite se cancela sola esa
  noche y la copia pasa al siguiente de la fila). Ambas comparten la disponibilidad del ejemplar como fuente
  de verdad: no se puede reservar ni prestar algo ya ocupado.
- Catalogación de libros con soporte real para **múltiples copias del
  mismo título** (registro bibliográfico separado de cada copia física,
  con su propio código de barras y estado), autor/categoría/carrera
  múltiples por libro, ISBN, generador de código de barras, y gestión del
  estado físico de cada copia (inventario → en estante → de baja, colección
  móvil, o un estado personalizado administrable), como eje independiente
  de su disponibilidad.
- Fichas bibliográficas con las etiquetas MARC que definió la biblioteca
  (documento del 29-09-2026): cada tipo de material usa una ficha (Libro,
  Trabajo de titulación, Juego educativo o Publicación seriada) que dice qué
  etiquetas se catalogan y cuáles ve el usuario. El portal recibe solo las
  públicas; el personal ve la ficha completa con las de uso interno
  marcadas. La definición está en `app/Support/FichaBibliografica.php`.
- Cambio masivo de estado de copias (por ubicación/estado actual/tipo/
  categoría, con confirmación seria) e historial de cada cambio de estado,
  además de un historial de préstamos por libro/copia.
- **Estudiantes** (Administración → Estudiantes, permiso `usuarios.gestionar`):
  crear y editar estudiantes y otros usuarios, y **carga masiva** desde una
  planilla Excel o CSV en tres pasos: elegir el archivo, revisar qué pasará
  con cada fila (nueva, **ya existente: no se carga ni se modifica**,
  repetida dentro del archivo o con errores) y confirmar de forma explícita
  (casilla + botón; el servidor exige `confirmado` y vuelve a revisar todo al
  cargar). La pantalla trae la plantilla para descargar; hay un ejemplo listo
  en [`docs/ejemplos/`](docs/ejemplos/). También acepta tal cual el **reporte
  de matrícula de pregrado** de la universidad (Excel, o CSV en UTF-8,
  Windows-1252 o Mac Roman, hasta 10.000 filas): toma solo RUT, nombre,
  apellidos (`PATERNO` + `MATERNO`), sexo, carrera unida a su especialidad
  («Ingeniería Civil» + «en Mecánica»; «SIN MODO» y «PLAN COMÚN» no se
  agregan), año de ingreso y correo si viene, e ignora el resto de las
  columnas. Con `NACIONALIDAD` distingue el RUT de un extranjero del DNI o
  pasaporte (y guarda el país), los textos en mayúsculas se pasan a nombre
  propio y la carrera se escribe igual que la que ya existe en el sistema.
- Gestión de usuarios (con acceso directo a sus préstamos, botón de acceso
  a la base de datos digital externa y generación de Constancia de No Multa
  en PDF), recursos de apoyo (agregar/dar de baja), listado de préstamos, listado de
  libros (filtrable por estado, tipo, categoría y **carrera asignada**, y
  **exportable a Excel o CSV**, de títulos, ejemplares o ambos, con sus propios
  filtros) y etiquetas para imprimir: códigos de barras (Code128) y
  **etiquetas de lomo** (horizontales, con la clasificación apilada a la
  izquierda y el **código de lomo** de la copia parado a la derecha). El código
  de lomo es un segundo código de barras de 8 dígitos que el sistema le asigna
  a cada copia (`ejemplares.codigo_lomo`, desde 10000001); el lector lo
  reconoce en Préstamos, Reservas y Estado de ejemplares igual que el de 14
  dígitos. Todo esto está agrupado por sección en el menú "Gestión" de la
  barra superior.
- **Listado de reservas** (Gestión → Consultas e historiales, permiso
  `listados.ver`): todas las reservas de salas y logias por pestaña (activas,
  finalizadas, no se presentaron, canceladas) con búsqueda por sala o por
  nombre/RUT de cualquier integrante, filtro de sala y de fechas, y su
  situación (pendiente de llegada, en uso, llave sin devolver). Con el permiso
  `reservas.editar` (de fábrica, solo Administrador/a) se abre una reserva
  activa para cambiarle la sala, el día o el horario (con las mismas reglas
  que al reservar), corregir sus integrantes, cancelarla o registrar una
  devolución de llave que quedó pendiente; cada cambio queda en la bitácora
  (`salas.editar`) con el antes y el después. Este listado y el de préstamos
  tienen la paginación arriba y abajo.
- **Registro absoluto** (al final de Gestión → Administración del sistema) con dos pestañas: las operaciones que pidieron los
  usuarios (préstamos, reservas, entradas) y **Acciones del personal**, la
  bitácora de quién hizo qué y cuándo (prestó, devolvió, cursó, cobró o anuló
  una multa, cambió el estado de una copia, catalogó, creó o editó un
  estudiante, cambió la configuración, las cuentas o los roles, inició
  sesión), con el antes y el después de cada cambio. Vive en la tabla
  `bitacora` (no se edita ni se borra) y se escribe con
  `App\Support\Bitacora::registrar()` desde cada controlador.
- Reportes por período (día, semana, mes, semestre o año, navegables y
  comparados con el período anterior) de préstamos, ingresos, uso de salas y
  libros más prestados, con indicadores, gráficos o tabla agrupable y
  ordenable, filtros por carrera, año de ingreso, sexo y tipo de usuario, y
  exportación a Excel o CSV. Las horas siguen el horario de atención de la
  biblioteca (`config/biblioteca.php`: lunes a viernes 08:45–20:30, sábado
  09:00–14:00, domingo cerrado), y los días sin atención se ven rayados.
- Código QR de acceso compartido y regenerable, para que los usuarios
  marquen su entrada por su cuenta.
- Panel de Administración: nombre/cargo de quien firma la Constancia de No
  Multa, catálogo de estados personalizados de ejemplar, catálogo de
  ubicaciones físicas, catálogo de tipos de material, categorías de recursos
  de apoyo y estudiantes.
- **Roles y permisos del personal**: cada persona tiene su propia cuenta y
  un rol. Vienen tres de fábrica: *Administrador/a* (acceso total),
  *Bibliotecario/a* (procesos técnicos y supervisión) y *Atención de
  público* (entradas, préstamos y salas). Desde "Personal y Roles" se
  crean cuentas, se desactivan, se restablecen contraseñas y se ajusta qué
  incluye cada rol, o se crean roles nuevos. La cuenta de alguien que ya no
  trabaja en la biblioteca, una vez desactivada, se puede **eliminar**
  escribiendo «confirmar»: deja de aparecer y no vuelve a entrar, pero no se
  borra su fila (`staff.deleted_at`), así que la bitácora, los préstamos, las
  multas y demás historial siguen diciendo quién hizo cada cosa; su correo
  queda libre para una cuenta nueva. El menú, los atajos y los
  botones muestran solo lo que el rol permite; la API aplica lo mismo.
- Cada persona cambia su propia contraseña desde la barra superior, y las
  pantallas de uso diario traen una guía "¿Cómo se usa esta pantalla?"
  (oculta por defecto) para el personal nuevo.
- Las búsquedas por título, autor o nombre no distinguen tildes, ñ ni
  mayúsculas ("calculo" encuentra "Cálculo I"), en el panel y en el portal.
- Las acciones delicadas piden confirmación con un resumen de lo que va a
  pasar: cerrar sesión, cambiar o restablecer contraseñas, crear o editar
  cuentas, roles y permisos, crear, editar o cargar estudiantes, cursar
  multas, liberar o cancelar reservas, prestar recursos de apoyo, darlos de baja, crear o editar sus categorías,
  cambiar la ficha de un tipo de material y exportar a Excel o CSV.

**Portal virtual de autoservicio (usuarios finales, sin ser staff)**

- Login propio, independiente del panel de personal, con pantalla de
  ingreso claramente diferenciada de la del staff.
- Marcar entrada/salida por RUT o escaneando el QR con la cámara.
- Consultar el catálogo de libros disponibles, y acceder a la base de datos
  digital externa de la universidad.
- Reservar salas de estudio, solo para el mismo día (no se puede elegir
  fecha futura), con la misma ventana de 15 minutos para confirmar
  asistencia que ve el staff.
- Reservar un libro por su cuenta, o unirse a la cola de espera si ya está
  prestado/reservado por otra persona. Cuando se libera, se promueve
  automáticamente al primero en la fila (sin tener que ir a mesón). Ambas
  acciones piden confirmación antes de registrarse. El catálogo busca por
  título, autor, área o ISBN, filtra por disponibilidad y área, y dice cuándo
  vuelve aproximadamente un libro prestado.
- Formato de escritorio en computador y de una columna en teléfonos y
  tablets. En el computador, el inicio resume la actividad de la persona:
  si ya marcó su ingreso hoy, sus préstamos con aviso de vencimiento, sus
  reservas de libros y salas, y sus multas. En el teléfono, el inicio tiene
  una barra de navegación fija abajo.
- «Mi perfil» (datos, Constancia de No Multa, cerrar sesión) y «Mis
  ingresos» (historial de entradas de la persona).
- Descargar su propia Constancia de No Multa en PDF, sin pasar por mesón
  (bloqueada si tiene una multa pendiente).

**Backend / integraciones**

- Dos guards de autenticación independientes vía Sanctum (`staff` y
  `usuario`). Dentro de `staff`, los permisos son granulares: el catálogo
  de permisos está fijo en el código (`app/Support/Permisos.php`, 23
  permisos en 5 grupos), qué incluye cada rol se guarda en la tabla
  `roles`, y cada grupo de rutas lo exige con el middleware
  `permiso:<clave>`. "Cuentas y roles del personal" queda reservado al rol
  con acceso total, para que nadie pueda darse más permisos a sí mismo.
  Cada sesión vence a las 8 horas, y las vencidas se borran solas una vez al
  día (`sanctum:prune-expired` en `routes/console.php`).
- **Tareas programadas**: el servicio `scheduler` de los dos docker-compose
  corre `php artisan schedule:work` con la misma imagen y el mismo entorno
  del backend, y ejecuta las tareas de `routes/console.php`: la limpieza diaria
  de sesiones vencidas y el vencimiento de las reservas de libros no retiradas
  (`reservas-libro:vencer`, cada noche a las 00:05).
- **Pensado para un catálogo grande**: los listados que crecen sin límite
  (listado de libros, historial de libros, listado de préstamos, catálogo del
  portal, estado de ejemplares) van **por páginas**, con búsqueda y filtros en
  el servidor; las búsquedas de texto usan índices de trigramas de Postgres
  (`pg_trgm`) y los reportes se cuentan en la base con `GROUP BY`. Medido con
  20.000 títulos, 40.000 copias y 82.000 préstamos: ninguna pantalla pasa de
  0,13 s (una página de 500 títulos de la exportación, 0,33 s) ni de 46 MB de
  memoria por petición. Autores, materias y áreas tampoco viajan completos: los
  combobox y filtros los buscan en el servidor a medida que se escribe y reciben
  a lo más 20 (`App\Support\OpcionesCatalogo`). Con 100.000 títulos, 20.000
  autores y 2.000 materias, cada búsqueda tarda menos de 0,1 s.
- Límites de solicitudes: los logins cuentan los intentos por cuenta + IP
  (6 por minuto), y el resto de la API, por cuenta (240 por minuto). Así
  una cuenta atacada no bloquea a las demás, aunque todo el campus salga a
  internet por la misma IP.
- Mensajes de validación de la API en español (`lang/es/`).
- Compatibilidad con los lectores de código de barras de Horizon para
  logias y puestos de trabajo (sin sincronizar datos con Horizon; ver
  Deuda técnica).
- Notificaciones por correo (reserva de libro lista para retirar, multa
  generada al devolver atrasado) ya implementadas y disparándose solas
  desde el flujo real, pero sin servidor SMTP configurado todavía, así
  que hoy quedan escritas en `storage/logs/laravel.log` en vez de salir
  por correo real. Activarlas es solo configurar `MAIL_MAILER`/`MAIL_HOST`
  en `.env`, sin tocar código.
- Login unificado (`/login/v2`) con Google y LDAP institucional además del
  login local, con el mismo diseño del login del portal. Entra **solo con
  correo**, para el personal y los usuarios por igual: por ahora con correos
  **simulados** de iniciales (Ignacio Contreras → `igcontre@umag.cl`) y la
  clave de prueba `demo1234`, guardados aparte en `credenciales_simuladas`
  (`php artisan login-v2:correos`; `mockup:datos` ya los crea). No cambian el
  correo, el RUT ni la clave reales de nadie: `/login` y el portal siguen
  igual, y en producción este ingreso está apagado
  (`LOGIN_V2_CORREOS_SIMULADOS`, ver `config/login_v2.php`). El bind LDAP (patrón
  "search + bind", sin asumir un formato de DN fijo) se probó de punta a
  punta contra un servidor OpenLDAP real; Google usa Socialite y quedó
  verificado hasta donde se puede sin una cuenta de Google Cloud real. Al
  volver de Google el token no viaja en la URL: llega un código de un solo
  uso que el frontend canjea con un POST; además se valida el `state` de
  OAuth, se exige el correo verificado por Google y, con `GOOGLE_DOMINIOS`,
  una cuenta del dominio institucional.
  Ninguno de los dos está activo sin configurar `.env` (`GOOGLE_CLIENT_ID`
  o `LDAP_HOST`), con el mismo criterio que las notificaciones: código real,
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
│   ├── Dockerfile         # etapas: desarrollo (Vite) y produccion (build + nginx)
│   ├── nginx.conf         # nginx de producción (rutas del router → index.html)
│   └── src/
├── docs/
│   ├── manual-usuario/    # Manual de usuario: manual.pdf (+ fuente LaTeX y capturas)
│   ├── estilo-visual.md   # Guía de estilo de la interfaz
│   ├── handoff-informatica.md       # Qué necesitamos de Informática para producción
│   ├── login-externo-pendientes.md  # Cómo activar el login con Google y LDAP
│   ├── recorrido-presentacion.md    # Guion para presentar el sistema con los datos de demostración
│   └── ejemplos/          # Planilla de ejemplo para la carga masiva de estudiantes (.xlsx y .csv)
└── docker-compose.yml
```

La columna "Permiso" es la clave de `app/Support/Permisos.php` que exige la
ruta (y que decide si el módulo aparece en el menú). La guía de estilo de la
interfaz está en [`docs/estilo-visual.md`](docs/estilo-visual.md).

| Módulo | Ruta staff | Permiso | Backend |
|---|---|---|---|
| Dashboard | `/dashboard` | (cualquier cuenta) | `DashboardController` |
| Entrada | `/entrada` | `entradas.registrar` | `EntradaController` |
| Préstamos | `/prestamo` | `prestamos.gestionar` | `PrestamoController` |
| Salas (logias) | `/salas` | `salas.gestionar` (+ `salas.hora_pasada`) | `SalaController` |
| Reportes | `/reportes` | `reportes.ver` | `ReporteController` |
| Usuarios | `/usuarios` | `usuarios.ver` | `UsuarioController` |
| Multas | `/multas` | `multas.ver` (+ `multas.cobrar`, `multas.crear`, `multas.anular`) | `MultaController`, `MultaService` |
| Listado de libros | `/libros/listado` | `listados.ver` | `LibroController` |
| Catalogación de libros | `/libros/catalogacion` | `libros.catalogar` | `LibroController::store/update`, `EjemplarController::store` |
| Estado de ejemplares | `/libros/estado` | `libros.estado` | `EjemplarController::cambiarEstado` |
| Cambio masivo de estado | `/libros/cambio-masivo` | `libros.cambio_masivo` | `EjemplarController::cambioMasivo*` |
| Etiquetas de código y de lomo | `/libros/etiquetas` | `libros.catalogar` | `EjemplarController::etiquetasLomo` (las de código, solo frontend) |
| Recursos de apoyo | `/equipos` | `equipos.gestionar` | `EquipoController`, `CategoriaRecursoController` (categorías: `catalogos.gestionar`) |
| Listado de préstamos | `/prestamos/listado` | `listados.ver` | `PrestamoController` |
| Historial de estado | `/libros/historial-estado` | `historiales.ver` | `EjemplarController::historialEstado` |
| Historial de libros | `/libros/historial-prestamos` | `historiales.ver` | `LibroController::historial` |
| Registro absoluto | `/registro-absoluto` | `registro_absoluto.ver` | `RegistroAbsolutoController`, `BitacoraController` |
| Código QR de acceso | `/codigo-qr` | `codigo_qr.ver` (+ `codigo_qr.regenerar`) | `CodigoAccesoController` |
| Administración | `/administracion` | `configuracion.editar` / `catalogos.gestionar` / `usuarios.gestionar` | `ConfiguracionInstitucionalController`, `EstadoLibroPersonalizadoController`, `UbicacionController`, `TipoMaterialController`, `UsuarioController` |
| Personal y roles | `/personal` | `personal.gestionar` (solo acceso total) | `PersonalController`, `RolController` |

### Cómo se arma la imagen del backend

`backend/Dockerfile` es un Dockerfile Laravel estándar: `composer install`
corre en tiempo de **build** (capa cacheada por Docker: solo se reinstala
si `composer.json`/`composer.lock` cambian) y `COPY . .` copia el proyecto
completo, ya armado, a la imagen. La imagen queda autocontenida: no depende
de ningún volumen para tener el código, y el primer arranque no necesita
red para instalar nada.

`docker-entrypoint.sh` solo hace trabajo de **runtime**: esperar a que la
base de datos esté lista, correr migraciones, cargar datos de prueba si
hace falta, y levantar el servidor.

La imagen parte de `php:8.3-fpm` y tiene dos etapas. **`app`** (la que se
construye por defecto) es Laravel: en desarrollo levanta `php artisan serve`,
y en producción (`SERVIDOR=fpm`, lo pone `docker-compose.prod.yml`) deja la
configuración y las rutas en caché y levanta **PHP-FPM**, que atiende con
varios procesos en paralelo. **`nginx`** es la puerta de entrada de la API en
producción (servicio `api`, puerto 8000), que le pasa cada petición a PHP-FPM.
Los ajustes (procesos, memoria por petición, OPcache, nginx) están en
`backend/docker/`.

(Hasta el 2026-07-17 esto se resolvía distinto: un "overlay" que se
aplicaba en el primer arranque del contenedor sobre un Laravel instalado en
un volumen. Se simplificó a un Dockerfile normal porque no aportaba
ninguna ventaja real sobre el patrón estándar y hacía la imagen depender de
red/volumen en el primer arranque, mala base para un despliegue real.)

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

Los tres quedan publicados **solo en este computador** (`127.0.0.1`): con las
cuentas de prueba y el modo debug, abrirlos a la red dejaría entrar como
administrador a cualquiera conectado al mismo WiFi. Para probar desde el celular
en una red de confianza, crea un archivo `.env` junto a `docker-compose.yml` con
`ESCUCHAR_EN=0.0.0.0` y vuelve a levantar; el frontend arma solo la URL de la API
con la IP del computador. Postgres sigue solo en `127.0.0.1`.

**Primer arranque:** la imagen del backend puede tardar un par de minutos en
construirse la primera vez (`composer install` corre en el build). Una vez
construida, arrancar y rearmar contenedores es rápido: el código ya está
horneado en la imagen, no se reinstala nada al levantar.

> Este `docker-compose.yml` es un entorno de **desarrollo** (bind mount del
> frontend, credenciales de Postgres hardcodeadas en el propio archivo). No
> está pensado como configuración de despliegue en producción.

Para producción está `docker-compose.prod.yml`: el frontend se compila
(`npm run build`) y lo sirve nginx (`frontend/nginx.conf`), así que al navegador
solo llegan los archivos compilados y minificados, sin el código fuente ni sus
comentarios. Lo que falta para publicarlo está en
[`docs/handoff-informatica.md`](docs/handoff-informatica.md).

### Credenciales de prueba

Una cuenta por cada rol de fábrica, para probar qué ve cada uno:

| Rol | Correo | Contraseña |
|---|---|---|
| Administrador/a | `admin@umag.cl` | `admin123` |
| Bibliotecario/a | `bibliotecaria@umag.cl` | `biblio123` |
| Atención de público | `meson@umag.cl` | `meson123` |

Los usuarios de ejemplo entran al portal (`/portal/login`) con su RUT y la
contraseña `umag123`.

Estas credenciales, junto con usuarios, salas y movimientos de ejemplo, se cargan
automáticamente **solo la primera vez** que levantas el proyecto (si la tabla `staff`
está vacía). En arranques posteriores tus datos persisten: `docker compose up` ya
no borra la base cada vez. En una base que ya existía, la migración de roles deja
a las cuentas `admin` como Administrador/a y al resto del personal como Atención
de público; las cuentas `bibliotecaria@` y `meson@` solo se crean con
`mockup:datos` (ver abajo).

## Cargar / regenerar datos de prueba manualmente

```bash
docker compose exec backend php artisan mockup:datos            # solo si no hay datos aún
docker compose exec backend php artisan mockup:datos --fresh    # borra todo y regenera desde cero
```

Este comando (`app/Console/Commands/SeedMockupData.php`) genera:

- 3 cuentas de personal, una por rol (ver Credenciales de prueba)
- 30 `usuarios` con RUT válido (con dígito verificador calculado), carrera (de las
  8 carreras UMAG), año de ingreso y sexo
- Multas de ejemplo: por atraso (pendientes y pagadas), manuales y una anulada
- Entradas y préstamos distribuidos en los últimos días dentro del horario de
  atención (sin domingos; más tráfico 10–13h y 15–18h), incluyendo ejemplos de entrada externa, de
  convenio y de visita para hoy
- 13 recursos de apoyo (audífonos, notebooks, cargadores y bicicletas, cada
  uno con nombre legible y código de barras propio) y sus préstamos asociados
- Las 18 salas: 15 logias de estudio (capacidades variables) más Sala de
  Seminarios, Sala de Postgrado y Sala AGACI. Las salas mismas las crea la
  migración `crear_salas_iniciales` en cualquier base nueva, también en
  producción; los datos de prueba solo le ponen a cada logia un
  `codigo_barras` inventado (Horizon aún no entrega los reales) y agregan
  reservas de los últimos días, con sus participantes reales
- Libros catalogados con autor(es)/categoría(s)/carrera(s), algunos con más
  de una copia física (ejemplares con su propio código de barras y estado)
- Un **escenario de demostración fijo** que no cambia entre corridas: seis
  usuarios con RUT fáciles de recordar (11.111.111-1 a 66.666.666-6), cada uno
  preparado para mostrar una función (préstamo atrasado, multa pendiente,
  reserva lista para retirar, lista de espera...), dos estudiantes
  extranjeros (Martina Gómez, DNI argentino 38456123, y Lucas Silva,
  pasaporte brasileño FT482913), un título con ficha
  completa de cada tipo y tres recursos de apoyo disponibles (audífonos,
  notebook y bicicleta). El guion para presentar el
  sistema con esos datos está en
  [`docs/recorrido-presentacion.md`](docs/recorrido-presentacion.md).

Los datos de prueba se cargan solo en desarrollo declarado: `APP_ENV=local`, que
pone `docker-compose.yml`. Con cualquier otro valor, o sin `APP_ENV` (la imagen
corrida a mano), el arranque los omite, y en producción (`APP_ENV=production`)
`mockup:datos` además se niega a correr, porque trae cuentas con contraseñas
conocidas. Lo que el sistema necesita para funcionar sí lo crean
las migraciones: los roles, los catálogos base y las salas; los códigos de
barras reales de las logias se aplican con `php artisan horizon:codigos-logia`. La primera cuenta con acceso total se crea con
`php artisan sistema:iniciar <correo> --nombre="Nombre Apellido"` (ver
[`docs/handoff-informatica.md`](docs/handoff-informatica.md)).

## Empezar sin datos de prueba

Para ensayar la carga de los datos reales (los usuarios desde la planilla, el
catálogo desde Horizon) en una base vacía, como la de producción:

```bash
docker compose exec backend php artisan sistema:iniciar --vaciar   # borra todo lo que haya; pide escribir VACIAR
```

Queda solo lo que crean las migraciones (roles, las 18 salas, carreras, categorías
de recursos, tipos de material) y una cuenta: la del administrador general,
`admin@umag.cl`, con una contraseña al azar que se muestra una sola vez (otro
correo: `sistema:iniciar <correo> --nombre="Nombre Apellido"`). Si la base ya
está vacía no hace falta `--vaciar`, y si tiene datos, sin `--vaciar` el comando no
hace nada (`app/Console/Commands/IniciarSistema.php`).

Para que una base nueva arranque vacía desde el principio, sin pasar por los datos
de prueba, se pone `DATOS_DE_PRUEBA=no` en un archivo `.env` junto a
`docker-compose.yml` (git lo ignora) antes de crearla:

```bash
docker compose down -v                                   # borra la base de desarrollo actual
echo DATOS_DE_PRUEBA=no > .env
docker compose up -d --build
docker compose exec backend php artisan sistema:iniciar
```

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

Hay una suite de Feature tests (`backend/tests/Feature/`, 448 tests al
2026-10-20) que cubre login de staff/usuario, fichas bibliográficas (qué ve
el usuario según el tipo de material), el escenario de demostración, roles y
permisos (qué puede y no puede hacer cada rol, incluida la protección contra darse más permisos a
uno mismo), cuentas del personal, multas (generación por atraso, manuales,
pago, anulación y bloqueo de préstamos y reservas mientras haya deuda),
retiro de reservas, límites de solicitudes, registro de entradas (incluido
el modo búsqueda por rango de fechas y RUT/nombre, y que nadie quede presente
dos veces el mismo día) y su historial, la entrada automática al confirmar la
llegada a una sala, la identificación con pasaporte o DNI extranjero, la
gestión y carga masiva de estudiantes (sin duplicar a los que ya existen), la
bitácora de acciones del personal, reservas
de sala en horario continuo (inicio libre + duración de hasta 2 horas,
solapamiento y validación grupal, cuota diaria de 4 horas por participante
sin importar en cuántas salas distintas se reparta, restricción a "solo
hoy" para alumnos, y el flujo completo de confirmación de asistencia con
liberación automática por no-show), préstamos
y reservas de libros y recursos de apoyo por código de barras (incluidas las
categorías administrables y el máximo por persona) (incluida la protección
contra condición de carrera con `DB::transaction()`+`lockForUpdate()`),
catalogación y cambio de estado de ejemplares (incluido el cambio masivo y
su historial), el catálogo administrable de tipos de material, CHECK
constraints de las columnas tipo enum y de que un préstamo apunte a una copia
o a un recurso según su tipo (nunca a los dos), cascadas de borrado `RESTRICT` en el
historial, atribución real de staff en préstamos, las notificaciones por correo (reserva
lista para retirar, multa generada; con `Notification::fake()`, sin
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

- **Múltiples copias de un mismo libro: ya resuelto (2026-08-15)**: el
  modelo `Ejemplar` separa la copia física (código de barras, estado,
  disponibilidad) del registro bibliográfico `Libro` (obra). Lo que sigue
  fuera de alcance es la separación estilo Horizon "Bib No." / "Item No."
  como identificadores formales; hoy es simplemente `libro_id` +
  `numero_copia`.
- **Multas institucionales por integrar**: la biblioteca lleva hoy sus multas
  en una tabla propia que todavía no se recibe. La tabla `multas` ya está
  preparada para importarlas: la columna `origen` distingue `sistema`
  (generada sola por atraso), `manual` e `importada`, y `referencia_externa` guarda el
  identificador de la fila original. Al recibirla, la importación va en
  `MultaService` (todo el acceso a multas pasa por ahí), sin tocar los
  controladores ni el frontend.
- **Contadores históricos de préstamo** (cantidad, última fecha) no se
  guardan como columnas: son derivables por query sobre `prestamos`, y ya
  hay una vista dedicada para verlos on-demand (Historial de Libros).
- **Credenciales de Postgres hardcodeadas** en `docker-compose.yml`:
  aceptable en desarrollo local, a propósito. Para un despliegue real usar
  `docker-compose.prod.yml` + `backend/.env.production.example` (plantillas,
  sin valores reales).
- **`PortalController` concentra varias responsabilidades** (estado/aforo,
  entrada/salida, catálogo, salas y reservas del usuario). Si crece más,
  conviene separar por dominio en vez de agregar más métodos ahí.
- **Sin aviso de préstamo por vencer**: ya existen notificaciones por
  correo para "reserva lista para retirar" y "multa generada" (ver
  Backend/integraciones), disparadas por eventos que ya ocurren en el
  código. Falta el aviso "tu préstamo vence mañana": sería una tarea
  programada más en `routes/console.php`, que el servicio `scheduler` ya
  ejecuta (hoy limpia las sesiones vencidas y vence las reservas de libros no
  retiradas). Quedó
  fuera de alcance por ahora, no es un olvido.
- **Notificaciones sin servidor SMTP real**: funcionan y están probadas,
  pero sin credenciales de un servidor de correo configuradas quedan
  escritas en el log del backend en vez de enviarse de verdad (ver
  Backend/integraciones para cómo activarlas).
- **Sin backups automatizados**: decisión de alcance deliberada por ahora,
  no un olvido. La integridad de datos (transacciones, locks, CHECK
  constraints, cascadas `RESTRICT`, baja lógica en la mayoría de las
  entidades) ya está resuelta.
