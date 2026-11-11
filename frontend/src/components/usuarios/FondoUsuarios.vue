<script setup lang="ts">
/** Motivo de la caja de título de Usuarios: credenciales de biblioteca, la del frente colgando de su cinta. */
const BLANCO = 'rgb(255 255 255 / 0.42)'
const TENUE = 'rgb(255 255 255 / 0.26)'
const DORADO = 'rgb(228 204 154 / 0.85)'
const MENTA = 'rgb(167 243 208 / 0.95)'
const BARRAS = [134, 137, 139, 143, 146, 148, 152, 155, 157, 161, 164]
const CARTAS = [
  { x: 14, y: 30, giro: -10, centro: '60 60', dorada: false },
  { x: 172, y: 26, giro: 9, centro: '218 56', dorada: true },
]
</script>

<template>
  <svg
    class="pointer-events-none absolute bottom-0 right-[19.5rem] hidden h-full w-[17.5rem] xl:block"
    style="-webkit-mask-image: linear-gradient(to right, transparent, black 14%); mask-image: linear-gradient(to right, transparent, black 14%);"
    viewBox="0 0 280 102"
    preserveAspectRatio="xMaxYMax meet"
    fill="none"
    aria-hidden="true"
  >
    <defs>
      <linearGradient id="usuarios-papel" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#fff" stop-opacity="0.2" />
        <stop offset="1" stop-color="#fff" stop-opacity="0.06" />
      </linearGradient>
      <linearGradient id="usuarios-oro" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#e4cc9a" stop-opacity="0.5" />
        <stop offset="1" stop-color="#e4cc9a" stop-opacity="0.16" />
      </linearGradient>
    </defs>

    <!-- Credenciales de atrás -->
    <g v-for="c in CARTAS" :key="c.x" :transform="`rotate(${c.giro} ${c.centro})`" stroke-linecap="round" stroke-linejoin="round">
      <rect :x="c.x" :y="c.y" width="92" height="60" rx="6" fill="url(#usuarios-papel)" :stroke="BLANCO" />
      <circle :cx="c.x + 20" :cy="c.y + 22" r="7" :stroke="BLANCO" />
      <path :d="`M${c.x + 8} ${c.y + 48}c1-9 7-13 12-13s11 4 12 13`" :stroke="BLANCO" />
      <path :d="`M${c.x + 42} ${c.y + 18}h40M${c.x + 42} ${c.y + 29}h34`" :stroke="TENUE" />
      <rect :x="c.x + 42" :y="c.y + 40" width="30" height="7" rx="3.500" :fill="c.dorada ? 'url(#usuarios-oro)' : 'rgb(255 255 255 / 0.14)'" :stroke="c.dorada ? DORADO : TENUE" />
    </g>

    <!-- Cinta y credencial del frente -->
    <g stroke-linecap="round" stroke-linejoin="round">
      <path d="M124-2l16 26M160-2l-16 26" :stroke="DORADO" stroke-width="3" />
      <rect x="134" y="21" width="16" height="11" rx="2.500" fill="url(#usuarios-oro)" :stroke="DORADO" />
      <rect x="88" y="30" width="108" height="68" rx="7" fill="#4338CA" fill-opacity="0.55" />
      <rect x="88" y="30" width="108" height="68" rx="7" fill="url(#usuarios-papel)" :stroke="BLANCO" stroke-width="1.300" />
      <path d="M88 44V37a7 7 0 0 1 7-7h94a7 7 0 0 1 7 7v7z" fill="url(#usuarios-oro)" :stroke="DORADO" />
      <text x="142" y="40.500" text-anchor="middle" font-size="7.500" font-weight="800" letter-spacing="1.600" :fill="DORADO" stroke="none" style="font-family: ui-sans-serif, system-ui, sans-serif">BIBLIOTECA</text>
      <rect x="96" y="50" width="28" height="40" rx="3" fill="rgb(255 255 255 / 0.1)" :stroke="BLANCO" />
      <circle cx="110" cy="63" r="6" :stroke="BLANCO" />
      <path d="M100 86c1-8 5-12 10-12s9 4 10 12" :stroke="BLANCO" />
      <path d="M132 53h56M132 62h40" :stroke="TENUE" />
      <path v-for="x in BARRAS" :key="x" :d="`M${x} 72v18`" :stroke="BLANCO" />
      <circle cx="182" cy="82" r="8" fill="rgb(52 211 153 / 0.16)" :stroke="MENTA" stroke-width="1.400" />
      <path d="m178 82 3 3 5-6" :stroke="MENTA" stroke-width="1.600" />
    </g>
  </svg>
</template>
