<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { SerieItem } from '@/types'

/**
 * Tabla de una agrupación del reporte: se ordena al hacer clic en los encabezados.
 * Las agrupaciones con orden propio (horas, días, años) parten en ese orden; el resto,
 * de mayor a menor.
 */
const props = withDefaults(
  defineProps<{
    titulo: string
    subtitulo: string
    columna: string
    unidad: string
    datos: SerieItem[]
    total: number
    color: string
    ordenPropio?: boolean
  }>(),
  { ordenPropio: false },
)

const emit = defineEmits<{ exportar: [] }>()

type Orden = { campo: 'propio' | 'label' | 'value'; asc: boolean }
const ordenInicial = (): Orden => (props.ordenPropio ? { campo: 'propio', asc: true } : { campo: 'value', asc: false })
const orden = ref<Orden>(ordenInicial())
watch(() => [props.columna, props.ordenPropio], () => (orden.value = ordenInicial()))

function ordenarPor(campo: 'label' | 'value') {
  orden.value = orden.value.campo === campo ? { campo, asc: !orden.value.asc } : { campo, asc: campo === 'label' }
}

const filas = computed(() => {
  const lista = props.datos.map((d, i) => ({ ...d, posicion: i }))
  const { campo, asc } = orden.value
  if (campo === 'propio') return asc ? lista : [...lista].reverse()
  return [...lista].sort((a, b) => {
    const comparacion = campo === 'value' ? a.value - b.value : a.label.localeCompare(b.label, 'es', { numeric: true })
    return asc ? comparacion : -comparacion
  })
})

const suma = computed(() => props.datos.reduce((total, d) => total + d.value, 0))
const porcentaje = (valor: number) => (props.total ? Math.round((valor / props.total) * 100) : 0)
const flecha = (campo: 'label' | 'value') => (orden.value.campo === campo ? (orden.value.asc ? '↑' : '↓') : '')
</script>

<template>
  <section class="min-w-0 overflow-hidden rounded-r-2xl bg-white">
    <div class="flex flex-wrap items-start justify-between gap-3 px-5 pt-5 pb-4">
      <div class="min-w-0">
        <h3 class="font-sans text-base font-bold text-gray-900">{{ titulo }}</h3>
        <p class="mt-0.5 text-xs text-gray-500">{{ subtitulo }}</p>
      </div>
      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition-colors"
        @click="emit('exportar')"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
        </svg>
        Exportar esta tabla
      </button>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full min-w-[34rem] text-sm">
        <thead>
          <tr class="border-y border-gray-200 bg-gray-50 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">
            <th class="w-12 px-5 py-2.5">#</th>
            <th class="px-3 py-2.5">
              <button type="button" class="uppercase hover:text-gray-800" @click="ordenarPor('label')">{{ columna }} {{ flecha('label') }}</button>
            </th>
            <th class="w-32 px-3 py-2.5 text-right">
              <button type="button" class="uppercase hover:text-gray-800" @click="ordenarPor('value')">{{ unidad }} {{ flecha('value') }}</button>
            </th>
            <th class="w-56 px-5 py-2.5">% del total</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="(fila, i) in filas" :key="fila.label" class="hover:bg-gray-50/70">
            <td class="px-5 py-2.5 text-xs tabular-nums text-gray-400">{{ i + 1 }}</td>
            <td class="px-3 py-2.5 font-medium text-gray-900">{{ fila.label }}</td>
            <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-gray-900">{{ fila.value }}</td>
            <td class="px-5 py-2.5">
              <div class="flex items-center gap-3">
                <span class="w-9 text-right text-xs tabular-nums text-gray-500">{{ porcentaje(fila.value) }}%</span>
                <span class="h-2 flex-1 rounded-full bg-gray-100">
                  <span class="block h-2 rounded-full" :style="{ width: `${porcentaje(fila.value)}%`, backgroundColor: color }" />
                </span>
              </div>
            </td>
          </tr>
          <tr v-if="!filas.length">
            <td colspan="4" class="px-5 py-8 text-center text-sm text-gray-400">Sin datos para este período.</td>
          </tr>
        </tbody>
        <tfoot v-if="filas.length">
          <tr class="border-t border-gray-200 bg-gray-50">
            <td class="px-5 py-2.5"></td>
            <td class="px-3 py-2.5 font-semibold text-gray-900">Total</td>
            <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-gray-900">{{ suma }}</td>
            <td class="px-5 py-2.5 text-xs tabular-nums text-gray-500">{{ porcentaje(suma) }}%</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </section>
</template>
