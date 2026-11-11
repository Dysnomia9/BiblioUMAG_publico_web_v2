<script setup lang="ts">
/**
 * Motivo de la caja de título de Multas: un libro con el timbre de vencido, la boleta que
 * sale de la caja con su total, el calendario con la fecha pasada y una pila de monedas.
 */
const BLANCO = 'rgb(255 255 255 / 0.42)'
const TENUE = 'rgb(255 255 255 / 0.26)'
const DORADO = 'rgb(228 204 154 / 0.85)'
const ROJO = 'rgb(254 178 178 / 0.95)'

// Borde cortado de la boleta, de derecha a izquierda.
const X_DER = 196
const X_IZQ = 124
const Y_CORTE = 82
const dientes = Array.from({ length: 9 }, (_, i) => `L${X_DER - (i + 1) * 8} ${Y_CORTE + (i % 2 === 0 ? 5 : 0)}`).join('')
const BOLETA = `M${X_IZQ} -4H${X_DER}V${Y_CORTE}${dientes}Z`

const FILAS_BOLETA = [14, 26, 38]
const DIAS = Array.from({ length: 15 }, (_, i) => ({ x: 233 + (i % 5) * 10, y: 50 + Math.floor(i / 5) * 11, i }))
const VENCIDO = 7
const MONEDAS = [0, 1, 2, 3]
</script>

<template>
  <svg
    class="pointer-events-none absolute bottom-0 right-[21.5rem] hidden h-full w-[21rem] xl:block"
    style="-webkit-mask-image: linear-gradient(to right, transparent, black 16%); mask-image: linear-gradient(to right, transparent, black 16%);"
    viewBox="0 0 336 102"
    preserveAspectRatio="xMaxYMax meet"
    fill="none"
    aria-hidden="true"
  >
    <defs>
      <linearGradient id="multas-papel" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#fff" stop-opacity="0.2" />
        <stop offset="1" stop-color="#fff" stop-opacity="0.05" />
      </linearGradient>
      <linearGradient id="multas-oro" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#e4cc9a" stop-opacity="0.42" />
        <stop offset="1" stop-color="#e4cc9a" stop-opacity="0.1" />
      </linearGradient>
    </defs>

    <!-- Libro atrasado, con su marcador y el timbre -->
    <g transform="rotate(-7 66 100)" stroke-linejoin="round" stroke-linecap="round">
      <path d="M78 14v22l6-5 6 5V14z" :fill="'url(#multas-oro)'" :stroke="DORADO" />
      <rect x="32" y="20" width="68" height="80" rx="4" fill="url(#multas-papel)" :stroke="BLANCO" />
      <path d="M42 20v80" :stroke="TENUE" />
      <path d="M52 34h38M52 42h26" :stroke="TENUE" />
    </g>
    <g transform="rotate(-13 72 70)">
      <rect x="39" y="59" width="68" height="21" rx="4" fill="rgb(248 113 113 / 0.14)" :stroke="ROJO" stroke-width="1.5" />
      <text x="73" y="74" text-anchor="middle" font-size="11" font-weight="800" letter-spacing="1.2" :fill="ROJO" style="font-family: ui-sans-serif, system-ui, sans-serif">VENCIDO</text>
    </g>

    <!-- Boleta que sale de arriba -->
    <g stroke-linecap="round" stroke-linejoin="round">
      <path :d="BOLETA" fill="url(#multas-papel)" :stroke="BLANCO" />
      <g v-for="y in FILAS_BOLETA" :key="y">
        <path :d="`M133 ${y}h26`" :stroke="TENUE" />
        <path :d="`M174 ${y}h13`" :stroke="BLANCO" />
      </g>
      <path d="M133 50h54" :stroke="TENUE" stroke-dasharray="2 3" />
      <text x="133" y="70" font-size="10" font-weight="700" letter-spacing="0.8" :fill="BLANCO" stroke="none" style="font-family: ui-sans-serif, system-ui, sans-serif">TOTAL</text>
      <text x="187" y="70" text-anchor="end" font-size="11" font-weight="800" :fill="DORADO" stroke="none" style="font-family: ui-sans-serif, system-ui, sans-serif">$</text>
    </g>

    <!-- Calendario con la fecha pasada -->
    <g stroke-linecap="round" stroke-linejoin="round">
      <rect x="224" y="24" width="58" height="72" rx="6" fill="url(#multas-papel)" :stroke="BLANCO" />
      <path d="M224 40h58" :stroke="BLANCO" />
      <path d="M239 19v10M267 19v10" :stroke="BLANCO" stroke-width="2" />
      <template v-for="d in DIAS" :key="d.i">
        <circle v-if="d.i !== VENCIDO" :cx="d.x" :cy="d.y" r="1.7" fill="rgb(255 255 255 / 0.36)" />
      </template>
      <circle :cx="DIAS[VENCIDO].x" :cy="DIAS[VENCIDO].y" r="6.500" fill="rgb(248 113 113 / 0.18)" :stroke="ROJO" stroke-width="1.4" />
      <path :d="`M${DIAS[VENCIDO].x - 2.5} ${DIAS[VENCIDO].y - 2.5}l5 5m0-5l-5 5`" :stroke="ROJO" stroke-width="1.4" />
    </g>

    <!-- Pila de monedas -->
    <g :stroke="DORADO">
      <g v-for="n in MONEDAS" :key="n">
        <path :d="`M294 ${92 - n * 8}v6c0 2.800 8 5 16 5s16-2.200 16-5v-6`" fill="url(#multas-oro)" />
        <ellipse cx="310" :cy="92 - n * 8" rx="16" ry="5" fill="rgb(228 204 154 / 0.16)" />
      </g>
    </g>
    <text x="310" y="72.500" text-anchor="middle" font-size="7" font-weight="800" :fill="DORADO" style="font-family: ui-sans-serif, system-ui, sans-serif">$</text>

    <rect x="16" y="100" width="320" height="2" fill="rgb(255 255 255 / 0.4)" />
  </svg>
</template>
