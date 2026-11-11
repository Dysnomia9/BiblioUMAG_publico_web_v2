<script setup lang="ts">
import { computed } from 'vue'
import type { SerieItem } from '@/types'

/**
 * Columnas verticales. Van en el color fuerte, salvo los fines de semana o, si se
 * indican `destacados`, las que no están en esa lista, que van en el tono suave.
 * Los días sin atención (`cerrado`) llevan el fondo rayado.
 */
const props = withDefaults(
  defineProps<{
    datos: SerieItem[]
    color: string
    colorSuave: string
    /** Si se indica, la columna con el valor más alto va en este color. */
    colorPico?: string
    destacados?: string[]
    mostrarValores?: boolean
    etiqueta?: (label: string) => string
    unidad?: string
    mensajeVacio?: string
  }>(),
  {
    colorPico: undefined,
    destacados: undefined,
    mostrarValores: false,
    etiqueta: (label: string) => label,
    unidad: '',
    mensajeVacio: 'Sin datos para mostrar.',
  },
)

const maximo = computed(() => Math.max(1, ...props.datos.map((d) => d.value)))

function colorDe(d: SerieItem) {
  if (props.colorPico && d.value === maximo.value) return props.colorPico
  if (props.destacados) return props.destacados.includes(d.label) ? props.color : props.colorSuave
  return d.finDeSemana ? props.colorSuave : props.color
}

/** Alto y relleno: un degradado suave sobre el color de la columna. */
function estiloColumna(d: SerieItem) {
  const alto = d.value ? `${(d.value / maximo.value) * 100}%` : d.cerrado ? '0' : '2px'
  if (!d.value) return { height: alto }
  const color = colorDe(d)
  return { height: alto, backgroundColor: color, backgroundImage: `linear-gradient(to bottom, color-mix(in srgb, ${color} 84%, white), ${color})` }
}

function titulo(d: SerieItem) {
  const valor = `${d.value}${props.unidad ? ' ' + props.unidad : ''}`
  return `${props.etiqueta(d.label)}: ${d.cerrado ? (d.value ? `${valor} (sin atención)` : 'sin atención') : valor}`
}
</script>

<template>
  <div class="flex h-full flex-col">
    <p v-if="!datos.length" class="m-auto text-sm text-gray-400">{{ mensajeVacio }}</p>
    <template v-else>
      <div class="relative flex flex-1 items-end gap-1.5 border-b border-gray-200">
        <div class="pointer-events-none absolute inset-0 flex flex-col justify-between" aria-hidden="true">
          <span v-for="n in 5" :key="n" class="border-t border-gray-100" />
        </div>
        <div
          v-for="(d, i) in datos"
          :key="i"
          class="group relative flex h-full min-w-0 flex-1 flex-col items-center justify-end"
          :title="titulo(d)"
        >
          <div v-if="d.cerrado" class="franja-pasada absolute inset-y-0 left-1/2 w-full max-w-[3.5rem] -translate-x-1/2 rounded-t-md" />
          <span
            v-if="mostrarValores && d.value"
            class="relative mb-1 text-[11px] font-semibold tabular-nums text-gray-700"
          >{{ d.value }}</span>
          <span
            v-else-if="!d.cerrado || d.value"
            class="pointer-events-none absolute -top-1 z-10 whitespace-nowrap rounded bg-gray-900 dark:bg-gray-700 px-1.5 py-0.5 text-[10px] font-semibold text-white opacity-0 transition-opacity group-hover:opacity-100 tabular-nums"
          >{{ titulo(d) }}</span>
          <div
            class="relative w-full max-w-[3.5rem] rounded-t-md transition-all group-hover:brightness-95"
            :class="{ 'bg-gray-200': !d.value }"
            :style="estiloColumna(d)"
          />
        </div>
      </div>
      <div class="mt-1.5 flex gap-1.5">
        <span
          v-for="(d, i) in datos"
          :key="i"
          class="min-w-0 flex-1 truncate text-center text-[10px]"
          :class="d.cerrado ? 'text-gray-300' : 'text-gray-400'"
        >
          {{ etiqueta(d.label) }}
        </span>
      </div>
    </template>
  </div>
</template>
