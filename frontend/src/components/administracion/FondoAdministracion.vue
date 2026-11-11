<script setup lang="ts">
/**
 * Motivo de la caja de título de Administración, una figura por pestaña: el calendario de la
 * vigencia, el fichero de los catálogos, el frontis de la universidad con su bandera, la
 * constancia firmada con su sello, el engranaje de la configuración y el plano de una sala.
 */
const BLANCO = 'rgb(255 255 255 / 0.42)'
const TENUE = 'rgb(255 255 255 / 0.26)'
const DORADO = 'rgb(228 204 154 / 0.85)'
const COLUMNAS = [162, 176, 204, 218]
const FICHAS = [
  { y: 46, x: 68, dorada: false },
  { y: 40, x: 74, dorada: true },
  { y: 34, x: 80, dorada: false },
]
// Días del calendario: [columna, fila]; el dorado es el día que vence.
const DIAS = [0, 1, 2, 3, 4].flatMap((c) => [0, 1, 2].map((f) => [c, f] as const))
const DIA_DORADO = '3-1'
const DIENTES = [0, 45, 90, 135, 180, 225, 270, 315]
</script>

<template>
  <svg
    class="pointer-events-none absolute bottom-0 right-6 hidden h-full w-[30rem] xl:block"
    style="-webkit-mask-image: linear-gradient(to right, transparent, black 16%); mask-image: linear-gradient(to right, transparent, black 16%);"
    viewBox="0 0 480 102"
    preserveAspectRatio="xMaxYMax meet"
    fill="none"
    aria-hidden="true"
  >
    <defs>
      <linearGradient id="admin-papel" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#fff" stop-opacity="0.2" />
        <stop offset="1" stop-color="#fff" stop-opacity="0.06" />
      </linearGradient>
      <linearGradient id="admin-oro" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#e4cc9a" stop-opacity="0.5" />
        <stop offset="1" stop-color="#e4cc9a" stop-opacity="0.16" />
      </linearGradient>
    </defs>

    <!-- Calendario de la vigencia, con el día de vencimiento en dorado -->
    <g stroke-linecap="round" stroke-linejoin="round" transform="rotate(-8 30 70)">
      <rect x="6" y="46" width="50" height="46" rx="4" fill="url(#admin-papel)" :stroke="BLANCO" />
      <path d="M6 58h50" :stroke="BLANCO" />
      <path d="M18 42v8M44 42v8" :stroke="BLANCO" stroke-width="1.6" />
      <rect
        v-for="[c, f] in DIAS"
        :key="`${c}-${f}`"
        :x="11 + c * 8.6"
        :y="63 + f * 8.6"
        width="5.5"
        height="5.5"
        rx="1"
        :fill="`${c}-${f}` === DIA_DORADO ? 'url(#admin-oro)' : 'rgb(255 255 255 / 0.12)'"
        :stroke="`${c}-${f}` === DIA_DORADO ? DORADO : 'none'"
      />
    </g>

    <!-- Fichero de catálogo: un cajón con fichas asomando, una con su pestaña dorada -->
    <g stroke-linecap="round" stroke-linejoin="round" transform="rotate(-6 100 80)">
      <rect v-for="f in FICHAS" :key="f.y" :x="f.x" :y="f.y" width="52" height="34" rx="3" fill="url(#admin-papel)" :stroke="f.dorada ? DORADO : TENUE" />
      <rect x="94" y="35" width="14" height="6" rx="1.5" fill="url(#admin-oro)" :stroke="DORADO" />
      <path d="M86 48h28M86 54h20" :stroke="TENUE" />
      <rect x="60" y="62" width="76" height="34" rx="4" fill="#4338CA" fill-opacity="0.55" />
      <rect x="60" y="62" width="76" height="34" rx="4" fill="url(#admin-papel)" :stroke="BLANCO" stroke-width="1.300" />
      <rect x="88" y="72" width="20" height="9" rx="2" :stroke="BLANCO" />
      <path d="M94 76.5h8" :stroke="DORADO" stroke-width="1.400" />
    </g>

    <!-- Frontis -->
    <g stroke-linecap="round" stroke-linejoin="round">
      <path d="M190 22V2" :stroke="BLANCO" stroke-width="1.300" />
      <path d="M190 3c5-2 9 2 14 0v9c-5 2-9-2-14 0z" fill="url(#admin-oro)" :stroke="DORADO" />
      <path d="M148 40l42-18 42 18z" fill="url(#admin-papel)" :stroke="BLANCO" stroke-width="1.300" />
      <circle cx="190" cy="33" r="4.5" fill="url(#admin-oro)" :stroke="DORADO" />
      <path d="M152 40h76v6h-76z" fill="rgb(255 255 255 / 0.1)" :stroke="BLANCO" />
      <g v-for="x in COLUMNAS" :key="x">
        <path :d="`M${x - 4} 49h8M${x - 4} 87h8`" :stroke="BLANCO" />
        <path :d="`M${x - 2.5} 49v38M${x + 2.5} 49v38`" :stroke="TENUE" />
      </g>
      <path d="M183 88V70a7 7 0 0 1 14 0v18z" fill="url(#admin-oro)" :stroke="DORADO" />
      <path d="M190 63v25" :stroke="DORADO" stroke-opacity="0.6" />
      <path d="M148 88h84v4h-84zM142 92h96v5h-96z" fill="url(#admin-papel)" :stroke="BLANCO" />
    </g>

    <!-- Constancia firmada, con el sello dorado y una pluma -->
    <g stroke-linecap="round" stroke-linejoin="round" transform="rotate(7 286 66)">
      <rect x="254" y="32" width="62" height="66" rx="4" fill="#4338CA" fill-opacity="0.55" />
      <rect x="254" y="32" width="62" height="66" rx="4" fill="url(#admin-papel)" :stroke="BLANCO" stroke-width="1.300" />
      <path d="M264 42h42" :stroke="BLANCO" />
      <path d="M264 51h42M264 58h36M264 65h40" :stroke="TENUE" />
      <path d="M266 84c4-6 7 3 11-3s6 2 10-1" :stroke="BLANCO" />
      <path d="M264 88h28" :stroke="TENUE" />
      <circle cx="302" cy="84" r="7" fill="url(#admin-oro)" :stroke="DORADO" />
      <path d="M299 90l-2 7 5-3 5 3-2-7" :stroke="DORADO" />
    </g>
    <path d="M246 30l18 30-2 4-3-2-17-31z" fill="rgb(255 255 255 / 0.12)" :stroke="BLANCO" stroke-linejoin="round" />
    <path d="M259 62l3 2" :stroke="DORADO" stroke-width="1.400" stroke-linecap="round" />

    <!-- Engranaje de la configuración, con uno chico dorado al lado -->
    <g stroke-linejoin="round">
      <rect v-for="a in DIENTES" :key="a" x="352" y="40" width="8" height="9" rx="1.5" fill="rgb(255 255 255 / 0.1)" :stroke="BLANCO" :transform="`rotate(${a} 356 62)`" />
      <circle cx="356" cy="62" r="15" fill="#4338CA" fill-opacity="0.55" />
      <circle cx="356" cy="62" r="15" fill="url(#admin-papel)" :stroke="BLANCO" stroke-width="1.300" />
      <circle cx="356" cy="62" r="5.5" :stroke="BLANCO" />
      <rect v-for="a in [0, 60, 120, 180, 240, 300]" :key="`c${a}`" x="377" y="79" width="5" height="5" rx="1" fill="url(#admin-oro)" :stroke="DORADO" :transform="`rotate(${a} 379.500 90)`" />
      <circle cx="379.500" cy="90" r="7" fill="url(#admin-oro)" :stroke="DORADO" />
      <circle cx="379.500" cy="90" r="2.500" :stroke="DORADO" />
    </g>

    <!-- Plano de una sala: muros, puerta y una mesa con sillas -->
    <g stroke-linecap="round" stroke-linejoin="round">
      <path d="M400 50h74v46h-74z" fill="url(#admin-papel)" :stroke="BLANCO" stroke-width="1.300" />
      <path d="M400 74c10 0 16 6 16 16M400 74v16" :stroke="TENUE" />
      <rect x="428" y="62" width="30" height="18" rx="3" fill="url(#admin-oro)" :stroke="DORADO" />
      <g :stroke="BLANCO">
        <circle cx="436" cy="56" r="3" /><circle cx="450" cy="56" r="3" />
        <circle cx="436" cy="86" r="3" /><circle cx="450" cy="86" r="3" />
      </g>
    </g>

    <!-- Repisa -->
    <path d="M4 99.300h472" stroke="rgb(255 255 255 / 0.2)" stroke-width="1.200" />
  </svg>
</template>
