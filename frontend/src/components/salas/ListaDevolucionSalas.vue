<script setup lang="ts">
import type { Sala, TramoReserva } from '@/types'

/** Filas de la pestaña «Devolución» del menú de SalasView: salas con la llave entregada que ya terminan o terminaron. */
defineProps<{
  pendientes: { sala: Sala; tramo: TramoReserva; minutosRestantes: number }[]
  puedeRenovar: (sala: Sala) => boolean
}>()

const emit = defineEmits<{
  renovar: [sala: Sala, tramo: TramoReserva]
  devolver: [sala: Sala, tramo: TramoReserva]
}>()

function textoPlazo(min: number) {
  if (min > 0) return `Expira en ${min} min`
  if (min === 0) return 'Expira ahora'
  const h = Math.floor(-min / 60)
  const m = -min % 60
  return `Expiró hace ${h ? `${h} h ` : ''}${m ? `${m} min` : ''}`.trim()
}
</script>

<template>
  <div class="rounded-lg border border-gray-200 divide-y divide-gray-100">
    <div
      v-for="p in pendientes"
      :key="p.tramo.reserva_id"
      class="flex items-center justify-between gap-3 px-4 py-3 flex-wrap"
    >
      <div class="min-w-0">
        <p class="text-sm font-medium text-gray-900">
          {{ p.sala.nombre }} · {{ p.tramo.hora_inicio }} – {{ p.tramo.hora_fin }}
        </p>
        <p class="text-xs text-gray-400">
          {{ p.tramo.cantidad_personas }} persona(s) · {{ p.tramo.rut_usuario }} · expira a las {{ p.tramo.hora_fin }}
        </p>
      </div>
      <div class="flex items-center gap-3 shrink-0">
        <span
          class="text-xs font-semibold px-2.5 py-1 rounded-full"
          :class="p.minutosRestantes <= 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'"
        >
          {{ textoPlazo(p.minutosRestantes) }}
        </span>
        <button
          v-if="puedeRenovar(p.sala)"
          @click="emit('renovar', p.sala, p.tramo)"
          class="text-xs font-medium px-3 py-1.5 border border-violet-300 text-violet-700 rounded-lg hover:bg-violet-50 transition-colors"
        >
          Renovar
        </button>
        <button
          @click="emit('devolver', p.sala, p.tramo)"
          class="text-xs font-medium px-3 py-1.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors"
        >
          Confirmar devolución
        </button>
      </div>
    </div>
  </div>
</template>
