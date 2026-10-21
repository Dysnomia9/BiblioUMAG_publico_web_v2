<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import EditorIntegrantes from '@/components/salas/EditorIntegrantes.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { SITUACIONES, duracion, fechaLargaReserva, horaDe, reservaEditable } from '@/utils/reservas'
import type { AtrasoDevolucion, IntegrantesReserva, ListadoReservas, ReservaListado } from '@/types'

/**
 * Detalle de una reserva del Listado de reservas. Con el permiso de editar reservas,
 * mientras sigue activa se le cambia la sala, el día o el horario, se corrigen sus
 * integrantes, se cancela o se registra la devolución de la llave. El servidor vuelve
 * a revisar todo con las mismas reglas que al reservar.
 */
const props = defineProps<{
  reserva: ReservaListado | null
  salas: ListadoReservas['salas']
  puedeEditar: boolean
}>()

// atraso: la llave volvió fuera de hora; la pantalla muestra el aviso para decidir la multa
// (este detalle se cierra al registrar la devolución).
const emit = defineEmits<{ cerrar: []; actualizada: []; atraso: [aviso: AtrasoDevolucion] }>()

const toast = useToast()

// Copia local: se actualiza al guardar sin esperar a que el listado se recargue.
const actual = ref<ReservaListado | null>(null)
const form = reactive({ sala_id: 0, fecha: '', hora_inicio: '', hora_fin: '' })
const guardando = ref(false)
const editandoIntegrantes = ref(false)
const confirmando = ref<'cancelar' | 'devolver' | null>(null)
const procesando = ref(false)

function llenarFormulario(r: ReservaListado) {
  Object.assign(form, { sala_id: r.sala?.id ?? 0, fecha: r.fecha, hora_inicio: r.hora_inicio, hora_fin: r.hora_fin })
}

watch(
  () => props.reserva,
  (r) => {
    actual.value = r ? { ...r } : null
    editandoIntegrantes.value = false
    confirmando.value = null
    if (r) llenarFormulario(r)
  },
  { immediate: true },
)

const editable = computed(() => props.puedeEditar && !!actual.value && reservaEditable(actual.value))
// Con el grupo ya en la sala se cambia la sala o el horario, pero no el día.
const conLlegada = computed(() => !!actual.value?.hora_prestamo_real)
const hayCambios = computed(() => {
  const r = actual.value
  return !!r && (form.sala_id !== r.sala?.id || form.fecha !== r.fecha || form.hora_inicio !== r.hora_inicio || form.hora_fin !== r.hora_fin)
})
const ocupado = computed(() => guardando.value || procesando.value)

async function guardar() {
  if (!actual.value || !hayCambios.value) return
  guardando.value = true
  try {
    const { data } = await api.put<ReservaListado>(`/reservas/${actual.value.id}`, { ...form })
    actual.value = data
    llenarFormulario(data)
    toast.success(`Reserva actualizada: ${data.sala?.nombre}, ${fechaLargaReserva(data.fecha)} de ${data.hora_inicio} a ${data.hora_fin}`)
    emit('actualizada')
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo guardar el cambio'))
  } finally {
    guardando.value = false
  }
}

function integrantesGuardados(resultado: IntegrantesReserva) {
  if (actual.value) {
    actual.value = { ...actual.value, personas: resultado.personas, cantidad_personas: resultado.cantidad_personas }
  }
  editandoIntegrantes.value = false
  const registradas = resultado.asistencia?.registradas ?? []
  toast.success(`Integrantes guardados: ${resultado.cantidad_personas} personas.${registradas.length ? ` Entrada registrada: ${registradas.join(', ')}.` : ''}`)
  emit('actualizada')
}

async function ejecutar() {
  if (!actual.value || !confirmando.value) return
  procesando.value = true
  try {
    if (confirmando.value === 'cancelar') {
      await api.delete(`/reservas/${actual.value.id}`)
      toast.success('Reserva cancelada: el horario quedó libre')
    } else {
      const { data } = await api.patch<{ atraso_devolucion?: AtrasoDevolucion | null }>(`/reservas/${actual.value.id}/devolver`)
      toast.success('Devolución de la llave registrada')
      if (data.atraso_devolucion) emit('atraso', data.atraso_devolucion)
    }
    confirmando.value = null
    emit('actualizada')
    emit('cerrar')
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo completar la acción'))
  } finally {
    procesando.value = false
  }
}

function cerrar() {
  if (!ocupado.value) emit('cerrar')
}

const titular = (rut: string) => actual.value?.titular.rut === rut
</script>

<template>
  <Modal
    :abierto="!!actual && !confirmando"
    :titulo="actual?.sala?.nombre ?? 'Reserva'"
    :descripcion="actual ? `${fechaLargaReserva(actual.fecha)} · ${actual.hora_inicio} a ${actual.hora_fin} (${duracion(actual.duracion_minutos)})` : ''"
    ancho="lg"
    pie-fijo
    :bloqueado="ocupado"
    @cerrar="cerrar"
  >
    <div v-if="actual" class="space-y-6 text-sm">
      <div class="flex flex-wrap items-center gap-2">
        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="SITUACIONES[actual.situacion].clase">{{ SITUACIONES[actual.situacion].texto }}</span>
        <span class="text-xs text-gray-500">Reserva #{{ actual.id }} · registrada el {{ new Date(actual.created_at).toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' }) }}</span>
      </div>

      <!-- Sala y horario -->
      <section>
        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">Sala y horario</p>
        <form v-if="editable" class="rounded-xl border border-gray-200 p-4" @submit.prevent="guardar">
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block sm:col-span-2">
              <span class="mb-1 block text-xs font-medium text-gray-600">Sala</span>
              <select v-model.number="form.sala_id" :disabled="ocupado" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500">
                <option v-for="s in salas" :key="s.id" :value="s.id">{{ s.nombre }}</option>
              </select>
            </label>
            <label class="block sm:col-span-2">
              <span class="mb-1 block text-xs font-medium text-gray-600">Día</span>
              <input
                v-model="form.fecha"
                type="date"
                required
                :disabled="ocupado || conLlegada"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-500"
              />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-medium text-gray-600">Desde</span>
              <input v-model="form.hora_inicio" type="time" step="1800" required :disabled="ocupado" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-medium text-gray-600">Hasta</span>
              <input v-model="form.hora_fin" type="time" step="1800" required :disabled="ocupado" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500" />
            </label>
          </div>
          <p class="mt-3 text-xs text-gray-500">
            <template v-if="conLlegada">El grupo ya está en la sala: se puede cambiar de sala o de horario, pero no de día. </template>
            Mismas reglas que al reservar: dentro del horario de atención, hasta 2 horas, en punto o y media, y sin chocar con otra reserva de la
            sala ni de los integrantes.
          </p>
          <div class="mt-3 flex justify-end gap-2">
            <button
              v-if="hayCambios"
              type="button"
              :disabled="ocupado"
              class="rounded-lg border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-100"
              @click="llenarFormulario(actual)"
            >
              Descartar
            </button>
            <button type="submit" :disabled="ocupado || !hayCambios" class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
              {{ guardando ? 'Guardando…' : 'Guardar cambios' }}
            </button>
          </div>
        </form>
        <dl v-else class="grid grid-cols-2 gap-3 rounded-xl border border-gray-200 bg-gray-50/60 p-4 sm:grid-cols-3">
          <div>
            <dt class="text-xs text-gray-500">Sala</dt>
            <dd class="font-medium text-gray-900">{{ actual.sala?.nombre ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500">Día</dt>
            <dd class="font-medium text-gray-900">{{ new Date(`${actual.fecha}T12:00:00`).toLocaleDateString('es-CL') }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500">Horario</dt>
            <dd class="font-medium text-gray-900">{{ actual.hora_inicio }} a {{ actual.hora_fin }}</dd>
          </div>
        </dl>
        <p v-if="puedeEditar && !editable" class="mt-2 text-xs text-gray-500">
          Las reservas finalizadas, canceladas o en que no se presentaron quedan como registro y no se editan.
        </p>
      </section>

      <!-- Integrantes -->
      <section>
        <div class="mb-2 flex items-center justify-between gap-3">
          <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Integrantes ({{ actual.personas.length }})</p>
          <button
            v-if="editable && !editandoIntegrantes"
            type="button"
            :disabled="ocupado"
            class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-700 hover:text-indigo-900 disabled:opacity-50"
            @click="editandoIntegrantes = true"
          >
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
            </svg>
            Modificar
          </button>
        </div>
        <EditorIntegrantes
          v-if="editandoIntegrantes"
          :reserva-id="actual.id"
          :personas="actual.personas"
          @guardado="integrantesGuardados"
          @cancelar="editandoIntegrantes = false"
        />
        <ul v-else class="space-y-2">
          <li v-for="p in actual.personas" :key="p.rut" class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5">
            <span class="min-w-0 truncate text-gray-900">
              {{ p.nombre ?? 'Sin registro en el sistema' }}
              <span v-if="titular(p.rut)" class="ml-1 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-indigo-700">A cargo</span>
            </span>
            <span class="shrink-0 font-mono text-xs text-gray-500">{{ p.rut }}</span>
          </li>
          <li v-if="!actual.personas.length" class="text-gray-400">Sin integrantes registrados.</li>
        </ul>
      </section>

      <!-- Llave -->
      <section>
        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">Llave</p>
        <dl class="grid gap-3 rounded-xl border border-gray-200 p-4 sm:grid-cols-2">
          <div>
            <dt class="text-xs text-gray-500">Entrega (llegada del grupo)</dt>
            <dd class="text-gray-900">
              <template v-if="actual.hora_prestamo_real">
                {{ horaDe(actual.hora_prestamo_real) }}<template v-if="actual.prestado_por"> · {{ actual.prestado_por }}</template>
                <span class="block text-xs text-gray-500">{{ actual.via === 'BC' ? 'Con código de barras' : 'Confirmada a mano' }}</span>
              </template>
              <template v-else-if="actual.situacion === 'pendiente'">Aún no llegan · tienen hasta las {{ horaDe(actual.plazo_confirmacion) }}</template>
              <template v-else>—</template>
            </dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500">Devolución</dt>
            <dd class="text-gray-900" :class="actual.situacion === 'sin_devolver' ? 'font-semibold text-red-700' : ''">
              <template v-if="actual.hora_devolucion_real">
                {{ horaDe(actual.hora_devolucion_real) }}<template v-if="actual.devuelto_por"> · {{ actual.devuelto_por }}</template>
              </template>
              <template v-else-if="actual.situacion === 'sin_devolver'">Pendiente: su horario ya terminó</template>
              <template v-else-if="actual.situacion === 'en_uso'">Pendiente</template>
              <template v-else>—</template>
            </dd>
          </div>
        </dl>
      </section>
    </div>

    <template #pie>
      <button type="button" :disabled="ocupado" class="px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm sm:mr-auto" @click="cerrar">
        Cerrar
      </button>
      <template v-if="editable && actual">
        <button
          v-if="actual.hora_prestamo_real"
          type="button"
          :disabled="ocupado || editandoIntegrantes"
          class="px-4 py-2.5 border border-emerald-300 rounded-lg text-emerald-700 hover:bg-emerald-50 transition-colors font-medium text-sm disabled:opacity-50"
          @click="confirmando = 'devolver'"
        >
          Registrar devolución
        </button>
        <button
          type="button"
          :disabled="ocupado || editandoIntegrantes"
          class="px-4 py-2.5 border border-red-300 rounded-lg text-red-700 hover:bg-red-50 transition-colors font-medium text-sm disabled:opacity-50"
          @click="confirmando = 'cancelar'"
        >
          Cancelar reserva
        </button>
      </template>
    </template>
  </Modal>

  <DialogoConfirmacion
    :abierto="!!actual && confirmando === 'cancelar'"
    titulo="¿Cancelar esta reserva?"
    confirmar="Sí, cancelar reserva"
    cancelar="Volver"
    tono="peligro"
    :cargando="procesando"
    @confirmar="ejecutar"
    @cancelar="confirmando = null"
  >
    <p v-if="actual">
      Se cancelará la reserva de <strong>{{ actual.sala?.nombre }}</strong> del {{ fechaLargaReserva(actual.fecha) }} de {{ actual.hora_inicio }} a
      {{ actual.hora_fin }} ({{ actual.cantidad_personas }} personas). El horario queda libre para otra reserva y la cancelación queda en el Registro
      absoluto con tu nombre.
    </p>
  </DialogoConfirmacion>

  <DialogoConfirmacion
    :abierto="!!actual && confirmando === 'devolver'"
    titulo="¿Registrar la devolución de la llave?"
    confirmar="Sí, registrar devolución"
    cancelar="Volver"
    tono="exito"
    :cargando="procesando"
    @confirmar="ejecutar"
    @cancelar="confirmando = null"
  >
    <p v-if="actual">
      La reserva de <strong>{{ actual.sala?.nombre }}</strong> ({{ actual.hora_inicio }} a {{ actual.hora_fin }}) quedará finalizada con la devolución
      registrada ahora, a tu nombre.
    </p>
  </DialogoConfirmacion>
</template>
