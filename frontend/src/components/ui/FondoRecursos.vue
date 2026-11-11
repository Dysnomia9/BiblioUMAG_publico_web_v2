<script setup lang="ts">
/**
 * Motivo de la caja de título de Recursos de apoyo: lo que se presta, sobre una repisa.
 * Notebook conectado a su cargador, audífonos en su soporte y la bicicleta con su canasto.
 */
const BLANCO = 'rgb(255 255 255 / 0.45)'
const TENUE = 'rgb(255 255 255 / 0.28)'
const DORADO = 'rgb(228 204 154 / 0.88)'

const RUEDAS = [232, 294]
const RAYOS = [0, 45, 90, 135]
</script>

<template>
  <svg
    class="pointer-events-none absolute bottom-0 right-[12.5rem] hidden h-full w-[19.75rem] xl:block"
    style="-webkit-mask-image: linear-gradient(to right, transparent, black 10%); mask-image: linear-gradient(to right, transparent, black 10%);"
    viewBox="0 0 316 102"
    preserveAspectRatio="xMaxYMax meet"
    fill="none"
    aria-hidden="true"
  >
    <defs>
      <linearGradient id="recursos-cuerpo" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#fff" stop-opacity="0.22" />
        <stop offset="1" stop-color="#fff" stop-opacity="0.06" />
      </linearGradient>
      <linearGradient id="recursos-oro" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#e4cc9a" stop-opacity="0.5" />
        <stop offset="1" stop-color="#e4cc9a" stop-opacity="0.14" />
      </linearGradient>
    </defs>

    <g stroke-linecap="round" stroke-linejoin="round">
      <!-- Notebook -->
      <rect x="20" y="32" width="78" height="56" rx="5" fill="url(#recursos-cuerpo)" :stroke="BLANCO" stroke-width="1.300" />
      <rect x="26" y="38" width="66" height="44" rx="2" fill="rgb(255 255 255 / 0.07)" :stroke="TENUE" />
      <rect x="32" y="45" width="20" height="30" rx="2" fill="url(#recursos-oro)" :stroke="DORADO" />
      <path d="M36 45v30" :stroke="DORADO" />
      <path d="M58 48h28M58 57h28M58 66h18" :stroke="TENUE" />
      <path d="M8 88h102l-6 10H14z" fill="url(#recursos-cuerpo)" :stroke="BLANCO" stroke-width="1.300" />
      <path d="M50 92h18" :stroke="BLANCO" stroke-width="1.600" />

      <!-- Cargador y su cable -->
      <path d="M107 91c8 0 8-14 15-14" :stroke="DORADO" stroke-width="1.500" />
      <rect x="122" y="68" width="24" height="30" rx="4" fill="url(#recursos-cuerpo)" :stroke="BLANCO" stroke-width="1.300" />
      <path d="m136 73-7 11h5l-2 9 7-11h-5z" fill="url(#recursos-oro)" :stroke="DORADO" />
      <path d="M130 68v-6M138 68v-6" :stroke="BLANCO" stroke-width="2" />

      <!-- Audífonos en su soporte -->
      <path d="M182 42v54" :stroke="BLANCO" stroke-width="1.500" />
      <path d="M168 98c0-4 6-5 14-5s14 1 14 5z" fill="url(#recursos-cuerpo)" :stroke="BLANCO" />
      <path d="M161 66v-3a21 21 0 0 1 42 0v3" :stroke="BLANCO" stroke-width="2.200" />
      <rect x="155" y="62" width="12" height="25" rx="5" fill="url(#recursos-oro)" :stroke="DORADO" />
      <rect x="197" y="62" width="12" height="25" rx="5" fill="url(#recursos-oro)" :stroke="DORADO" />

      <!-- Bicicleta -->
      <g v-for="x in RUEDAS" :key="x">
        <circle :cx="x" cy="80" r="18" fill="rgb(255 255 255 / 0.04)" :stroke="BLANCO" stroke-width="1.400" />
        <path v-for="g in RAYOS" :key="g" :d="`M${x - 16} 80h32`" :transform="`rotate(${g} ${x} 80)`" :stroke="TENUE" stroke-width="0.800" />
        <circle :cx="x" cy="80" r="2.500" fill="#4338CA" :stroke="BLANCO" />
      </g>
      <path d="M232 80h27l-8-27M251 53h31M282 53l-23 27M282 53l12 27M251 53l-19 27" :stroke="BLANCO" stroke-width="1.700" />
      <path d="M244 51h15" :stroke="DORADO" stroke-width="3" />
      <path d="M282 53l-3-10h11" :stroke="BLANCO" stroke-width="1.700" />
      <circle cx="259" cy="80" r="4.500" fill="url(#recursos-oro)" :stroke="DORADO" />
      <path d="M286 44h20l-2 13h-16z" fill="url(#recursos-oro)" :stroke="DORADO" />
      <path d="M291 44v-7h5v7M297 44v-5h4v5" :stroke="BLANCO" />
    </g>

    <rect x="4" y="98" width="312" height="2" fill="rgb(255 255 255 / 0.42)" />
  </svg>
</template>
