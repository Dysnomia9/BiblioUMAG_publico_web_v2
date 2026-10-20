<script setup lang="ts">
import type { ModoDocumento } from '@/utils/documento'

/** «RUT | Pasaporte / DNI»: dice cómo se escribe y se valida el campo de identificación que acompaña. */
withDefaults(defineProps<{ compacto?: boolean; etiqueta?: string }>(), { compacto: false, etiqueta: 'Tipo de documento' })

const modo = defineModel<ModoDocumento>({ default: 'rut' })

const OPCIONES = [
  { id: 'rut', texto: 'RUT' },
  { id: 'extranjero', texto: 'Pasaporte / DNI' },
] as const
</script>

<template>
  <div class="inline-flex shrink-0 rounded-lg bg-gray-100 p-0.5" role="radiogroup" :aria-label="etiqueta">
    <button
      v-for="o in OPCIONES"
      :key="o.id"
      type="button"
      role="radio"
      :aria-checked="modo === o.id"
      class="whitespace-nowrap rounded-md font-semibold transition-colors"
      :class="[compacto ? 'px-2 py-1 text-[11px]' : 'px-3 py-1.5 text-xs', modo === o.id ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-500 hover:text-gray-800']"
      @click="modo = o.id"
    >
      {{ o.texto }}
    </button>
  </div>
</template>
