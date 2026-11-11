<script setup lang="ts">
import { ref } from 'vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { duracion } from '@/utils/reservas'
import type { TiempoSinUsar } from '@/types'

/**
 * Aparece al registrar la devolución de una llave antes de tiempo. Devolver antes no tiene
 * ninguna consecuencia para el grupo; el mesón decide si libera lo que quedaba del bloque
 * para que otro grupo pueda ocupar la sala (si no, sigue reservada hasta la hora original).
 */
const props = defineProps<{ aviso: TiempoSinUsar | null }>()
const emit = defineEmits<{ cerrar: []; liberado: [] }>()

const toast = useToast()
const liberando = ref(false)

async function liberar() {
  if (!props.aviso) return
  liberando.value = true
  try {
    await api.patch(`/reservas/${props.aviso.reserva_id}/liberar-resto`)
    toast.success(`${props.aviso.sala} quedó libre para otro grupo`)
    emit('liberado')
    emit('cerrar')
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo liberar el resto del bloque'))
  } finally {
    liberando.value = false
  }
}
</script>

<template>
  <DialogoConfirmacion
    :abierto="!!aviso"
    titulo="¿Liberar el resto del bloque?"
    confirmar="Sí, liberar"
    cancelar="No, dejarla reservada"
    tono="exito"
    destacado
    :cargando="liberando"
    @confirmar="liberar"
    @cancelar="emit('cerrar')"
  >
    <template v-if="aviso">
      <p>
        La llave de <strong class="text-gray-900">{{ aviso.sala }}</strong> volvió a las {{ aviso.devuelta }} y la reserva era hasta las {{ aviso.hora_fin }}.
      </p>
      <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2 text-xs">
        <span class="flex items-center gap-1.5">
          <span class="text-gray-400 line-through">hasta las {{ aviso.hora_fin }}</span>
          <span class="text-gray-400" aria-hidden="true">→</span>
          <span class="rounded border border-emerald-200 bg-emerald-50 px-1.5 py-px font-semibold text-emerald-700">libre desde las {{ aviso.devuelta }}</span>
        </span>
        <span class="text-gray-500">quedan <strong class="text-emerald-700">{{ duracion(aviso.minutos) }}</strong> sin usar</span>
      </div>
      <p class="mt-3 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
        Si lo liberas, otro grupo puede ocupar la sala desde ya. Devolver antes no tiene ninguna consecuencia para el grupo, y su
        cuota del día cuenta solo el tiempo que usó.
      </p>
    </template>
  </DialogoConfirmacion>
</template>
