<script setup lang="ts">
import { computed } from 'vue'
import type { SerieItem } from '@/types'

/** Barra apilada al 100 % con su leyenda (cantidad y porcentaje de cada parte). */
const props = withDefaults(
  defineProps<{
    datos: SerieItem[]
    colores: Record<string, string>
    nombres?: Record<string, string>
  }>(),
  { nombres: () => ({}) },
)

const total = computed(() => props.datos.reduce((suma, d) => suma + d.value, 0))
const partes = computed(() =>
  props.datos
    .filter((d) => d.value > 0)
    .map((d) => ({
      ...d,
      nombre: props.nombres[d.label] ?? d.label,
      color: props.colores[d.label] ?? '#9CA3AF',
      porcentaje: total.value ? Math.round((d.value / total.value) * 100) : 0,
    })),
)
</script>

<template>
  <p v-if="!partes.length" class="py-6 text-center text-sm text-gray-400">Sin datos para mostrar.</p>
  <div v-else>
    <div class="flex h-4 gap-0.5 overflow-hidden rounded-full">
      <span
        v-for="p in partes"
        :key="p.label"
        class="h-full first:rounded-l-full last:rounded-r-full"
        :style="{ width: `${(p.value / total) * 100}%`, backgroundColor: p.color }"
        :title="`${p.nombre}: ${p.value} (${p.porcentaje}%)`"
      />
    </div>
    <ul class="mt-4 space-y-2">
      <li v-for="p in partes" :key="p.label" class="flex items-center gap-2 text-sm">
        <span class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ backgroundColor: p.color }"></span>
        <span class="flex-1 text-gray-700">{{ p.nombre }}</span>
        <span class="font-semibold tabular-nums text-gray-900">{{ p.value }}</span>
        <span class="w-10 text-right text-xs tabular-nums text-gray-400">{{ p.porcentaje }}%</span>
      </li>
    </ul>
  </div>
</template>
