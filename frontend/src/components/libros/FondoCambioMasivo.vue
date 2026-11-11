<script setup lang="ts">
/** Motivo de la caja de título de Cambio masivo: una caja de libros pasa de una vez al estante, todos con su nuevo estado. */
const BLANCO = 'rgb(255 255 255 / 0.42)'
const TENUE = 'rgb(255 255 255 / 0.3)'
const DORADO = 'rgb(228 204 154 / 0.85)'
const MENTA = 'rgb(110 231 183 / 0.95)'
const BASE = 98
const EN_CAJA = [
  { x: 26, ancho: 12, alto: 58 },
  { x: 39, ancho: 10, alto: 66, dorado: true },
  { x: 50, ancho: 13, alto: 60 },
  { x: 64, ancho: 10, alto: 68 },
  { x: 75, ancho: 12, alto: 58, dorado: true },
  { x: 88, ancho: 10, alto: 63 },
]
const EN_ESTANTE = [
  { x: 186, ancho: 13, alto: 58 },
  { x: 200, ancho: 11, alto: 70 },
  { x: 212, ancho: 15, alto: 62, dorado: true },
  { x: 228, ancho: 11, alto: 74 },
  { x: 240, ancho: 14, alto: 60 },
  { x: 255, ancho: 12, alto: 68, dorado: true },
  { x: 268, ancho: 15, alto: 76 },
  { x: 284, ancho: 11, alto: 62 },
  { x: 296, ancho: 14, alto: 70 },
]
const FLECHAS = [{ y: 54, largo: 40 }, { y: 68, largo: 52 }, { y: 82, largo: 40 }]
</script>

<template>
  <svg
    class="pointer-events-none absolute bottom-0 right-4 hidden h-full w-[21rem] xl:block"
    style="-webkit-mask-image: linear-gradient(to right, transparent, black 12%); mask-image: linear-gradient(to right, transparent, black 12%);"
    viewBox="0 0 336 102"
    preserveAspectRatio="xMaxYMax meet"
    fill="none"
    aria-hidden="true"
  >
    <defs>
      <linearGradient id="masivo-papel" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#fff" stop-opacity="0.2" />
        <stop offset="1" stop-color="#fff" stop-opacity="0.05" />
      </linearGradient>
      <linearGradient id="masivo-oro" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#e4cc9a" stop-opacity="0.45" />
        <stop offset="1" stop-color="#e4cc9a" stop-opacity="0.1" />
      </linearGradient>
    </defs>

    <!-- Caja con los libros por cambiar -->
    <g stroke-linejoin="round" stroke-linecap="round">
      <g v-for="l in EN_CAJA" :key="l.x">
        <rect :x="l.x" :y="BASE - l.alto" :width="l.ancho" :height="l.alto" rx="1.500" :fill="l.dorado ? 'url(#masivo-oro)' : 'url(#masivo-papel)'" :stroke="l.dorado ? DORADO : BLANCO" />
        <circle :cx="l.x + l.ancho / 2" :cy="BASE - l.alto + 7" r="2.200" fill="rgb(255 255 255 / 0.55)" />
      </g>
      <path d="M16 66h92l-7 32H23z" fill="#4338CA" fill-opacity="0.8" />
      <path d="M16 66h92l-7 32H23z" fill="url(#masivo-papel)" :stroke="BLANCO" stroke-width="1.300" />
      <path d="M20 76h84M22 87h80" :stroke="TENUE" />
      <path d="M50 66v32M74 66v32" :stroke="TENUE" />
    </g>

    <!-- El lote -->
    <g stroke-linecap="round" stroke-linejoin="round">
      <rect x="123" y="22" width="44" height="18" rx="4" fill="url(#masivo-oro)" :stroke="DORADO" />
      <text x="145" y="35" text-anchor="middle" font-size="10" font-weight="800" letter-spacing="1.200" :fill="DORADO" stroke="none" style="font-family: ui-sans-serif, system-ui, sans-serif">LOTE</text>
      <g v-for="f in FLECHAS" :key="f.y" :stroke="DORADO" stroke-width="1.700">
        <path :d="`M${122 + (52 - f.largo) / 2} ${f.y}h${f.largo}`" />
        <path :d="`m${168 - (52 - f.largo) / 2} ${f.y - 5} 6 5-6 5`" />
      </g>
    </g>

    <!-- Estante: todos con su estado nuevo -->
    <g stroke-linejoin="round" stroke-linecap="round">
      <g v-for="l in EN_ESTANTE" :key="l.x">
        <rect :x="l.x" :y="BASE - l.alto" :width="l.ancho" :height="l.alto" rx="1.500" :fill="l.dorado ? 'url(#masivo-oro)' : 'url(#masivo-papel)'" :stroke="l.dorado ? DORADO : BLANCO" />
        <circle :cx="l.x + l.ancho / 2" :cy="BASE - l.alto + 7" r="2.200" :fill="MENTA" />
      </g>
      <circle cx="320" cy="20" r="11" fill="rgb(52 211 153 / 0.16)" :stroke="MENTA" stroke-width="1.500" />
      <path d="m315 20 3.500 3.500 6.500-7" :stroke="MENTA" stroke-width="1.800" />
    </g>

    <rect x="8" y="98" width="328" height="2" fill="rgb(255 255 255 / 0.4)" />
  </svg>
</template>
