<script setup lang="ts">
import { computed } from 'vue'

/** Pie de un listado por páginas: «Mostrando 1 a 25 de 4.650 usuarios» y los números de página. */
const props = defineProps<{ total: number; porPagina: number; singular: string; plural: string }>()

const pagina = defineModel<number>({ required: true })

const totalPaginas = computed(() => Math.max(1, Math.ceil(props.total / props.porPagina)))
const desde = computed(() => (props.total ? (pagina.value - 1) * props.porPagina + 1 : 0))
const hasta = computed(() => Math.min(pagina.value * props.porPagina, props.total))

/** Con muchas páginas: la primera, la última y las vecinas de la actual («1 … 6 7 8 … 186»). */
const numeros = computed<(number | '…')[]>(() => {
  const n = totalPaginas.value
  const p = pagina.value
  if (n <= 7) return Array.from({ length: n }, (_, i) => i + 1)
  if (p <= 4) return [1, 2, 3, 4, 5, '…', n]
  if (p >= n - 3) return [1, '…', n - 4, n - 3, n - 2, n - 1, n]
  return [1, '…', p - 1, p, p + 1, '…', n]
})

const miles = (n: number) => n.toLocaleString('es-CL')
</script>

<template>
  <div v-if="total" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <p class="text-sm text-gray-500">
      Mostrando {{ miles(desde) }} a {{ miles(hasta) }} de {{ miles(total) }} {{ total === 1 ? singular : plural }}
    </p>
    <nav v-if="totalPaginas > 1" class="flex flex-wrap items-center gap-1 sm:gap-1.5" aria-label="Páginas">
      <button
        type="button"
        class="flex h-8 w-8 sm:h-9 sm:w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 transition-colors hover:bg-gray-50 disabled:opacity-40"
        aria-label="Página anterior"
        :disabled="pagina === 1"
        @click="pagina--"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
      </button>
      <template v-for="(n, i) in numeros" :key="`${n}-${i}`">
        <span v-if="n === '…'" class="px-0.5 sm:px-1 text-sm text-gray-400">…</span>
        <button
          v-else
          type="button"
          class="h-8 min-w-[2rem] sm:h-9 sm:min-w-[2.25rem] rounded-lg px-1.5 sm:px-2 text-sm font-semibold transition-colors"
          :class="n === pagina ? 'bg-indigo-600 text-white shadow-sm' : 'border border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
          :aria-current="n === pagina ? 'page' : undefined"
          @click="pagina = n"
        >
          {{ n }}
        </button>
      </template>
      <button
        type="button"
        class="flex h-8 w-8 sm:h-9 sm:w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 transition-colors hover:bg-gray-50 disabled:opacity-40"
        aria-label="Página siguiente"
        :disabled="pagina === totalPaginas"
        @click="pagina++"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
      </button>
    </nav>
  </div>
</template>
