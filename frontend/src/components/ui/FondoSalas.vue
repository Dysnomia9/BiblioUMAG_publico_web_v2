<template>
  <!-- Motivo de Salas: el plano de unas salas de estudio, con una ocupada en dorado. -->
  <svg
    class="pointer-events-none absolute right-48 top-1/2 hidden h-[5.5rem] w-[18rem] -translate-y-1/2 xl:block"
    style="-webkit-mask-image: linear-gradient(to right, transparent, black 30%); mask-image: linear-gradient(to right, transparent, black 30%);"
    viewBox="0 0 288 88"
    fill="none"
    aria-hidden="true"
  >
    <!-- Muros -->
    <path d="M14 8h260v72H14z" fill="rgb(255 255 255 / 0.05)" stroke="rgb(255 255 255 / 0.38)" stroke-width="1.6" stroke-linejoin="round" />
    <path d="M100 8v26M100 54v26M187 8v26M187 54v26" stroke="rgb(255 255 255 / 0.38)" stroke-width="1.6" stroke-linecap="round" />

    <!-- Sala ocupada -->
    <rect x="101" y="9" width="85" height="70" fill="rgb(228 204 154 / 0.13)" />

    <!-- Puertas -->
    <g stroke="rgb(255 255 255 / 0.3)" stroke-linecap="round">
      <path d="M100 34c11 0 20 9 20 20M100 34v20" />
      <path d="M187 34c-11 0-20 9-20 20M187 34v20" />
    </g>

    <!-- Mesas y sillas -->
    <g stroke="rgb(255 255 255 / 0.42)">
      <rect x="40" y="33" width="34" height="22" rx="4" fill="rgb(255 255 255 / 0.1)" />
      <circle cx="49" cy="25" r="4" /><circle cx="65" cy="25" r="4" /><circle cx="49" cy="63" r="4" /><circle cx="65" cy="63" r="4" />
      <rect x="213" y="33" width="34" height="22" rx="4" fill="rgb(255 255 255 / 0.1)" />
      <circle cx="222" cy="25" r="4" /><circle cx="238" cy="25" r="4" /><circle cx="222" cy="63" r="4" /><circle cx="238" cy="63" r="4" />
    </g>
    <g stroke="rgb(228 204 154 / 0.85)" stroke-width="1.4">
      <circle cx="143" cy="44" r="14" fill="rgb(228 204 154 / 0.16)" />
      <circle cx="143" cy="21" r="4" /><circle cx="143" cy="67" r="4" /><circle cx="120" cy="44" r="4" /><circle cx="166" cy="44" r="4" />
    </g>
  </svg>
</template>
