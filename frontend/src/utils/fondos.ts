/**
 * Fondos del modo día. Cada persona del personal elige el suyo (menú de la cuenta ›
 * Aspecto visual) y queda en su cuenta (staff.fondo); el portal de usuarios usa siempre
 * Neblina. En modo noche no se usan: se ve el fondo oscuro de siempre.
 *
 * tailwind.config.js arma con esta lista las variables de cada fondo, que se activan con
 * data-fondo en <html> (composables/useFondo.ts). Cada fondo puede cambiar:
 * - la página (`base` y `capas`, que dibuja .fondo-pagina en los layouts);
 * - la superficie de tarjetas y modales (`tarjeta`, lo que vale bg-white);
 * - los grises claros de fondo (`neutros`: gray y slate 50–300) y los bordes (`bordes`).
 * Lo que no indica queda como en Tailwind. Textos, botones, estados y el degradado morado
 * no cambian.
 *
 * Las claves tienen que coincidir con Staff::FONDOS en el backend. Este archivo no importa
 * nada: lo carga también la configuración de Tailwind.
 */

type Tono4 = { 50: string; 100: string; 200: string; 300: string }
type Tono3 = { 100: string; 200: string; 300: string }

export type Fondo = {
  clave: string
  nombre: string
  descripcion: string
  /** Color de la página, debajo de las capas (y lo que asoma al estirar la página). */
  base: string
  /** Capas de background-image sobre la base: degradados, tramas o un SVG. */
  capas: string
  /** background-size de cada capa, en el mismo orden y una por capa. Sin él, todas auto. */
  tamanos?: string
  tarjeta?: string
  neutros?: Tono4
  bordes?: Tono3
}

/** Un SVG como imagen de fondo (la política de seguridad permite imágenes data:). */
const svg = (contenido: string) => `url("data:image/svg+xml,${encodeURIComponent(contenido)}")`

// Ondas del fondo Lavanda, pegadas al borde derecho (slice recorta por la izquierda).
const ONDAS = svg(
  `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1600 1000' preserveAspectRatio='xMaxYMid slice'>` +
    `<path fill='#e1d9f7' fill-opacity='0.6' d='M1600 0H1050c-40 90 10 170 120 210 130 48 200 120 230 230 30 110 110 170 200 190z'/>` +
    `<path fill='#d6ccf3' fill-opacity='0.4' d='M1600 0h-330c-20 70 20 130 100 160 90 34 150 100 170 180 16 64 30 100 60 120z'/>` +
    `<path fill='#e4ddf8' fill-opacity='0.7' d='M1600 1000H980c60-90 170-140 290-160 120-20 230-80 330-200z'/>` +
    `<path fill='#d9d0f4' fill-opacity='0.4' d='M1600 1000h-360c50-60 130-95 210-110 70-13 120-50 150-100z'/>` +
    `</svg>`,
)

// Grano del fondo Papel: ruido fino color tinta, casi transparente, en mosaico.
const GRANO = svg(
  `<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'>` +
    `<filter id='g'><feTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='3' stitchTiles='stitch'/>` +
    `<feColorMatrix values='0 0 0 0 0.42 0 0 0 0 0.35 0 0 0 0 0.25 0 0 0 0.11 0'/></filter>` +
    `<rect width='100%' height='100%' filter='url(#g)'/></svg>`,
)

// Cordillera, estepa y estrecho del fondo Patagonia, pegados al borde de abajo.
const CERROS = svg(
  `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1600 1000' preserveAspectRatio='xMidYMax slice'>` +
    `<path fill='#d5ddeb' fill-opacity='0.75' d='M0 770L110 712L200 738L320 640L410 694L515 606L600 658L700 588L790 642L885 598L1000 672L1090 620L1185 552L1265 612L1365 578L1455 640L1535 612L1600 648V1000H0Z'/>` +
    `<path fill='#ccd5e5' fill-opacity='0.6' d='M0 842C120 802 200 822 300 792S470 742 560 772S720 822 820 782S990 722 1080 752S1260 822 1360 792S1520 762 1600 782V1000H0Z'/>` +
    `<path fill='#c9d2e2' fill-opacity='0.55' d='M0 905C200 885 400 910 600 895S1000 875 1200 895S1450 910 1600 897V1000H0Z'/>` +
    `</svg>`,
)

// Degradado ambiental: luz violeta arriba a la derecha, azul abajo a la izquierda y un
// matiz más frío abajo. LUZ_CENTRAL va encima de todo: aclara el centro y, en la versión
// con puntos, los va borrando hacia el centro. Está corrida hacia abajo a la izquierda, así
// que los puntos se ven sobre todo arriba a la derecha y en los bordes.
const LUZ_CENTRAL = 'radial-gradient(ellipse 80% 75% at 36% 60%, #f7f8fc 0%, rgb(247 248 252 / 0.9) 35%, rgb(247 248 252 / 0) 78%)'
const LUCES_AMBIENTALES =
  'radial-gradient(ellipse 60% 55% at 100% 0%, rgb(112 91 230 / 0.10), transparent 70%), ' +
  'radial-gradient(ellipse 60% 55% at 0% 100%, rgb(90 150 230 / 0.07), transparent 70%), ' +
  'linear-gradient(180deg, #f1f3fa, #f1f3fa 40%, #eef1fa)'
// Un punto violeta azulado de 1,25 px por cada cuadro de 20 px.
const PUNTOS = 'radial-gradient(circle, rgb(92 84 204 / 0.12) 1.25px, transparent 1.5px)'

// «Exploración de fondos»: el mismo gris lila de base con distintas texturas finas.
const GRIS_LILA = '#f1f1f8'
const PUNTOS_LILA = 'radial-gradient(circle, rgb(99 102 170 / 0.22) 1px, transparent 1.4px)'
// Verde arriba a la izquierda, lila arriba a la derecha y dorado abajo: los tonos de las tarjetas.
const LUCES_AURORA =
  'radial-gradient(ellipse 50% 45% at 8% 6%, rgb(167 243 208 / 0.35), transparent 70%), ' +
  'radial-gradient(ellipse 50% 50% at 95% 8%, rgb(196 189 250 / 0.5), transparent 70%), ' +
  'radial-gradient(ellipse 55% 40% at 45% 100%, rgb(214 182 124 / 0.22), transparent 70%)'
// Anillos que salen de la esquina de abajo a la izquierda y de la de arriba a la derecha.
const anillos = (cx: number, cy: number) =>
  Array.from({ length: 11 }, (_, i) => `<circle cx='${cx}' cy='${cy}' r='${170 + i * 125}'/>`).join('')
const ANILLOS = svg(
  `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1600 1000' preserveAspectRatio='xMidYMid slice'>` +
    `<g fill='none' stroke='#6366f1' stroke-opacity='0.09' stroke-width='1.5'>${anillos(0, 1000)}${anillos(1600, 0)}</g></svg>`,
)
// Franjas verticales de distinto ancho (lomos en un estante), que se borran hacia abajo.
const LOMOS =
  `linear-gradient(180deg, rgb(241 241 248 / 0) 0%, rgb(241 241 248 / 0.7) 30%, ${GRIS_LILA} 55%), ` +
  'repeating-linear-gradient(90deg, rgb(99 102 241 / 0.07) 0 12px, transparent 12px 20px, rgb(99 102 241 / 0.04) 20px 34px, ' +
  'transparent 34px 40px, rgb(176 141 87 / 0.08) 40px 48px, transparent 48px 62px, rgb(99 102 241 / 0.05) 62px 70px, transparent 70px 90px)'

export const FONDOS: Fondo[] = [
  {
    clave: 'neblina',
    nombre: 'Neblina',
    descripcion: 'Gris azulado parejo y tarjetas apenas más suaves que el blanco.',
    base: '#edeff5',
    capas: 'linear-gradient(135deg, #e7eaf5, #edeff5 50%, #eeecf6)',
    tarjeta: '#fbfbfe',
    neutros: { 50: '#f5f6fa', 100: '#eceef5', 200: '#e1e4ed', 300: '#cfd3df' },
    bordes: { 100: '#e8eaf1', 200: '#dfe2ec', 300: '#c8ccda' },
  },
  {
    clave: 'clasico',
    nombre: 'Clásico',
    descripcion: 'El de siempre: casi blanco, con tarjetas blancas.',
    base: '#f8fafc',
    capas: 'linear-gradient(to bottom right, rgb(238 242 255 / 0.6), #f8fafc, rgb(250 245 255 / 0.4))',
  },
  {
    clave: 'liso',
    nombre: 'Liso',
    descripcion: 'Sin textura, solo el gris lila. El más neutro y limpio.',
    base: GRIS_LILA,
    capas: 'none',
  },
  {
    clave: 'puntos',
    nombre: 'Puntos',
    descripcion: 'Puntos finos y parejos: discreto y ordenado, casi no se nota.',
    base: GRIS_LILA,
    capas: PUNTOS_LILA,
    tamanos: '18px 18px',
  },
  {
    clave: 'cuadricula',
    nombre: 'Cuadrícula',
    descripcion: 'Como papel milimetrado: técnico y da sensación de orden.',
    base: GRIS_LILA,
    capas: 'linear-gradient(rgb(99 102 241 / 0.07) 1px, transparent 1px), linear-gradient(90deg, rgb(99 102 241 / 0.07) 1px, transparent 1px)',
    tamanos: '24px 24px, 24px 24px',
  },
  {
    clave: 'diagonales',
    nombre: 'Diagonales',
    descripcion: 'Líneas finas en diagonal: algo de movimiento sin distraer.',
    base: GRIS_LILA,
    capas: 'repeating-linear-gradient(135deg, rgb(99 102 241 / 0.07) 0 1px, transparent 1px 14px)',
  },
  {
    clave: 'aurora-suave',
    nombre: 'Aurora',
    descripcion: 'Manchas difusas de verde, lila y dorado, los mismos tonos de las tarjetas.',
    base: GRIS_LILA,
    capas: LUCES_AURORA,
  },
  {
    clave: 'aurora-puntos',
    nombre: 'Aurora + puntos',
    descripcion: 'La aurora con los puntos finos encima: cálido y con profundidad.',
    base: GRIS_LILA,
    capas: `${PUNTOS_LILA}, ${LUCES_AURORA}`,
    tamanos: '18px 18px, auto, auto, auto',
  },
  {
    clave: 'ondas',
    nombre: 'Ondas',
    descripcion: 'Anillos desde las esquinas, como curvas de nivel.',
    base: GRIS_LILA,
    capas: ANILLOS,
  },
  {
    clave: 'lomos',
    nombre: 'Lomos de libro',
    descripcion: 'Franjas verticales que recuerdan un estante y se desvanecen hacia abajo.',
    base: GRIS_LILA,
    capas: LOMOS,
  },
  {
    clave: 'gris-azulado',
    nombre: 'Gris azulado',
    descripcion: 'Degradado del celeste al lavanda, con tarjetas blancas.',
    base: '#e6ebf6',
    capas:
      'radial-gradient(ellipse 70% 60% at 0% 0%, rgb(196 210 244 / 0.75), transparent 70%), ' +
      'radial-gradient(ellipse 60% 60% at 100% 100%, rgb(225 218 247 / 0.8), transparent 70%), ' +
      'linear-gradient(135deg, #e1e8f6, #eef0f8 55%, #ebe8f7)',
  },
  {
    clave: 'lavanda',
    nombre: 'Lavanda',
    descripcion: 'Lavanda muy tenue, con ondas suaves a la derecha.',
    base: '#f0edfa',
    capas: `${ONDAS}, linear-gradient(160deg, #f4f2fb, #ece8f8)`,
  },
  {
    clave: 'perla',
    nombre: 'Gris perla',
    descripcion: 'Gris perla con brillos diagonales y una trama muy fina.',
    base: '#eceef1',
    capas:
      'repeating-linear-gradient(135deg, rgb(255 255 255 / 0.3) 0 1px, transparent 1px 6px), ' +
      'linear-gradient(115deg, transparent 20%, rgb(255 255 255 / 0.5) 38%, transparent 55%, rgb(255 255 255 / 0.35) 75%, transparent 90%), ' +
      'linear-gradient(180deg, #e8eaee, #f0f1f4)',
  },
  {
    clave: 'marfil',
    nombre: 'Marfil',
    descripcion: 'Blanco cálido, como marfil, con tarjetas blancas.',
    base: '#f7f3ea',
    capas: 'linear-gradient(135deg, #f4eee2, #faf7f1 50%, #f5efe5)',
  },
  {
    clave: 'ambiental',
    nombre: 'Ambiental',
    descripcion: 'Luz violeta tenue arriba y azul abajo, con el centro más claro.',
    base: '#f1f3fa',
    capas: `${LUZ_CENTRAL}, ${LUCES_AMBIENTALES}`,
  },
  {
    clave: 'ambiental-puntos',
    nombre: 'Ambiental con puntos',
    descripcion: 'La luz ambiental con puntos finos que se desvanecen hacia el centro.',
    base: '#f1f3fa',
    capas: `${LUZ_CENTRAL}, ${PUNTOS}, ${LUCES_AMBIENTALES}`,
    tamanos: 'auto, 20px 20px, auto, auto, auto',
  },
  {
    clave: 'papel',
    nombre: 'Papel',
    descripcion: 'Tono cálido con grano fino de papel; tarjetas y grises a juego.',
    base: '#f2efe9',
    capas: `${GRANO}, linear-gradient(135deg, #eeeae1, #f2efe9 50%, #f0ece6)`,
    tarjeta: '#fdfcf9',
    neutros: { 50: '#f8f6f1', 100: '#f0ede6', 200: '#e6e2d9', 300: '#d5d0c5' },
    bordes: { 100: '#ece8e0', 200: '#e6e1d7', 300: '#d3cdc1' },
  },
  {
    clave: 'lino',
    nombre: 'Lino',
    descripcion: 'Verde salvia con la trama de una tela de encuadernación.',
    base: '#e9ece5',
    capas:
      'repeating-linear-gradient(0deg, rgb(70 90 70 / 0.05) 0 1px, transparent 1px 4px), ' +
      'repeating-linear-gradient(90deg, rgb(70 90 70 / 0.05) 0 1px, transparent 1px 4px), ' +
      'linear-gradient(160deg, #e5e9e1, #eef0ea)',
  },
  {
    clave: 'aurora',
    nombre: 'Aurora austral',
    descripcion: 'Manchas de color difusas, verde y violeta, como la aurora del sur.',
    base: '#eef0f7',
    capas:
      'radial-gradient(ellipse 55% 45% at 12% 8%, rgb(167 243 208 / 0.45), transparent 70%), ' +
      'radial-gradient(ellipse 50% 50% at 88% 15%, rgb(196 181 253 / 0.45), transparent 70%), ' +
      'radial-gradient(ellipse 60% 45% at 75% 100%, rgb(186 230 253 / 0.5), transparent 70%), ' +
      'radial-gradient(ellipse 45% 40% at 5% 95%, rgb(233 213 255 / 0.45), transparent 70%)',
  },
  {
    clave: 'patagonia',
    nombre: 'Patagonia',
    descripcion: 'Cordillera, estepa y estrecho en el horizonte, en azules muy suaves.',
    base: '#eef1f6',
    capas: `${CERROS}, linear-gradient(180deg, #e4ebf5, #eff2f7 55%, #f1f2f6)`,
  },
]

/** El del personal que no ha elegido (staff.fondo vacío) y el fijo del portal. */
export const FONDO_PREDETERMINADO = 'neblina'
export const FONDO_PORTAL = 'neblina'

export function esFondo(clave: unknown): clave is string {
  return typeof clave === 'string' && FONDOS.some((f) => f.clave === clave)
}
