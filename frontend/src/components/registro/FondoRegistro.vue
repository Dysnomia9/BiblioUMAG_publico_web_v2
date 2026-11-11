<script setup lang="ts">
/** Motivo de la caja de título del Registro absoluto: un libro de registro abierto y una línea de tiempo con sus hitos. */
const BLANCO = 'rgb(255 255 255 / 0.42)'
const TENUE = 'rgb(255 255 255 / 0.26)'
const DORADO = 'rgb(228 204 154 / 0.85)'
const RENGLONES = [30, 42, 54, 66, 78]
const HITOS = [{ y: 24, largo: 40 }, { y: 54, largo: 30, dorado: true }, { y: 84, largo: 36 }]
</script>

<template>
  <svg
    class="pointer-events-none absolute bottom-0 right-10 hidden h-full w-[16rem] xl:block"
    style="-webkit-mask-image: linear-gradient(to right, transparent, black 16%); mask-image: linear-gradient(to right, transparent, black 16%);"
    viewBox="0 0 256 102"
    preserveAspectRatio="xMaxYMax meet"
    fill="none"
    aria-hidden="true"
  >
    <defs>
      <linearGradient id="registro-papel" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#fff" stop-opacity="0.2" />
        <stop offset="1" stop-color="#fff" stop-opacity="0.06" />
      </linearGradient>
    </defs>

    <!-- Libro de registro abierto -->
    <g stroke-linecap="round" stroke-linejoin="round">
      <path d="M20 16c18-4 38-3 58 4v80c-20-7-40-8-58-4z" fill="url(#registro-papel)" :stroke="BLANCO" stroke-width="1.300" />
      <path d="M136 16c-18-4-38-3-58 4v80c20-7 40-8 58-4z" fill="url(#registro-papel)" :stroke="BLANCO" stroke-width="1.300" />
      <g v-for="y in RENGLONES" :key="y">
        <circle cx="28" :cy="y" r="1.3" :fill="TENUE" />
        <path :d="`M33 ${y}h37`" :stroke="TENUE" />
        <path :d="`M87 ${y}h${y === 54 ? 26 : 40}`" :stroke="y === 54 ? DORADO : TENUE" />
      </g>
      <path d="M78 20v80" :stroke="BLANCO" />
    </g>

    <!-- Línea de tiempo: cada hito, una acción registrada -->
    <g stroke-linecap="round">
      <path d="M176 6v96" :stroke="TENUE" stroke-dasharray="2 4" />
      <g v-for="h in HITOS" :key="h.y">
        <circle cx="176" :cy="h.y" r="5.5" :fill="h.dorado ? 'rgb(228 204 154 / 0.3)' : 'rgb(255 255 255 / 0.1)'" :stroke="h.dorado ? DORADO : BLANCO" stroke-width="1.300" />
        <path :d="`M190 ${h.y}h${h.largo}`" :stroke="h.dorado ? DORADO : BLANCO" />
        <path :d="`M190 ${h.y + 8}h${h.largo - 14}`" :stroke="TENUE" />
      </g>
    </g>
  </svg>
</template>
