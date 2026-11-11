<script setup lang="ts">
/**
 * Motivo de la caja de título de Listado de préstamos: el fichero con sus tarjetas, el libro
 * de registro con cada préstamo marcado según su estado, y el reloj de arena del plazo.
 * Se dibuja a tamaño real para que no se deforme.
 */
const BLANCO = 'rgb(255 255 255 / 0.42)'
const TENUE = 'rgb(255 255 255 / 0.28)'
const DORADO = 'rgb(228 204 154 / 0.85)'
const VERDE = 'rgb(110 231 183 / 0.95)'
const CELESTE = 'rgb(125 211 252 / 0.95)'
const ROJO = 'rgb(252 165 165 / 0.95)'

const TARJETAS = [
  { y: 16, tab: 34 },
  { y: 24, tab: 56, dorada: true },
  { y: 32, tab: 80 },
  { y: 40, tab: 42 },
  { y: 48, tab: 66, dorada: true },
]
const IZQUIERDA = [VERDE, CELESTE, CELESTE, ROJO, VERDE]
const DERECHA = [CELESTE, VERDE, ROJO, CELESTE, VERDE]
const filaY = (i: number) => 36 + i * 10.5
</script>

<template>
  <svg
    class="pointer-events-none absolute bottom-0 right-6 hidden h-24 w-[25rem] lg:block"
    style="-webkit-mask-image: linear-gradient(to right, transparent, black 12%); mask-image: linear-gradient(to right, transparent, black 12%);"
    viewBox="0 0 400 96"
    fill="none"
    aria-hidden="true"
  >
    <defs>
      <linearGradient id="listado-papel" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#fff" stop-opacity="0.2" />
        <stop offset="1" stop-color="#fff" stop-opacity="0.05" />
      </linearGradient>
      <linearGradient id="listado-oro" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#e4cc9a" stop-opacity="0.5" />
        <stop offset="1" stop-color="#e4cc9a" stop-opacity="0.12" />
      </linearGradient>
    </defs>

    <!-- Fichero con sus tarjetas -->
    <g stroke-linejoin="round" stroke-linecap="round">
      <g v-for="t in TARJETAS" :key="t.y">
        <rect :x="t.tab" :y="t.y - 6" width="18" height="8" rx="2" :fill="t.dorada ? 'url(#listado-oro)' : 'url(#listado-papel)'" :stroke="t.dorada ? DORADO : BLANCO" />
        <rect x="28" :y="t.y" width="94" height="40" rx="2.500" fill="#3F32B8" fill-opacity="0.75" />
        <rect x="28" :y="t.y" width="94" height="40" rx="2.500" fill="url(#listado-papel)" :stroke="BLANCO" />
        <path :d="`M36 ${t.y + 6}h46`" :stroke="TENUE" />
      </g>
      <rect x="20" y="58" width="110" height="36" rx="4" fill="#4338CA" fill-opacity="0.75" />
      <rect x="20" y="58" width="110" height="36" rx="4" fill="url(#listado-papel)" :stroke="BLANCO" stroke-width="1.300" />
      <rect x="55" y="65" width="40" height="13" rx="1.500" fill="url(#listado-oro)" :stroke="DORADO" />
      <path d="M61 71.500h28" :stroke="DORADO" />
      <path d="M66 84c0 4 18 4 18 0" :stroke="BLANCO" stroke-width="2" />
    </g>

    <!-- Libro de registro -->
    <g stroke-linejoin="round" stroke-linecap="round">
      <path d="M222 24c-18-9-46-10-68-5v70c22-5 50-4 68 5z" fill="url(#listado-papel)" :stroke="BLANCO" />
      <path d="M222 24c18-9 46-10 68-5v70c-22-5-50-4-68 5z" fill="url(#listado-papel)" :stroke="BLANCO" />
      <path d="M222 24v70" :stroke="BLANCO" />
      <g v-for="(color, i) in IZQUIERDA" :key="`i${i}`">
        <circle cx="166" :cy="filaY(i) - 1" r="2.600" :fill="color" />
        <path :d="`M174 ${filaY(i) - 1}h34`" :stroke="TENUE" />
      </g>
      <g v-for="(color, i) in DERECHA" :key="`d${i}`">
        <path :d="`M236 ${filaY(i) - 1}h34`" :stroke="TENUE" />
        <circle cx="278" :cy="filaY(i) - 1" r="2.600" :fill="color" />
      </g>
      <path d="M254 18V6l5 4 5-4v12" fill="url(#listado-oro)" :stroke="DORADO" />
    </g>

    <!-- Reloj de arena del plazo -->
    <g stroke-linejoin="round" stroke-linecap="round">
      <path d="M322 27c0 17 18 21 18 30s-18 13-18 30h40c0-17-18-21-18-30s18-13 18-30z" fill="url(#listado-papel)" :stroke="BLANCO" />
      <path d="M329 38c2 8 11 12 13 17 2-5 11-9 13-17z" fill="url(#listado-oro)" :stroke="DORADO" />
      <path d="M342 57v21" :stroke="DORADO" stroke-dasharray="2 2.500" />
      <path d="M328 87c3-8 10-11 14-11s11 3 14 11z" fill="url(#listado-oro)" :stroke="DORADO" />
      <rect x="315" y="20" width="54" height="7" rx="2.500" fill="url(#listado-papel)" :stroke="BLANCO" />
      <rect x="315" y="87" width="54" height="7" rx="2.500" fill="url(#listado-papel)" :stroke="BLANCO" />
    </g>

    <rect x="8" y="94" width="392" height="2" fill="rgb(255 255 255 / 0.4)" />
  </svg>
</template>
