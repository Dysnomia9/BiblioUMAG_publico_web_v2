<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import EditorIntegrantes from '@/components/salas/EditorIntegrantes.vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { SITUACIONES, duracion, fechaLargaReserva, horaDe, reservaEditable } from '@/utils/reservas'
import type { AtrasoDevolucion, IntegrantesReserva, ListadoReservas, ReservaListado, TiempoSinUsar } from '@/types'

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

// atraso: la llave volvió fuera de hora; la pantalla muestra el aviso para decidir la multa.
// sinUsar: volvió antes de tiempo; el aviso para liberar el resto del bloque.
// (Este detalle se cierra al registrar la devolución.)
const emit = defineEmits<{ cerrar: []; actualizada: []; atraso: [aviso: AtrasoDevolucion]; sinUsar: [aviso: TiempoSinUsar] }>()

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
// Liberar el resto de un bloque devuelto antes lo puede el mesón de salas o quien edita reservas.
const auth = useAuthStore()
const puedeLiberar = computed(() => !!actual.value?.tiempo_sin_usar && (props.puedeEditar || auth.puede('salas.gestionar')))

function liberarResto() {
  if (!actual.value?.tiempo_sin_usar) return
  emit('sinUsar', actual.value.tiempo_sin_usar)
  emit('cerrar')
}
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
      const { data } = await api.patch<{ atraso_devolucion?: AtrasoDevolucion | null; tiempo_sin_usar?: TiempoSinUsar | null }>(
        `/reservas/${actual.value.id}/devolver`,
      )
      toast.success('Devolución de la llave registrada')
      if (data.atraso_devolucion) emit('atraso', data.atraso_devolucion)
      if (data.tiempo_sin_usar) emit('sinUsar', data.tiempo_sin_usar)
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
    pie-con-fondo
    :bloqueado="ocupado"
    @cerrar="cerrar"
  >
    <template #cabecera>
      <div v-if="actual" class="relative -mx-6 -mt-6 mb-4 overflow-hidden rounded-t-2xl border-b border-gray-100 bg-gradient-to-b from-indigo-50 to-white">
        <div class="h-1" style="background: linear-gradient(90deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);" aria-hidden="true"></div>
        <!-- Motivo: las puertas de las salas. -->
        <svg class="pointer-events-none absolute bottom-0 right-5 hidden h-[4.75rem] w-32 text-indigo-300 sm:block" viewBox="0 0 128 76" fill="none" aria-hidden="true">
          <g stroke="currentColor" stroke-opacity="0.7" stroke-width="1.300" stroke-linejoin="round">
            <rect x="16" y="6" width="44" height="68" rx="3" fill="currentColor" fill-opacity="0.12" />
            <rect x="70" y="6" width="44" height="68" rx="3" fill="currentColor" fill-opacity="0.12" />
          </g>
          <rect x="26" y="14" width="24" height="7" rx="2" fill="#b08d57" fill-opacity="0.35" />
          <rect x="80" y="14" width="24" height="7" rx="2" fill="#b08d57" fill-opacity="0.35" />
          <circle cx="53" cy="42" r="2" fill="currentColor" />
          <circle cx="107" cy="42" r="2" fill="currentColor" />
          <rect y="74" width="128" height="1.500" fill="currentColor" fill-opacity="0.6" />
        </svg>
        <div class="relative px-6 py-3.5 sm:pr-40">
          <h3 class="font-serif text-xl font-bold text-gray-900">{{ actual.sala?.nombre ?? 'Reserva' }}</h3>
          <p class="mt-0.5 text-sm text-gray-600">{{ fechaLargaReserva(actual.fecha) }} · {{ actual.hora_inicio }} a {{ actual.hora_fin }} ({{ duracion(actual.duracion_minutos) }})</p>
          <div class="mt-2 flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="SITUACIONES[actual.situacion].clase">
              <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
              {{ SITUACIONES[actual.situacion].texto }}
            </span>
            <span class="text-xs text-gray-500">Reserva <strong class="font-semibold text-gray-700">#{{ actual.id }}</strong> · registrada el {{ new Date(actual.created_at).toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' }) }}</span>
          </div>
        </div>
      </div>
    </template>

    <div v-if="actual" class="space-y-4 text-sm">
      <!-- Sala y horario -->
      <section>
        <p class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-gray-600"><span class="h-3.5 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>Sala y horario</p>
        <form v-if="editable" class="rounded-xl border border-gray-200 bg-gray-50 p-3.5" @submit.prevent="guardar">
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block sm:col-span-2">
              <span class="mb-1 block text-xs font-medium text-gray-600">Sala</span>
              <select v-model.number="form.sala_id" :disabled="ocupado" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500">
                <option v-for="s in salas.filter((sala) => sala.activa !== false)" :key="s.id" :value="s.id">{{ s.nombre }}</option>
              </select>
            </label>
            <label class="block sm:col-span-2">
              <span class="mb-1 block text-xs font-medium text-gray-600">Día</span>
              <input
                v-model="form.fecha"
                type="date"
                required
                :disabled="ocupado || conLlegada"
                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500 disabled:border-dashed disabled:border-gray-300 disabled:bg-gray-100 disabled:text-gray-500"
              />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-medium text-gray-600">Desde</span>
              <input v-model="form.hora_inicio" type="time" step="1800" required :disabled="ocupado" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-medium text-gray-600">Hasta</span>
              <input v-model="form.hora_fin" type="time" step="1800" required :disabled="ocupado" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500" />
            </label>
          </div>
          <p class="mt-3 rounded-lg border border-gray-100 bg-white px-3 py-2 text-xs text-gray-600">
            <template v-if="conLlegada"><strong class="font-semibold text-gray-800">El grupo ya está en la sala:</strong> se puede cambiar de sala o de horario, pero no de día. </template>
            Mismas reglas que al reservar: dentro del horario de atención, hasta 2 horas, en punto o y media, y sin chocar con otra reserva de la
            sala ni de los integrantes.
          </p>
          <div class="mt-3 flex justify-end gap-2">
            <button
              v-if="hayCambios"
              type="button"
              :disabled="ocupado"
              class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-100"
              @click="llenarFormulario(actual)"
            >
              Descartar
            </button>
            <button type="submit" :disabled="ocupado || !hayCambios" class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
              {{ guardando ? 'Guardando…' : 'Guardar cambios' }}
            </button>
          </div>
        </form>
        <dl v-else class="grid divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200 bg-gray-50 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
          <div class="px-4 py-2.5">
            <dt class="text-xs text-gray-500">Sala</dt>
            <dd class="font-semibold text-gray-900">{{ actual.sala?.nombre ?? '—' }}</dd>
          </div>
          <div class="px-4 py-2.5">
            <dt class="text-xs text-gray-500">Día</dt>
            <dd class="font-semibold text-gray-900">{{ new Date(`${actual.fecha}T12:00:00`).toLocaleDateString('es-CL') }}</dd>
          </div>
          <div class="px-4 py-2.5">
            <dt class="text-xs text-gray-500">Horario</dt>
            <dd class="font-semibold text-gray-900">
              {{ actual.hora_inicio }} a {{ actual.hora_fin }}
              <span v-if="actual.hora_fin_reservada" class="block text-xs font-normal text-gray-500">
                Reservada hasta las {{ actual.hora_fin_reservada }}: devolvieron antes y se liberó el resto
              </span>
            </dd>
          </div>
        </dl>
        <p v-if="puedeEditar && !editable" class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">
          Las reservas finalizadas, canceladas o en que no se presentaron quedan como registro y no se editan.
        </p>
      </section>

      <!-- Integrantes -->
      <section>
        <div class="mb-2 flex items-center justify-between gap-3">
          <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-gray-600"><span class="h-3.5 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>Integrantes <span class="rounded-full bg-indigo-50 px-2 text-[11px] font-bold tabular-nums text-indigo-700">{{ actual.personas.length }}</span></p>
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
          :solo-responsable="!!salas.find((s) => s.id === actual?.sala?.id)?.solo_responsable"
          @guardado="integrantesGuardados"
          @cancelar="editandoIntegrantes = false"
        />
        <ul v-else class="space-y-2">
          <li v-for="p in actual.personas" :key="p.rut" class="relative flex items-center justify-between gap-3 overflow-hidden rounded-lg border border-gray-200 bg-white py-2 pl-4 pr-3">
            <span class="absolute inset-y-0 left-0 w-1" :class="titular(p.rut) ? 'bg-indigo-600' : 'bg-gray-200'" aria-hidden="true"></span>
            <span class="min-w-0 truncate font-semibold text-gray-900">
              {{ p.nombre ?? 'Sin registro en el sistema' }}
              <span v-if="titular(p.rut)" class="ml-1 rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-bold uppercase text-indigo-700">A cargo</span>
            </span>
            <span class="shrink-0 rounded-md border border-gray-200 bg-gray-50 px-1.5 py-0.5 font-mono text-[11px] text-gray-700">{{ p.rut }}</span>
          </li>
          <li v-if="!actual.personas.length" class="text-gray-400">Sin integrantes registrados.</li>
        </ul>
      </section>

      <!-- Llave -->
      <section>
        <p class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-gray-600"><span class="h-3.5 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>Llave</p>
        <dl class="grid divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200 bg-gray-50 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
          <div class="px-4 py-2.5">
            <dt class="text-xs text-gray-500">Entrega (llegada del grupo)</dt>
            <dd class="font-semibold text-gray-900">
              <template v-if="actual.hora_prestamo_real">
                {{ horaDe(actual.hora_prestamo_real) }}<template v-if="actual.prestado_por"> · {{ actual.prestado_por }}</template>
                <span class="block text-xs font-normal text-gray-500">{{ actual.via === 'codigo' ? 'Con el código de la sala' : actual.via === 'BC' ? 'Con código de barras' : 'Confirmada desde la reserva' }}</span>
              </template>
              <template v-else-if="actual.situacion === 'pendiente'">Aún no llegan · tienen hasta las {{ horaDe(actual.plazo_confirmacion) }}</template>
              <span v-else class="font-normal text-gray-400">Sin registro</span>
            </dd>
          </div>
          <div class="px-4 py-2.5" :class="actual.situacion === 'sin_devolver' ? 'border-t-2 border-t-red-500 bg-red-50' : ''">
            <dt class="text-xs text-gray-500">Devolución</dt>
            <dd class="font-semibold" :class="actual.situacion === 'sin_devolver' ? 'text-red-700' : 'text-gray-900'">
              <template v-if="actual.hora_devolucion_real">
                {{ horaDe(actual.hora_devolucion_real) }}<template v-if="actual.devuelto_por"> · {{ actual.devuelto_por }}</template>
              </template>
              <template v-else-if="actual.situacion === 'sin_devolver'">Pendiente: su horario ya terminó</template>
              <template v-else-if="actual.situacion === 'en_uso'">Pendiente</template>
              <span v-else class="font-normal text-gray-400">Sin registro</span>
            </dd>
          </div>
        </dl>
      </section>
    </div>

    <template #pie>
      <button type="button" :disabled="ocupado" class="px-4 py-2 border border-gray-200 bg-white rounded-lg text-gray-800 shadow-sm hover:bg-gray-100 transition-colors font-semibold text-sm sm:mr-auto" @click="cerrar">
        Cerrar
      </button>
      <button
        v-if="puedeLiberar && actual?.tiempo_sin_usar"
        type="button"
        class="px-4 py-2 border border-emerald-300 bg-emerald-50 rounded-lg text-emerald-700 hover:bg-emerald-100 transition-colors font-semibold text-sm"
        @click="liberarResto"
      >
        Liberar el resto del bloque · {{ duracion(actual.tiempo_sin_usar.minutos) }} sin usar
      </button>
      <template v-if="editable && actual">
        <button
          v-if="actual.hora_prestamo_real"
          type="button"
          :disabled="ocupado || editandoIntegrantes"
          class="px-4 py-2 border border-emerald-300 bg-emerald-50 rounded-lg text-emerald-700 hover:bg-emerald-100 transition-colors font-semibold text-sm disabled:opacity-50"
          @click="confirmando = 'devolver'"
        >
          Registrar devolución
        </button>
        <button
          type="button"
          :disabled="ocupado || editandoIntegrantes"
          class="px-4 py-2 border border-red-300 bg-white rounded-lg text-red-700 hover:bg-red-50 transition-colors font-semibold text-sm disabled:opacity-50"
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
    destacado
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
    destacado
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
