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
    periodo: string
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

// Solo de mayor a menor la posición es un ranking: por hora o por nombre no se resalta a nadie.
const esRanking = computed(() => orden.value.campo === 'value' && !orden.value.asc)
const suma = computed(() => props.datos.reduce((total, d) => total + d.value, 0))
const maximo = computed(() => Math.max(1, ...props.datos.map((d) => d.value)))
const porcentaje = (valor: number) => (props.total ? Math.round((valor / props.total) * 100) : 0)
const flecha = (campo: 'label' | 'value') => (orden.value.campo === campo ? (orden.value.asc ? '↑' : '↓') : '')
const ariaOrden = (campo: 'label' | 'value') => (orden.value.campo === campo ? (orden.value.asc ? 'ascending' : 'descending') : 'none')
const claseOrden = (campo: 'label' | 'value') => (orden.value.campo === campo ? 'bg-indigo-100 text-indigo-800' : 'hover:text-gray-800')
</script>

<template>
  <section class="min-w-0">
    <div class="flex flex-wrap items-start justify-between gap-3 px-5 pb-3 pt-4">
      <div class="min-w-0 border-l-[3px] border-indigo-600 pl-3">
        <h3 class="font-sans text-base font-bold text-gray-900">{{ titulo }}</h3>
        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
          <span class="rounded-md bg-indigo-50 px-2 py-0.5 font-semibold text-indigo-700">{{ periodo }}</span>
          {{ subtitulo }}
        </p>
      </div>
      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 border-b-indigo-200 bg-white px-3 py-1.5 text-xs font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-gray-50"
        @click="emit('exportar')"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
        </svg>
        Exportar esta tabla
      </button>
    </div>

    <div class="mx-4 mb-4 overflow-x-auto rounded-xl border border-gray-200">
      <table class="w-full min-w-[34rem] text-sm">
        <thead>
          <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
            <th class="w-14 px-4 py-2">#</th>
            <th class="px-3 py-2" :aria-sort="ariaOrden('label')">
              <button type="button" class="-mx-2 rounded-md px-2 py-0.5 uppercase tracking-wider transition-colors" :class="claseOrden('label')" @click="ordenarPor('label')">{{ columna }} {{ flecha('label') }}</button>
            </th>
            <th class="w-36 px-3 py-2 text-right" :aria-sort="ariaOrden('value')">
              <button type="button" class="-mx-2 rounded-md px-2 py-0.5 uppercase tracking-wider transition-colors" :class="claseOrden('value')" @click="ordenarPor('value')">{{ unidad }} {{ flecha('value') }}</button>
            </th>
            <th class="w-64 px-4 py-2">% del total</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="(fila, i) in filas" :key="fila.label" :class="esRanking && i === 0 ? 'bg-indigo-50/40' : 'hover:bg-gray-50/70'">
            <td class="px-4 py-2">
              <span
                class="flex h-5 w-5 items-center justify-center rounded-md text-[11px] font-bold tabular-nums"
                :class="esRanking && i < 3 ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600'"
              >{{ i + 1 }}</span>
            </td>
            <td class="px-3 py-2 text-gray-900" :class="esRanking && i === 0 ? 'font-semibold' : 'font-medium'">
              {{ fila.label }}
              <span v-if="esRanking && i === 0" class="ml-1.5 rounded bg-amber-100 px-1 py-px align-middle text-[9px] font-bold uppercase tracking-wide text-amber-800">top</span>
            </td>
            <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-900">{{ fila.value }}</td>
            <td class="px-4 py-2">
              <div class="flex items-center gap-3">
                <span class="w-9 text-right text-xs tabular-nums text-gray-500">{{ porcentaje(fila.value) }}%</span>
                <span class="h-2 flex-1 rounded-full bg-gray-100">
                  <span
                    class="block h-2 rounded-full"
                    :class="esRanking && i === 0 ? 'brightness-75 dark:brightness-125' : esRanking && i > 2 ? 'opacity-60' : ''"
                    :style="{ width: `${Math.max(2, (fila.value / maximo) * 100)}%`, backgroundColor: color }"
                  />
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
            <td class="px-4 py-2"></td>
            <td class="px-3 py-2 font-semibold text-gray-900">Total</td>
            <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-900">{{ suma }}</td>
            <td class="px-4 py-2 text-xs tabular-nums text-gray-500">{{ porcentaje(suma) }}%</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </section>
</template>
