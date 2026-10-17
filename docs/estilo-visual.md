# Guía de estilo visual de Biblioteca UMAG

Referencia de cómo se ve la interfaz **hoy** (panel del personal y portal del
usuario) para que las pantallas nuevas se vean como las existentes. Si algo de
esta guía no coincide con la app, manda la app: corrige la guía.

> **La identidad es el degradado morado institucional + la barra superior.**
> No hay barra lateral (sidebar). No se cambian paleta, tipografías ni la
> navegación sin acordarlo antes con la biblioteca.

Todo se escribe con clases de Tailwind directamente en los componentes. El
`tailwind.config.js` solo agrega las dos tipografías y el radio por defecto; no
hay colores propios definidos ahí: se usa la paleta estándar de Tailwind más los
valores fijos de la sección 1.

---

## 1. Colores

### Degradado institucional (morado)

```css
background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);
```

Se usa en:

- la barra superior del personal (`components/layout/TopBar.vue`);
- la barra superior del portal (`components/layout/PortalLayout.vue`);
- el encabezado de cada pantalla (`components/ui/EncabezadoPagina.vue`),
  salvo las que usan `EncabezadoGestion` (sección 3);
- los logins.

Sobre el degradado, el texto va en blanco o `slate-100/200`, y el secundario en
`slate-400` o `white/60`. Los separadores son `bg-white/10`.

### Dorado de la pestaña activa

La pestaña activa de la barra superior (y el botón "Gestión" cuando se está en
uno de sus módulos) usa:

| Uso | Valor |
|---|---|
| Fondo | `#b08d57` → `bg-[#b08d57]` |
| Texto e ícono | `#1a2430` → `text-[#1a2430]` |
| Brillo superior | `box-shadow: inset 0 1px 0 rgba(255,255,255,0.28)` |

Los enlaces inactivos van en `text-slate-200 hover:bg-white/5`, con el ícono en
`text-slate-400`.

### Color de acción

**`indigo-600`** es el color de los botones principales (`hover:bg-indigo-700`),
de los anillos de foco (`focus:ring-indigo-500`), de los enlaces y de los
acentos (por ejemplo la barrita vertical de los títulos o los números de la
guía de uso).

### Estados

Estos colores **significan algo**, así que no se usan como decoración:

| Color | Significado | Ejemplos |
|---|---|---|
| `emerald` | correcto, disponible, resuelto | copia disponible, multa pagada, reserva confirmada, toasts de éxito |
| `amber` | atención, falta algo | "Vence hoy", reserva por confirmar, copia prestada, "Por colocar" |
| `red` | problema, error o acción destructiva | préstamo "Vencido", multa pendiente, reserva vencida, anular, dar de baja, toasts de error |

Siempre van con texto (una etiqueta o un mensaje), nunca solo el color. Las
etiquetas que solo clasifican (tipo de usuario, tipo de movimiento, rol) pueden
usar otros tonos de Tailwind porque no indican un estado.

Los estados de las copias tienen su propio juego de colores, en
`utils/estadosEjemplar.ts` (punto y etiqueta de cada uno): Inventario gris,
Procesos técnicos violeta, Por colocar ámbar, En estante verde, Estantería
auxiliar verde azulado, Colección móvil azul, Personalizado rosado y De baja
rojo. La situación de una copia para el préstamo (`situacionCopia`) es
Disponible verde, Prestado ámbar o Reservado violeta, igual que en el portal.
Se toman siempre de ese archivo, sin repetir los colores en cada pantalla.

### Neutros y fondos

| Dónde | Valor |
|---|---|
| `<body>` | `bg-slate-50` (en `index.html`) |
| Fondo de las pantallas del personal | `bg-gradient-to-br from-indigo-50/60 via-slate-50 to-purple-50/40` (en `StaffLayout.vue`) |
| Fondo de las pantallas del portal | `linear-gradient(180deg, #F7F8FC 0%, #ffffff 60%)` (en `PortalLayout.vue`) |
| Texto normal / secundario / apagado | `gray-900` / `gray-500–600` / `gray-400` |
| Bordes | `gray-200` (tarjetas y tablas), `gray-300` (campos y botones secundarios) |

### Colores por módulo en el Menú de Gestión

Cada tarjeta del menú tiene un color propio (borde superior + ícono) para
reconocerla de un vistazo: indigo, amber, emerald, violet, sky, rose, teal, red,
orange, fuchsia o slate. El mapa está en `ADMIN_COLORS` dentro de `TopBar.vue`.
Un módulo nuevo toma uno de esos 11 colores, sin inventar otros.

### Excepciones intencionales

- **Cambio Masivo de Estado** tiene su encabezado en degradado naranja/café
  (`#7C2D12 → #9A3412 → #B45309`) y sus campos con foco `amber`, para que se
  note que es una acción de riesgo que afecta muchas copias a la vez.
- **Salas de estudio** usa la convención de ocupación en su línea de tiempo:
  verde = libre, amarillo = por confirmar, naranja = plazo vencido,
  rojo = ocupada (confirmada o en uso), gris = llave devuelta. Ahí el rojo
  significa "ocupada", no "error". La leyenda está bajo la línea de tiempo.
- **Reportes**: los gráficos usan su propia paleta categórica
  (`PALETA_CATEGORICA` en `ReportesView.vue`) y una rampa azul para el mapa de
  calor (`Heatmap.vue`). No se reutilizan en el resto de la interfaz.

---

## 2. Tipografía

| Familia | Uso | Pesos cargados |
|---|---|---|
| **Source Sans 3** (`font-sans`) | todo el texto | 400, 500, 600, 700 |
| **Source Serif 4** (`font-serif`) | `h1`, `h2`, `h3` (automático, ver `style.css`) | 600, 700 |

Ambas se cargan desde Google Fonts en `index.html`. Tamaños de uso común:

- título de pantalla: `text-2xl font-serif font-bold` (dentro de `EncabezadoPagina`);
- título de tarjeta: `font-semibold text-gray-900` (a veces `text-lg`);
- texto de apoyo: `text-sm text-gray-500`, o `text-xs` en formularios densos;
- rótulos de sección: `text-[11px] font-semibold uppercase tracking-wider text-gray-400`.

---

## 3. Estructura y navegación

### Panel del personal

`StaffLayout.vue` = `TopBar` arriba + `<main class="p-4 sm:p-6">` con la
pantalla. **No hay sidebar.**

La barra superior (`TopBar.vue`) tiene, de izquierda a derecha:

1. Logo en un cuadro `bg-white/10`, "Biblioteca UMAG" en serif blanco (dos
   líneas) y la etiqueta dorada **ADMIN** (lleva al Inicio).
2. Al centro, un bloque `bg-white/10 rounded-xl` con **Inicio** y los módulos
   principales con su atajo de teclado: **Entradas (F1)**, **Préstamos (F2)**,
   **Salas (F3)**, **Reportes (F4)**. La activa va en dorado.
3. El botón **Gestión** (engranaje y flecha, translúcido con borde `white/20`;
   dorado si se está en uno de sus módulos), que abre un menú a pantalla
   completa con tarjetas agrupadas en cuatro secciones: *Atención de público*,
   *Colección*, *Consultas e historiales* y *Administración del sistema*. Las
   páginas pueden abrirlo con `window.dispatchEvent(new Event('abrir-menu-gestion'))`
   (lo usa la ruta «Gestión › Catalogación»).
4. La burbuja de la cuenta, igual a la del portal: iniciales en un círculo
   dorado, nombre y rol (desde `xl`). Abre un menú con el correo, **Cambiar mi
   contraseña** y **Cerrar sesión** (las dos piden confirmación).

**Solo se muestra lo que el rol puede usar.** Cada enlace, tarjeta y atajo se
filtra con el permiso de su módulo (`src/navegacion.ts` + `auth.puedeAlguno`).
El router aplica la misma regla: si alguien escribe la URL de un módulo sin
permiso, vuelve al Inicio con un aviso.

### Portal del usuario

`PortalLayout.vue` usa el mismo degradado morado del panel, con su propio
diseño: el logo en un cuadrado `bg-white/10` sin borde (el mismo tono de la burbuja de la persona) y
«Biblioteca UMAG» en serif blanca; al centro, las pestañas Inicio, Entrada,
Catálogo y Salas como un control segmentado (fondo `bg-white/10`, la activa
en blanco con texto `indigo-900`); a la derecha, una burbuja (`rounded-full`,
`bg-white/10`, borde `white/20`) con las iniciales en un círculo dorado
`#b08d57`, el nombre completo y el tipo de usuario, que abre un menú con el
RUT, «Mi perfil», «Mis ingresos» y «Cerrar sesión». La pestaña dorada queda
solo en la barra del personal.

En el teléfono (bajo `md`) el portal tiene una barra de navegación fija
abajo (Inicio, Catálogo, Entrada como botón central elevado con el
degradado, Salas y Perfil) y el `main` deja espacio con `pb-28`. El inicio
del teléfono pone su saludo dentro de la franja morada usando el slot
`cabecera` de `PortalLayout`, y la tarjeta «¿Llegaste a la biblioteca?» se
monta sobre la franja con margen negativo. Las tarjetas de «Explorar» van
cada una en su color: índigo, dorado `#b08d57`, esmeralda y celeste
oscuro, con fondo `-50` y el ícono en un cuadrado del color fuerte.
Las pantallas del portal usan tarjetas blancas `rounded-2xl` con borde gris y
`shadow-sm`, y un título serif sin banda (Inicio, Entrada y Catálogo); Salas
mantiene la banda de encabezado del panel del personal.

- **Estado con punto:** un punto de color de 6 px más el texto del estado
  (verde disponible, ámbar prestado o no disponible, índigo reservado para ti,
  celeste en lista de espera), con el detalle debajo en gris.
- **Píldora de horario** (`components/portal/PildoraHorario.vue`): «Abierta ·
  cierra a las HH:MM» en verde, o en gris «Cerrada · abre a las HH:MM» (hoy),
  «abre mañana…» o «abre el lunes…» (el próximo día con atención), según el
  horario semanal de `config/biblioteca.php` que entrega `/mi/estado`.
- **Portadas del catálogo:** rectángulo con las iniciales del título en serif,
  con un color pastel por área (así los títulos de una misma área comparten
  color).
- **Etiquetas de tarjeta** («Recomendado en celular», «Ideal en computador»):
  `text-[10px] font-bold uppercase tracking-wider` sobre un fondo `-50`.

### Líneas de tiempo de salas (personal y portal)

- Una línea guía por hora (más marcada en las horas pares, que llevan
  etiqueta).
- Lo que ya pasó del día va con la clase `.franja-pasada` (gris con rayas
  diagonales, definida en `src/style.css`); un día anterior va rayado entero,
  y también un día sin atención (domingo), con un aviso gris `slate-50` arriba.
- La línea abarca lo más amplio de la semana (08:45–20:30) y lo que queda
  fuera del horario de ese día también va rayado: el sábado, desde las 14:00,
  con el mismo aviso gris. `composables/useHorarioSalas.ts` hace esas cuentas
  para el panel y el portal (la ventana del día viene del backend como
  `atencion`).
- La hora actual es una línea azul `bg-indigo-600` de 3 px, con la píldora
  «Ahora · HH:MM» arriba, alineada con ella.
- Las reservas de 30 minutos se dibujan como punto (`rounded-full`) y la
  leyenda lo explica.

### Reportes

- Título serif sin banda, con el selector «Gráfico | Tabla» y el botón
  «Exportar» a la derecha; pestañas subrayadas; período en control
  segmentado con flechas ‹ ›; filtros como píldoras («Carrera: Todas»).
- Tres tarjetas de indicadores con borde superior de 4 px en su color:
  índigo `#4F46E5`, dorado `#B08D57` y verde azulado `#0D9488` (el azul
  pizarra `#3F5F8A` queda para la tabla por tipo de usuario).
- Gráficos (`components/reportes/`): `GraficoColumnas` (columnas; fines de
  semana u horas que no son pico en el tono suave: índigo `#C7D2FE`, dorado
  `#EADFC9`; los días sin atención, con `.franja-pasada` detrás, y
  `mensajeVacio` para avisar, p. ej., que un domingo no se atiende), `BarrasRanking` (barras horizontales, el primero más oscuro y
  con la marca «TOP»), `BarraApilada` (100 % con leyenda: estudiantes índigo,
  funcionarios dorado, docentes verde azulado; femenino índigo, masculino
  dorado) y `TablaReporte` (tabla ordenable con barra de porcentaje y fila de
  total). El mapa de calor de salas sigue en `Heatmap`.

### Entradas

- Sin banda: antetítulo `text-xs uppercase tracking-[0.14em]` («Control de
  acceso»), título serif `text-3xl sm:text-4xl` y una línea de descripción; a
  la derecha, el enlace **¿Cómo se usa?**, que abre la `GuiaUso` bajo el
  encabezado (`:con-boton="false"` + `v-model:abierta`).
- Dos tarjetas `rounded-2xl` desde `lg` (1,65 : 1): el registro (campo de RUT
  de `h-14` con borde `indigo-200`, botón con el degradado índigo y la tecla
  «Enter», y **Registrar visitante externo** como botón `indigo-50`) y **Hoy
  en la biblioteca** (personas distintas contra el aforo con barra
  `emerald-600`, la `PildoraHorario` y tres cifras con su ícono en un cuadro
  `indigo-50`).
- El listado del día en una tarjeta blanca: título, flechas de día y fecha
  (abre el calendario con `showPicker()`), buscador y el control segmentado de
  vía. Desde `lg` cada fila es una grilla; bajo `lg`, una tarjeta compacta
  (avatar, nombre, hora, RUT y etiquetas). Etiquetas de tipo: estudiante
  `sky`, docente `emerald`, funcionario `indigo`, externo `stone`, visita
  `teal`; vía: QR `violet`, manual `slate`. La nota de una entrada repetida a
  pocos minutos va en `amber-700`. Diez por página, con números de página.

### Préstamos

- Banda con el degradado y, a la derecha, **¿Cómo se usa?** (igual que en
  Entradas).
- **Estado vacío**: antes de buscar a alguien la pantalla ya muestra lo que
  vendrá, deshabilitado: una tarjeta punteada (`border-2 border-dashed`)
  «Ningún usuario seleccionado» con los botones en `gray-100`, las pestañas con
  «–», filas de relleno (barras `bg-gray-100 rounded-full`) y los equipos con
  «Primero busca un usuario».
- Ficha: avatar dorado `#b08d57`, nombre serif, la cuenta en una etiqueta
  (Habilitada `emerald`, Bloqueada por multa `red`, Inactiva `gray`) y el
  resumen en etiquetas de colores. Pestañas con su cantidad; la activa,
  `indigo-600` con el número sobre `white/20`.
- Tabla de préstamos y reservas como filas de grilla (se apilan bajo `md`).
  Lo terminado queda tras **Ver devueltos** / **Ver retiradas y canceladas**,
  salvo un préstamo con multa por pagar. **Devolver** es un botón
  `emerald-600`.
- Cómo se muestra un préstamo (ícono del ítem, «Devolución» con cuánto
  falta, etiqueta de estado) sale de `utils/prestamos.ts`, el mismo del
  Listado de préstamos.

### Módulo de libros y Listado de préstamos

Catalogación, Listado de libros, Historial de libros, Estado de ejemplares y
Listado de préstamos usan `EncabezadoGestion` en vez de la banda: la ruta
«Gestión › sección» (pulsar «Gestión» abre el menú), el título serif sin banda
y, a la derecha desde `xl` (abajo antes), las acciones. Las cuatro pantallas
del libro llevan ahí `PestanasLibros`: Catalogar, Listado, Historial y Estado
de ejemplares, solo las que el rol puede abrir.

- **Listas como filas de grilla**, no `<table>`: desde `lg` cada fila es una
  grilla de columnas fijas y sus grupos internos usan `lg:contents` para
  volverse celdas; bajo `lg` la misma fila se apila en dos o tres líneas, sin
  desplazamiento lateral (Listado de libros y Listado de préstamos).
- **Dos paneles** (Estado de ejemplares e Historial de libros): la lista a la
  izquierda (`22–23rem`, con su propio desplazamiento) y el detalle a la
  derecha, fijo con `lg:sticky`. En el teléfono van uno sobre otro, la lista
  con alto máximo, y al elegir algo la página baja hasta el detalle.
- **Filtros como píldoras** («Estado: En estante») con el `<select>` invisible
  encima, como en Reportes. Los grupos de vista (Activos, Vencidos,
  Devueltos, Todos) son botones con su cantidad; el elegido, en `gray-900`.
- **Copias de un título:** una barrita por copia con el color de su
  situación y «N de M disponibles».
- Las portadas de iniciales (`utils/portada.ts`) son las mismas del portal.

### Catalogación

- Bajo el título, el control segmentado «Libro nuevo | Agregar copia a libro
  existente»; a la derecha, las pestañas del módulo.
- Tres columnas desde `lg`: índice de secciones (la actual en dorado sobre
  `amber-50`, las recorridas con ✓ verde o «!» naranja si les falta algo
  obligatorio), el formulario y, a la derecha y fija, la vista previa del
  portal, la lista de obligatorios y los botones. En el teléfono, una sola
  columna sin índice.
- Cada sección es una `SeccionFicha` con su color (`tonosFicha.ts`):
  Identificación índigo, Título y autoría dorado, Publicación verde azulado,
  Materias azul pizarra y Ejemplar violeta. Las etiquetas MARC y las fichas de
  autores, materias o carreras usan el mismo color.
- La vista previa usa las portadas del catálogo (`utils/portada.ts`), igual
  que el portal.

---

## 4. Componentes reutilizables (`src/components/`)

| Componente | Para qué | Notas |
|---|---|---|
| `ui/EncabezadoPagina.vue` | Encabezado de cada pantalla del personal y de Salas en el portal | Banda con el degradado, `titulo`, `descripcion` opcional y slot `acciones` para botones a la derecha (abajo en celular). |
| `ui/EncabezadoGestion.vue` | Encabezado sin banda del módulo de libros y del Listado de préstamos | Ruta «Gestión › `seccion`» (abre el menú), título serif `text-3xl sm:text-4xl`, `descripcion` opcional, slot `debajo` (bajo el título) y slot `acciones` (a la derecha desde `xl`). |
| `libros/PestanasLibros.vue` | Pestañas Catalogar, Listado, Historial y Estado de ejemplares | `actual` = nombre de la ruta. Muestra solo las que el rol puede abrir; en pantallas angostas se deslizan de lado y la activa queda centrada. |
| `ui/GuiaUso.vue` | Pasos de "¿Cómo se usa esta pantalla?" para personal nuevo | **Siempre parte oculta** (solo se ve el botón con forma de píldora) y se despliega al pulsarlo; no recuerda si quedó abierta. Admite `consejo` al pie. Con `:con-boton="false"` y `v-model:abierta`, la pantalla pone su propio botón (Entradas y Préstamos). |
| `ui/Modal.vue` | Ventanas de formulario o detalle | Fondo `bg-black/40 backdrop-blur-sm`, tarjeta `rounded-2xl shadow-2xl`. Anchos `sm`/`md`/`lg`/`xl`. Se cierra con Esc o con un clic afuera, salvo con `bloqueado` (mientras guarda). |
| `libros/SeccionFicha.vue` | Sección numerada del formulario de catalogación | Cabecera con el número y el color de `tonosFicha.ts` (`indigo`, `dorado`, `teal`, `pizarra`, `violeta`); el contenido va en el slot. |
| `libros/MultiSelectCombobox.vue` | Elegir varios autores, materias o carreras | Fichas dentro del mismo recuadro (`claseFicha` les da el color de la sección), sugerencias del catálogo y «+ Crear» si no existe; Retroceso con el campo vacío quita la última. |
| `libros/FichaBibliografica.vue` | Ficha de un título (etiqueta, campo, valor) | El personal la ve con los números de etiqueta MARC y la marca "uso interno"; el portal, solo las filas públicas y sin números. El enlace de la 856 se muestra como "Abrir recurso electrónico". |
| `portal/PildoraHorario.vue` | Estado de la biblioteca en el portal | «Abierta · cierra a las HH:MM» o «Cerrada · abre …» (hoy, mañana o el próximo día con atención); recibe `horario` (por día ISO, de `/mi/estado`) y se actualiza cada minuto. `oscura` para ponerla sobre el degradado. |
| `ui/DialogoConfirmacion.vue` | Confirmar una acción | Tonos `primario` (indigo), `peligro` (red) y `exito` (emerald). Muestra "Procesando…" mientras `cargando`. Se puede abrir encima de un formulario en `Modal`: Esc cierra solo el de más arriba. |

Los avisos flotantes salen con `useToast()` (`success`, `error`, `info`,
arriba a la derecha). Para los errores de la API se usa
`mensajeDeError(e, 'texto por defecto')`, que toma el primer mensaje útil (la
API ya responde las validaciones en español).

---

## 5. Patrones de clases

**Tarjeta**

```html
<div class="bg-white rounded-xl shadow-md p-6">…</div>        <!-- p-5 / p-4 si es compacta -->
```

**Tabla dentro de una tarjeta**

```html
<div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full">
      <thead>
        <tr class="bg-gray-100 border-b-2 border-gray-200">
          <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">…</th>
        </tr>
      </thead>
      <tbody>
        <!-- filas alternas bg-white / bg-slate-50, hover:bg-indigo-50/40 -->
      </tbody>
    </table>
  </div>
</div>
```

**Botones**

| Tipo | Clases |
|---|---|
| Principal | `px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60` |
| Secundario | `px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm` |
| Peligro / éxito | como el principal, con `bg-red-600 hover:bg-red-700` / `bg-emerald-600 hover:bg-emerald-700` |

**Campos de formulario**

```html
<label class="block text-sm font-medium text-gray-700 mb-1">RUT</label>
<input class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
```

Los campos de RUT formatean al escribir con `formatearInputRut($event)` (de
`composables/useRut`), que además deja el campo igual al valor formateado:
así un guion o punto de más no llena el `maxlength` antes del dígito
verificador.

Los filtros que se hacen en el navegador comparan con `sinTildes()` (de
`utils/texto.ts`), igual que las búsquedas del backend (`whereSinTildes`):
«calculo» encuentra «Cálculo».

**Confirmaciones**

Toda acción que cambia algo importante o saca datos del sistema pasa por
`DialogoConfirmacion` (o por el mismo diseño escrito en la vista, donde ya
estaba así): título en pregunta («¿Cursar esta multa?»), un resumen de lo
que va a pasar (lo anterior tachado → lo nuevo en negrita; advertencias en
un recuadro ámbar), **Cancelar** a la izquierda y el verbo de la acción
(«Sí, guardar») a la derecha, en tono `peligro` si quita o bloquea algo. Si
la acción viene de un formulario, el formulario queda debajo y, al cancelar,
vuelve con lo escrito.

**Etiquetas de estado (insignias)**

```html
<span class="text-xs px-2 py-0.5 rounded-full font-medium bg-emerald-100 text-emerald-700">Pagada</span>
```

Con el color según la tabla de estados (`emerald` / `amber` / `red`), o
`gray`/`indigo` si la etiqueta es solo informativa.

**Radios.** `rounded` vale 10px (`borderRadius.DEFAULT` en la config).
`rounded-lg` para botones y campos, `rounded-xl` para tarjetas y encabezados,
`rounded-2xl` para modales, `rounded-full` para insignias y avatares.

---

## 6. Responsive

Se usan los cortes estándar de Tailwind: `sm` 640px, `md` 768px, `lg` 1024px,
`xl` 1280px y `2xl` 1536px.

- **Barra superior del personal:** los enlaces aparecen desde `lg`. Bajo ese
  ancho se reemplazan por un botón **"Menú"** que muestra el nombre del módulo
  actual y abre el mismo menú, que en celular incluye además los *Módulos
  principales*. El nombre y el rol del usuario aparecen desde `xl`. A 1280px
  todo cabe en una línea: si se agrega un enlace, hay que volver a verificarlo.
- **Barra del portal:** los enlaces aparecen desde `md`.
- **Pantallas del portal:** tienen dos formatos. Bajo `lg` (teléfono y
  tablet) son de una columna, con botones grandes. Desde `lg`: el Inicio es un
  panel (aviso de ingreso, ingresos de hoy, buscador, «Tu actividad» y accesos
  rápidos) y en teléfono sigue siendo la lista de accesos; Entrada muestra sus
  dos tarjetas lado a lado (apiladas en teléfono); el Catálogo es una lista con
  columnas y **Mis reservas** fijo a la derecha (en teléfono, una tarjeta por
  título); y en Salas la línea de tiempo cabe completa (en teléfono se desliza
  de lado, con el nombre de la sala fijo y abriendo en la hora actual).
- **Tablas:** siempre dentro de `overflow-x-auto`, para que en celular se
  desplacen hacia el lado en vez de romper la página. Las listas hechas con
  filas de grilla (sección 3) no se desplazan: se apilan.
- **Formularios:** una columna en celular, y más desde `sm`/`md`
  (`grid-cols-1 sm:grid-cols-2`).
- **Encabezado de pantalla:** los botones de `acciones` bajan bajo el título en
  celular (`flex-col sm:flex-row`).
- **Modales:** se ajustan al alto de la pantalla (`max-h-[92vh] overflow-y-auto`)
  y sus botones se apilan en celular.

Antes de dar por terminada una pantalla, revisarla al menos a 390px (celular),
768px (tablet) y 1280px (notebook).

---

## 7. Agregar un módulo nuevo al panel

1. Permiso: agregar la clave en `backend/app/Support/Permisos.php` (`GRUPOS`) y
   proteger sus rutas con `middleware('permiso:…')` en `routes/api.php`.
2. Frontend: la ruta en `src/router/index.ts` y la entrada en
   `src/navegacion.ts` con ese mismo permiso.
3. Menú: una tarjeta en la sección que corresponda de `SECCIONES`
   (`TopBar.vue`), con uno de los colores de `ADMIN_COLORS`.
4. Pantalla: `StaffLayout` + `EncabezadoPagina` + `GuiaUso` (si la pantalla no
   es obvia para alguien nuevo) + tarjetas y tablas con los patrones de arriba.
