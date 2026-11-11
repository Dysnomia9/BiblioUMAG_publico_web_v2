<script setup lang="ts">
/**
 * Motivo de la caja de título de Listado de reservas: el pasillo de salas, una con la puerta
 * abierta y su luz, el tablero con las llaves numeradas y el reloj del horario.
 */
const BLANCO = 'rgb(255 255 255 / 0.42)'
const TENUE = 'rgb(255 255 255 / 0.28)'
const DORADO = 'rgb(228 204 154 / 0.88)'
const PUERTAS = [16, 80]
const LLAVES = [
  { x: 236, dorada: false },
  { x: 258, dorada: true },
  { x: 280, dorada: false },
  { x: 302, dorada: true },
]
const MARCAS = [0, 30, 60, 90, 120, 150, 180, 210, 240, 270, 300, 330]
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
      <linearGradient id="reservas-puerta" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#fff" stop-opacity="0.2" />
        <stop offset="1" stop-color="#fff" stop-opacity="0.05" />
      </linearGradient>
      <linearGradient id="reservas-oro" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#e4cc9a" stop-opacity="0.48" />
        <stop offset="1" stop-color="#e4cc9a" stop-opacity="0.08" />
      </linearGradient>
    </defs>

    <g stroke-linejoin="round" stroke-linecap="round">
      <!-- Puertas cerradas -->
      <g v-for="x in PUERTAS" :key="x">
        <rect :x="x" y="14" width="54" height="80" rx="3" fill="url(#reservas-puerta)" :stroke="BLANCO" />
        <rect :x="x + 12" y="23" width="30" height="10" rx="2" fill="url(#reservas-oro)" :stroke="DORADO" />
        <rect :x="x + 10" y="42" width="34" height="42" rx="1.500" :stroke="TENUE" />
        <circle :cx="x + 47" cy="60" r="2.200" :fill="DORADO" />
      </g>

      <!-- Sala con la puerta abierta y su luz -->
      <rect x="144" y="14" width="56" height="80" rx="3" fill="url(#reservas-oro)" :stroke="DORADO" />
      <path d="M152 94l6-22h28l6 22" :stroke="DORADO" stroke-opacity="0.55" />
      <rect x="160" y="60" width="24" height="12" rx="2" fill="rgb(228 204 154 / 0.22)" :stroke="DORADO" />
      <path d="M144 14l-18 8v80l18-8z" fill="url(#reservas-puerta)" :stroke="BLANCO" />
      <circle cx="131" cy="60" r="2" :fill="DORADO" />

      <!-- Tablero de llaves -->
      <rect x="226" y="24" width="96" height="66" rx="5" fill="url(#reservas-puerta)" :stroke="BLANCO" />
      <path d="M234 33h80" :stroke="TENUE" stroke-dasharray="3 3" />
      <g v-for="l in LLAVES" :key="l.x" :stroke="l.dorada ? DORADO : BLANCO" stroke-width="1.400">
        <circle :cx="l.x + 5" cy="39" r="1.600" />
        <path :d="`M${l.x + 5} 41v5`" />
        <circle :cx="l.x + 5" cy="51" r="5" :fill="l.dorada ? 'url(#reservas-oro)' : 'rgb(255 255 255 / 0.08)'" />
        <path :d="`M${l.x + 5} 56v23m0-5h4m-4-5h3`" />
      </g>

      <!-- Reloj del horario -->
      <circle cx="362" cy="44" r="27" fill="url(#reservas-puerta)" :stroke="BLANCO" stroke-width="1.400" />
      <path v-for="g in MARCAS" :key="g" d="M362 20v4" :stroke="g % 90 === 0 ? BLANCO : TENUE" :transform="`rotate(${g} 362 44)`" />
      <path d="M362 44V29" :stroke="BLANCO" stroke-width="1.800" />
      <path d="M362 44l11 7" :stroke="DORADO" stroke-width="1.800" />
      <circle cx="362" cy="44" r="2.400" :fill="DORADO" />
      <path d="M362 71v23M350 94h24" :stroke="BLANCO" stroke-width="1.400" />
    </g>

    <rect x="4" y="94" width="396" height="2" fill="rgb(255 255 255 / 0.42)" />
  </svg>
</template>
