<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import api from '@/services/api'
import { ESTADOS_EJEMPLAR } from '@/utils/estadosEjemplar'
import { fechaCorta } from '@/utils/prestamos'
import { mensajeDeError, useToast } from '@/composables/useToast'
import type { Ejemplar, EstadoLibroPersonalizado, EstadoProcesoEjemplar, PrestamoInterbibliotecario } from '@/types'

const props = defineProps<{ prestamo: PrestamoInterbibliotecario | null }>()
const emit = defineEmits<{ cerrar: []; cambiado: [ejemplar: Ejemplar] }>()
const toast = useToast()
const estado = ref<EstadoProcesoEjemplar>('por_colocar')
const personalizado = ref<number | ''>('')
const personalizados = ref<EstadoLibroPersonalizado[]>([])
const motivo = ref('')
const confirmacion = ref('')
const guardando = ref(false)
const opciones = Object.entries(ESTADOS_EJEMPLAR).filter(([id]) => id !== 'devuelto_propietaria')
const puedeGuardar = computed(() => !!props.prestamo && !props.prestamo.devuelto_el && !guardando.value
  && (estado.value !== 'personalizado' || !!personalizado.value)
  && (estado.value !== 'de_baja' || (!!motivo.value.trim() && confirmacion.value === 'CONFIRMAR')))
watch(() => props.prestamo, async (p) => {
  if (!p) return
  estado.value = p.ejemplar.estado_proceso
  personalizado.value = ''
  motivo.value = ''
  confirmacion.value = ''
  try { const { data } = await api.get<EstadoLibroPersonalizado[]>('/estados-libro-personalizados', { params: { activo: 1 } }); personalizados.value = data }
  catch { personalizados.value = [] }
})
async function guardar() {
  if (!puedeGuardar.value || !props.prestamo) return
  guardando.value = true
  try {
    const { data } = await api.patch<Ejemplar & { apartada_para?: string | null }>(`/ejemplares/${props.prestamo.ejemplar_id}/estado`, {
      estado_proceso: estado.value, estado_personalizado_id: estado.value === 'personalizado' ? personalizado.value : null,
      ...(estado.value === 'de_baja' ? { motivo: motivo.value.trim(), confirmacion: confirmacion.value } : {}),
    })
    toast.success(data.apartada_para ? `Ejemplar apartado para ${data.apartada_para}` : 'Estado del ejemplar actualizado')
    emit('cambiado', data)
    emit('cerrar')
  } catch (e) { toast.error(mensajeDeError(e, 'No se pudo cambiar el estado del ejemplar')) }
  finally { guardando.value = false }
}
</script>

<template>
  <Modal :abierto="!!prestamo" titulo="Estado del ejemplar interbibliotecario" ancho="md" @cerrar="!guardando && emit('cerrar')">
    <form v-if="prestamo" class="space-y-4" @submit.prevent="guardar">
      <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-950">
        <p class="font-semibold">{{ prestamo.ejemplar.libro.titulo }}</p>
        <p class="mt-1 font-mono">{{ prestamo.ejemplar.codigo_barras }}</p>
        <p class="mt-2">Propietaria: {{ prestamo.biblioteca }} · Devolver el {{ fechaCorta(prestamo.fecha_limite) }}.</p>
      </div>
      <label class="block text-sm font-semibold text-gray-800">Estado del ejemplar
        <select v-model="estado" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm" :disabled="guardando">
          <option v-for="[id, info] in opciones" :key="id" :value="id">{{ info.label }}</option>
        </select>
      </label>
      <label v-if="estado === 'personalizado'" class="block text-sm font-semibold text-gray-800">Estado personalizado
        <select v-model="personalizado" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm" :disabled="guardando"><option value="">Elige un estado</option><option v-for="p in personalizados" :key="p.id" :value="p.id">{{ p.nombre }}</option></select>
      </label>
      <template v-if="estado === 'de_baja'">
        <p class="text-sm text-red-800">Dar de baja retira el ejemplar de circulación y puede cerrar su préstamo a un usuario. Para registrar el regreso a su biblioteca propietaria, usa «Devolver a su biblioteca» en el listado.</p>
        <label class="block text-sm font-semibold text-gray-800">Motivo de la baja<textarea v-model="motivo" required maxlength="500" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" :disabled="guardando"></textarea></label>
        <label class="block text-sm font-semibold text-gray-800">Escribe CONFIRMAR<input v-model="confirmacion" required autocomplete="off" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" :disabled="guardando" /></label>
      </template>
      <p class="text-xs text-gray-600">Este cambio conserva la biblioteca propietaria y la fecha de devolución del préstamo.</p>
      <div class="flex justify-end gap-3"><button type="button" class="min-h-10 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold" :disabled="guardando" @click="emit('cerrar')">Cancelar</button><button type="submit" class="min-h-10 rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="!puedeGuardar">{{ guardando ? 'Guardando…' : 'Guardar estado' }}</button></div>
    </form>
  </Modal>
</template>
