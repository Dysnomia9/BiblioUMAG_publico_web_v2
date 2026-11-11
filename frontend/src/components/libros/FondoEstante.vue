<script setup lang="ts">
/**
 * Motivo de la caja de título del catálogo: una estantería en trazo tenue que se apoya
 * en el borde inferior de la franja morada y se desvanece hacia la izquierda.
 * `conAcciones` la corre a la izquierda de los botones de la derecha.
 */
defineProps<{ conAcciones?: boolean }>()

const BASE = 96
type Lomo = { x: number; ancho: number; alto: number; dorado?: boolean; bandas?: boolean; rotulo?: boolean; inclinado?: boolean }
const LOMOS: Lomo[] = [
  { x: 40, ancho: 14, alto: 56 },
  { x: 56, ancho: 18, alto: 70, bandas: true },
  { x: 76, ancho: 12, alto: 62 },
  { x: 90, ancho: 20, alto: 82, rotulo: true },
  { x: 112, ancho: 14, alto: 60, dorado: true },
  { x: 130, ancho: 13, alto: 72, inclinado: true },
  { x: 158, ancho: 16, alto: 76, bandas: true },
  { x: 176, ancho: 22, alto: 88, dorado: true, bandas: true, rotulo: true },
  { x: 200, ancho: 13, alto: 64 },
  { x: 215, ancho: 18, alto: 80, bandas: true },
  { x: 235, ancho: 12, alto: 58 },
  { x: 249, ancho: 20, alto: 84, rotulo: true },
  { x: 271, ancho: 14, alto: 68, dorado: true, bandas: true },
  { x: 287, ancho: 17, alto: 74 },
  { x: 306, ancho: 12, alto: 60, bandas: true },
  { x: 320, ancho: 22, alto: 86, rotulo: true, bandas: true },
  { x: 344, ancho: 15, alto: 66 },
  { x: 361, ancho: 18, alto: 78, dorado: true },
  { x: 381, ancho: 13, alto: 62, bandas: true },
  { x: 486, ancho: 16, alto: 72, bandas: true },
  { x: 504, ancho: 20, alto: 84, dorado: true, rotulo: true },
  { x: 526, ancho: 14, alto: 64 },
]
const PILA = [
  { x: 402, ancho: 74, alto: 12, y: BASE - 12 },
  { x: 408, ancho: 62, alto: 10, y: BASE - 22, dorado: true },
  { x: 404, ancho: 68, alto: 11, y: BASE - 33 },
]
</script>

<template>
  <div class="pointer-events-none absolute inset-0 overflow-hidden rounded-t-2xl" aria-hidden="true">
    <svg
      class="absolute bottom-0 hidden h-[6rem] max-w-[35rem] lg:block"
      :class="conAcciones ? 'right-[18rem] w-[30%]' : 'right-6 w-[46%]'"
      style="-webkit-mask-image: linear-gradient(to right, transparent, black 30%); mask-image: linear-gradient(to right, transparent, black 30%);"
      viewBox="0 0 560 98"
      preserveAspectRatio="xMaxYMax slice"
    >
      <defs>
        <linearGradient id="estante-lomo" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="#fff" stop-opacity="0.2" />
          <stop offset="1" stop-color="#fff" stop-opacity="0.04" />
        </linearGradient>
        <linearGradient id="estante-dorado" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="#e4cc9a" stop-opacity="0.38" />
          <stop offset="1" stop-color="#e4cc9a" stop-opacity="0.07" />
        </linearGradient>
      </defs>

      <g v-for="l in LOMOS" :key="l.x" :transform="l.inclinado ? `rotate(10 ${l.x} ${BASE})` : undefined">
        <rect
          :x="l.x"
          :y="BASE - l.alto"
          :width="l.ancho"
          :height="l.alto"
          rx="2"
          :fill="l.dorado ? 'url(#estante-dorado)' : 'url(#estante-lomo)'"
          :stroke="l.dorado ? 'rgb(228 204 154 / 0.75)' : 'rgb(255 255 255 / 0.36)'"
        />
        <template v-if="l.bandas">
          <line :x1="l.x + 1" :x2="l.x + l.ancho - 1" :y1="BASE - l.alto + 8" :y2="BASE - l.alto + 8" :stroke="l.dorado ? 'rgb(228 204 154 / 0.7)' : 'rgb(255 255 255 / 0.3)'" />
          <line :x1="l.x + 1" :x2="l.x + l.ancho - 1" :y1="BASE - 9" :y2="BASE - 9" :stroke="l.dorado ? 'rgb(228 204 154 / 0.7)' : 'rgb(255 255 255 / 0.3)'" />
        </template>
        <rect v-if="l.rotulo" :x="l.x + 4" :y="BASE - l.alto * 0.68" :width="l.ancho - 8" height="11" rx="1.5" fill="rgb(228 204 154 / 0.6)" />
      </g>

      <rect
        v-for="p in PILA"
        :key="p.y"
        :x="p.x"
        :y="p.y"
        :width="p.ancho"
        :height="p.alto"
        rx="2"
        :fill="p.dorado ? 'url(#estante-dorado)' : 'url(#estante-lomo)'"
        :stroke="p.dorado ? 'rgb(228 204 154 / 0.75)' : 'rgb(255 255 255 / 0.36)'"
      />
      <!-- La planta sobre la pila de libros -->
      <path d="M429 63h20l-2.5-13h-15z" fill="url(#estante-lomo)" stroke="rgb(255 255 255 / 0.4)" stroke-linejoin="round" />
      <g fill="none" stroke="rgb(190 235 215 / 0.6)" stroke-linecap="round">
        <path d="M439 50c0-10-1-18-7-27 7 5 10 16 7 27z" />
        <path d="M439 50c1-8 5-15 13-20-2 9-6 16-13 20z" />
        <path d="M438 50c-3-6-8-10-15-12 3 7 8 11 15 12z" />
      </g>

      <rect x="20" y="96" width="540" height="2" fill="rgb(255 255 255 / 0.4)" />
    </svg>
  </div>
</template>
