<script setup lang="ts">
/**
 * Cabecera de los cuadros con degradado (Modal destacado + degradado): una etiqueta
 * pequeña con ícono, el título en serif, la descripción y, si hay, los pasos del
 * formulario como fichas que llevan a cada sección.
 */
withDefaults(
  defineProps<{
    etiqueta: string
    titulo: string
    descripcion?: string
    /** Trazo (atributo d) del ícono de la etiqueta. */
    icono?: string
    pasos?: string[]
    pasoActivo?: number
    bloqueado?: boolean
  }>(),
  { icono: 'M15.750 6a3.750 3.750 0 11-7.500 0 3.750 3.750 0 017.500 0zM4.501 20.118a7.500 7.500 0 0114.998 0A17.933 17.933 0 0112 21.750c-2.676 0-5.216-.584-7.499-1.632z', pasos: () => [], pasoActivo: 0, bloqueado: false },
)

const emit = defineEmits<{ cerrar: []; irAPaso: [indice: number] }>()
</script>

<template>
  <!-- La credencial tenue a la derecha, como en el cuadro de visitantes externos -->
  <svg class="pointer-events-none absolute right-12 top-2 hidden h-16 w-24 rotate-[-8deg] sm:block" viewBox="0 0 96 64" fill="none" stroke="rgb(255 255 255 / 0.18)" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">
    <rect x="4" y="4" width="88" height="56" rx="7" fill="rgb(255 255 255 / 0.07)" />
    <circle cx="28" cy="26" r="8" />
    <path d="M15 52c1-9 7-13 13-13s12 4 13 13M50 24h30M50 35h24" />
  </svg>
  <div class="relative flex items-start justify-between gap-3">
    <span class="inline-flex items-center gap-1.5 rounded-full bg-blanco/15 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-[0.14em] text-white/90">
      <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="icono" /></svg>
      {{ etiqueta }}
    </span>
    <button
      type="button"
      class="-mr-1 -mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-white/30 text-white transition-colors hover:bg-blanco/15 disabled:opacity-50"
      aria-label="Cerrar"
      :disabled="bloqueado"
      @click="emit('cerrar')"
    >
      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
    </button>
  </div>
  <h3 class="relative mt-1.5 font-serif text-lg font-bold leading-tight text-white">{{ titulo }}</h3>
  <p v-if="descripcion" class="relative mt-0.5 max-w-md text-xs text-white/80">{{ descripcion }}</p>
  <div v-if="pasos.length" class="relative mt-2.5 flex flex-wrap gap-1.5">
    <button
      v-for="(paso, i) in pasos"
      :key="paso"
      type="button"
      class="inline-flex items-center gap-1.5 rounded-full py-0.5 pl-0.5 pr-2.5 text-[11px] font-semibold transition-colors"
      :class="i === pasoActivo ? 'bg-blanco/25 text-white' : 'bg-blanco/10 text-white/80 hover:bg-blanco/20'"
      @click="emit('irAPaso', i)"
    >
      <span class="flex h-4 w-4 items-center justify-center rounded-full text-[10px] font-bold" :class="i === pasoActivo ? 'bg-blanco text-indigo-700' : 'bg-blanco/20 text-white'">{{ i + 1 }}</span>
      {{ paso }}
    </button>
  </div>
</template>
