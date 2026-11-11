<script setup lang="ts">
import { computed } from 'vue'
import type { SerieItem } from '@/types'

/** Ranking horizontal ordenado de mayor a menor; el primero lleva la marca «TOP». */
const props = withDefaults(
  defineProps<{
    datos: SerieItem[]
    total: number
    color: string
    colorPrimero: string
    limite?: number
    /** Color del riel bajo cada barra; por defecto, gris. */
    colorFondo?: string
    /** Muestra la posición de cada fila (las tres primeras, resaltadas). */
    numerado?: boolean
  }>(),
  { limite: 8, colorFondo: undefined, numerado: false },
)

const filas = computed(() => props.datos.slice(0, props.limite))
const maximo = computed(() => Math.max(1, ...filas.value.map((d) => d.value)))
const porcentaje = (valor: number) => (props.total ? Math.round((valor / props.total) * 100) : 0)
</script>

<template>
  <p v-if="!filas.length" class="py-8 text-center text-sm text-gray-400">Sin datos para mostrar.</p>
  <ul v-else class="space-y-1">
    <li
      v-for="(d, i) in filas"
      :key="d.label"
      class="grid items-center gap-3 rounded-lg px-2 py-1.5"
      :class="[
        numerado ? 'grid-cols-[1.25rem_minmax(0,6.5rem)_1fr_5.75rem] sm:grid-cols-[1.25rem_minmax(0,11rem)_1fr_6.5rem]' : 'grid-cols-[minmax(0,7rem)_1fr_5.75rem] sm:grid-cols-[minmax(0,10rem)_1fr_6.5rem]',
        i === 0 ? 'bg-gray-50' : '',
      ]"
      :title="`${d.label}: ${d.value} (${porcentaje(d.value)}%)`"
    >
      <span
        v-if="numerado"
        class="flex h-5 w-5 items-center justify-center rounded-md text-[11px] font-bold tabular-nums"
        :class="i < 3 ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600'"
      >{{ i + 1 }}</span>
      <span class="truncate text-sm text-gray-700">{{ d.label }}</span>
      <span class="h-2 rounded-full bg-gray-100" :style="{ backgroundColor: colorFondo }">
        <span
          class="block h-2 rounded-full"
          :style="{ width: `${Math.max(2, (d.value / maximo) * 100)}%`, backgroundColor: i === 0 ? colorPrimero : color }"
        />
      </span>
      <span class="flex items-center justify-end gap-1.5 text-right">
        <span class="text-sm font-semibold tabular-nums text-gray-900">{{ d.value }}</span>
        <span
          v-if="i === 0"
          class="rounded bg-amber-100 px-1 py-px text-[9px] font-bold uppercase tracking-wide text-amber-800"
        >top</span>
        <span class="w-8 text-right text-[11px] tabular-nums text-gray-400">{{ porcentaje(d.value) }}%</span>
      </span>
    </li>
  </ul>
</template>
