<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import PortalLayout from '@/components/layout/PortalLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import GuiaUso from '@/components/ui/GuiaUso.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import SelectorDocumento from '@/components/ui/SelectorDocumento.vue'
import apiUsuario from '@/services/apiUsuario'
import { DIAS_SEMANA, diaIso } from '@/utils/horario'
import { sinTildes } from '@/utils/texto'
import { useToast } from '@/composables/useToast'
import { useRefrescoPeriodico } from '@/composables/useRefrescoPeriodico'
import { useHorarioSalas } from '@/composables/useHorarioSalas'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import { largoSegunModo, modoDe } from '@/utils/documento'
import { useModosPorFila } from '@/composables/useModoDocumento'
import type { Sala, TramoReserva } from '@/types'

const toast = useToast()
const auth = useUsuarioAuthStore()
const router = useRouter()

const hoy = new Date().toLocaleDateString('sv-SE')
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
const cuotaDiaria = ref(240)
const diaCerrado = ref(false)
const atencion = ref<[string, string] | null>(null)
const textoDiaCerrado = `Hoy es ${DIAS_SEMANA[diaIso(new Date())]} y la biblioteca no atiende: no se pueden reservar salas.`

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

const {
  aperturaMin,
  cierreMin,
  totalMin,
  cierraMin,
  primerInicioMin,
  pctAntesDeAbrir,
  pctDesdeCierre,
  horasRotuladas,
  atiende,
  horarioReducido,
  textoFueraDeHorario,
  posicionTramo,
} = useHorarioSalas({ apertura, cierre, atencion, granularidad, fecha: ref(hoy) })
function pct(hhmm: string): number {
  return ((timeToMinutes(hhmm) - aperturaMin.value) / totalMin.value) * 100
}

/** Ancho del bloque en % de la línea de tiempo, con un piso mínimo para que un tramo corto (ej. 30 min) siga siendo visible. */
function anchoTramoPct(tramo: TramoReserva): number {
  return posicionTramo(tramo.hora_inicio, tramo.hora_fin).ancho
}

const ahora = ref(new Date())
let relojTimer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  relojTimer = setInterval(() => (ahora.value = new Date()), 30000)
})
onUnmounted(() => clearInterval(relojTimer))
const ahoraMin = computed(() => ahora.value.getHours() * 60 + ahora.value.getMinutes())
const nowPct = computed(() => Math.min(100, Math.max(0, ((ahoraMin.value - aperturaMin.value) / totalMin.value) * 100)))
/** Horas enteras dentro del horario (sin los bordes): una línea guía por cada una. */
const horasIntermedias = computed(() => {
  const primera = Math.floor(aperturaMin.value / 60) + 1
  const ultima = Math.ceil(cierreMin.value / 60) - 1
  return Array.from({ length: Math.max(0, ultima - primera + 1) }, (_, i) => primera + i)
})
const horaActual = computed(() => ahora.value.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false }))
/** El marcador «Ahora» se centra en la línea, salvo en los bordes, donde se apoya hacia adentro. */
const alineacionAhora = computed(() => (nowPct.value < 8 ? '' : nowPct.value > 92 ? '-translate-x-full' : '-translate-x-1/2'))
/** Lo rayado: desde la izquierda, lo que ya pasó (o lo de antes de abrir); desde la derecha, lo de después del cierre. */
const pctPasado = computed(() => {
  const pasado = diaCerrado.value ? 100 : Math.max(pctAntesDeAbrir.value, nowPct.value)
  return pasado >= pctDesdeCierre.value ? 100 : pasado
})
const pctTrasCierre = computed(() => (pctPasado.value >= 100 ? 0 : 100 - pctDesdeCierre.value))

const modalOpen = ref(false)
const selectedSala = ref<Sala | null>(null)
const modalHoraInicio = ref('')
const modalDuracion = ref(60)
const enviando = ref(false)
const cantidadPersonas = ref(CANTIDAD_MIN)
const rutsReserva = ref<string[]>(Array.from({ length: CANTIDAD_MIN }, () => ''))
// Cada integrante con su RUT o, si es extranjero, su pasaporte/DNI.
const { modos: modosReserva, escribir: escribirFila, cambiar: cambiarModoFila, reiniciar: reiniciarModos, errores: erroresFilas } = useModosPorFila(rutsReserva, { tuteo: true })
const rutErrores = ref<Record<number, string>>({})

watch(cantidadPersonas, (nueva) => {
  const actuales = rutsReserva.value.length
  if (nueva > actuales) {
    rutsReserva.value.push(...Array.from({ length: nueva - actuales }, () => ''))
  } else if (nueva < actuales) {
    rutsReserva.value.splice(nueva)
  }
})

// Sus cancelaciones de hoy (config/salas.php): la primera no tiene espera; desde la
// segunda, unos minutos sin poder reservar otra sala. Solo cuentan las que hizo él.
type Cancelaciones = { sin_espera_restantes: number; minutos_espera: number; espera: string | null; espera_hasta: string | null }
const cancelaciones = ref<Cancelaciones>({ sin_espera_restantes: 1, minutos_espera: 5, espera: null, espera_hasta: null })

// Cumplida la espera, se vuelve a cargar y se puede reservar de nuevo (sin esperar al refresco de 30 s).
let finEspera: ReturnType<typeof setTimeout> | undefined
watch(cancelaciones, (c) => {
  clearTimeout(finEspera)
  if (c.espera_hasta) finEspera = setTimeout(cargar, Math.max(0, new Date(c.espera_hasta).getTime() - Date.now()) + 1000)
})
onUnmounted(() => clearTimeout(finEspera))

async function cargar() {
  try {
    const { data } = await apiUsuario.get('/mi/salas', { params: { fecha: hoy } })
    if (data.cancelaciones) cancelaciones.value = data.cancelaciones
    salas.value = data.salas
    apertura.value = data.apertura
    cierre.value = data.cierre
    granularidad.value = data.granularidad
    duracionMinima.value = data.duracion_minima
    duracionMaxima.value = data.duracion_maxima
    cuotaDiaria.value = data.cuota_diaria
    diaCerrado.value = data.cerrado
    atencion.value = data.atencion
    apiError.value = false
  } catch {
    apiError.value = true
    salas.value = []
  }
}

const lineaTiempo = ref<HTMLElement | null>(null)
const ANCHO_NOMBRE_SALA = 112

/** Si la línea de tiempo no cabe y se desliza, la deja mostrando la hora actual en vez de las 08:00. */
async function mostrarHoraActual() {
  await nextTick()
  const el = lineaTiempo.value
  if (!el || el.scrollWidth <= el.clientWidth) return
  const xAhora = ANCHO_NOMBRE_SALA + (nowPct.value / 100) * (el.scrollWidth - ANCHO_NOMBRE_SALA)
  el.scrollLeft = Math.max(0, xAhora - ANCHO_NOMBRE_SALA - (el.clientWidth - ANCHO_NOMBRE_SALA) / 3)
}

onMounted(async () => {
  await cargar()
  mostrarHoraActual()
})
useRefrescoPeriodico(cargar, 30000)

const filteredSalas = computed(() => {
  if (!busqueda.value.trim()) return salas.value
  const q = sinTildes(busqueda.value.trim())
  return salas.value.filter((s) => sinTildes(s.nombre).includes(q))
})

const pasosAyuda = computed(() => [
  'Busca un espacio <strong>verde</strong> en la sala que quieras, a la derecha de la línea azul <strong>Ahora</strong>: lo rayado ya pasó o está fuera del horario de la biblioteca.',
  `Tócalo o haz clic, elige la <strong>duración</strong> (hasta ${formatMinutos(duracionMaxima.value)}) y escribe el <strong>RUT de cada integrante</strong> (de ${CANTIDAD_MIN} a ${CANTIDAD_MAX} personas). El tuyo ya viene escrito.`,
  'Pulsa <strong>Confirmar</strong>: tu reserva queda en <strong>azul</strong> en la línea de tiempo.',
  'Preséntate con tu grupo en el mesón dentro de los <strong>15 minutos</strong> desde la hora de inicio; si no, la sala se libera sola.',
])
const consejoAyuda = computed(
  () => `Las reservas empiezan a la hora o a la media hora. Cada persona puede reservar hasta ${formatMinutos(cuotaDiaria.value)} al día, no puede estar en dos salas a la vez y tiene una sola reserva pendiente: para pedir otra, primero usa o cancela la que tiene. Para cancelar, toca tu reserva en azul. Puedes cancelar una reserva hecha por ti al día sin consecuencias; desde la segunda, tendrás que esperar ${cancelaciones.value.minutos_espera} minutos para reservar otra sala.`,
)

// La hizo la persona o la incluyeron como integrante (el servidor lo indica en es_mia).
function esMia(tramo: TramoReserva) {
  return tramo.es_mia ?? tramo.usuario_id === auth.usuario?.id
}

function disponibilidadDesde(sala: Sala, desdeMin: number): number {
  if (!atiende(desdeMin)) return 0
  const ocupando = sala.tramos.some((t) => timeToMinutes(t.hora_inicio) <= desdeMin && timeToMinutes(t.hora_fin) > desdeMin)
  if (ocupando) return 0
  const siguientes = sala.tramos.map((t) => timeToMinutes(t.hora_inicio)).filter((i) => i >= desdeMin).sort((a, b) => a - b)
  const limite = Math.min(siguientes.length ? siguientes[0] : cierraMin.value, cierraMin.value)
  return Math.max(0, Math.min(limite - desdeMin, duracionMaxima.value))
}

const modalInicioMin = computed(() => timeToMinutes(modalHoraInicio.value || apertura.value))
const modalDisponibleMin = computed(() => (selectedSala.value ? disponibilidadDesde(selectedSala.value, modalInicioMin.value) : 0))
const modalDuracionesValidas = computed(() => DURACIONES.filter((d) => d >= duracionMinima.value && d <= modalDisponibleMin.value))

const horaInicioOpciones = computed(() => {
  const opciones: string[] = []
  const minimoInicio = Math.max(primerInicioMin.value, Math.ceil(ahoraMin.value / granularidad.value) * granularidad.value)
  for (let m = minimoInicio; m <= cierraMin.value - duracionMinima.value; m += granularidad.value) {
    opciones.push(minutesToTime(m))
  }
  return opciones
})

function abrirModal(sala: Sala, inicioSugeridoMin: number) {
  selectedSala.value = sala
  const alineado = Math.max(primerInicioMin.value, Math.ceil(inicioSugeridoMin / granularidad.value) * granularidad.value)
  modalHoraInicio.value = minutesToTime(Math.min(alineado, cierraMin.value - duracionMinima.value))
  const disponibles = DURACIONES.filter((d) => d >= duracionMinima.value && d <= disponibilidadDesde(sala, timeToMinutes(modalHoraInicio.value)))
  modalDuracion.value = disponibles.length ? disponibles[disponibles.length - 1] : duracionMinima.value
  cantidadPersonas.value = CANTIDAD_MIN
  rutsReserva.value = Array.from({ length: CANTIDAD_MIN }, (_, idx) => (idx === 0 ? (auth.usuario?.rut ?? '') : ''))
  reiniciarModos([modoDe(auth.usuario?.tipo_documento)])
  rutErrores.value = {}
  modalOpen.value = true
}

function onTimelineClick(sala: Sala, event: MouseEvent) {
  if (diaCerrado.value) {
    toast.error(textoDiaCerrado)
    return
  }
  if (cancelaciones.value.espera) {
    toast.error(cancelaciones.value.espera)
    return
  }
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const fraccion = (event.clientX - rect.left) / rect.width
  const minutoClickeado = aperturaMin.value + fraccion * totalMin.value

  if (!atiende(minutoClickeado)) {
    toast.error(textoFueraDeHorario.value)
    return
  }
  if (minutoClickeado < ahoraMin.value) {
    toast.error('Esa hora ya pasó: elige un horario desde ahora en adelante.')
    return
  }
  // Se propone el bloque de media hora donde cayó el clic o, si ya empezó o está
  // tomado, el siguiente.
  const bloque = Math.floor(minutoClickeado / granularidad.value) * granularidad.value
  const inicio = [bloque, bloque + granularidad.value]
    .filter((m) => m >= ahoraMin.value)
    .find((m) => disponibilidadDesde(sala, m) > 0)
  if (inicio === undefined) {
    toast.error('Esa hora ya está ocupada en esta sala.')
    return
  }

  abrirModal(sala, inicio)
}

function onRutInput(index: number, event: Event) {
  escribirFila(index, event)
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
    toast.error('Complete el RUT (o pasaporte/DNI) de cada persona')
    return
  }
  const malas = erroresFilas()
  if (Object.keys(malas).length) {
    rutErrores.value = malas
    toast.error(Object.keys(malas).length === 1 ? 'Revisa el RUT o documento marcado en rojo' : 'Revisa los RUT o documentos marcados en rojo')
    return
  }
  enviando.value = true
  rutErrores.value = {}
  try {
    const { data } = await apiUsuario.post('/mi/reservas', {
      sala_id: selectedSala.value.id,
      fecha: hoy,
      hora_inicio: modalHoraInicio.value,
      hora_fin: minutesToTime(Math.min(modalInicioMin.value + modalDuracion.value, cierraMin.value)),
      cantidad_personas: cantidadPersonas.value,
      ruts: rutsReserva.value,
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
      // Canceló hace poco (quizás en otra pestaña): se trae la hora desde la que puede reservar.
      if (e?.response?.status === 429) {
        modalOpen.value = false
        await cargar()
      }
    }
  } finally {
    enviando.value = false
  }
}

const reservaPorCancelar = ref<{ sala: Sala; tramo: TramoReserva } | null>(null)
const cancelando = ref(false)

/** Qué significa cancelar esta reserva para la espera: solo pesa en quien la hizo. */
const avisoCancelacion = computed(() => {
  const r = reservaPorCancelar.value
  const { sin_espera_restantes: libres, minutos_espera: minutos } = cancelaciones.value
  if (!r || !minutos) return null
  if (r.tramo.usuario_id !== auth.usuario?.id) return 'No hiciste tú esta reserva: cancelarla no tiene consecuencias para ti.'
  if (libres === 1) return `Esta cancelación no tiene consecuencias, pero es la única del día: si vuelves a cancelar otra reserva hoy, tendrás que esperar ${minutos} minutos para reservar una sala.`
  if (libres > 1) return `Esta cancelación no tiene consecuencias (hoy te quedan ${libres} sin espera). Después, cada vez que canceles tendrás que esperar ${minutos} minutos para reservar una sala.`
  return `Ya cancelaste una reserva hoy: si cancelas esta, tendrás que esperar ${minutos} minutos para reservar otra sala.`
})

async function confirmarCancelacion() {
  if (!reservaPorCancelar.value) return
  cancelando.value = true
  try {
    await apiUsuario.delete(`/mi/reservas/${reservaPorCancelar.value.tramo.reserva_id}`)
    toast.success('Reserva cancelada')
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo cancelar la reserva')
  } finally {
    cancelando.value = false
    reservaPorCancelar.value = null
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

      <GuiaUso titulo="¿Cómo reservo una sala?" :pasos="pasosAyuda" :consejo="consejoAyuda" />

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
      </div>

      <div class="bg-white border border-gray-200 rounded-lg p-4">
        <div v-if="diaCerrado" class="mb-3 flex items-start gap-2.5 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
          <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
          </svg>
          <p>{{ textoDiaCerrado }}</p>
        </div>
        <div v-else-if="horarioReducido" class="mb-3 flex items-start gap-2.5 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
          <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p>{{ textoFueraDeHorario }}</p>
        </div>
        <div v-if="cancelaciones.espera && !diaCerrado" class="mb-3 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p>{{ cancelaciones.espera }}</p>
        </div>
        <p v-if="!diaCerrado" class="text-xs text-gray-400">
          Toca o haz clic en un espacio libre (verde) de la línea de tiempo para reservar esa sala a esa hora.
        </p>
        <p class="md:hidden mt-1 flex items-center gap-1 text-xs font-medium text-indigo-600">
          Desliza hacia el lado para ver todas las horas
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
          </svg>
        </p>
        <!-- En pantallas angostas la línea de tiempo se desliza de lado y el nombre de la sala queda fijo. -->
        <div ref="lineaTiempo" class="mt-3 overflow-x-auto">
        <div class="min-w-[720px] md:min-w-0">
        <div v-if="!diaCerrado && nowPct > 0 && nowPct < 100" class="flex items-end mb-1">
          <div class="w-[112px] shrink-0 self-stretch sticky left-0 z-30 bg-white"></div>
          <div class="relative h-7 flex-1">
            <span
              class="absolute top-0 rounded-full bg-indigo-600 px-2 py-0.5 text-[10px] font-semibold text-white shadow-sm whitespace-nowrap"
              :class="alineacionAhora"
              :style="{ left: nowPct + '%' }"
            >
              Ahora · {{ horaActual }}
            </span>
            <span class="absolute bottom-0 h-2 w-[3px] -ml-px rounded-full bg-indigo-600" :style="{ left: nowPct + '%' }"></span>
          </div>
        </div>
        <div class="flex items-center mb-2">
          <div class="w-[112px] shrink-0 self-stretch sticky left-0 z-30 bg-white"></div>
          <div class="relative h-4 flex-1 text-[10px] font-medium text-gray-400">
            <span
              v-for="h in horasRotuladas"
              :key="h"
              class="absolute"
              :class="pct(`${h}:00`) < 3 ? '' : '-translate-x-1/2'"
              :style="{ left: pct(`${h}:00`) + '%' }"
            >{{ String(h).padStart(2, '0') }}:00</span>
          </div>
        </div>
        <div class="space-y-2.5">
          <div v-for="sala in filteredSalas" :key="sala.id" class="flex items-center">
            <div class="w-[112px] shrink-0 self-stretch pr-3 flex flex-col justify-center sticky left-0 z-30 bg-white">
              <div class="font-medium text-sm text-gray-900 truncate">{{ sala.nombre }}</div>
              <div class="text-xs text-gray-400">{{ sala.capacidad }} personas</div>
            </div>
            <div
              class="group relative h-12 flex-1 bg-emerald-50/70 hover:bg-emerald-100/90 rounded-xl overflow-hidden border border-emerald-100 shadow-inner cursor-pointer transition-colors"
              @click="onTimelineClick(sala, $event)"
            >
              <div class="absolute inset-0 flex pointer-events-none">
                <div
                  v-for="h in horasIntermedias"
                  :key="h"
                  class="absolute inset-y-0 border-l"
                  :class="h % 2 === 0 ? 'border-emerald-900/[0.09]' : 'border-emerald-900/[0.05]'"
                  :style="{ left: pct(`${h}:00`) + '%' }"
                />
              </div>
              <div v-if="pctPasado > 0" class="absolute inset-y-0 left-0 franja-pasada pointer-events-none" :style="{ width: pctPasado + '%' }" />
              <div v-if="pctTrasCierre > 0" class="absolute inset-y-0 right-0 franja-pasada pointer-events-none" :style="{ width: pctTrasCierre + '%' }" />
              <p
                v-if="!sala.tramos.length && 100 - pctPasado - pctTrasCierre > 12"
                class="absolute inset-y-0 flex items-center justify-center text-[11px] font-medium text-emerald-700/60 group-hover:text-emerald-700 pointer-events-none transition-colors"
                :style="{ left: pctPasado + '%', right: pctTrasCierre + '%' }"
              >
                + Click para reservar
              </p>
              <div
                v-for="tramo in sala.tramos.filter((t) => posicionTramo(t.hora_inicio, t.hora_fin).visible)"
                :key="tramo.reserva_id"
                class="absolute inset-y-1 border flex items-center justify-center text-[10px] font-semibold overflow-hidden whitespace-nowrap shadow-sm hover:shadow-md hover:z-20 hover:scale-y-105 transition-all"
                :class="[esMia(tramo) ? 'bg-indigo-100 border-indigo-300 text-indigo-800' : 'bg-red-100 border-red-300 text-red-700', anchoTramoPct(tramo) < 6 ? 'rounded-full' : 'rounded-lg px-1.5']"
                :style="{ left: posicionTramo(tramo.hora_inicio, tramo.hora_fin).izquierda + '%', width: anchoTramoPct(tramo) + '%' }"
                :title="`${tramo.hora_inicio.slice(0, 5)} – ${tramo.hora_fin.slice(0, 5)}${esMia(tramo) ? ' · tu reserva (clic para cancelar)' : ''}`"
              >
                <button v-if="esMia(tramo)" @click.stop="reservaPorCancelar = { sala, tramo }" class="hover:underline w-full h-full flex items-center justify-center">
                  <span v-if="anchoTramoPct(tramo) >= 6">{{ tramo.hora_inicio.slice(0, 5) }}–{{ tramo.hora_fin.slice(0, 5) }}<template v-if="anchoTramoPct(tramo) >= 13"> (cancelar)</template></span>
                  <span v-else>×</span>
                </button>
                <span v-else-if="anchoTramoPct(tramo) >= 6">{{ tramo.hora_inicio.slice(0, 5) }}–{{ tramo.hora_fin.slice(0, 5) }}</span>
              </div>
              <div
                v-if="!diaCerrado"
                class="absolute inset-y-0 w-[3px] -ml-px bg-indigo-600 pointer-events-none rounded-full shadow-[0_0_0_2px_rgba(79,70,229,0.15)]"
                :style="{ left: nowPct + '%' }"
              />
            </div>
          </div>
        </div>
        </div>
        </div>
        <p v-if="!filteredSalas.length" class="text-sm text-gray-400 text-center py-6">Sin salas que coincidan con la búsqueda.</p>
      </div>

      <div class="flex flex-wrap gap-x-4 gap-y-1.5 mt-4 text-xs text-gray-500">
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded border border-emerald-200 bg-emerald-50" /> Disponible (click para reservar)</div>
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded border border-indigo-300 bg-indigo-100" /> Tu reserva</div>
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded border border-red-300 bg-red-100" /> Ocupada</div>
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded-full border border-red-300 bg-red-100" /> Reserva de 30 min</div>
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded franja-pasada border border-slate-200" /> Ya pasó o fuera de horario</div>
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
                  v-model="modalHoraInicio"
                  class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
                >
                  <option v-for="h in horaInicioOpciones" :key="h" :value="h">{{ h }}</option>
                </select>
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
              <label class="block text-sm font-medium text-gray-700">RUT de cada persona <span class="font-normal text-gray-500">(extranjeros: pasaporte o DNI)</span></label>
              <p class="text-xs text-gray-400 -mt-1 mb-1">Deben ser personas registradas en la biblioteca</p>
              <div v-for="(_, idx) in rutsReserva" :key="idx">
                <div class="flex flex-wrap items-center gap-2">
                  <input
                    :value="rutsReserva[idx]"
                    @input="onRutInput(idx, $event)"
                    type="text"
                    :placeholder="`${modosReserva[idx] === 'extranjero' ? 'Pasaporte o DNI' : 'RUT'} persona ${idx + 1}`"
                    :readonly="idx === 0 && !!auth.usuario?.rut"
                    :title="idx === 0 && auth.usuario?.rut ? 'Tu documento: quien reserva siempre va en el grupo' : undefined"
                    :maxlength="largoSegunModo(modosReserva[idx] ?? 'rut')"
                    class="min-w-0 flex-1 px-4 py-2.5 border rounded-lg focus:ring-2 outline-none read-only:bg-gray-50 read-only:text-gray-600"
                    :class="rutErrores[idx] ? 'border-red-400 ring-1 ring-red-200 focus:ring-red-400' : 'border-gray-300 focus:ring-indigo-500'"
                  />
                  <SelectorDocumento
                    v-if="!(idx === 0 && auth.usuario?.rut)"
                    :model-value="modosReserva[idx] ?? 'rut'"
                    compacto
                    :etiqueta="`Documento de la persona ${idx + 1}`"
                    @update:model-value="cambiarModoFila(idx, $event)"
                  />
                </div>
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

    <DialogoConfirmacion
      :abierto="reservaPorCancelar !== null"
      titulo="¿Cancelar tu reserva?"
      confirmar="Sí, cancelar"
      cancelar="No, mantener"
      tono="peligro"
      :cargando="cancelando"
      @confirmar="confirmarCancelacion"
      @cancelar="reservaPorCancelar = null"
    >
      <p v-if="reservaPorCancelar">
        Se liberará <strong>{{ reservaPorCancelar.sala.nombre }}</strong> de
        <strong>{{ reservaPorCancelar.tramo.hora_inicio.slice(0, 5) }} a {{ reservaPorCancelar.tramo.hora_fin.slice(0, 5) }}</strong>
        y quedará disponible para otras personas.
      </p>
      <p v-if="avisoCancelacion" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
        {{ avisoCancelacion }}
      </p>
    </DialogoConfirmacion>
  </PortalLayout>
</template>
