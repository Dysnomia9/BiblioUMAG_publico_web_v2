<script setup lang="ts">
import { computed } from 'vue'
import type { HoraPorSala } from '@/types'

const props = defineProps<{ data: HoraPorSala[] }>()

// Rampa secuencial de un solo tono (magnitud) — paso 0 "recede" al plano de fondo
// para valor cero, pasos 1-7 de menor a mayor intensidad.
const RAMPA = ['#f4f2ec', '#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#184f95', '#0d366b']

const horas = computed(() => props.data[0]?.horas.map((h) => h.label) ?? [])

const maxValor = computed(() => {
  let max = 0
  for (const fila of props.data) {
    for (const h of fila.horas) {
      if (h.value > max) max = h.value
    }
  }
  return max
})

function pasoDe(valor: number) {
  if (valor === 0 || maxValor.value === 0) return 0
  return Math.max(1, Math.ceil((valor / maxValor.value) * (RAMPA.length - 1)))
}

function colorDe(valor: number) {
  return RAMPA[pasoDe(valor)]
}

function textoClaro(valor: number) {
  return pasoDe(valor) >= 5
}
</script>

<template>
  <div v-if="!data.length" class="text-center text-sm text-gray-400 py-8">
    Sin reservas registradas todavía para este período.
  </div>
  <div v-else class="overflow-x-auto">
    <div class="inline-block min-w-full">
      <div
        class="grid gap-[2px]"
        :style="{ gridTemplateColumns: `140px repeat(${horas.length}, 32px)` }"
      >
        <div></div>
        <div v-for="h in horas" :key="h" class="text-center text-[10px] font-medium text-gray-400 pb-1">
          {{ h.replace('h', '') }}
        </div>

        <template v-for="fila in data" :key="fila.sala">
          <div class="text-xs text-gray-600 pr-2 py-0.5 truncate flex items-center" :title="fila.sala">
            {{ fila.sala }}
          </div>
          <div
            v-for="celda in fila.horas"
            :key="celda.label"
            class="group relative h-7 rounded flex items-center justify-center text-[10px] font-medium cursor-default"
            :style="{ backgroundColor: colorDe(celda.value) }"
            :class="textoClaro(celda.value) ? 'text-white' : 'text-gray-500'"
          >
            {{ celda.value > 0 ? celda.value : '' }}
            <div
              class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-gray-900 px-2 py-1 text-[11px] text-white group-hover:block"
            >
              {{ fila.sala }} · {{ celda.label }}: <strong>{{ celda.value }}</strong> reserva(s)
            </div>
          </div>
        </template>
      </div>

      <div class="flex items-center gap-2 mt-4 text-[10px] text-gray-400">
        <span>Menos</span>
        <div class="flex gap-0.5">
          <div v-for="c in RAMPA" :key="c" class="w-4 h-3 rounded-sm border border-black/5" :style="{ backgroundColor: c }"></div>
        </div>
        <span>Más</span>
        <span class="ml-2 text-gray-300">(máximo: {{ maxValor }} en un mismo bloque horario)</span>
      </div>
    </div>
  </div>
</template>
