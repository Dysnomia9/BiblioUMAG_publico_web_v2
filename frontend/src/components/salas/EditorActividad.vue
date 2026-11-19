<script setup lang="ts">
import { ref } from 'vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import type { ActividadReserva } from '@/types'

/**
 * Corrige la persona a cargo y la actividad / descripción de una reserva por nombre
 * (salas de la administración, sin RUT). El servidor vuelve a revisar el permiso de la sala.
 */
const props = defineProps<{
  reservaId: number
  nombre: string
  descripcion: string | null | undefined
}>()

const emit = defineEmits<{ guardado: [resultado: ActividadReserva]; cancelar: [] }>()

const toast = useToast()
const nombre = ref(props.nombre)
const descripcion = ref(props.descripcion ?? '')
const guardando = ref(false)

async function guardar() {
  if (!nombre.value.trim()) {
    toast.error('Escribe el nombre de la persona a cargo o del grupo.')
    return
  }
  guardando.value = true
  try {
    const { data } = await api.put<ActividadReserva>(`/reservas/${props.reservaId}/actividad`, {
      nombre_actividad: nombre.value.trim(),
      descripcion_actividad: descripcion.value.trim() || null,
    })
    toast.success('Persona a cargo y actividad guardadas')
    emit('guardado', data)
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo guardar el cambio'))
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <form class="space-y-3 rounded-xl border border-indigo-200 bg-white p-4 shadow-sm" @submit.prevent="guardar">
    <div>
      <label :for="`actividad-nombre-${reservaId}`" class="text-[10px] font-bold uppercase tracking-widest text-indigo-600">Persona a cargo / grupo</label>
      <input
        :id="`actividad-nombre-${reservaId}`"
        v-model="nombre"
        type="text"
        maxlength="120"
        required
        class="mt-1 w-full rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-500"
      />
    </div>
    <div>
      <label :for="`actividad-descripcion-${reservaId}`" class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Actividad / descripción</label>
      <input
        :id="`actividad-descripcion-${reservaId}`"
        v-model="descripcion"
        type="text"
        maxlength="300"
        placeholder="Ej: Clase de Física II"
        class="mt-1 w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
      />
    </div>
    <div class="flex justify-end gap-2">
      <button type="button" :disabled="guardando" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50" @click="emit('cancelar')">
        Cancelar
      </button>
      <button type="submit" :disabled="guardando" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
        {{ guardando ? 'Guardando…' : 'Guardar' }}
      </button>
    </div>
  </form>
</template>
