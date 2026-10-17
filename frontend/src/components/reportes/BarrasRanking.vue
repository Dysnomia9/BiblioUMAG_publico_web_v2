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
  }>(),
  { limite: 8 },
)

const filas = computed(() => props.datos.slice(0, props.limite))
const maximo = computed(() => Math.max(1, ...filas.value.map((d) => d.value)))
const porcentaje = (valor: number) => (props.total ? Math.round((valor / props.total) * 100) : 0)
</script>

<template>
  <p v-if="!filas.length" class="py-8 text-center text-sm text-gray-400">Sin datos para mostrar.</p>
  <ul v-else class="space-y-3">
    <li v-for="(d, i) in filas" :key="d.label" class="grid grid-cols-[minmax(0,10rem)_1fr_6.5rem] items-center gap-3">
      <span class="truncate text-sm text-gray-700" :title="d.label">{{ d.label }}</span>
      <span class="h-3 rounded-full bg-gray-100">
        <span
          class="block h-3 rounded-full"
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
