# Biblioteca UMAG: qué necesitamos de Informática

Este documento es para la reunión de entrega con el departamento de
Informática de la UMAG. Resume, en un solo lugar, qué está listo, qué
necesitamos que ellos provean, y qué decisiones de alcance se tomaron a
propósito (para que no se lean como cosas que "faltó hacer").

El detalle técnico está en el `README.md` (raíz del repo) y en
`docs/login-externo-pendientes.md`; el manual para el personal de biblioteca,
en `docs/manual-usuario/manual.pdf`. Este documento es el resumen ejecutivo.

## Estado del sistema

El sistema está funcionalmente completo: gestión de usuarios, préstamos,
devoluciones, multas, reservas de sala y de libro, catalogación, reportes,
dashboard, código QR de acceso, portal de autoservicio para
estudiantes/docentes/funcionarios, y panel de administración. El personal
entra con cuentas individuales y roles con permisos granulares
(Administrador/a, Bibliotecario/a y Atención de público, ajustables desde la
propia aplicación). Corre 100% en Docker. Tiene 438 tests automatizados que
verifican el comportamiento real contra una base de datos MySQL (no
simulada), así que no es una demo, es una implementación completa.

**No hace falta que Informática programe nada.** Lo que sigue es
configuración: variables de entorno, credenciales de sistemas que ya existen
en la universidad y la forma de publicar la aplicación en su infraestructura
(ver "Recomendaciones de infraestructura").

## Qué necesitamos que nos den

### Imprescindible para levantar en producción

1. **Dónde va a correr esto**: un servidor (VM, contenedor gestionado, lo
   que Informática ya use) con Docker y Docker Compose. El proyecto trae
   `docker-compose.prod.yml`, listo para `docker compose -f
   docker-compose.prod.yml up -d --build` una vez completadas las variables
   de entorno.
2. **Credenciales de MySQL de producción**: host, usuario, contraseña,
   nombre de base de datos. Puede ser el `db` que trae el propio compose
   (MySQL 8.4 con volumen persistente; además pide `DB_ROOT_PASSWORD`) o el
   servidor MySQL que ya tiene Informática (8.0.19 o superior); el backend se
   conecta a cualquiera de los dos, solo cambia `DB_HOST`. La base tiene que
   crearse con `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`: las
   búsquedas sin tildes dependen de esa collation. El usuario necesita permisos
   completos sobre esa base (las migraciones crean tablas, índices, columnas
   generadas y `CHECK`). Si el servidor exige TLS, se indica el certificado de
   la CA en `MYSQL_ATTR_SSL_CA`.
3. **Dominio/URL reales**: `APP_URL` (backend), `FRONTEND_URL`,
   `SANCTUM_STATEFUL_DOMAINS` (dominio del frontend, sin protocolo).
4. **Códigos de barra reales de Horizon**: hoy el sistema convive
   físicamente con los lectores de código de barras de Horizon usando
   valores **inventados como placeholder** (`config/horizon_barcodes.php`,
   código de puesto de trabajo genérico `'62572'`, sin los códigos reales de
   cada logia). Con los códigos reales, se cargan con el comando
   `php artisan horizon:codigos-logia`; no requiere tocar código, es un
   comando que ya existe. Las salas mismas no hay que crearlas: al migrar
   una base nueva se crean solas las 15 logias (Logia 01 a Logia 15) y la
   Sala de Seminarios, la de Postgrado y la AGACI (migración
   `crear_salas_iniciales`), sin códigos de barras hasta correr ese comando.
5. **Quién será el primer administrador**: en producción el sistema
   arranca sin cuentas: los datos de prueba (que traen `admin@umag.cl` /
   `admin123` con acceso total) solo se cargan en desarrollo (`APP_ENV=local`,
   que pone `docker-compose.yml`), y el comando
   que los carga se niega a correr con `APP_ENV=production`. La primera
   cuenta con acceso total se crea con:

   ```bash
   docker compose -f docker-compose.prod.yml exec backend \
     php artisan personal:crear-admin jefatura@umag.cl --nombre="Nombre Apellido"
   ```

   (genera una contraseña al azar y la muestra una vez). Desde ahí, esa
   persona crea las cuentas del resto del personal en "Personal y Roles".

### Opcional: el sistema funciona sin esto, pero mejora con ello

6. **Servidor de correo (SMTP) institucional**: el sistema ya envía
   notificaciones reales por correo (aviso de reserva de libro lista para
   retirar, aviso de multa generada) pero sin un SMTP configurado quedan
   solo registradas en el log del servidor en vez de enviarse. Necesitamos
   host, puerto, usuario y contraseña de un SMTP institucional (o un
   servicio como SendGrid/Mailgun si prefieren).
7. **Login institucional (LDAP/Active Directory)**: si quieren que el
   personal/alumnos puedan entrar con su cuenta institucional en vez de
   usuario y contraseña propios del sistema, necesitamos: host y puerto del
   servidor LDAP, base DN, una cuenta de servicio de solo lectura, y qué
   atributo identifica a cada persona (`sAMAccountName`, `uid`, u otro
   según cómo esté armado su directorio). Detalle exacto en
   `docs/login-externo-pendientes.md`.
8. **Login con Google**: si quieren habilitar "Continuar con Google" con
   cuentas `@umag.cl`, necesitamos un Client ID/Secret de un proyecto en
   Google Cloud Console (lo puede generar cualquiera con acceso a la cuenta
   de Google Workspace de la universidad). Detalle exacto en
   `docs/login-externo-pendientes.md`.

Los puntos 6-8 están **implementados y probados**, simplemente inactivos
hasta tener las credenciales: no es trabajo pendiente de programar, es
trabajo pendiente de que Informática nos pase los datos.

### Ajustes de la biblioteca que no requieren programar

Si la biblioteca cambia alguno de estos valores, basta editar el archivo y
reconstruir el backend (`docker compose -f docker-compose.prod.yml up -d
--build backend`, porque el código va dentro de la imagen):

- **Horario de atención**: `backend/config/biblioteca.php` (hoy lunes a
  viernes 08:45–20:30, sábado 09:00–14:00, domingo cerrado). Lo usan los
  reportes (horas y días sin atención), el estado «Abierta / Cerrada» de
  Entradas y del portal, las salas (solo se reservan dentro de ese horario),
  las fechas de devolución y de retiro (no pueden caer en un día sin
  atención) y los datos de prueba de `mockup:datos`. En el mismo archivo
  está el **aforo** (220) que muestran Entradas, el inicio y el portal.
- **Horario y reglas de las salas**: `backend/config/salas.php` (cada día se
  recorta además al horario de atención).
- **Tarifa de multas por atraso**: `backend/config/multas.php`.

## Decisiones de alcance tomadas a propósito

Para que no se lean como carencias en la revisión:

- **Las multas pendientes bloquean**: mientras un usuario tenga multas sin
  pagar no puede llevarse libros ni equipos, ni reservar libros (en mesón
  ni desde el portal), ni obtener la Constancia de No Multa. Al pagar o
  anular la multa se desbloquea al instante.
- **Un libro a la vez**: un usuario no puede llevarse un segundo libro
  mientras tenga uno sin devolver. Aplica solo a libros, no a equipos
  (audífonos/notebooks/cargadores).
- **Sin renovación de préstamos** ni **aviso automático de "tu préstamo
  vence mañana"** (el segundo necesitaría un proceso de cron corriendo
  aparte, que hoy no está montado en el `docker-compose.prod.yml`).
- **Sin backups automatizados**: la integridad de datos (transacciones,
  bloqueos, restricciones a nivel de base de datos) ya está resuelta; los
  backups periódicos son responsabilidad operativa de quien administre el
  servidor de producción, no algo que la aplicación gestione por sí sola.
- **La integración con Horizon es de compatibilidad de código de barras,
  no una integración de datos real**: no hay sincronización ni llamadas a
  una base de datos o API de Horizon. Es intencional: no se contó con
  acceso al sistema real de Horizon durante el desarrollo.
- **Las multas históricas de la biblioteca todavía no se importan**: hoy
  están en una tabla propia de la biblioteca que aún no recibimos. La tabla
  `multas` del sistema ya está preparada para recibirlas (`origen =
  'importada'` y `referencia_externa` con el identificador original); con
  la tabla en mano, la importación es un script acotado en `MultaService`.

## Recomendaciones de infraestructura

- **Frontend:** `docker-compose.prod.yml` ya compila la aplicación y la sirve
  con nginx (etapa `produccion` de `frontend/Dockerfile`, configuración en
  `frontend/nginx.conf`). Al navegador solo llegan los archivos compilados y
  minificados: ni el código fuente ni sus comentarios se publican.
- **Backend:** en `docker-compose.prod.yml` la API corre con **PHP-FPM detrás
  de nginx**: el servicio `backend` (etapa `app` de `backend/Dockerfile`,
  `SERVIDOR=fpm`) atiende con varios procesos en paralelo, y el servicio `api`
  (etapa `nginx`) recibe las peticiones en el puerto 8000. Así, una consulta
  lenta no hace esperar al resto, y si un proceso se queda sin memoria muere
  solo ese, no la API completa. Los ajustes están en `backend/docker/`:
  `php-fpm.conf` (16 procesos; subir `pm.max_children` si el servidor tiene RAM
  de sobra, unos 60 MB por proceso), `php.ini` (256 MB por petición, OPcache)
  y `nginx.conf.template`. Si Informática prefiere publicar con su propio nginx,
  basta apuntarlo a `backend:9000` por FastCGI con
  `SCRIPT_FILENAME /var/www/public/index.php` (como en la plantilla) y no
  levantar el servicio `api`. En desarrollo (`docker-compose.yml`) se sigue
  usando `php artisan serve`, que es más cómodo para trabajar.
- **Listados grandes:** el catálogo real son decenas de miles de títulos. Los
  listados que crecen sin límite (libros, historial de libros, préstamos,
  catálogo del portal) van por páginas, y los reportes se calculan en la base
  con `GROUP BY`. Se probó en MySQL 8.4 con 20.000 títulos y 82.000
  préstamos: ninguna pantalla pasa de 0,22 s (una página de 500 títulos de la
  exportación, 0,32 s). Las búsquedas de texto no necesitan nada especial en
  MySQL: la collation `utf8mb4_unicode_ci` ya ignora tildes y mayúsculas, y
  cada campo (título, autor, ISBN, código de copia) se busca por separado y
  se juntan los resultados.
- **Proxy / HTTPS:** si la aplicación queda detrás de un proxy inverso,
  configurar los proxies de confianza de Laravel (`trustProxies` en
  `bootstrap/app.php`) para que los registros y los límites de intentos de
  login vean la IP real de cada persona.
- **Actualización automática de salas:** la pantalla de salas consulta al
  servidor cada 3 segundos en el panel del personal y cada 30 en el portal,
  solo mientras la pestaña está a la vista (con la pestaña oculta no
  consulta). Es una consulta liviana (unos 45 ms) y son 20 por minuto por
  pestaña, muy por debajo del límite de 240 solicitudes por minuto por
  cuenta.
- **HTTPS es obligatorio para el QR:** en el portal, **Escanear QR** usa la
  cámara del equipo, y los navegadores solo dan acceso a la cámara en sitios
  seguros (`https://` o `localhost`). Publicado con `http://`, el ingreso por
  RUT funciona, pero el escaneo no.

## Antes de comprometerse a una fecha de producción real

1. Levante `docker-compose.prod.yml` en un ambiente de prueba propio
   (staging) con datos ficticios primero, no directo a producción.
2. En ese mismo servidor, corra la suite de tests con el compose de
   desarrollo (`docker compose up -d --build` y luego
   `docker compose exec backend php artisan test`), que crea sola la base de
   pruebas; el de producción no la crea a propósito. Si los 438 tests pasan
   en su infraestructura, es una buena señal de que el entorno está bien
   armado.
3. Revise que `APP_DEBUG=false` haya quedado activo (ya viene forzado por
   `docker-compose.prod.yml`, pero vale la pena que ellos también lo
   confirmen desde su lado).
