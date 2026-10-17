<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { DIAS_SEMANA, diaIso } from '@/utils/horario'
import type { HorarioBiblioteca } from '@/types'

const props = withDefaults(
  defineProps<{
    horario: HorarioBiblioteca
    /** Para ponerla sobre el degradado morado (inicio en el teléfono). */
    oscura?: boolean
  }>(),
  { oscura: false },
)

const ahora = ref(new Date())
let reloj: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  reloj = setInterval(() => (ahora.value = new Date()), 60000)
})
onUnmounted(() => clearInterval(reloj))

function aMinutos(hhmm: string) {
  const [h, m] = hhmm.split(':').map(Number)
  return h * 60 + m
}

const estado = computed(() => {
  const hoy = diaIso(ahora.value)
  const minutos = ahora.value.getHours() * 60 + ahora.value.getMinutes()
  const deHoy = props.horario[hoy]

  if (deHoy && minutos >= aMinutos(deHoy[0]) && minutos < aMinutos(deHoy[1])) {
    return { abierta: true, texto: props.oscura ? `Abierta hasta ${deHoy[1]}` : `Abierta · cierra a las ${deHoy[1]}` }
  }
  if (deHoy && minutos < aMinutos(deHoy[0])) return { abierta: false, texto: `Cerrada · abre a las ${deHoy[0]}` }

  // Ya cerró o hoy no abre: el próximo día con atención.
  for (let dias = 1; dias <= 7; dias++) {
    const dia = ((hoy - 1 + dias) % 7) + 1
    const horario = props.horario[dia]
    if (horario) return { abierta: false, texto: `Cerrada · abre ${dias === 1 ? 'mañana' : `el ${DIAS_SEMANA[dia]}`} a las ${horario[0]}` }
  }
  return { abierta: false, texto: 'Cerrada' }
})

const clases = computed(() => {
  if (props.oscura) return 'bg-white/15 border-white/20 text-white'
  return estado.value.abierta ? 'bg-emerald-50 border-emerald-100 text-emerald-800' : 'bg-gray-100 border-gray-200 text-gray-600'
})
</script>

<template>
  <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium shrink-0" :class="clases">
    <span class="h-2 w-2 rounded-full" :class="estado.abierta ? (oscura ? 'bg-emerald-400' : 'bg-emerald-500') : 'bg-gray-400'"></span>
    {{ estado.texto }}
  </span>
</template>
