<script setup lang="ts">
/**
 * Los tres pasos para dejar un título en el estante: la ficha, la copia con su código y el
 * cambio de estado. Los anteriores al actual van con ✓; el actual, con su número en color.
 */
const props = defineProps<{ actual: 1 | 2 | 3 }>()

const PASOS = ['Ficha', 'Ejemplar', 'Estado']
const hecho = (i: number) => i + 1 < props.actual
const actual = (i: number) => i + 1 === props.actual
</script>

<template>
  <ol class="mb-3 flex items-center gap-2 text-[13px]" aria-label="Pasos">
    <template v-for="(paso, i) in PASOS" :key="paso">
      <li v-if="i" aria-hidden="true" class="h-0.5 w-5 rounded-full" :class="hecho(i) || actual(i) ? 'bg-emerald-600' : 'bg-gray-200'"></li>
      <li class="flex items-center gap-1.5" :aria-current="actual(i) ? 'step' : undefined">
        <span
          class="flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold"
          :class="hecho(i) ? 'bg-emerald-600 text-white' : actual(i) ? 'bg-indigo-600 text-white' : 'border border-gray-300 bg-white text-gray-500'"
        >
          <svg v-if="hecho(i)" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
          <template v-else>{{ i + 1 }}</template>
        </span>
        <span class="font-semibold" :class="actual(i) ? 'text-gray-900' : 'text-gray-500'">{{ paso }}</span>
      </li>
    </template>
  </ol>
</template>
