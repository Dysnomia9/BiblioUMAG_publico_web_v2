<script setup lang="ts">
import { ref, watch } from 'vue'
import DialogoConfirmacionEscrita from '@/components/ui/DialogoConfirmacionEscrita.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { fechaCorta } from '@/utils/prestamos'

/**
 * Un libro de otra biblioteca que figura prestado, pero el usuario ya no lo tiene (volvió
 * sin registrarse, o se perdió y se arregló con la biblioteca propietaria): se cierra ese
 * préstamo sin multa y la copia se devuelve a su biblioteca. Pide el motivo y escribir
 * CONFIRMAR. Lo usan Catalogación › Préstamo interbibliotecario y el mesón (Préstamos).
 */
const props = defineProps<{
  abierto: boolean
  /** id del préstamo interbibliotecario. */
  id: number | null
  biblioteca: string
  titulo: string
  usuario: string | null
  fechaDevolucion: string | null
}>()

const emit = defineEmits<{ listo: []; cancelar: [] }>()

const toast = useToast()
const motivo = ref('')
const cargando = ref(false)

watch(() => props.abierto, (abierto) => {
  if (abierto) motivo.value = ''
})

async function cerrar() {
  if (!props.id || !motivo.value.trim()) return
  cargando.value = true
  try {
    await api.post(`/prestamos-interbibliotecarios/${props.id}/devolver`, { motivo: motivo.value.trim(), confirmacion: 'CONFIRMAR' })
    toast.success(`Préstamo cerrado y «${props.titulo}» devuelto a ${props.biblioteca}`)
    emit('listo')
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo cerrar el préstamo'))
  } finally {
    cargando.value = false
  }
}
</script>

<template>
  <DialogoConfirmacionEscrita
    :abierto="abierto"
    :titulo="`¿Cerrar el préstamo y devolver a ${biblioteca}?`"
    confirmar="Cerrar y devolver"
    :cargando="cargando"
    :deshabilitado="!motivo.trim()"
    @confirmar="cerrar"
    @cancelar="emit('cancelar')"
  >
    <p>
      <strong class="text-gray-900">{{ titulo }}</strong> figura prestado a <strong class="text-gray-900">{{ usuario ?? 'un usuario' }}</strong
      ><template v-if="fechaDevolucion"> hasta el {{ fechaCorta(`${fechaDevolucion.slice(0, 10)}T12:00:00`) }}</template>.
    </p>
    <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
      Úsalo solo si estás seguro de que el usuario ya no lo tiene. Si el libro está aquí, recíbelo con «Devolver» en Préstamos.
    </p>
    <ul class="list-disc space-y-1 pl-5">
      <li>Su préstamo queda devuelto hoy, <strong class="text-gray-900">sin multa automática</strong>: si corresponde cobrar, se cursa en Multas.</li>
      <li>La copia pasa a «Devuelto a su biblioteca» y sale del catálogo; sus préstamos siguen en el historial y en los reportes.</li>
    </ul>
    <div>
      <label for="cerrar-pib-motivo" class="mb-1.5 block text-xs font-semibold text-gray-700">Motivo (obligatorio, queda registrado)</label>
      <input
        id="cerrar-pib-motivo"
        v-model="motivo"
        type="text"
        maxlength="500"
        placeholder="Ej: lo devolvió directo a su biblioteca el 10/11"
        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
      />
    </div>
  </DialogoConfirmacionEscrita>
</template>
