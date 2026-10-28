<script setup lang="ts">
import { useTema } from '@/composables/useTema'

/**
 * Interruptor día / noche de la barra superior (personal y portal), a la izquierda de la
 * burbuja de la cuenta. Va sobre el degradado morado, o con `en-pagina` sobre un fondo
 * claro (los logins); la perilla muestra el modo actual.
 */
defineProps<{ enPagina?: boolean }>()

const { oscuro, alternar } = useTema()
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="oscuro"
    aria-label="Modo noche"
    :title="oscuro ? 'Cambiar a modo día' : 'Cambiar a modo noche'"
    class="relative flex h-8 w-14 shrink-0 items-center rounded-full border p-0.5 transition-colors"
    :class="enPagina ? 'border-gray-200 bg-white/70 backdrop-blur-sm hover:border-indigo-300' : 'border-white/20 bg-blanco/10 hover:bg-blanco/15'"
    @click="alternar"
  >
    <svg class="absolute left-[7px] h-4 w-4" :class="enPagina ? 'text-gray-400' : 'text-white/60'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1.5M12 19.5V21M4.22 4.22l1.06 1.06M18.72 18.72l1.06 1.06M3 12h1.5M19.5 12H21M4.22 19.78l1.06-1.06M18.72 5.28l1.06-1.06M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
    </svg>
    <svg class="absolute right-[7px] h-4 w-4" :class="enPagina ? 'text-gray-400' : 'text-white/60'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
    </svg>
    <span
      class="relative flex h-6 w-6 items-center justify-center rounded-full bg-blanco shadow transition-transform"
      :class="oscuro ? 'translate-x-6' : 'translate-x-0'"
      aria-hidden="true"
    >
      <svg v-if="oscuro" class="h-3.5 w-3.5 text-[#3730a3]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
      </svg>
      <svg v-else class="h-4 w-4 text-[#b08d57]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1.5M12 19.5V21M4.22 4.22l1.06 1.06M18.72 18.72l1.06 1.06M3 12h1.5M19.5 12H21M4.22 19.78l1.06-1.06M18.72 5.28l1.06-1.06M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
      </svg>
    </span>
  </button>
</template>
