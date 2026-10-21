<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import GuiaUso from '@/components/ui/GuiaUso.vue'
import Modal from '@/components/ui/Modal.vue'
import ListaConfirmacionAsistencia from '@/components/salas/ListaConfirmacionAsistencia.vue'
import EditorIntegrantes from '@/components/salas/EditorIntegrantes.vue'
import AvisoMultaAtraso from '@/components/salas/AvisoMultaAtraso.vue'
import SelectorDocumento from '@/components/ui/SelectorDocumento.vue'
import api from '@/services/api'
import { diaEnPlural } from '@/utils/horario'
import { sinTildes } from '@/utils/texto'
import { useToast } from '@/composables/useToast'
import { useRefrescoPeriodico } from '@/composables/useRefrescoPeriodico'
import { useHorarioSalas } from '@/composables/useHorarioSalas'
import { largoSegunModo } from '@/utils/documento'
import { useModosPorFila } from '@/composables/useModoDocumento'
import { useAuthStore } from '@/stores/auth'
import type { AsistenciaSala, AtrasoDevolucion, IntegrantesReserva, Sala, TramoReserva } from '@/types'

const toast = useToast()
const auth = useAuthStore()
// Agendar en un horario ya pasado (ej. registrar algo que se atendió sin pasar por
// el sistema) requiere el permiso 'salas.hora_pasada' — el resto del personal sigue
// restringido a "desde ahora en adelante". El backend re-valida esto igual (ver
// ReservaSalaService::validarTramo()); esto solo evita ofrecer una opción que el
// servidor rechazaría.
const puedeHoraPasada = computed(() => auth.puede('salas.hora_pasada'))

const hoy = new Date().toLocaleDateString('sv-SE')

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
const diaCerrado = ref(false)
const atencion = ref<[string, string] | null>(null)

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
  abreMin,
  cierraMin,
  primerInicioMin,
  pctAntesDeAbrir,
  pctDesdeCierre,
  horasRotuladas,
  atiende,
  horarioReducido,
  textoHorario,
  textoFueraDeHorario,
  posicionTramo,
} = useHorarioSalas({ apertura, cierre, atencion, granularidad, fecha: selectedDate })

function pct(hhmm: string): number {
  return ((timeToMinutes(hhmm) - aperturaMin.value) / totalMin.value) * 100
}

/** Ancho del bloque en % de la línea de tiempo, con un piso mínimo para que un tramo corto (ej. 30 min) siga siendo clickeable y visible. */
function anchoTramoPct(tramo: TramoReserva): number {
  return posicionTramo(tramo.hora_inicio, tramo.hora_fin).ancho
}

const modalOpen = ref(false)
const selectedSala = ref<Sala | null>(null)
const modalInmediata = ref(false)
const modalHoraInicio = ref('')
const modalDuracion = ref(60)
const cantidadPersonas = ref(CANTIDAD_MIN)
const rutsReserva = ref<string[]>(Array.from({ length: CANTIDAD_MIN }, () => ''))
// Cada integrante con su RUT o, si es extranjero, su pasaporte/DNI.
const { modos: modosReserva, escribir: escribirFila, cambiar: cambiarModoFila, reiniciar: reiniciarModos, errores: erroresFilas } = useModosPorFila(rutsReserva)
const rutErrores = ref<Record<number, string>>({})

const detalleOpen = ref(false)
const detalleTramo = ref<TramoReserva | null>(null)
const detalleSala = ref<Sala | null>(null)

const cancelacionPendiente = ref<{ reservaId: number; salaNombre: string; label: string } | null>(null)
const devolucionPendiente = ref<{ reservaId: number; salaNombre: string; label: string } | null>(null)
const devolviendo = ref(false)
// Si la llave volvió fuera de hora, el aviso para decidir si se cursa la multa.
const atrasoDevolucion = ref<AtrasoDevolucion | null>(null)
const llegadaPendiente = ref<{ reservaId: number; salaNombre: string; label: string; personas: TramoReserva['personas'] } | null>(null)
const confirmandoLlegada = ref(false)
// Editando los integrantes ahí mismo: en el modal de llegada o en el detalle de la reserva.
const editandoIntegrantesLlegada = ref(false)
const editandoIntegrantesDetalle = ref(false)
const liberando = ref<number | null>(null)
const liberacionPendiente = ref<{ reservaId: number; salaNombre: string; label: string } | null>(null)

const ahora = ref(new Date())
let relojTimer: ReturnType<typeof setInterval> | undefined
const actualizadoEn = ref<Date | null>(null)
// Corto para que una reserva hecha desde el portal aparezca casi al instante:
// la consulta tarda unos 45 ms y son 20 por minuto, lejos del límite de 240.
const REFRESCO_SEGUNDOS = 3

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
    diaCerrado.value = data.cerrado
    atencion.value = data.atencion
    apiError.value = false
    actualizadoEn.value = new Date()
  } catch {
    apiError.value = true
    salas.value = []
  }
}

onMounted(() => {
  cargar()
  relojTimer = setInterval(() => (ahora.value = new Date()), 1000)
})
onUnmounted(() => clearInterval(relojTimer))
useRefrescoPeriodico(cargar, REFRESCO_SEGUNDOS * 1000)
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
    if (data.hora_devolucion_real) {
      toast.success('Devolución de logia registrada')
      atrasoDevolucion.value = data.atraso_devolucion ?? null
    } else toast.success(`Entrega de logia registrada. ${textoAsistencia(data.asistencia)}`)
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
  const q = sinTildes(busqueda.value.trim())
  return salas.value.filter((s) => sinTildes(s.nombre).includes(q) || String(s.capacidad).includes(q))
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredSalas.value.length / salasPerPage)))
const salasPage = computed(() => filteredSalas.value.slice(page.value * salasPerPage, (page.value + 1) * salasPerPage))

const esHoy = computed(() => selectedDate.value === hoy)
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
/** Lo rayado desde la izquierda: hasta ahora si es hoy (o hasta que abre), entera si es un día anterior o sin atención. */
const pctPasado = computed(() => {
  const pasado = diaCerrado.value || selectedDate.value < hoy ? 100 : Math.max(pctAntesDeAbrir.value, esHoy.value ? nowPct.value : 0)
  return pasado >= pctDesdeCierre.value ? 100 : pasado
})
/** Lo rayado desde la derecha: después del cierre de ese día (el sábado, desde las 14:00). */
const pctTrasCierre = computed(() => (pctPasado.value >= 100 ? 0 : 100 - pctDesdeCierre.value))
const textoDiaCerrado = computed(() => `La biblioteca no atiende los ${diaEnPlural(new Date(`${selectedDate.value}T12:00:00`))}: ese día no se reservan salas.`)

/** Minutos libres en `sala` desde `desdeMin` (calculado en el cliente con los mismos datos que ya trae la vista, sin ida y vuelta al servidor — el backend vuelve a validar todo al confirmar). */
function disponibilidadDesde(sala: Sala, desdeMin: number): number {
  if (!atiende(desdeMin)) return 0

  const ocupando = sala.tramos.some((t) => timeToMinutes(t.hora_inicio) <= desdeMin && timeToMinutes(t.hora_fin) > desdeMin)
  if (ocupando) return 0

  const siguientes = sala.tramos
    .map((t) => timeToMinutes(t.hora_inicio))
    .filter((inicio) => inicio >= desdeMin)
    .sort((a, b) => a - b)

  const limite = Math.min(siguientes.length ? siguientes[0] : cierraMin.value, cierraMin.value)
  return Math.max(0, Math.min(limite - desdeMin, duracionMaxima.value))
}

const modalInicioMin = computed(() => timeToMinutes(modalHoraInicio.value || apertura.value))
const modalDisponibleMin = computed(() => (selectedSala.value ? disponibilidadDesde(selectedSala.value, modalInicioMin.value) : 0))
const modalDuracionesValidas = computed(() => DURACIONES.filter((d) => d >= duracionMinima.value && d <= modalDisponibleMin.value))

const horaInicioOpciones = computed(() => {
  const opciones: string[] = []
  let minimoInicio = primerInicioMin.value
  if (esHoy.value && !puedeHoraPasada.value) {
    minimoInicio = Math.max(minimoInicio, Math.ceil(ahoraMin.value / granularidad.value) * granularidad.value)
  }
  for (let m = minimoInicio; m <= cierraMin.value - duracionMinima.value; m += granularidad.value) {
    opciones.push(minutesToTime(m))
  }
  return opciones
})

function abrirModalProgramado(sala: Sala, inicioSugeridoMin: number) {
  selectedSala.value = sala
  modalInmediata.value = false
  const alineado = Math.max(primerInicioMin.value, Math.ceil(inicioSugeridoMin / granularidad.value) * granularidad.value)
  modalHoraInicio.value = minutesToTime(Math.min(alineado, cierraMin.value - duracionMinima.value))
  const disponibles = DURACIONES.filter((d) => d >= duracionMinima.value && d <= disponibilidadDesde(sala, timeToMinutes(modalHoraInicio.value)))
  modalDuracion.value = disponibles.length ? disponibles[disponibles.length - 1] : duracionMinima.value
  cantidadPersonas.value = CANTIDAD_MIN
  rutsReserva.value = Array.from({ length: CANTIDAD_MIN }, () => '')
  reiniciarModos()
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
  reiniciarModos()
  rutErrores.value = {}
  modalOpen.value = true
}

function onTimelineClick(sala: Sala, event: MouseEvent) {
  if (diaCerrado.value) {
    toast.error(textoDiaCerrado.value)
    return
  }
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const fraccion = (event.clientX - rect.left) / rect.width
  const minutoClickeado = aperturaMin.value + fraccion * totalMin.value

  if (!atiende(minutoClickeado)) {
    toast.error(textoFueraDeHorario.value)
    return
  }
  if (esHoy.value && !puedeHoraPasada.value && minutoClickeado < ahoraMin.value) {
    toast.error('Esa hora ya pasó — elige un horario desde ahora en adelante, o usa "Reservar ahora".')
    return
  }
  // Se propone el bloque de media hora donde cayó el clic o, si ya empezó o está
  // tomado, el siguiente.
  const bloque = Math.floor(minutoClickeado / granularidad.value) * granularidad.value
  const inicio = [bloque, bloque + granularidad.value]
    .filter((m) => puedeHoraPasada.value || !esHoy.value || m >= ahoraMin.value)
    .find((m) => disponibilidadDesde(sala, m) > 0)
  if (inicio === undefined) {
    toast.error('Esa hora ya está ocupada en esta sala.')
    return
  }

  abrirModalProgramado(sala, inicio)
}

function reservarAhora() {
  if (diaCerrado.value) {
    toast.error(textoDiaCerrado.value)
    return
  }
  if (!atiende(ahoraMin.value)) {
    toast.error(textoFueraDeHorario.value)
    return
  }
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
  editandoIntegrantesDetalle.value = false
  detalleOpen.value = true
}

/** Los integrantes se pueden corregir mientras la reserva sigue en curso: no devuelta ni vencida sin confirmar. */
function integrantesEditables(tramo: TramoReserva): boolean {
  return tramo.estado === 'activa' && !tramo.hora_devolucion_real && !tramo.vencida_sin_confirmar
}

function integrantesGuardados(resultado: IntegrantesReserva) {
  if (llegadaPendiente.value?.reservaId === resultado.reserva_id) {
    llegadaPendiente.value = { ...llegadaPendiente.value, personas: resultado.personas }
  }
  if (detalleTramo.value?.reserva_id === resultado.reserva_id) {
    detalleTramo.value = { ...detalleTramo.value, personas: resultado.personas, cantidad_personas: resultado.cantidad_personas }
  }
  editandoIntegrantesLlegada.value = false
  editandoIntegrantesDetalle.value = false
  const registradas = resultado.asistencia?.registradas ?? []
  toast.success(
    `Integrantes guardados: ${resultado.cantidad_personas} personas.${registradas.length ? ` Entrada registrada: ${registradas.join(', ')}.` : ''}`,
  )
  cargar()
}

function onRutInput(index: number, event: Event) {
  escribirFila(index, event)
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
    toast.error('Complete el RUT (o pasaporte/DNI) de cada persona')
    return
  }
  const malas = erroresFilas()
  if (Object.keys(malas).length) {
    rutErrores.value = malas
    toast.error(Object.keys(malas).length === 1 ? 'Revisa el RUT o documento marcado en rojo' : 'Revisa los RUT o documentos marcados en rojo')
    return
  }
  if (!selectedSala.value) return

  rutErrores.value = {}
  try {
    const { data } = await api.post('/reservas', {
      sala_id: selectedSala.value.id,
      fecha: selectedDate.value,
      hora_inicio: modalHoraInicio.value,
      hora_fin: minutesToTime(Math.min(modalInicioMin.value + modalDuracion.value, cierraMin.value)),
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
    const { data } = await api.patch<{ atraso_devolucion?: AtrasoDevolucion | null }>(`/reservas/${devolucionPendiente.value.reservaId}/devolver`)
    toast.success('Devolución de llave confirmada')
    atrasoDevolucion.value = data.atraso_devolucion ?? null
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

/** Con `modificar`, el modal se abre con la lista de integrantes ya en edición. */
function pedirLlegada(sala: Sala, tramo: TramoReserva, modificar = false) {
  llegadaPendiente.value = {
    reservaId: tramo.reserva_id,
    salaNombre: sala.nombre,
    label: labelTramo(tramo.hora_inicio, tramo.hora_fin),
    personas: tramo.personas ?? [],
  }
  editandoIntegrantesLlegada.value = modificar
}

function cerrarLlegada() {
  llegadaPendiente.value = null
  editandoIntegrantesLlegada.value = false
}

/**
 * Con la llegada, los integrantes quedan presentes en la biblioteca: a quien no había
 * marcado entrada hoy se le registra; a quien ya la tenía no se le duplica.
 */
function textoAsistencia(asistencia?: AsistenciaSala): string {
  if (!asistencia) return ''
  const { registradas, ya_presentes } = asistencia
  const partes: string[] = []
  if (registradas.length) partes.push(`Entrada registrada: ${registradas.join(', ')}.`)
  if (ya_presentes.length) partes.push(`${ya_presentes.join(', ')} ya ${ya_presentes.length === 1 ? 'estaba presente' : 'estaban presentes'} hoy (no se duplica).`)
  return partes.join(' ')
}

async function confirmarLlegadaAction() {
  if (!llegadaPendiente.value) return
  confirmandoLlegada.value = true
  try {
    const { data } = await api.patch<{ asistencia?: AsistenciaSala }>(`/reservas/${llegadaPendiente.value.reservaId}/llegada`)
    toast.success(`Llegada confirmada. ${textoAsistencia(data.asistencia)}`.trim())
    cerrarLlegada()
    detalleOpen.value = false
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo confirmar la llegada')
    await cargar()
  } finally {
    confirmandoLlegada.value = false
  }
}

function pedirLiberacion(sala: Sala, tramo: TramoReserva) {
  liberacionPendiente.value = { reservaId: tramo.reserva_id, salaNombre: sala.nombre, label: labelTramo(tramo.hora_inicio, tramo.hora_fin) }
}

async function confirmarLiberacion() {
  if (!liberacionPendiente.value) return
  const reservaId = liberacionPendiente.value.reservaId
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
    liberacionPendiente.value = null
  }
}

/**
 * "Menú de confirmación": tramos de hoy que ya deberían haber llegado (su hora de
 * inicio ya pasó) y todavía no confirman llegada. Un tramo que recién empieza más
 * tarde hoy no pertenece acá: nadie puede "llegar" a algo que no ha comenzado.
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

// La tarjeta muestra solo las más urgentes; el resto, en el popup "Ver todas" (con los
// datos de prueba pueden empezar varias a la misma hora).
const CONFIRMACION_VISIBLES = 5
const verTodasConfirmacion = ref(false)
const vencidasConfirmacion = computed(() => pendientesConfirmacion.value.filter((p) => p.vencida).length)

/** Las próximas reservas de hoy sin llegada: entran al menú a su hora de inicio. */
const proximaConfirmacion = computed(() => {
  if (selectedDate.value !== hoy) return null
  const proximas = salas.value
    .flatMap((sala) => sala.tramos.map((tramo) => ({ sala, tramo })))
    .filter(({ tramo }) => tramo.estado === 'activa' && !tramo.hora_prestamo_real && timeToMinutes(tramo.hora_inicio) > ahoraMin.value)
    .sort((a, b) => a.tramo.hora_inicio.localeCompare(b.tramo.hora_inicio))
  if (!proximas.length) return null
  const hora = proximas[0].tramo.hora_inicio
  return { hora, sala: proximas[0].sala.nombre, otras: proximas.filter((p) => p.tramo.hora_inicio === hora).length - 1 }
})
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
            15 logias de estudio, Sala de Seminarios, Sala de Postgrado y Sala AGACI · Horario del día {{ textoHorario }} · Reservas de hasta {{ formatMinutos(duracionMaxima) }}
          </p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <GuiaUso
        :pasos="[
          'Para reservar, haga <strong>clic en el espacio verde</strong> de la sala a la hora que quieren, o use <strong>Reservar ahora</strong>.',
          'Ingrese el <strong>RUT de cada integrante</strong> del grupo (de 2 a 5 personas, todas registradas en la biblioteca). Cada persona tiene <strong>una sola reserva pendiente</strong>: para entrar en otra, primero debe usar o cancelar la que tiene.',
          'Cuando el grupo llegue, pulse <strong>Confirmar llegada</strong> y entréguele la llave: tienen <strong>15 minutos</strong> desde la hora de inicio. Si alguien no vino o se sumó otra persona, pulse <strong>Modificar</strong> para corregir los integrantes.',
          'Si no llegan a tiempo, la sala se libera sola (o pulse <strong>Liberar ahora</strong>). Al recibir la llave, escanee su código o pulse la reserva y <strong>Confirmar devolución</strong>.',
        ]"
        consejo="Haga clic en cualquier bloque de color para ver quiénes reservaron y las acciones disponibles."
      />

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-md p-5">
          <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Ocupación Hoy</div>
          <div class="flex items-end gap-2">
            <span class="text-3xl font-bold text-indigo-700">
              {{ cierraMin > abreMin ? Math.round((salas.reduce((s, sala) => s + sala.tramos.reduce((s2, t) => s2 + (timeToMinutes(t.hora_fin) - timeToMinutes(t.hora_inicio)), 0), 0) / (salas.length * (cierraMin - abreMin) || 1)) * 100) : 0 }}%
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
              v-if="esHoy && atiende(ahoraMin)"
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
        <div class="flex items-start justify-between gap-3 mb-1">
          <h3 class="text-sm font-semibold text-gray-900">Menú de confirmación de asistencia</h3>
          <div v-if="pendientesConfirmacion.length" class="flex shrink-0 items-center gap-1.5">
            <span v-if="vencidasConfirmacion" class="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700">
              {{ vencidasConfirmacion }} {{ vencidasConfirmacion === 1 ? 'vencida' : 'vencidas' }}
            </span>
            <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700">
              {{ pendientesConfirmacion.length }} por confirmar
            </span>
          </div>
        </div>
        <p class="text-xs text-gray-400 mb-4">
          Cada reserva aparece aquí desde su hora de inicio y tiene 15 minutos para confirmar que el grupo llegó. Pasado
          ese plazo, la sala se libera sola.
        </p>

        <template v-if="pendientesConfirmacion.length">
          <ListaConfirmacionAsistencia
            :pendientes="pendientesConfirmacion.slice(0, CONFIRMACION_VISIBLES)"
            :liberando="liberando"
            @llegada="pedirLlegada"
            @modificar="(sala, tramo) => pedirLlegada(sala, tramo, true)"
            @liberar="pedirLiberacion"
          />
          <button
            v-if="pendientesConfirmacion.length > CONFIRMACION_VISIBLES"
            @click="verTodasConfirmacion = true"
            class="mt-3 w-full rounded-lg border border-gray-200 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-50 transition-colors"
          >
            Ver las {{ pendientesConfirmacion.length }} reservas por confirmar
          </button>
        </template>
        <p v-else class="text-sm text-gray-400 text-center py-4">Sin reservas pendientes de confirmar por ahora.</p>
        <p v-if="proximaConfirmacion" class="mt-3 text-xs text-gray-500">
          <template v-if="proximaConfirmacion.otras">
            Próximas: {{ proximaConfirmacion.sala }} y {{ proximaConfirmacion.otras }} más a las {{ proximaConfirmacion.hora }};
            aparecerán aquí a esa hora.
          </template>
          <template v-else>
            Próxima: {{ proximaConfirmacion.sala }} a las {{ proximaConfirmacion.hora }}; aparecerá aquí a esa hora.
          </template>
        </p>
      </div>

      <Modal
        :abierto="verTodasConfirmacion"
        titulo="Confirmación de asistencia"
        :descripcion="pendientesConfirmacion.length ? `${pendientesConfirmacion.length} por confirmar, de la más urgente a la menos urgente.` : undefined"
        ancho="lg"
        pie-fijo
        :bloqueado="llegadaPendiente !== null || liberacionPendiente !== null"
        @cerrar="verTodasConfirmacion = false"
      >
        <ListaConfirmacionAsistencia
          v-if="pendientesConfirmacion.length"
          :pendientes="pendientesConfirmacion"
          :liberando="liberando"
          @llegada="pedirLlegada"
          @modificar="(sala, tramo) => pedirLlegada(sala, tramo, true)"
          @liberar="pedirLiberacion"
        />
        <p v-else class="text-sm text-gray-400 text-center py-4">Ya no quedan reservas por confirmar.</p>
        <template #pie>
          <button
            @click="verTodasConfirmacion = false"
            class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
          >
            Cerrar
          </button>
        </template>
      </Modal>

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
        <div class="mb-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
          <p class="text-xs text-gray-400">
            {{ diaCerrado ? 'Elija otra fecha arriba para reservar.' : 'Click en cualquier espacio libre (verde) de la línea de tiempo para reservar esa sala a esa hora.' }}
          </p>
          <p v-if="actualizadoEn" class="flex items-center gap-1.5 text-[11px] text-gray-400">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
            Se actualiza sola cada {{ REFRESCO_SEGUNDOS }} s · última vez a las {{ actualizadoEn.toLocaleTimeString('es-CL', { hour12: false }) }}
            <button
              type="button"
              @click="cargar"
              class="rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-indigo-700 transition-colors"
              title="Actualizar ahora"
              aria-label="Actualizar ahora"
            >
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
              </svg>
            </button>
          </p>
        </div>
        <!-- En pantallas chicas la línea de tiempo se desplaza de lado en vez de
             comprimirse, para que las horas no se encimen. -->
        <div class="overflow-x-auto">
        <div class="min-w-[640px]">
        <div v-if="esHoy && !diaCerrado && nowPct > 0 && nowPct < 100" class="flex items-end mb-1">
          <div class="w-[132px] shrink-0 self-stretch"></div>
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
        <div class="flex items-center gap-3 mb-2">
          <div class="w-[120px] shrink-0"></div>
          <div class="relative h-4 flex-1 text-[10px] font-medium text-gray-400">
            <span
              v-for="h in horasRotuladas"
              :key="h"
              class="absolute -translate-x-1/2"
              :style="{ left: pct(`${h}:00`) + '%' }"
            >{{ String(h).padStart(2, '0') }}:00</span>
          </div>
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
                  v-for="h in horasIntermedias"
                  :key="h"
                  class="absolute inset-y-0 border-l"
                  :class="h % 2 === 0 ? 'border-emerald-900/[0.09]' : 'border-emerald-900/[0.05]'"
                  :style="{ left: pct(`${h}:00`) + '%' }"
                />
              </div>
              <div
                v-if="pctPasado > 0"
                class="absolute inset-y-0 left-0 franja-pasada pointer-events-none"
                :style="{ width: pctPasado + '%' }"
              />
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
                class="absolute inset-y-1 border flex items-center justify-center text-[10px] font-semibold overflow-hidden whitespace-nowrap shadow-sm hover:shadow-md hover:brightness-95 hover:z-20 hover:scale-y-105 transition-all cursor-pointer"
                :class="[tramoClases(tramo), anchoTramoPct(tramo) < 6 ? 'rounded-full' : 'rounded-lg px-1.5']"
                :style="{ left: posicionTramo(tramo.hora_inicio, tramo.hora_fin).izquierda + '%', width: anchoTramoPct(tramo) + '%' }"
                :title="`${tramo.hora_inicio.slice(0, 5)} – ${tramo.hora_fin.slice(0, 5)} · ${tramo.cantidad_personas} persona(s)`"
                @click.stop="verDetalle(sala, tramo)"
              >
                <span v-if="anchoTramoPct(tramo) >= 6">{{ tramo.hora_inicio.slice(0, 5) }}–{{ tramo.hora_fin.slice(0, 5) }}</span>
              </div>
              <div
                v-if="esHoy && !diaCerrado"
                class="absolute inset-y-0 w-[3px] -ml-px bg-indigo-600 pointer-events-none rounded-full shadow-[0_0_0_2px_rgba(79,70,229,0.15)]"
                :style="{ left: nowPct + '%' }"
              />
            </div>
          </div>
          <p v-if="!salasPage.length" class="text-sm text-gray-400 text-center py-6">Sin salas que coincidan con la búsqueda.</p>
        </div>
        </div>
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
        <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded franja-pasada border border-slate-200" /> Ya pasó o fuera de horario</div>
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
            Capacidad: {{ selectedSala.capacidad }} personas · {{ formatFechaLarga(selectedDate) }}
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
              <label class="block text-sm font-medium text-gray-700">RUT de cada persona <span class="font-normal text-gray-500">(extranjeros: pasaporte o DNI)</span></label>
              <div v-for="(_, idx) in rutsReserva" :key="idx">
                <div class="flex flex-wrap items-center gap-2">
                  <input
                    :value="rutsReserva[idx]"
                    @input="onRutInput(idx, $event)"
                    type="text"
                    :placeholder="`${modosReserva[idx] === 'extranjero' ? 'Pasaporte o DNI' : 'RUT'} persona ${idx + 1}`"
                    :maxlength="largoSegunModo(modosReserva[idx] ?? 'rut')"
                    class="min-w-0 flex-1 px-4 py-2.5 border rounded-lg focus:ring-2 outline-none"
                    :class="rutErrores[idx] ? 'border-red-400 ring-1 ring-red-200 focus:ring-red-400' : 'border-gray-300 focus:ring-indigo-500'"
                  />
                  <SelectorDocumento :model-value="modosReserva[idx] ?? 'rut'" compacto :etiqueta="`Documento de la persona ${idx + 1}`" @update:model-value="cambiarModoFila(idx, $event)" />
                </div>
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

          <div class="mb-6">
            <div class="mb-2 flex items-center justify-between gap-3">
              <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Integrantes ({{ detalleTramo.personas?.length ?? 0 }})</p>
              <button
                v-if="integrantesEditables(detalleTramo) && !editandoIntegrantesDetalle"
                @click="editandoIntegrantesDetalle = true"
                class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-700 hover:text-indigo-900"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                </svg>
                Modificar
              </button>
            </div>
            <EditorIntegrantes
              v-if="editandoIntegrantesDetalle"
              :reserva-id="detalleTramo.reserva_id"
              :personas="detalleTramo.personas ?? []"
              @guardado="integrantesGuardados"
              @cancelar="editandoIntegrantesDetalle = false"
            />
            <div v-else class="space-y-2">
              <div
                v-for="(persona, idx) in detalleTramo.personas ?? []"
                :key="idx"
                class="flex items-center justify-between px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg"
              >
                <span class="text-sm text-gray-900">{{ persona.nombre ?? 'Sin registro en el sistema' }}</span>
                <span class="text-xs font-mono text-gray-500">{{ persona.rut }}</span>
              </div>
              <p v-if="!detalleTramo.personas?.length" class="text-sm text-gray-400">Sin integrantes registrados.</p>
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
            {{
              detalleTramo.vencida_sin_confirmar
                ? 'Venció el plazo de 15 minutos sin confirmar llegada'
                : selectedDate === hoy
                  ? 'Todavía no se confirma la llegada del grupo'
                  : 'La llegada se confirma el mismo día de la reserva'
            }}
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
                @click="pedirLiberacion(detalleSala, detalleTramo)"
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
                <template v-if="!detalleTramo.hora_prestamo_real">
                  <button
                    v-if="selectedDate === hoy"
                    @click="pedirLlegada(detalleSala, detalleTramo)"
                    class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm"
                  >
                    Confirmar llegada
                  </button>
                </template>
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
        v-if="liberacionPendiente"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[60] flex items-center justify-center p-4"
        @click.self="liberando === null && (liberacionPendiente = null)"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Liberar la sala?</h3>
          <p class="text-sm text-gray-500 mb-6">
            Se registrará que el grupo no se presentó a <strong>{{ liberacionPendiente.salaNombre }}</strong> en el tramo
            <strong>{{ liberacionPendiente.label }}</strong>, y el bloque quedará libre para otras reservas. Esta acción no se puede deshacer.
          </p>
          <div class="flex gap-3">
            <button
              @click="liberacionPendiente = null"
              :disabled="liberando !== null"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              No, mantener
            </button>
            <button
              @click="confirmarLiberacion"
              :disabled="liberando !== null"
              class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ liberando !== null ? 'Liberando…' : 'Sí, liberar' }}
            </button>
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
        @click.self="!confirmandoLlegada && cerrarLlegada()"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-md max-h-[90vh] overflow-y-auto">
          <h3 class="text-lg font-bold text-gray-900 mb-1">Confirmar llegada</h3>
          <p class="text-sm text-gray-500 mb-4">
            Se registrará que el grupo se presentó en <strong>{{ llegadaPendiente.salaNombre }}</strong> para el tramo
            <strong>{{ llegadaPendiente.label }}</strong>. Antes de entregar la llave, verifique que estén sus integrantes:
            si alguien no vino o se sumó otra persona, pulse <strong>Modificar</strong>. Quienes no hayan marcado su entrada
            hoy quedarán presentes en la biblioteca.
          </p>
          <div class="mb-6">
            <div class="mb-2 flex items-center justify-between gap-3">
              <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">
                Integrantes ({{ llegadaPendiente.personas.length }})
              </p>
              <button
                v-if="!editandoIntegrantesLlegada"
                @click="editandoIntegrantesLlegada = true"
                :disabled="confirmandoLlegada"
                class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-700 hover:text-indigo-900 disabled:opacity-50"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                </svg>
                Modificar
              </button>
            </div>
            <EditorIntegrantes
              v-if="editandoIntegrantesLlegada"
              :reserva-id="llegadaPendiente.reservaId"
              :personas="llegadaPendiente.personas"
              @guardado="integrantesGuardados"
              @cancelar="editandoIntegrantesLlegada = false"
            />
            <div v-else class="space-y-2">
              <div
                v-for="(persona, idx) in llegadaPendiente.personas"
                :key="persona.rut"
                class="flex items-center gap-3 px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg"
              >
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold text-indigo-700">
                  {{ idx + 1 }}
                </span>
                <span class="flex-1 min-w-0 text-sm text-gray-900 truncate">{{ persona.nombre ?? 'Sin registro en el sistema' }}</span>
                <span class="text-xs font-mono text-gray-600 shrink-0">{{ persona.rut }}</span>
              </div>
              <p v-if="!llegadaPendiente.personas.length" class="text-sm text-gray-400">Sin integrantes registrados.</p>
            </div>
          </div>
          <p v-if="editandoIntegrantesLlegada" class="mb-3 text-xs text-amber-700">
            Guarde o descarte los cambios de integrantes antes de confirmar la llegada.
          </p>
          <div class="flex gap-3">
            <button
              @click="cerrarLlegada"
              :disabled="confirmandoLlegada"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="confirmarLlegadaAction"
              :disabled="confirmandoLlegada || editandoIntegrantesLlegada"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ confirmandoLlegada ? 'Guardando…' : 'Confirmar' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <AvisoMultaAtraso :aviso="atrasoDevolucion" @cerrar="atrasoDevolucion = null" />
  </StaffLayout>
</template>
