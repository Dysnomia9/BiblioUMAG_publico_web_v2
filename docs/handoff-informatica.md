# Biblioteca UMAG — qué necesitamos de Informática

Este documento es para la reunión de entrega con el departamento de
Informática de la UMAG. Resume, en un solo lugar, qué está listo, qué
necesitamos que ellos provean, y qué decisiones de alcance se tomaron a
propósito (para que no se lean como cosas que "faltó hacer").

Detalle técnico completo de cada punto está en `CLAUDE.md` (raíz del repo) y
en `docs/login-externo-pendientes.md`. Este documento es el resumen ejecutivo.

## Estado del sistema

El sistema está funcionalmente completo: gestión de usuarios, préstamos,
devoluciones, reservas de sala y de libro, catalogación, reportes,
dashboard, código QR de acceso, portal de autoservicio para
estudiantes/docentes/funcionarios, y panel de administración. Corre 100%
en Docker. Tiene 231 tests automatizados que verifican el comportamiento
real contra una base de datos Postgres (no simulada) — no es una demo, es
una implementación completa.

**No hace falta que Informática programe nada.** Lo que sigue es
exclusivamente configuración: variables de entorno y credenciales de
sistemas que ya existen en la universidad.

## Qué necesitamos que nos den

### Imprescindible para levantar en producción

1. **Dónde va a correr esto** — un servidor (VM, contenedor gestionado, lo
   que Informática ya use) con Docker y Docker Compose. El proyecto trae
   `docker-compose.prod.yml`, listo para `docker compose -f
   docker-compose.prod.yml up -d --build` una vez completadas las variables
   de entorno.
2. **Credenciales de Postgres de producción** — host, usuario, contraseña,
   nombre de base de datos. Puede ser el `db` que trae el propio compose
   (con volumen persistente) o un Postgres gestionado por Informática — el
   backend se conecta a cualquiera de los dos, solo cambia `DB_HOST`.
3. **Dominio/URL reales** — `APP_URL` (backend), `FRONTEND_URL`,
   `SANCTUM_STATEFUL_DOMAINS` (dominio del frontend, sin protocolo).
4. **Códigos de barra reales de Horizon** — hoy el sistema convive
   físicamente con los lectores de código de barras de Horizon usando
   valores **inventados como placeholder** (`config/horizon_barcodes.php`,
   código de puesto de trabajo genérico `'62572'`, sin los códigos reales de
   cada logia). Con los códigos reales, se cargan con el comando
   `php artisan horizon:codigos-logia` — no requiere tocar código, es un
   comando que ya existe.

### Opcional — el sistema funciona sin esto, pero mejora con ello

5. **Servidor de correo (SMTP) institucional** — el sistema ya envía
   notificaciones reales por correo (aviso de reserva de libro lista para
   retirar, aviso de multa generada) pero sin un SMTP configurado quedan
   solo registradas en el log del servidor en vez de enviarse. Necesitamos
   host, puerto, usuario y contraseña de un SMTP institucional (o un
   servicio como SendGrid/Mailgun si prefieren).
6. **Login institucional (LDAP/Active Directory)** — si quieren que el
   personal/alumnos puedan entrar con su cuenta institucional en vez de
   usuario y contraseña propios del sistema, necesitamos: host y puerto del
   servidor LDAP, base DN, una cuenta de servicio de solo lectura, y qué
   atributo identifica a cada persona (`sAMAccountName`, `uid`, u otro
   según cómo esté armado su directorio). Detalle exacto en
   `docs/login-externo-pendientes.md`.
7. **Login con Google** — si quieren habilitar "Continuar con Google" con
   cuentas `@umag.cl`, necesitamos un Client ID/Secret de un proyecto en
   Google Cloud Console (lo puede generar cualquiera con acceso a la cuenta
   de Google Workspace de la universidad). Detalle exacto en
   `docs/login-externo-pendientes.md`.

Los puntos 5-7 están **implementados y probados**, simplemente inactivos
hasta tener las credenciales — no es trabajo pendiente de programar, es
trabajo pendiente de que Informática nos pase los datos.

## Decisiones de alcance tomadas a propósito

Para que no se lean como carencias en la revisión:

- **Sin bloqueo duro de multas**: un usuario con deuda pendiente puede
  seguir pidiendo préstamos (se le avisa al personal, pero no se le
  rechaza). Decisión de alcance confirmada explícitamente durante el
  desarrollo, no un olvido.
- **Con bloqueo duro de préstamos concurrentes de libro** (distinto del
  punto anterior, no confundir): un usuario no puede llevarse un segundo
  libro mientras tenga uno sin devolver — acá sí se rechaza el préstamo,
  no es solo un aviso. Aplica solo a libros, no a equipos (audífonos/
  notebooks/cargadores).
- **Sin renovación de préstamos** ni **aviso automático de "tu préstamo
  vence mañana"** (el segundo necesitaría un proceso de cron corriendo
  aparte, que hoy no está montado en el `docker-compose.prod.yml`).
- **Sin backups automatizados** — la integridad de datos (transacciones,
  bloqueos, restricciones a nivel de base de datos) ya está resuelta; los
  backups periódicos son responsabilidad operativa de quien administre el
  servidor de producción, no algo que la aplicación gestione por sí sola.
- **La integración con Horizon es de compatibilidad de código de barras,
  no una integración de datos real** — no hay sincronización ni llamadas a
  una base de datos o API de Horizon. Es intencional: no se contó con
  acceso al sistema real de Horizon durante el desarrollo.

## Antes de comprometerse a una fecha de producción real


1. Levante `docker-compose.prod.yml` en un ambiente de prueba propio
   (staging) con datos ficticios primero, no directo a producción.
2. Corra `docker compose exec backend php artisan test` ahí — si los 231
   tests pasan en su infraestructura, es una buena señal de que el entorno
   está bien armado.
3. Revise que `APP_DEBUG=false` haya quedado activo (ya viene forzado por
   `docker-compose.prod.yml`, pero vale la pena que ellos también lo
   confirmen desde su lado).
