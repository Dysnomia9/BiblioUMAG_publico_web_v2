<script setup lang="ts">
import { ref } from 'vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import type { AtrasoDevolucion } from '@/types'

/**
 * Aparece al registrar la devolución de una llave fuera de hora: dice cuántos minutos se
 * atrasó el grupo y el mesón decide si cursa la multa o la deja pasar (los atrasos chicos
 * a veces se perdonan). El monto lo calcula y la multa la guarda el servidor (MultaService).
 */
const props = defineProps<{ aviso: AtrasoDevolucion | null }>()
const emit = defineEmits<{ cerrar: [] }>()

const toast = useToast()
const generando = ref(false)

const pesos = (monto: number) => new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP' }).format(monto)

function duracion(minutos: number) {
  const horas = Math.floor(minutos / 60)
  const resto = minutos % 60
  if (!horas) return `${resto} ${resto === 1 ? 'minuto' : 'minutos'}`
  return resto ? `${horas} h ${resto} min` : `${horas} ${horas === 1 ? 'hora' : 'horas'}`
}

async function generar() {
  if (!props.aviso) return
  generando.value = true
  try {
    const { data } = await api.post<{ monto: number }>(`/reservas/${props.aviso.reserva_id}/multa-atraso`)
    toast.success(`Multa de ${pesos(data.monto)} generada a ${props.aviso.persona}`)
    emit('cerrar')
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo generar la multa'))
  } finally {
    generando.value = false
  }
}
</script>

<template>
  <DialogoConfirmacion
    :abierto="!!aviso"
    titulo="Devolución con atraso"
    confirmar="Sí, generar multa"
    cancelar="No, dejarla pasar"
    tono="peligro"
    :cargando="generando"
    @confirmar="generar"
    @cancelar="emit('cerrar')"
  >
    <template v-if="aviso">
      <!-- En una sola línea: Vue quita el espacio entre dos etiquetas separadas por un salto de línea. -->
      <p>
        <strong class="text-gray-900">{{ aviso.persona }}</strong> devolvió la llave de
        <strong class="text-gray-900">{{ aviso.sala }}</strong> <strong class="text-red-700">{{ duracion(aviso.minutos) }} tarde</strong>
        (la reserva era hasta las {{ aviso.hora_fin }}).
      </p>
      <p v-if="aviso.minutos_cobrables !== undefined" class="mt-3 rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-2 text-sm text-indigo-950">
        Se descuentan {{ duracion(aviso.minutos_tolerancia) }} sin cargo. Se cobran
        <strong>{{ duracion(aviso.minutos_cobrables) }}</strong> a {{ pesos(aviso.monto_por_minuto) }} por minuto<template v-if="aviso.tope_maximo">, con un máximo de {{ pesos(aviso.tope_maximo) }} por reserva</template>.
      </p>
      <p class="mt-3">
        ¿Desea generar una multa de <strong class="text-gray-900">{{ pesos(aviso.monto) }}</strong>? Queda pendiente a su
        nombre y, mientras no la pague, no podrá llevarse libros ni recursos de apoyo, ni reservar libros.
      </p>
      <p v-if="aviso.monto_provisional" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
        Monto provisional: la biblioteca todavía no define la tarifa de estas multas.
      </p>
    </template>
  </DialogoConfirmacion>
</template>
