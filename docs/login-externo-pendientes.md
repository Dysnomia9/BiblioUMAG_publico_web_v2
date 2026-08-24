# Login con Google y LDAP — qué falta para activarlos

`LoginV2View.vue` (`/login/v2`) ya tiene el código completo y probado para
login con Google y con LDAP institucional. Los dos están **inactivos** porque
no hay credenciales/servidor reales todavía — no falta programar nada, falta
completar configuración. Este documento es la lista de lo que hay que hacer
cuando se tengan esos datos.

Contexto completo de la implementación (qué se probó, cómo, y los gotchas
encontrados) está en `CLAUDE.md`, sección de Gotchas, entrada "Login con
Google y LDAP institucional" (2026-08-20).

## Google OAuth

1. Ir a [Google Cloud Console](https://console.cloud.google.com/) → crear un
   proyecto (o usar uno existente) → **APIs & Services → Credentials**.
2. Crear un **OAuth Client ID**, tipo "Web application".
3. En **Authorized redirect URIs**, agregar exactamente:
   - Desarrollo: `http://localhost:8000/api/auth/google/callback`
   - Producción: `https://<dominio-real-del-backend>/api/auth/google/callback`
4. Copiar el **Client ID** y **Client Secret** que genera Google.
5. Completar en `backend/.env` (no en `.env.example`, ese es solo la
   plantilla):
   ```
   GOOGLE_CLIENT_ID=<el que dio Google>
   GOOGLE_CLIENT_SECRET=<el que dio Google>
   GOOGLE_REDIRECT_URI=http://localhost:8000/api/auth/google/callback
   ```
6. `docker compose up --build backend` (el `.env` se hornea en la imagen al
   build — un simple restart no alcanza, ver CLAUDE.md).
7. Probar: entrar a `/login/v2`, click en "Continuar con Google", debería
   redirigir de verdad a la pantalla de consentimiento de Google.

**Decisión pendiente para cuando esto se active**: hoy `GoogleAuthController`
mapea el login solo por **email** (`LoginUnificadoService::porEmail()`) — si
la cuenta de Google de alguien no coincide exactamente con el email
registrado en `Staff`/`Usuario`, el login se rechaza con "no está habilitada".
Si en la práctica los emails de Google no calzan 1:1 con los registrados acá
(ej. alguien usa un Gmail personal en vez de su `@umag.cl`), hay que decidir
si se restringe el dominio permitido (`hd` param de Google, fuerza que sea
`@umag.cl`) o si se agrega una pantalla de "vincular cuenta" — no implementado
todavía porque depende de cómo se comporte en la práctica.

## LDAP institucional

Esto necesita datos reales del **directorio LDAP/Active Directory de la
UMAG** que no estaban disponibles al implementarlo — hay que conseguirlos con
el equipo de informática/redes de la universidad:

1. **Host y puerto** del servidor LDAP (`LDAP_HOST`, `LDAP_PORT` — 389 sin
   cifrar, 636 si es LDAPS).
2. **Base DN** de dónde buscar usuarios (`LDAP_BASE_DN`, ej.
   `dc=umag,dc=cl` o similar — hay que preguntarlo, no adivinarlo).
3. **Cuenta de servicio** con permiso de solo-lectura para buscar usuarios
   (`LDAP_BIND_USERNAME`/`LDAP_BIND_PASSWORD`) — el login del propio usuario
   final NO sirve para esto, tiene que ser una cuenta de servicio dedicada.
4. **Qué atributo identifica a cada persona** al buscarla
   (`LDAP_USER_ATTRIBUTE`) — Active Directory típicamente usa
   `sAMAccountName`, OpenLDAP típicamente usa `uid`. Por defecto queda en
   `mail`, que probablemente haya que cambiar.
5. **Qué atributo tiene el email** de esa persona (`LDAP_EMAIL_ATTRIBUTE`,
   default `mail`) — es lo que `LoginUnificadoService::porEmail()` usa para
   mapear a `Staff`/`Usuario`, tiene que coincidir con el email ya registrado
   en el sistema.
6. Si el servidor requiere cifrado: `LDAP_TLS=true` (STARTTLS) o
   `LDAP_STARTTLS=true` según lo que use la UMAG — no confirmado.

Completar en `backend/.env`:
```
LDAP_HOST=<host real>
LDAP_PORT=389
LDAP_BASE_DN=<base dn real>
LDAP_BIND_USERNAME=<cuenta de servicio>
LDAP_BIND_PASSWORD=<contraseña de esa cuenta>
LDAP_USER_ATTRIBUTE=sAMAccountName   # o el que corresponda
LDAP_EMAIL_ATTRIBUTE=mail            # o el que corresponda
```

`docker compose up --build backend` y probar con una cuenta real desde
`/login/v2` → "Cuenta institucional (LDAP)".

**Cómo se probó sin acceso al servidor real**: se levantó un servidor
OpenLDAP de prueba (`osixia/openldap`, Docker, descartable) y se verificó el
flujo completo — login válido, contraseña incorrecta, usuario inexistente, y
cuenta LDAP válida pero sin cuenta local habilitada. El código (patrón
"search + bind", sin asumir formato de DN) debería funcionar contra
cualquier LDAP/AD real sin cambios, ajustando solo las variables de arriba —
pero no hay garantía absoluta sin probarlo contra el servidor real de la
UMAG, que puede tener particularidades (ACLs, atributos custom, etc.) no
anticipables sin acceso a él.

## Ambos: cuentas que no auto-provisionan

Ni Google ni LDAP crean cuentas nuevas — solo autentican identidades que
**ya existen** en `staff`/`usuarios` (buscadas por email). Si alguien tiene
una cuenta de Google o LDAP válida pero nadie la dio de alta acá antes, el
login se rechaza con un mensaje claro en vez de crearle acceso solo. Es a
propósito, no hace falta "arreglarlo" — si se quiere auto-provisionar en el
futuro, es una decisión de producto a tomar explícitamente, no un bug.
