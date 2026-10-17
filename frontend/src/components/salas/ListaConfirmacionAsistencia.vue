<script setup lang="ts">
import type { Sala, TramoReserva } from '@/types'

/** Filas del menú de confirmación de asistencia de SalasView: la tarjeta muestra las primeras y el popup, todas. */
defineProps<{
  pendientes: { sala: Sala; tramo: TramoReserva; vencida: boolean; segundosRestantes: number }[]
  liberando: number | null
}>()

const emit = defineEmits<{
  llegada: [sala: Sala, tramo: TramoReserva]
  /** Corregir quiénes vienen (alguien no vino, se sumó otra persona) antes de confirmar. */
  modificar: [sala: Sala, tramo: TramoReserva]
  liberar: [sala: Sala, tramo: TramoReserva]
}>()

function formatCuentaRegresiva(segundos: number) {
  if (segundos <= 0) return 'Vencido'
  const m = Math.floor(segundos / 60)
  const s = segundos % 60
  return `${m}:${String(s).padStart(2, '0')}`
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
        <p class="text-xs text-gray-400">{{ p.tramo.cantidad_personas }} persona(s) · {{ p.tramo.rut_usuario }}</p>
      </div>
      <div class="flex items-center gap-3 shrink-0">
        <span
          class="text-xs font-mono font-semibold px-2.5 py-1 rounded-full"
          :class="p.vencida ? 'bg-red-100 text-red-700' : p.segundosRestantes < 300 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'"
        >
          {{ formatCuentaRegresiva(p.segundosRestantes) }}
        </span>
        <template v-if="!p.vencida">
          <button
            @click="emit('modificar', p.sala, p.tramo)"
            class="text-xs font-medium px-3 py-1.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
          >
            Modificar
          </button>
          <button
            @click="emit('llegada', p.sala, p.tramo)"
            class="text-xs font-medium px-3 py-1.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors"
          >
            Confirmar llegada
          </button>
        </template>
        <button
          v-else
          @click="emit('liberar', p.sala, p.tramo)"
          :disabled="liberando === p.tramo.reserva_id"
          class="text-xs font-medium px-3 py-1.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors disabled:opacity-60"
        >
          {{ liberando === p.tramo.reserva_id ? 'Liberando…' : 'Liberar ahora' }}
        </button>
      </div>
    </div>
  </div>
</template>
