<script setup lang="ts">
/**
 * Motivo de la caja de título de Préstamos, una pieza por función: el carro de libros
 * (devoluciones), la ficha timbrada en su bolsillo (préstamos) y los libros con cinta
 * (reservas), con el lector de códigos. Se dibuja a tamaño real para que no se deforme.
 */
const BLANCO = 'rgb(255 255 255 / 0.42)'
const TENUE = 'rgb(255 255 255 / 0.26)'
const DORADO = 'rgb(228 204 154 / 0.85)'
const MENTA = 'rgb(167 243 208 / 0.95)'

type Lomo = { x: number; ancho: number; alto: number; dorado?: boolean; inclinado?: boolean }
const ARRIBA: Lomo[] = [
  { x: 32, ancho: 9, alto: 26 },
  { x: 42, ancho: 11, alto: 32, dorado: true },
  { x: 54, ancho: 8, alto: 24 },
  { x: 63, ancho: 10, alto: 30 },
  { x: 74, ancho: 9, alto: 27, inclinado: true },
  { x: 92, ancho: 11, alto: 31 },
]
const ABAJO: Lomo[] = [
  { x: 33, ancho: 12, alto: 21 },
  { x: 46, ancho: 9, alto: 18 },
  { x: 56, ancho: 11, alto: 22 },
  { x: 68, ancho: 8, alto: 17, dorado: true },
  { x: 77, ancho: 12, alto: 21 },
  { x: 90, ancho: 13, alto: 19 },
]
const REPISA_ARRIBA = 54
const REPISA_ABAJO = 83
const FILAS_FICHA = [26, 36, 46]
const PILA = [
  { x: 262, ancho: 76, y: 84, alto: 10 },
  { x: 268, ancho: 66, y: 74, alto: 10, dorado: true },
  { x: 264, ancho: 72, y: 64, alto: 10 },
]
const BARRAS = [352, 355, 357, 361, 364, 366, 370]
</script>

<template>
  <svg
    class="pointer-events-none absolute bottom-0 right-44 hidden h-[5.25rem] w-[21.875rem] xl:block"
    style="-webkit-mask-image: linear-gradient(to right, transparent, black 12%); mask-image: linear-gradient(to right, transparent, black 12%);"
    viewBox="0 0 400 96"
    fill="none"
    aria-hidden="true"
  >
    <defs>
      <linearGradient id="prestamo-papel" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#fff" stop-opacity="0.2" />
        <stop offset="1" stop-color="#fff" stop-opacity="0.05" />
      </linearGradient>
      <linearGradient id="prestamo-oro" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#e4cc9a" stop-opacity="0.42" />
        <stop offset="1" stop-color="#e4cc9a" stop-opacity="0.09" />
      </linearGradient>
    </defs>

    <!-- Carro de devoluciones -->
    <g stroke-linecap="round" stroke-linejoin="round">
      <template v-for="(fila, f) in [{ lomos: ARRIBA, base: REPISA_ARRIBA }, { lomos: ABAJO, base: REPISA_ABAJO }]" :key="f">
        <rect
          v-for="l in fila.lomos"
          :key="l.x"
          :x="l.x"
          :y="fila.base - l.alto"
          :width="l.ancho"
          :height="l.alto"
          rx="1.500"
          :transform="l.inclinado ? `rotate(10 ${l.x} ${fila.base})` : undefined"
          :fill="l.dorado ? 'url(#prestamo-oro)' : 'url(#prestamo-papel)'"
          :stroke="l.dorado ? DORADO : BLANCO"
        />
      </template>
      <path :d="`M28 ${REPISA_ARRIBA}h80M28 ${REPISA_ABAJO}h80`" :stroke="BLANCO" stroke-width="2" />
      <path d="M28 22v62M108 26v58" :stroke="BLANCO" stroke-width="1.600" />
      <path d="M28 22c-7 0-11-4-13-9" :stroke="BLANCO" stroke-width="1.600" />
      <circle cx="38" cy="90" r="5" fill="rgb(255 255 255 / 0.08)" :stroke="BLANCO" />
      <circle cx="98" cy="90" r="5" fill="rgb(255 255 255 / 0.08)" :stroke="BLANCO" />
    </g>

    <!-- Va y vuelve -->
    <g :stroke="DORADO" stroke-width="1.500" stroke-linecap="round" stroke-linejoin="round">
      <path d="M120 44c7-7 15-7 22 0" />
      <path d="m137 38 5 6-7 2" />
      <path d="M142 60c-7 7-15 7-22 0" />
      <path d="m125 66-5-6 7-2" />
    </g>

    <!-- Ficha de préstamo en su bolsillo -->
    <g stroke-linecap="round" stroke-linejoin="round">
      <rect x="158" y="6" width="78" height="82" rx="4" fill="url(#prestamo-papel)" :stroke="BLANCO" />
      <path d="M166 16h34" :stroke="BLANCO" stroke-width="2" />
      <g v-for="y in FILAS_FICHA" :key="y">
        <path :d="`M166 ${y}h30`" :stroke="TENUE" />
        <path :d="`M204 ${y}h24`" :stroke="TENUE" stroke-dasharray="3 2" />
      </g>
      <path d="M200 21v30" :stroke="TENUE" />
      <path d="M152 60h90v36h-90z" fill="url(#prestamo-papel)" :stroke="BLANCO" />
      <path d="M152 60c14 9 76 9 90 0" :stroke="BLANCO" />
    </g>
    <g transform="rotate(-11 198 52)">
      <rect x="166" y="42" width="66" height="19" rx="4" fill="rgb(52 211 153 / 0.14)" :stroke="MENTA" stroke-width="1.500" />
      <text x="199" y="55.500" text-anchor="middle" font-size="10" font-weight="800" letter-spacing="1" :fill="MENTA" style="font-family: ui-sans-serif, system-ui, sans-serif">DEVUELTO</text>
    </g>

    <!-- Libros reservados, con su cinta -->
    <g stroke-linecap="round" stroke-linejoin="round">
      <g v-for="p in PILA" :key="p.y">
        <rect :x="p.x" :y="p.y" :width="p.ancho" :height="p.alto" rx="2" :fill="p.dorado ? 'url(#prestamo-oro)' : 'url(#prestamo-papel)'" :stroke="p.dorado ? DORADO : BLANCO" />
        <path :d="`M${p.x + 8} ${p.y}v${p.alto}`" :stroke="p.dorado ? DORADO : TENUE" />
      </g>
      <path d="M314 36V20l5 4 5-4v16" fill="url(#prestamo-oro)" :stroke="DORADO" />
      <path d="M300 42c-8-5-19-6-29-3v22c10-3 21-2 29 3z" fill="url(#prestamo-papel)" :stroke="BLANCO" />
      <path d="M300 42c8-5 19-6 29-3v22c-10-3-21-2-29 3z" fill="url(#prestamo-papel)" :stroke="BLANCO" />
      <path d="M278 47c6-1 11 0 16 2M278 54c6-1 11 0 16 2M322 47c-6-1-11 0-16 2M322 54c-6-1-11 0-16 2" :stroke="TENUE" />
    </g>

    <!-- Lector de códigos sobre una etiqueta -->
    <g stroke-linecap="round" stroke-linejoin="round">
      <path d="M350 22h34a6 6 0 0 1 6 6v6a6 6 0 0 1-6 6h-12l-5 22a4 4 0 0 1-4 3h-5l6-25h-14z" fill="url(#prestamo-papel)" :stroke="BLANCO" />
      <path d="M350 26l-9 10 9 4" :stroke="DORADO" stroke-dasharray="2 2.500" />
      <rect x="348" y="74" width="30" height="20" rx="2" fill="rgb(255 255 255 / 0.1)" :stroke="BLANCO" />
      <path v-for="x in BARRAS" :key="x" :d="`M${x} 78v12`" :stroke="BLANCO" />
    </g>

    <rect x="8" y="94" width="392" height="2" fill="rgb(255 255 255 / 0.4)" />
  </svg>
</template>
