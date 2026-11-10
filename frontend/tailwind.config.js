/** @type {import('tailwindcss').Config} */
// La interfaz usa la paleta estándar de Tailwind (indigo/slate/gray para la base,
// emerald/amber/red para estados) más el degradado morado institucional y el
// dorado de la pestaña activa, escritos directamente en los componentes.
// Guía completa: docs/estilo-visual.md
import colores from 'tailwindcss/colors'
import plugin from 'tailwindcss/plugin'
import { FONDOS } from './src/utils/fondos.ts'

// Modo oscuro (clase .dark en <html>, ver src/composables/useTema.ts). Los tonos que se
// usan de fondo claro, de texto oscuro o de borde claro valen una variable CSS: en modo
// claro, el mismo color de Tailwind (la interfaz no cambia); en oscuro, su equivalente.
// Así ninguna pantalla necesita clases dark: propias. El resto de los tonos (botones
// indigo-600, puntos de estado, el degradado morado) se ven bien en los dos modos.
const ESCALAS = ['slate', 'gray', 'zinc', 'neutral', 'stone', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose']
const NEUTRAS = ['slate', 'gray', 'zinc', 'neutral', 'stone']

// Textos con el dorado y el azul pizarra institucionales (secciones de catalogación,
// recursos de apoyo): [en claro, en oscuro].
const PROPIOS = {
  dorado: { 600: ['#8a6a3b', '#d6b67c'], 700: ['#6f5226', '#e4cc9a'] },
  pizarra: { 600: ['#3F5F8A', '#a7bee0'], 700: ['#2f4a6e', '#c3d4ee'] },
}

// Superficie de las tarjetas (bg-white) y de los campos en modo oscuro.
const SUPERFICIE = '#181c30'

// Grises del modo oscuro, con un dejo índigo: fondos que suben de la superficie,
// textos claros con la misma jerarquía que en claro y bordes apenas marcados.
const NEUTROS_OSCUROS = {
  fondo: { 50: '#1d2237', 100: '#232942', 200: '#2c334f', 300: '#39405f' },
  texto: { 300: '#555b78', 400: '#71789a', 500: '#8e94ae', 600: '#a9aec4', 700: '#c6cadb', 800: '#dcdfeb', 900: '#eceef6', 950: '#f5f6fb' },
  borde: { 50: '#1f2439', 100: '#252b44', 200: '#2d3452', 300: '#3a4264', 400: '#4b5478' },
}

// Los tonos de color: fondos y bordes claros pasan a un tinte oscuro del mismo color;
// los textos oscuros, al tono claro equivalente (700 → 300).
const TINTE_FONDO = { 50: 0.12, 100: 0.18, 200: 0.28, 300: 0.4 }
const TINTE_BORDE = { 50: 0.16, 100: 0.22, 200: 0.32, 300: 0.45 }
const TEXTO_CLARO = { 600: 400, 700: 300, 800: 200, 900: 100, 950: 50 }

const rgb = (hex) => {
  const n = parseInt(hex.slice(1), 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}
const canales = (hex) => rgb(hex).join(' ')
const mezcla = (base, color, t) => {
  const [a, b] = [rgb(base), rgb(color)]
  return a.map((x, i) => Math.round(x + (b[i] - x) * t)).join(' ')
}

// Tonos que cambian, por tipo de utilidad: { tono: canales en oscuro }.
function tonosOscuros(tipo, escala) {
  const c = colores[escala]
  if (NEUTRAS.includes(escala)) return Object.fromEntries(Object.entries(NEUTROS_OSCUROS[tipo]).map(([t, hex]) => [t, canales(hex)]))
  if (tipo === 'texto') return Object.fromEntries(Object.entries(TEXTO_CLARO).map(([t, claro]) => [t, canales(c[claro])]))
  const tintes = tipo === 'fondo' ? TINTE_FONDO : TINTE_BORDE
  return Object.fromEntries(Object.entries(tintes).map(([t, cuanto]) => [t, mezcla(SUPERFICIE, c[500], cuanto)]))
}

const variables = { claro: { '--fondo-white': '255 255 255' }, oscuro: { '--fondo-white': canales(SUPERFICIE) } }

function paleta(tipo) {
  const resultado = { inherit: 'inherit', current: 'currentColor', transparent: 'transparent', black: colores.black, white: colores.white }
  for (const escala of ESCALAS) {
    resultado[escala] = { ...colores[escala] }
    for (const [tono, oscuro] of Object.entries(tonosOscuros(tipo, escala))) {
      const nombre = `--${tipo}-${escala}-${tono}`
      variables.claro[nombre] = canales(colores[escala][tono])
      variables.oscuro[nombre] = oscuro
      resultado[escala][tono] = `rgb(var(${nombre}) / <alpha-value>)`
    }
  }
  return resultado
}

const fondos = {
  ...paleta('fondo'),
  // bg-white es la superficie de tarjetas y modales: en oscuro, SUPERFICIE.
  white: 'rgb(var(--fondo-white) / <alpha-value>)',
  // Blanco que no cambia: los velos translúcidos sobre el degradado morado (bg-blanco/10).
  blanco: '#ffffff',
}
const textos = paleta('texto')
const bordes = paleta('borde')

for (const [nombre, tonos] of Object.entries(PROPIOS)) {
  textos[nombre] = {}
  for (const [tono, [claro, oscuro]] of Object.entries(tonos)) {
    const variable = `--texto-${nombre}-${tono}`
    variables.claro[variable] = canales(claro)
    variables.oscuro[variable] = canales(oscuro)
    textos[nombre][tono] = `rgb(var(${variable}) / <alpha-value>)`
  }
}

// Fondos del modo día (src/utils/fondos.ts), que activa data-fondo en <html>. Todos fijan
// el mismo juego de variables: tarjetas, grises claros y bordes de gray y slate (lo que no
// indican, como en Tailwind). Así la miniatura de un fondo en el selector no hereda nada
// del que está puesto, y .tema-claro vuelve al blanco con ese mismo juego. La página la
// dibuja .fondo-pagina con --pagina-base y --pagina-capas.
function variablesDeFondo(fondo = {}) {
  const v = { '--fondo-white': canales(fondo.tarjeta ?? '#ffffff') }
  for (const escala of ['gray', 'slate']) {
    for (const tono of [50, 100, 200, 300]) v[`--fondo-${escala}-${tono}`] = canales(fondo.neutros?.[tono] ?? colores[escala][tono])
    for (const tono of [100, 200, 300]) v[`--borde-${escala}-${tono}`] = canales(fondo.bordes?.[tono] ?? colores[escala][tono])
  }
  return v
}
const pagina = (fondo) => ({ '--pagina-base': fondo.base, '--pagina-capas': fondo.capas, '--pagina-tamanos': fondo.tamanos ?? 'auto' })
const reglasDeFondos = Object.fromEntries(
  // Sin .dark: en modo noche <html> no toma el fondo, pero una miniatura sí (se ve en claro).
  FONDOS.map((fondo) => [`[data-fondo="${fondo.clave}"]:not(.dark)`, { ...variablesDeFondo(fondo), ...pagina(fondo) }]),
)
// Sin data-fondo, la página como siempre.
Object.assign(variables.claro, pagina(FONDOS.find((f) => f.clave === 'clasico')))

export default {
  content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
  // Tailwind solo emite las reglas de base con clases si encuentra esas clases en el
  // código; .dark la pone useTema.ts en tiempo de ejecución.
  safelist: ['dark', 'tema-claro'],
  darkMode: 'selector',
  theme: {
    backgroundColor: fondos,
    gradientColorStops: fondos,
    textColor: textos,
    borderColor: { ...bordes, DEFAULT: 'rgb(var(--borde-gray-200) / <alpha-value>)' },
    ringColor: { ...bordes, DEFAULT: colores.blue[500] },
    extend: {
      borderRadius: {
        DEFAULT: '0.625rem',
      },
      fontFamily: {
        sans: ['"Source Sans 3"', 'system-ui', 'sans-serif'],
        serif: ['"Source Serif 4"', 'Georgia', 'serif'],
      },
    },
  },
  plugins: [
    plugin(({ addBase }) => {
      addBase({
        ':root': variables.claro,
        // Solo en pantalla: lo que se imprime (etiquetas, listados) sale siempre en claro.
        '@media screen': {
          'html.dark': { ...variables.oscuro, 'color-scheme': 'dark' },
          // Lo que siempre se ve como en papel o sobre el degradado: etiquetas, el QR,
          // la pestaña activa del portal.
          'html.dark .tema-claro': { ...variables.claro, 'color-scheme': 'light' },
          'html.dark *, html.dark ::before, html.dark ::after': { '--tw-ring-offset-color': 'rgb(var(--fondo-white))' },
          // Campos sin fondo propio: la superficie, no el gris del navegador.
          ':where(html.dark) :where(input:not([type="checkbox"], [type="radio"], [type="range"], [type="color"], [type="file"]), select, textarea)': {
            'background-color': 'rgb(var(--fondo-white))',
          },
          ...reglasDeFondos,
          // En modo día, lo que se ve como en papel (QR, etiqueta de lomo) sigue en blanco.
          '[data-fondo] .tema-claro': variablesDeFondo(),
          'html:not(.dark) body': { 'background-color': 'var(--pagina-base)' },
          // La página de los layouts: una capa fija detrás de todo, para que las ondas, los
          // cerros o las manchas queden en su lugar al desplazarse por una lista larga.
          'html:not(.dark) .fondo-pagina::before': {
            content: '""',
            position: 'fixed',
            inset: '0',
            'z-index': '-1',
            'pointer-events': 'none',
            'background-color': 'var(--pagina-base)',
            'background-image': 'var(--pagina-capas)',
            'background-size': 'var(--pagina-tamanos)',
          },
        },
      })
    }),
  ],
}
