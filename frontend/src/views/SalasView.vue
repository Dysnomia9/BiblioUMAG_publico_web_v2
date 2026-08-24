<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import { formatRut } from '@/composables/useRut'
import { useAuthStore } from '@/stores/auth'
import type { Sala, TramoReserva } from '@/types'

const toast = useToast()
const auth = useAuthStore()
// Solo el admin puede agendar en un horario ya pasado (ej. registrar algo que se
// atendió sin pasar por el sistema) — el resto del staff sigue restringido a "desde
// ahora en adelante", igual que antes. El backend re-valida esto igual (ver
// ReservaSalaService::validarTramo()), esto solo evita mostrar una opción que el
// servidor rechazaría para quien no es admin.
const esAdmin = computed(() => auth.staff?.rol === 'admin')

const hoy = new Date().toISOString().slice(0, 10)

const DURACIONES = [30, 60, 90, 120]

const CANTIDAD_MIN = 2
const CANTIDAD_MAX = 5

const salas = ref<Sala[]>([])
const apiError = ref(false)
const selectedDate = ref(hoy)
const busqueda = ref('')
const page = ref(0)
const salasPerPage = 10

// Ventana de atención y reglas del día — vienen del backend (config/salas.php), nunca hardcodeadas acá.
const apertura = ref('08:00')
const cierre = ref('21:00')
const granularidad = ref(30)
const duracionMinima = ref(30)
const duracionMaxima = ref(120)
const cuotaDiaria = ref(240)

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

/** Ancho del bloque en % de la línea de tiempo, con un piso mínimo para que un tramo corto (ej. 30 min) siga siendo clickeable y visible. */
function anchoTramoPct(tramo: TramoReserva): number {
  return Math.max(pct(tramo.hora_fin) - pct(tramo.hora_inicio), 2.5)
}

const modalOpen = ref(false)
const selectedSala = ref<Sala | null>(null)
const modalInmediata = ref(false)
const modalHoraInicio = ref('')
const modalDuracion = ref(60)
const cantidadPersonas = ref(CANTIDAD_MIN)
const rutsReserva = ref<string[]>(Array.from({ length: CANTIDAD_MIN }, () => ''))
const rutErrores = ref<Record<number, string>>({})

const detalleOpen = ref(false)
const detalleTramo = ref<TramoReserva | null>(null)
const detalleSala = ref<Sala | null>(null)

const cancelacionPendiente = ref<{ reservaId: number; salaNombre: string; label: string } | null>(null)
const devolucionPendiente = ref<{ reservaId: number; salaNombre: string; label: string } | null>(null)
const devolviendo = ref(false)
const llegadaPendiente = ref<{ reservaId: number; salaNombre: string; label: string } | null>(null)
const confirmandoLlegada = ref(false)
const liberando = ref<number | null>(null)

const ahora = ref(new Date())
let relojTimer: ReturnType<typeof setInterval> | undefined
let refrescoTimer: ReturnType<typeof setInterval> | undefined

const codigoLogiaScan = ref('')
const escaneando = ref(false)

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
    const { data } = await api.get('/salas', { params: { fecha: selectedDate.value } })
    salas.value = data.salas
    apertura.value = data.apertura
    cierre.value = data.cierre
    granularidad.value = data.granularidad
    duracionMinima.value = data.duracion_minima
    duracionMaxima.value = data.duracion_maxima
    cuotaDiaria.value = data.cuota_diaria
    apiError.value = false
  } catch {
    apiError.value = true
    salas.value = []
  }
}

onMounted(() => {
  cargar()
  relojTimer = setInterval(() => (ahora.value = new Date()), 1000)
  refrescoTimer = setInterval(cargar, 60000)
})
onUnmounted(() => {
  clearInterval(relojTimer)
  clearInterval(refrescoTimer)
})
watch(selectedDate, cargar)

async function escanearLogia() {
  if (!codigoLogiaScan.value.trim()) {
    toast.error('Ingrese el código de barras')
    return
  }
  escaneando.value = true
  try {
    const { data } = await api.post('/salas/scan-logia', {
      codigo_barras: codigoLogiaScan.value.trim(),
    })
    toast.success(data.hora_devolucion_real ? 'Devolución de logia registrada' : 'Entrega de logia registrada')
    codigoLogiaScan.value = ''
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo procesar el escaneo')
  } finally {
    escaneando.value = false
  }
}

const filteredSalas = computed(() => {
  if (!busqueda.value.trim()) return salas.value
  const q = busqueda.value.toLowerCase()
  return salas.value.filter((s) => s.nombre.toLowerCase().includes(q) || String(s.capacidad).includes(q))
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredSalas.value.length / salasPerPage)))
const salasPage = computed(() => filteredSalas.value.slice(page.value * salasPerPage, (page.value + 1) * salasPerPage))

const esHoy = computed(() => selectedDate.value === hoy)
const ahoraMin = computed(() => ahora.value.getHours() * 60 + ahora.value.getMinutes())
const nowPct = computed(() => Math.min(100, Math.max(0, ((ahoraMin.value - aperturaMin.value) / totalMin.value) * 100)))

/** Minutos libres en `sala` desde `desdeMin` (calculado en el cliente con los mismos datos que ya trae la vista, sin ida y vuelta al servidor — el backend vuelve a validar todo al confirmar). */
function disponibilidadDesde(sala: Sala, desdeMin: number): number {
  if (desdeMin >= cierreMin.value) return 0

  const ocupando = sala.tramos.some((t) => timeToMinutes(t.hora_inicio) <= desdeMin && timeToMinutes(t.hora_fin) > desdeMin)
  if (ocupando) return 0

  const siguientes = sala.tramos
    .map((t) => timeToMinutes(t.hora_inicio))
    .filter((inicio) => inicio >= desdeMin)
    .sort((a, b) => a - b)

  const limite = siguientes.length ? siguientes[0] : cierreMin.value
  return Math.max(0, Math.min(limite - desdeMin, duracionMaxima.value))
}

const modalInicioMin = computed(() => timeToMinutes(modalHoraInicio.value || apertura.value))
const modalDisponibleMin = computed(() => (selectedSala.value ? disponibilidadDesde(selectedSala.value, modalInicioMin.value) : 0))
const modalDuracionesValidas = computed(() => DURACIONES.filter((d) => d >= duracionMinima.value && d <= modalDisponibleMin.value))

const horaInicioOpciones = computed(() => {
  const opciones: string[] = []
  let minimoInicio = aperturaMin.value
  if (esHoy.value && !esAdmin.value) {
    minimoInicio = Math.max(minimoInicio, Math.ceil(ahoraMin.value / granularidad.value) * granularidad.value)
  }
  for (let m = minimoInicio; m <= cierreMin.value - duracionMinima.value; m += granularidad.value) {
    opciones.push(minutesToTime(m))
  }
  return opciones
})

function abrirModalProgramado(sala: Sala, inicioSugeridoMin: number) {
  selectedSala.value = sala
  modalInmediata.value = false
  const alineado = Math.max(aperturaMin.value, Math.ceil(inicioSugeridoMin / granularidad.value) * granularidad.value)
  modalHoraInicio.value = minutesToTime(Math.min(alineado, cierreMin.value - duracionMinima.value))
  const disponibles = DURACIONES.filter((d) => d >= duracionMinima.value && d <= disponibilidadDesde(sala, timeToMinutes(modalHoraInicio.value)))
  modalDuracion.value = disponibles.length ? disponibles[disponibles.length - 1] : duracionMinima.value
  cantidadPersonas.value = CANTIDAD_MIN
  rutsReserva.value = Array.from({ length: CANTIDAD_MIN }, () => '')
  rutErrores.value = {}
  modalOpen.value = true
}

function abrirModalInmediata(sala: Sala) {
  selectedSala.value = sala
  modalInmediata.value = true
  modalHoraInicio.value = minutesToTime(ahoraMin.value)
  const disponibles = DURACIONES.filter((d) => d >= duracionMinima.value && d <= disponibilidadDesde(sala, ahoraMin.value))
  modalDuracion.value = disponibles.length ? disponibles[disponibles.length - 1] : duracionMinima.value
  cantidadPersonas.value = CANTIDAD_MIN
  rutsReserva.value = Array.from({ length: CANTIDAD_MIN }, () => '')
  rutErrores.value = {}
  modalOpen.value = true
}

function onTimelineClick(sala: Sala, event: MouseEvent) {
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const fraccion = (event.clientX - rect.left) / rect.width
  const minutoClickeado = aperturaMin.value + fraccion * totalMin.value

  if (esHoy.value && !esAdmin.value && minutoClickeado < ahoraMin.value) {
    toast.error('Esa hora ya pasó — elige un horario desde ahora en adelante, o usa "Reservar ahora".')
    return
  }
  if (disponibilidadDesde(sala, Math.ceil(minutoClickeado / granularidad.value) * granularidad.value) <= 0) {
    toast.error('Esa hora ya está ocupada en esta sala.')
    return
  }

  abrirModalProgramado(sala, minutoClickeado)
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

  abrirModalInmediata(candidatas[0].sala)
  if (candidatas[0].disponible < duracionMaxima.value) {
    toast.error(`Ninguna sala tiene ${formatMinutos(duracionMaxima.value)} libres ahora; ${candidatas[0].sala.nombre} está libre ${formatMinutos(candidatas[0].disponible)}`)
  }
}

function verDetalle(sala: Sala, tramo: TramoReserva) {
  detalleSala.value = sala
  detalleTramo.value = tramo
  detalleOpen.value = true
}

function onRutInput(index: number, event: Event) {
  rutsReserva.value[index] = formatRut((event.target as HTMLInputElement).value)
  if (rutErrores.value[index]) {
    const restantes = { ...rutErrores.value }
    delete restantes[index]
    rutErrores.value = restantes
  }
}

function labelTramo(inicio: string, fin: string): string {
  return `${inicio} – ${fin}`
}

/** Color del bloque en la línea de tiempo según su estado — más informativo que un solo rojo parejo para "ocupado". */
function tramoClases(tramo: TramoReserva): string {
  if (tramo.hora_devolucion_real) return 'bg-gray-100 border-gray-300 text-gray-500'
  if (tramo.hora_prestamo_real) return 'bg-red-100 border-red-300 text-red-700'
  if (tramo.vencida_sin_confirmar) return 'bg-orange-100 border-orange-300 text-orange-700'
  return 'bg-amber-100 border-amber-300 text-amber-700'
}

async function confirmarReserva() {
  if (rutsReserva.value.some((r) => !r.trim())) {
    toast.error('Complete el RUT de cada persona')
    return
  }
  if (!selectedSala.value) return

  rutErrores.value = {}
  try {
    const { data } = await api.post('/reservas', {
      sala_id: selectedSala.value.id,
      fecha: selectedDate.value,
      hora_inicio: modalHoraInicio.value,
      hora_fin: minutesToTime(Math.min(modalInicioMin.value + modalDuracion.value, cierreMin.value)),
      cantidad_personas: cantidadPersonas.value,
      ruts: rutsReserva.value,
      inmediata: modalInmediata.value,
    })
    toast.success(`${selectedSala.value.nombre} reservada de ${labelTramo(data.hora_inicio.slice(0, 5), data.hora_fin.slice(0, 5))} para ${cantidadPersonas.value} persona(s)`)
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
      toast.error(e?.response?.data?.message ?? 'No se pudo crear la reserva')
    }
  }
}

function pedirCancelacion(sala: Sala, tramo: TramoReserva) {
  cancelacionPendiente.value = { reservaId: tramo.reserva_id, salaNombre: sala.nombre, label: labelTramo(tramo.hora_inicio, tramo.hora_fin) }
}

async function confirmarCancelacion() {
  if (!cancelacionPendiente.value) return
  try {
    await api.delete(`/reservas/${cancelacionPendiente.value.reservaId}`)
    toast.success('Reserva cancelada')
    detalleOpen.value = false
    await cargar()
  } catch {
    toast.error('No se pudo cancelar la reserva')
  } finally {
    cancelacionPendiente.value = null
  }
}

function pedirDevolucion(sala: Sala, tramo: TramoReserva) {
  devolucionPendiente.value = { reservaId: tramo.reserva_id, salaNombre: sala.nombre, label: labelTramo(tramo.hora_inicio, tramo.hora_fin) }
}

async function confirmarDevolucion() {
  if (!devolucionPendiente.value) return
  devolviendo.value = true
  try {
    await api.patch(`/reservas/${devolucionPendiente.value.reservaId}/devolver`)
    toast.success('Devolución de llave confirmada')
    devolucionPendiente.value = null
    detalleOpen.value = false
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo confirmar la devolución')
  } finally {
    devolviendo.value = false
  }
}

function formatFechaLarga(fecha: string) {
  return fecha === hoy ? 'Hoy' : new Date(`${fecha}T12:00:00`).toLocaleDateString('es-CL')
}

function pedirLlegada(sala: Sala, tramo: TramoReserva) {
  llegadaPendiente.value = { reservaId: tramo.reserva_id, salaNombre: sala.nombre, label: labelTramo(tramo.hora_inicio, tramo.hora_fin) }
}

async function confirmarLlegadaAction() {
  if (!llegadaPendiente.value) return
  confirmandoLlegada.value = true
  try {
    await api.patch(`/reservas/${llegadaPendiente.value.reservaId}/llegada`)
    toast.success('Llegada confirmada')
    llegadaPendiente.value = null
    detalleOpen.value = false
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo confirmar la llegada')
    await cargar()
  } finally {
    confirmandoLlegada.value = false
  }
}

async function liberarReservaAction(reservaId: number) {
  liberando.value = reservaId
  try {
    await api.patch(`/reservas/${reservaId}/liberar`)
    toast.success('Sala liberada por no presentación')
    detalleOpen.value = false
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo liberar la reserva')
  } finally {
    liberando.value = null
  }
}

/**
 * "Menú de confirmación": tramos de hoy que ya deberían haber llegado (su hora de
 * inicio ya pasó) y todavía no confirman llegada. Un tramo que recién empieza más
 * tarde hoy no pertenece acá — nadie puede "llegar" a algo que no ha comenzado. Sin
 * este filtro por hora_inicio, la lista mostraba TODAS las reservas activas del día
 * completo (incluidas las de la noche a primera hora de la mañana), con cuentas
 * regresivas de cientos de minutos — un bug real, no la intención original.
 */
const pendientesConfirmacion = computed(() => {
  if (selectedDate.value !== hoy) return []
  return salas.value
    .flatMap((sala) => sala.tramos.map((tramo) => ({ sala, tramo })))
    .filter(({ tramo }) => tramo.estado === 'activa' && !tramo.hora_prestamo_real && timeToMinutes(tramo.hora_inicio) <= ahoraMin.value)
    .map(({ sala, tramo }) => ({
      sala,
      tramo,
      vencida: tramo.vencida_sin_confirmar,
      segundosRestantes: Math.floor((new Date(tramo.plazo_confirmacion).getTime() - ahora.value.getTime()) / 1000),
    }))
    .sort((a, b) => a.segundosRestantes - b.segundosRestantes)
})

function formatCuentaRegresiva(segundos: number) {
  if (segundos <= 0) return 'Vencido'
  const m = Math.floor(segundos / 60)
  const s = segundos % 60
  return `${m}:${String(s).padStart(2, '0')}`
}
</script>

<template>
  <StaffLayout>
    <div class="max-w-[1200px] mx-auto">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Salas de Estudio</h1>
          <p class="text-sm text-white/60 mt-1">
            15 logias de estudio, Sala de Seminarios, Sala de Postgrado y Sala AGACI · Horario {{ apertura }} – {{ cierre }} · Reservas de hasta {{ formatMinutos(duracionMaxima) }}
          </p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-md p-5">
          <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Ocupación Hoy</div>
          <div class="flex items-end gap-2">
            <span class="text-3xl font-bold text-indigo-700">
              {{ totalMin ? Math.round((salas.reduce((s, sala) => s + sala.tramos.reduce((s2, t) => s2 + (timeToMinutes(t.hora_fin) - timeToMinutes(t.hora_inicio)), 0), 0) / (salas.length * totalMin || 1)) * 100) : 0 }}%
            </span>
          </div>
        </div>
        <div class="bg-white rounded-xl shadow-md p-5">
          <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Salas Disponibles Ahora</div>
          <div class="flex items-end justify-between gap-2 flex-wrap">
            <div class="flex items-end gap-2">
              <span class="text-3xl font-bold text-emerald-600">{{ salas.filter((s) => s.libre_ahora).length }}</span>
              <span class="text-sm text-gray-400 mb-1">de {{ salas.length }} salas</span>
            </div>
            <button
              v-if="esHoy"
              @click="reservarAhora"
              class="px-3 py-1.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors text-xs font-medium shrink-0"
            >
              Reservar ahora
            </button>
          </div>
        </div>
        <div class="bg-white rounded-xl shadow-md p-5">
          <label class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1 block">Fecha</label>
          <input
            v-model="selectedDate"
            type="date"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
          />
        </div>
      </div>

      <div v-if="selectedDate === hoy" class="bg-white rounded-xl shadow-md p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-1">Menú de confirmación de asistencia</h3>
        <p class="text-xs text-gray-400 mb-4">
          Cada reserva tiene 15 minutos desde su hora de inicio para confirmar que el grupo llegó. Pasado ese plazo, la
          sala se libera sola.
        </p>

        <div v-if="pendientesConfirmacion.length" class="rounded-lg border border-gray-200 divide-y divide-gray-100">
          <div
            v-for="p in pendientesConfirmacion"
            :key="p.tramo.reserva_id"
            class="flex items-center justify-between gap-3 px-4 py-3 flex-wrap"
          >
            <div class="min-w-0">
              <p class="text-sm font-medium text-gray-900">
                {{ p.sala.nombre }} · {{ labelTramo(p.tramo.hora_inicio, p.tramo.hora_fin) }}
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
              <button
                v-if="!p.vencida"
                @click="pedirLlegada(p.sala, p.tramo)"
                class="text-xs font-medium px-3 py-1.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors"
              >
                Confirmar llegada
              </button>
              <button
                v-else
                @click="liberarReservaAction(p.tramo.reserva_id)"
                :disabled="liberando === p.tramo.reserva_id"
                class="text-xs font-medium px-3 py-1.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors disabled:opacity-60"
              >
                {{ liberando === p.tramo.reserva_id ? 'Liberando…' : 'Liberar ahora' }}
              </button>
            </div>
          </div>
        </div>
        <p v-else class="text-sm text-gray-400 text-center py-4">Sin reservas pendientes de confirmar por ahora.</p>
      </div>

      <div class="bg-white rounded-xl shadow-md p-5 mb-6">
        <h3 class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-3">Escanear código de barras (Horizon)</h3>
        <div class="flex gap-3 flex-wrap items-end">
          <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-medium text-gray-600 mb-1">Código de barras de la logia</label>
            <input
              v-model="codigoLogiaScan"
              type="text"
              placeholder="Escanear o ingresar código"
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none font-mono"
              @keydown.enter="escanearLogia"
            />
          </div>
          <button
            @click="escanearLogia"
            :disabled="escaneando"
            class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium disabled:opacity-60"
          >
            {{ escaneando ? 'Procesando…' : 'Registrar' }}
          </button>
        </div>
      </div>

      <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="relative max-w-sm flex-1 min-w-[200px]">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
          </svg>
          <input
            v-model="busqueda"
            @input="page = 0"
            type="text"
            placeholder="Buscar logia..."
            class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"
          />
        </div>
        <div v-if="totalPages > 1" class="flex items-center gap-2 bg-white rounded-lg shadow-sm p-1.5">
          <button
            v-for="n in totalPages"
            :key="n"
            @click="page = n - 1"
            class="px-4 py-2 rounded-md text-sm font-semibold transition-colors"
            :class="page === n - 1 ? 'bg-indigo-600 text-white shadow' : 'text-gray-600 hover:bg-gray-100'"
          >
            Página {{ n }}
          </button>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-md p-5">
        <p class="text-xs text-gray-400 mb-3">
          Click en cualquier espacio libre (verde) de la línea de tiempo para reservar esa sala a esa hora.
        </p>
        <div class="flex text-[10px] font-medium text-gray-400 mb-2 px-[132px] justify-between">
          <span v-for="h in [8, 10, 12, 14, 16, 18, 20]" :key="h">{{ String(h).padStart(2, '0') }}:00</span>
        </div>
        <div class="space-y-2.5">
          <div v-for="sala in salasPage" :key="sala.id" class="flex items-center gap-3">
            <div class="w-[120px] shrink-0">
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
              <div
                v-if="esHoy"
                class="absolute inset-y-0 left-0 bg-gray-500/[0.06] pointer-events-none"
                :style="{ width: nowPct + '%' }"
              />
              <div
                v-for="tramo in sala.tramos"
                :key="tramo.reserva_id"
                class="absolute inset-y-1 border flex items-center justify-center text-[10px] font-semibold overflow-hidden whitespace-nowrap shadow-sm hover:shadow-md hover:brightness-95 hover:z-20 hover:scale-y-105 transition-all cursor-pointer"
                :class="[tramoClases(tramo), anchoTramoPct(tramo) < 6 ? 'rounded-full' : 'rounded-lg px-1.5']"
                :style="{ left: pct(tramo.hora_inicio) + '%', width: anchoTramoPct(tramo) + '%' }"
                :title="`${tramo.hora_inicio.slice(0, 5)} – ${tramo.hora_fin.slice(0, 5)} · ${tramo.cantidad_personas} persona(s)`"
                @click.stop="verDetalle(sala, tramo)"
              >
                <span v-if="anchoTramoPct(tramo) >= 6">{{ tramo.hora_inicio.slice(0, 5) }}–{{ tramo.hora_fin.slice(0, 5) }}</span>
              </div>
              <div
                v-if="esHoy"
                class="absolute inset-y-0 w-[3px] -ml-px bg-indigo-600 pointer-events-none rounded-full shadow-[0_0_0_2px_rgba(79,70,229,0.15)]"
                :style="{ left: nowPct + '%' }"
              />
            </div>
          </div>
          <p v-if="!salasPage.length" class="text-sm text-gray-400 text-center py-6">Sin salas que coincidan con la búsqueda.</p>
        </div>

        <div v-if="totalPages > 1" class="flex items-center justify-between pt-4 mt-4 border-t border-gray-100">
          <span class="text-sm text-gray-500">
            Mostrando {{ page * salasPerPage + 1 }}–{{ Math.min((page + 1) * salasPerPage, filteredSalas.length) }} de {{ filteredSalas.length }} salas
          </span>
          <div class="flex gap-1">
            <button
              @click="page = Math.max(0, page - 1)"
              :disabled="page === 0"
              class="p-1.5 rounded-lg border border-gray-300 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed"
            >
              ‹
            </button>
            <button
              @click="page = Math.min(totalPages - 1, page + 1)"
              :disabled="page === totalPages - 1"
              class="p-1.5 rounded-lg border border-gray-300 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed"
            >
              ›
            </button>
          </div>
        </div>
      </div>

      <div class="flex flex-wrap gap-x-4 gap-y-1.5 mt-4 text-xs text-gray-500">
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded bg-emerald-50 border border-emerald-100" /> Disponible (click para reservar)</div>
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded bg-amber-100 border border-amber-300" /> Por confirmar</div>
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded bg-orange-100 border border-orange-300" /> Plazo vencido</div>
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded bg-red-100 border border-red-300" /> Confirmada / en uso</div>
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded bg-gray-100 border border-gray-300" /> Llave devuelta</div>
        <div class="flex items-center gap-1.5"><div class="w-1 h-3 rounded-full bg-indigo-600" /> Ahora</div>
      </div>

      <div
        v-if="modalOpen && selectedSala"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="modalOpen = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-md">
          <h3 class="text-lg font-bold text-gray-900 mb-1">Reservar {{ selectedSala.nombre }}</h3>
          <p class="text-sm text-gray-500 mb-5">
            {{ selectedSala.capacidad }} personas · {{ formatFechaLarga(selectedDate) }}
          </p>

          <div class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Hora de inicio</label>
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
                    {{ formatMinutos(d) }}{{ !modalDuracionesValidas.includes(d) ? ' (no disponible)' : '' }}
                  </option>
                </select>
              </div>
            </div>
            <p v-if="modalDisponibleMin < duracionMinima" class="text-xs text-red-600">
              Esta sala está libre solo {{ formatMinutos(modalDisponibleMin) }} desde las {{ modalHoraInicio }}.
            </p>
            <p v-else class="text-xs text-gray-400">
              Libre {{ formatMinutos(modalDisponibleMin) }} desde las {{ modalHoraInicio }}.
            </p>

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

          <div class="flex gap-3 mt-6">
            <button
              @click="modalOpen = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="confirmarReserva"
              :disabled="modalDisponibleMin < duracionMinima"
              class="flex-1 px-4 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium text-sm disabled:opacity-50"
            >
              Confirmar
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="detalleOpen && detalleTramo && detalleSala"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="detalleOpen = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-md">
          <h3 class="text-lg font-bold text-gray-900 mb-1">{{ detalleSala.nombre }} — Ocupada</h3>
          <p class="text-sm text-gray-500 mb-5">
            {{ labelTramo(detalleTramo.hora_inicio, detalleTramo.hora_fin) }} · {{ detalleTramo.cantidad_personas }} persona(s) · {{ formatFechaLarga(selectedDate) }}
          </p>

          <div class="space-y-2 mb-6">
            <div
              v-for="(persona, idx) in detalleTramo.personas ?? []"
              :key="idx"
              class="flex items-center justify-between px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg"
            >
              <span class="text-sm text-gray-900">{{ persona.nombre ?? 'Sin registro en el sistema' }}</span>
              <span class="text-xs font-mono text-gray-500">{{ persona.rut }}</span>
            </div>
          </div>

          <div v-if="detalleTramo.prestado_por || detalleTramo.devuelto_por" class="space-y-1.5 mb-6 text-sm border-t border-gray-100 pt-4">
            <div v-if="detalleTramo.prestado_por" class="flex justify-between">
              <span class="text-gray-500">Entregada por</span>
              <span class="text-gray-900 font-medium">{{ detalleTramo.prestado_por }}</span>
            </div>
            <div v-if="detalleTramo.devuelto_por" class="flex justify-between">
              <span class="text-gray-500">Devuelta por</span>
              <span class="text-gray-900 font-medium">{{ detalleTramo.devuelto_por }}</span>
            </div>
          </div>

          <div v-if="detalleTramo.hora_devolucion_real" class="mb-6 flex items-center gap-2 px-3 py-2.5 bg-emerald-50 border border-emerald-200 rounded-lg text-sm text-emerald-700">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
            Devolución de llave confirmada
          </div>
          <div
            v-else-if="!detalleTramo.hora_prestamo_real"
            class="mb-6 flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm"
            :class="detalleTramo.vencida_sin_confirmar ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-amber-50 border border-amber-200 text-amber-700'"
          >
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
            </svg>
            {{ detalleTramo.vencida_sin_confirmar ? 'Venció el plazo de 15 minutos sin confirmar llegada' : 'Todavía no se confirma la llegada del grupo' }}
          </div>

          <div class="flex gap-3">
            <button
              @click="detalleOpen = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cerrar
            </button>
            <template v-if="!detalleTramo.hora_devolucion_real">
              <button
                v-if="!detalleTramo.hora_prestamo_real && detalleTramo.vencida_sin_confirmar"
                @click="liberarReservaAction(detalleTramo.reserva_id)"
                :disabled="liberando === detalleTramo.reserva_id"
                class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium text-sm disabled:opacity-60"
              >
                {{ liberando === detalleTramo.reserva_id ? 'Liberando…' : 'Liberar (no llegó)' }}
              </button>
              <template v-else>
                <button
                  @click="pedirCancelacion(detalleSala, detalleTramo)"
                  class="flex-1 px-4 py-2.5 border border-red-300 text-red-700 rounded-lg hover:bg-red-50 transition-colors font-medium text-sm"
                >
                  Cancelar reserva
                </button>
                <button
                  v-if="!detalleTramo.hora_prestamo_real"
                  @click="pedirLlegada(detalleSala, detalleTramo)"
                  class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm"
                >
                  Confirmar llegada
                </button>
                <button
                  v-else
                  @click="pedirDevolucion(detalleSala, detalleTramo)"
                  class="flex-1 px-4 py-2.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors font-medium text-sm"
                >
                  Confirmar devolución
                </button>
              </template>
            </template>
          </div>
        </div>
      </div>

      <div
        v-if="cancelacionPendiente"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[60] flex items-center justify-center p-4"
        @click.self="cancelacionPendiente = null"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Cancelar esta reserva?</h3>
          <p class="text-sm text-gray-500 mb-6">
            Se cancelará la reserva de <strong>{{ cancelacionPendiente.salaNombre }}</strong> para el tramo
            <strong>{{ cancelacionPendiente.label }}</strong>. Esta acción no se puede deshacer.
          </p>
          <div class="flex gap-3">
            <button
              @click="cancelacionPendiente = null"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              No, mantener
            </button>
            <button
              @click="confirmarCancelacion"
              class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium text-sm"
            >
              Sí, cancelar
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="devolucionPendiente"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[60] flex items-center justify-center p-4"
        @click.self="devolucionPendiente = null"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">Confirmar devolución de llave</h3>
          <p class="text-sm text-gray-500 mb-6">
            Se registrará la devolución de <strong>{{ devolucionPendiente.salaNombre }}</strong> para el tramo
            <strong>{{ devolucionPendiente.label }}</strong>. La reserva queda marcada como finalizada
            (no se borra, a diferencia de cancelar).
          </p>
          <div class="flex gap-3">
            <button
              @click="devolucionPendiente = null"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="confirmarDevolucion"
              :disabled="devolviendo"
              class="flex-1 px-4 py-2.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ devolviendo ? 'Guardando…' : 'Confirmar' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="llegadaPendiente"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[60] flex items-center justify-center p-4"
        @click.self="llegadaPendiente = null"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">Confirmar llegada</h3>
          <p class="text-sm text-gray-500 mb-6">
            Se registrará que el grupo se presentó en <strong>{{ llegadaPendiente.salaNombre }}</strong> para el tramo
            <strong>{{ llegadaPendiente.label }}</strong>.
          </p>
          <div class="flex gap-3">
            <button
              @click="llegadaPendiente = null"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="confirmarLlegadaAction"
              :disabled="confirmandoLlegada"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ confirmandoLlegada ? 'Guardando…' : 'Confirmar' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </StaffLayout>
</template>
