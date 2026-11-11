<script setup lang="ts">
/** Motivo de la caja de título de Reportes: columnas apoyadas en el borde de la franja y su tendencia. */
const BASE = 96
const COLUMNAS = [
  { x: 12, alto: 26 },
  { x: 40, alto: 40 },
  { x: 68, alto: 32 },
  { x: 96, alto: 52 },
  { x: 124, alto: 44 },
  { x: 152, alto: 62 },
  { x: 180, alto: 74, dorada: true },
  { x: 208, alto: 56 },
]
const ANCHO = 20
const PUNTOS = COLUMNAS.map((c) => ({ x: c.x + ANCHO / 2, y: BASE - c.alto - 10 }))
const TENDENCIA = `M${PUNTOS.map((p) => `${p.x} ${p.y}`).join('L')}`
</script>

<template>
  <div class="pointer-events-none absolute inset-0 overflow-hidden rounded-t-2xl" aria-hidden="true">
    <svg
      class="absolute bottom-0 right-[20rem] hidden h-[6rem] w-[15rem] xl:block"
      style="-webkit-mask-image: linear-gradient(to right, transparent, black 25%); mask-image: linear-gradient(to right, transparent, black 25%);"
      viewBox="0 0 240 98"
      fill="none"
    >
      <defs>
        <linearGradient id="reportes-columna" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="#fff" stop-opacity="0.22" />
          <stop offset="1" stop-color="#fff" stop-opacity="0.04" />
        </linearGradient>
        <linearGradient id="reportes-dorada" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="#e4cc9a" stop-opacity="0.45" />
          <stop offset="1" stop-color="#e4cc9a" stop-opacity="0.08" />
        </linearGradient>
      </defs>

      <rect
        v-for="c in COLUMNAS"
        :key="c.x"
        :x="c.x"
        :y="BASE - c.alto"
        :width="ANCHO"
        :height="c.alto"
        rx="3"
        :fill="c.dorada ? 'url(#reportes-dorada)' : 'url(#reportes-columna)'"
        :stroke="c.dorada ? 'rgb(228 204 154 / 0.8)' : 'rgb(255 255 255 / 0.36)'"
      />

      <path :d="TENDENCIA" stroke="rgb(228 204 154 / 0.8)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
      <circle v-for="p in PUNTOS" :key="p.x" :cx="p.x" :cy="p.y" r="2.6" fill="#4338CA" stroke="rgb(228 204 154 / 0.95)" stroke-width="1.4" />

      <rect y="96" width="240" height="2" fill="rgb(255 255 255 / 0.4)" />
    </svg>
  </div>
</template>
