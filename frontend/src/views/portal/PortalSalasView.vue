<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import PortalLayout from '@/components/layout/PortalLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import apiUsuario from '@/services/apiUsuario'
import { useToast } from '@/composables/useToast'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import { formatRut } from '@/composables/useRut'
import type { Sala, TramoReserva } from '@/types'

const toast = useToast()
const auth = useUsuarioAuthStore()
const router = useRouter()

const hoy = new Date().toISOString().slice(0, 10)
const DURACIONES = [30, 60, 90, 120]
const CANTIDAD_MIN = 2
const CANTIDAD_MAX = 5

const salas = ref<Sala[]>([])
const apiError = ref(false)
const busqueda = ref('')

const apertura = ref('08:00')
const cierre = ref('21:00')
const granularidad = ref(30)
const duracionMinima = ref(30)
const duracionMaxima = ref(120)

function timeToMinutes(hhmm: string): number {
  const [h, m] = hhmm.split(':').map(Number)
  return h * 60 + m
}
function minutesToTime(min: number): string {
  const h = Math.floor(min / 60)
  const m = min % 60
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
}
function formatMinutos(min: number): string {
  const h = Math.floor(min / 60)
  const m = min % 60
  if (h === 0) return `${m} min`
  return m === 0 ? `${h} h` : `${h} h ${m} min`
}

const aperturaMin = computed(() => timeToMinutes(apertura.value))
const cierreMin = computed(() => timeToMinutes(cierre.value))
const totalMin = computed(() => cierreMin.value - aperturaMin.value)
function pct(hhmm: string): number {
  return ((timeToMinutes(hhmm) - aperturaMin.value) / totalMin.value) * 100
}

/** Ancho del bloque en % de la línea de tiempo, con un piso mínimo para que un tramo corto (ej. 30 min) siga siendo visible. */
function anchoTramoPct(tramo: TramoReserva): number {
  return Math.max(pct(tramo.hora_fin) - pct(tramo.hora_inicio), 2.5)
}

const ahora = ref(new Date())
let relojTimer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  relojTimer = setInterval(() => (ahora.value = new Date()), 30000)
})
onUnmounted(() => clearInterval(relojTimer))
const ahoraMin = computed(() => ahora.value.getHours() * 60 + ahora.value.getMinutes())
const nowPct = computed(() => Math.min(100, Math.max(0, ((ahoraMin.value - aperturaMin.value) / totalMin.value) * 100)))

const modalOpen = ref(false)
const selectedSala = ref<Sala | null>(null)
const modalInmediata = ref(false)
const modalHoraInicio = ref('')
const modalDuracion = ref(60)
const enviando = ref(false)
const cantidadPersonas = ref(CANTIDAD_MIN)
const rutsReserva = ref<string[]>(Array.from({ length: CANTIDAD_MIN }, () => ''))
const rutErrores = ref<Record<number, string>>({})

watch(cantidadPersonas, (nueva) => {
  const actuales = rutsReserva.value.length
  if (nueva > actuales) {
    rutsReserva.value.push(...Array.from({ length: nueva - actuales }, () => ''))
  } else if (nueva < actuales) {
    rutsReserva.value.splice(nueva)
  }
})

async function cargar() {
  try {
    const { data } = await apiUsuario.get('/mi/salas', { params: { fecha: hoy } })
    salas.value = data.salas
    apertura.value = data.apertura
    cierre.value = data.cierre
    granularidad.value = data.granularidad
    duracionMinima.value = data.duracion_minima
    duracionMaxima.value = data.duracion_maxima
    apiError.value = false
  } catch {
    apiError.value = true
    salas.value = []
  }
}

onMounted(cargar)

const filteredSalas = computed(() => {
  if (!busqueda.value.trim()) return salas.value
  const q = busqueda.value.toLowerCase()
  return salas.value.filter((s) => s.nombre.toLowerCase().includes(q))
})

function esMia(tramo: TramoReserva) {
  return tramo.usuario_id === auth.usuario?.id
}

function disponibilidadDesde(sala: Sala, desdeMin: number): number {
  if (desdeMin >= cierreMin.value) return 0
  const ocupando = sala.tramos.some((t) => timeToMinutes(t.hora_inicio) <= desdeMin && timeToMinutes(t.hora_fin) > desdeMin)
  if (ocupando) return 0
  const siguientes = sala.tramos.map((t) => timeToMinutes(t.hora_inicio)).filter((i) => i >= desdeMin).sort((a, b) => a - b)
  const limite = siguientes.length ? siguientes[0] : cierreMin.value
  return Math.max(0, Math.min(limite - desdeMin, duracionMaxima.value))
}

const modalInicioMin = computed(() => timeToMinutes(modalHoraInicio.value || apertura.value))
const modalDisponibleMin = computed(() => (selectedSala.value ? disponibilidadDesde(selectedSala.value, modalInicioMin.value) : 0))
const modalDuracionesValidas = computed(() => DURACIONES.filter((d) => d >= duracionMinima.value && d <= modalDisponibleMin.value))

const horaInicioOpciones = computed(() => {
  const opciones: string[] = []
  const minimoInicio = Math.max(aperturaMin.value, Math.ceil(ahoraMin.value / granularidad.value) * granularidad.value)
  for (let m = minimoInicio; m <= cierreMin.value - duracionMinima.value; m += granularidad.value) {
    opciones.push(minutesToTime(m))
  }
  return opciones
})

function abrirModal(sala: Sala, inicioSugeridoMin: number) {
  selectedSala.value = sala
  modalInmediata.value = false
  const alineado = Math.max(aperturaMin.value, Math.ceil(inicioSugeridoMin / granularidad.value) * granularidad.value)
  modalHoraInicio.value = minutesToTime(Math.min(alineado, cierreMin.value - duracionMinima.value))
  const disponibles = DURACIONES.filter((d) => d >= duracionMinima.value && d <= disponibilidadDesde(sala, timeToMinutes(modalHoraInicio.value)))
  modalDuracion.value = disponibles.length ? disponibles[disponibles.length - 1] : duracionMinima.value
  cantidadPersonas.value = CANTIDAD_MIN
  rutsReserva.value = Array.from({ length: CANTIDAD_MIN }, (_, idx) => (idx === 0 ? (auth.usuario?.rut ?? '') : ''))
  rutErrores.value = {}
  modalOpen.value = true
}

function onTimelineClick(sala: Sala, event: MouseEvent) {
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const fraccion = (event.clientX - rect.left) / rect.width
  const minutoClickeado = aperturaMin.value + fraccion * totalMin.value

  if (minutoClickeado < ahoraMin.value) {
    toast.error('Esa hora ya pasó — elige un horario desde ahora en adelante, o usa "Reservar ahora".')
    return
  }
  if (disponibilidadDesde(sala, Math.ceil(minutoClickeado / granularidad.value) * granularidad.value) <= 0) {
    toast.error('Esa hora ya está ocupada en esta sala.')
    return
  }

  abrirModal(sala, minutoClickeado)
}

function reservarAhora() {
  const candidatas = filteredSalas.value
    .map((s) => ({ sala: s, disponible: disponibilidadDesde(s, ahoraMin.value) }))
    .filter((c) => c.disponible >= duracionMinima.value)
    .sort((a, b) => b.disponible - a.disponible)

  if (!candidatas.length) {
    toast.error('No hay salas disponibles en este momento')
    return
  }

  selectedSala.value = candidatas[0].sala
  modalInmediata.value = true
  modalHoraInicio.value = minutesToTime(ahoraMin.value)
  const disponibles = DURACIONES.filter((d) => d >= duracionMinima.value && d <= candidatas[0].disponible)
  modalDuracion.value = disponibles.length ? disponibles[disponibles.length - 1] : duracionMinima.value
  cantidadPersonas.value = CANTIDAD_MIN
  rutsReserva.value = Array.from({ length: CANTIDAD_MIN }, (_, idx) => (idx === 0 ? (auth.usuario?.rut ?? '') : ''))
  rutErrores.value = {}
  modalOpen.value = true

  if (candidatas[0].disponible < duracionMaxima.value) {
    toast.error(`Ninguna sala tiene ${formatMinutos(duracionMaxima.value)} libres ahora; ${candidatas[0].sala.nombre} está libre ${formatMinutos(candidatas[0].disponible)}`)
  }
}

function onRutInput(index: number, event: Event) {
  rutsReserva.value[index] = formatRut((event.target as HTMLInputElement).value)
  if (rutErrores.value[index]) {
    const restantes = { ...rutErrores.value }
    delete restantes[index]
    rutErrores.value = restantes
  }
}

function primerMensajeError(e: any): string | undefined {
  const errores = e?.response?.data?.errors
  if (errores) {
    const primero = Object.values(errores)[0]
    if (Array.isArray(primero) && typeof primero[0] === 'string') return primero[0]
  }
  return e?.response?.data?.message
}

async function confirmarReserva() {
  if (!selectedSala.value) return
  if (rutsReserva.value.some((r) => !r.trim())) {
    toast.error('Complete el RUT de cada persona')
    return
  }
  enviando.value = true
  rutErrores.value = {}
  try {
    const { data } = await apiUsuario.post('/mi/reservas', {
      sala_id: selectedSala.value.id,
      fecha: hoy,
      hora_inicio: modalHoraInicio.value,
      hora_fin: minutesToTime(Math.min(modalInicioMin.value + modalDuracion.value, cierreMin.value)),
      cantidad_personas: cantidadPersonas.value,
      ruts: rutsReserva.value,
      inmediata: modalInmediata.value,
    })
    toast.success(`${selectedSala.value.nombre} reservada de ${data.hora_inicio.slice(0, 5)} a ${data.hora_fin.slice(0, 5)}`)
    modalOpen.value = false
    await cargar()
  } catch (e: any) {
    const errores = e?.response?.data?.errors as Record<string, string[]> | undefined
    const erroresRuts = Object.entries(errores ?? {}).filter(([campo]) => campo.startsWith('ruts.'))

    if (erroresRuts.length) {
      const mapa: Record<number, string> = {}
      for (const [campo, mensajes] of erroresRuts) {
        const idx = Number(campo.split('.')[1])
        mapa[idx] = mensajes[0]
      }
      rutErrores.value = mapa
      toast.error(erroresRuts.length === 1 ? 'Revisa el RUT marcado en rojo' : 'Revisa los RUT marcados en rojo')
    } else {
      toast.error(primerMensajeError(e) ?? 'No se pudo crear la reserva')
    }
  } finally {
    enviando.value = false
  }
}

async function cancelarPropia(tramo: TramoReserva) {
  try {
    await apiUsuario.delete(`/mi/reservas/${tramo.reserva_id}`)
    toast.success('Reserva cancelada')
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo cancelar la reserva')
  }
}
</script>

<template>
  <PortalLayout>
    <div class="max-w-[1100px] mx-auto">
      <button
        @click="router.push({ name: 'portal-home' })"
        class="mb-5 flex items-center gap-2 text-sm text-gray-600 hover:text-indigo-700"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
        Volver al inicio
      </button>

      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Reservar Sala de Estudio</h1>
          <p class="text-sm text-white/60 mt-1">Elige la hora de inicio y la duración (hasta {{ formatMinutos(duracionMaxima) }}) — puedes cancelar únicamente tus propias reservas</p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="flex items-start gap-2.5 bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-5 text-sm text-amber-800">
        <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
        </svg>
        <p>
          Tienes <strong>15 minutos</strong> desde que empieza tu reserva para presentarte y que el personal confirme tu
          llegada. Si nadie se presenta dentro de ese plazo, la sala queda disponible para otra persona.
        </p>
      </div>

      <div class="bg-white border border-gray-200 rounded-lg p-4 mb-5 flex flex-col sm:flex-row gap-3 sm:items-end">
        <div class="flex-1">
          <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Buscar sala</label>
          <input
            v-model="busqueda"
            type="text"
            placeholder="Ej: Logia 03"
            class="w-full px-3.5 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none text-sm"
          />
        </div>
        <p class="text-xs text-gray-400 sm:pb-2.5">
          Solo puedes reservar para <strong>hoy</strong>
        </p>
        <button
          @click="reservarAhora"
          class="px-4 py-2.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors text-sm font-medium shrink-0"
        >
          Reservar ahora
        </button>
      </div>

      <div class="bg-white border border-gray-200 rounded-lg p-4">
        <p class="text-xs text-gray-400 mb-3">
          Click en cualquier espacio libre (verde) de la línea de tiempo para reservar esa sala a esa hora.
        </p>
        <div class="flex text-[10px] font-medium text-gray-400 mb-2 px-[112px] justify-between">
          <span v-for="h in [8, 10, 12, 14, 16, 18, 20]" :key="h">{{ String(h).padStart(2, '0') }}:00</span>
        </div>
        <div class="space-y-2.5">
          <div v-for="sala in filteredSalas" :key="sala.id" class="flex items-center gap-3">
            <div class="w-[100px] shrink-0">
              <div class="font-medium text-sm text-gray-900 truncate">{{ sala.nombre }}</div>
              <div class="text-xs text-gray-400">{{ sala.capacidad }} personas</div>
            </div>
            <div
              class="group relative h-12 flex-1 bg-emerald-50/70 hover:bg-emerald-100/90 rounded-xl overflow-hidden border border-emerald-100 shadow-inner cursor-pointer transition-colors"
              @click="onTimelineClick(sala, $event)"
            >
              <div class="absolute inset-0 flex pointer-events-none">
                <div
                  v-for="h in [10, 12, 14, 16, 18, 20]"
                  :key="h"
                  class="absolute inset-y-0 border-l border-emerald-900/[0.06]"
                  :style="{ left: pct(`${h}:00`) + '%' }"
                />
              </div>
              <p
                v-if="!sala.tramos.length"
                class="absolute inset-0 flex items-center justify-center text-[11px] font-medium text-emerald-700/60 group-hover:text-emerald-700 pointer-events-none transition-colors"
              >
                + Click para reservar
              </p>
              <div class="absolute inset-y-0 left-0 bg-gray-500/[0.06] pointer-events-none" :style="{ width: nowPct + '%' }" />
              <div
                v-for="tramo in sala.tramos"
                :key="tramo.reserva_id"
                class="absolute inset-y-1 border flex items-center justify-center text-[10px] font-semibold overflow-hidden whitespace-nowrap shadow-sm hover:shadow-md hover:z-20 hover:scale-y-105 transition-all"
                :class="[esMia(tramo) ? 'bg-indigo-100 border-indigo-300 text-indigo-800' : 'bg-red-100 border-red-300 text-red-700', anchoTramoPct(tramo) < 6 ? 'rounded-full' : 'rounded-lg px-1.5']"
                :style="{ left: pct(tramo.hora_inicio) + '%', width: anchoTramoPct(tramo) + '%' }"
                :title="`${tramo.hora_inicio.slice(0, 5)} – ${tramo.hora_fin.slice(0, 5)}${esMia(tramo) ? ' · tu reserva' : ''}`"
              >
                <button v-if="esMia(tramo)" @click.stop="cancelarPropia(tramo)" class="hover:underline w-full h-full flex items-center justify-center">
                  <span v-if="anchoTramoPct(tramo) >= 6">{{ tramo.hora_inicio.slice(0, 5) }}–{{ tramo.hora_fin.slice(0, 5) }} (cancelar)</span>
                  <span v-else>×</span>
                </button>
                <span v-else-if="anchoTramoPct(tramo) >= 6">{{ tramo.hora_inicio.slice(0, 5) }}–{{ tramo.hora_fin.slice(0, 5) }}</span>
              </div>
              <div
                class="absolute inset-y-0 w-[3px] -ml-px bg-indigo-600 pointer-events-none rounded-full shadow-[0_0_0_2px_rgba(79,70,229,0.15)]"
                :style="{ left: nowPct + '%' }"
              />
            </div>
          </div>
          <p v-if="!filteredSalas.length" class="text-sm text-gray-400 text-center py-6">Sin salas que coincidan con la búsqueda.</p>
        </div>
      </div>

      <div class="flex flex-wrap gap-x-4 gap-y-1.5 mt-4 text-xs text-gray-500">
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded border border-emerald-200 bg-emerald-50" /> Disponible (click para reservar)</div>
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded border border-indigo-300 bg-indigo-100" /> Tu reserva</div>
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded border border-red-300 bg-red-100" /> Ocupada</div>
      </div>

      <div
        v-if="modalOpen && selectedSala"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="modalOpen = false"
      >
        <div class="bg-white rounded-xl shadow-2xl border border-gray-200 p-6 w-full max-w-sm">
          <h3 class="text-lg font-semibold text-gray-900 mb-1">Confirmar reserva</h3>
          <p class="text-sm text-gray-500 mb-5">{{ selectedSala.nombre }}</p>

          <div class="space-y-4 mb-5">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Inicio</label>
                <select
                  v-if="!modalInmediata"
                  v-model="modalHoraInicio"
                  class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
                >
                  <option v-for="h in horaInicioOpciones" :key="h" :value="h">{{ h }}</option>
                </select>
                <div v-else class="px-3 py-2.5 border border-gray-200 bg-gray-50 rounded-lg text-gray-600 font-mono text-sm">
                  Ahora ({{ modalHoraInicio }})
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Duración</label>
                <select
                  v-model.number="modalDuracion"
                  class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
                >
                  <option v-for="d in DURACIONES" :key="d" :value="d" :disabled="!modalDuracionesValidas.includes(d)">
                    {{ formatMinutos(d) }}
                  </option>
                </select>
              </div>
            </div>
            <p v-if="modalDisponibleMin < duracionMinima" class="text-xs text-red-600">
              Esta sala está libre solo {{ formatMinutos(modalDisponibleMin) }} desde las {{ modalHoraInicio }}.
            </p>
            <p v-else class="text-xs text-gray-400">Libre {{ formatMinutos(modalDisponibleMin) }} desde las {{ modalHoraInicio }}.</p>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad de personas</label>
              <select
                v-model.number="cantidadPersonas"
                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
              >
                <option v-for="n in CANTIDAD_MAX - CANTIDAD_MIN + 1" :key="n" :value="CANTIDAD_MIN + n - 1">
                  {{ CANTIDAD_MIN + n - 1 }} personas
                </option>
              </select>
            </div>
            <div class="space-y-2">
              <label class="block text-sm font-medium text-gray-700">RUT de cada persona</label>
              <p class="text-xs text-gray-400 -mt-1 mb-1">Deben ser RUT de usuarios registrados en el sistema</p>
              <div v-for="(_, idx) in rutsReserva" :key="idx">
                <input
                  :value="rutsReserva[idx]"
                  @input="onRutInput(idx, $event)"
                  type="text"
                  :placeholder="`RUT persona ${idx + 1}`"
                  maxlength="12"
                  class="w-full px-4 py-2.5 border rounded-lg focus:ring-2 outline-none"
                  :class="rutErrores[idx] ? 'border-red-400 ring-1 ring-red-200 focus:ring-red-400' : 'border-gray-300 focus:ring-indigo-500'"
                />
                <p v-if="rutErrores[idx]" class="text-xs text-red-600 mt-1">{{ rutErrores[idx] }}</p>
              </div>
            </div>
          </div>

          <div class="flex gap-3">
            <button
              @click="modalOpen = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="confirmarReserva"
              :disabled="enviando || modalDisponibleMin < duracionMinima"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ enviando ? 'Reservando…' : 'Confirmar' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </PortalLayout>
</template>
