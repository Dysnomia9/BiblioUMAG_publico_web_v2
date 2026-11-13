<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import FondoSalas from '@/components/ui/FondoSalas.vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import GuiaUso from '@/components/ui/GuiaUso.vue'
import Modal from '@/components/ui/Modal.vue'
import ListaConfirmacionAsistencia from '@/components/salas/ListaConfirmacionAsistencia.vue'
import EditorIntegrantes from '@/components/salas/EditorIntegrantes.vue'
import AvisoMultaAtraso from '@/components/salas/AvisoMultaAtraso.vue'
import AvisoTiempoSinUsar from '@/components/salas/AvisoTiempoSinUsar.vue'
import SelectorDocumento from '@/components/ui/SelectorDocumento.vue'
import CampoHora from '@/components/ui/CampoHora.vue'
import api from '@/services/api'
import { diaEnPlural, enPuntaArenas } from '@/utils/horario'
import { PISOS_MAPA } from '@/utils/mapaSalas'
import { sinTildes } from '@/utils/texto'
import { duracion, PERSONAS_MAXIMAS_MESON, PERSONAS_MINIMAS_MESON, textoMinutos } from '@/utils/reservas'
import { useToast } from '@/composables/useToast'
import { useRefrescoPeriodico } from '@/composables/useRefrescoPeriodico'
import { useHorarioSalas } from '@/composables/useHorarioSalas'
import { largoSegunModo, modoSegunValor } from '@/utils/documento'
import { useModosPorFila } from '@/composables/useModoDocumento'
import { useAuthStore } from '@/stores/auth'
import type { AsistenciaSala, AtrasoDevolucion, IntegrantesReserva, Sala, TiempoSinUsar, TramoReserva } from '@/types'

const toast = useToast()
const auth = useAuthStore()
// Agendar en un horario ya pasado (ej. registrar algo que se atendió sin pasar por
// el sistema) requiere el permiso 'salas.hora_pasada' — el resto del personal sigue
// restringido a "desde ahora en adelante". El backend re-valida esto igual (ver
// ReservaSalaService::validarTramo()); esto solo evita ofrecer una opción que el
// servidor rechazaría.
const puedeHoraPasada = computed(() => auth.puede('salas.hora_pasada'))
// Reservar por más que el máximo del portal (ej. la Sala de Postgrado todo el día) requiere
// el permiso 'salas.sin_limite'; el servidor vuelve a revisarlo.
const puedeReservaLarga = computed(() => auth.puede('salas.sin_limite'))
// Las salas de la administración (solo_administracion: seminarios, Cooperación, Moraga) no se prestan a estudiantes: las reserva quien tiene este permiso.
const puedeSalasAdministracion = computed(() => auth.puede('salas.administracion'))

// La fecha y la hora son las de Punta Arenas, como en el servidor (utils/horario.ts), no las del computador.
// Cambia con el reloj (ver onMounted): el mesón suele quedar abierto de un día para otro.
const hoy = ref(enPuntaArenas().fecha)

const DURACIONES = [30, 60, 90, 120]

// El rango del mesón llega con las salas (config/salas.php): más amplio que el del portal.
const cantidadMinima = ref(PERSONAS_MINIMAS_MESON)
const cantidadMaxima = ref(PERSONAS_MAXIMAS_MESON)

const salas = ref<Sala[]>([])
const apiError = ref(false)
const selectedDate = ref(hoy.value)
const busqueda = ref('')
// La página (Piso 1, Piso 2, Administración) que se mira: cada computador recuerda la última,
// porque cada mesón suele atender un piso. Sin almacenamiento disponible, la primera.
const CLAVE_PAGINA = 'salas.pagina'
function paginaGuardada(): number {
  try {
    return Math.max(0, Number(localStorage.getItem(CLAVE_PAGINA)) || 0)
  } catch {
    return 0
  }
}
const page = ref(paginaGuardada())
watch(page, (n) => {
  try {
    localStorage.setItem(CLAVE_PAGINA, String(n))
  } catch {
    // Sin almacenamiento: vale solo mientras la página esté abierta.
  }
})

// Ventana de atención y reglas del día — vienen del backend (config/salas.php), nunca hardcodeadas acá.
const apertura = ref('08:00')
const cierre = ref('21:00')
const granularidad = ref(30)
const duracionMinima = ref(30)
const duracionMaxima = ref(120)
const cuotaDiaria = ref(240)
const minutosPresentarse = ref(15)

/** «22 logias de estudio, Seminario 1 y Sala AGACI», según las salas que hay. */
const resumenSalas = computed(() => {
  const logias = salas.value.filter((s) => s.tipo === 'logia').length
  const partes = [...(logias ? [`${logias} ${logias === 1 ? 'logia' : 'logias'} de estudio`] : []), ...salas.value.filter((s) => s.tipo !== 'logia').map((s) => s.nombre)]
  return new Intl.ListFormat('es').format(partes)
})
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

/** Parte del horario del día que ya está reservada, sumando todas las salas (0–100). */
const ocupacionHoy = computed(() => {
  if (cierraMin.value <= abreMin.value || !salas.value.length) return 0
  const reservado = salas.value.reduce((s, sala) => s + sala.tramos.reduce((s2, t) => s2 + (timeToMinutes(t.hora_fin) - timeToMinutes(t.hora_inicio)), 0), 0)
  return Math.min(100, Math.round((reservado / (salas.value.length * (cierraMin.value - abreMin.value))) * 100))
})

// «¿Cómo se usa esta pantalla?» va en el encabezado; los pasos se abren debajo.
const guiaAbierta = ref(false)
// Línea morada en el borde superior de la tarjeta de la línea de tiempo.
const FRANJA = 'linear-gradient(90deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%)'

function pct(hhmm: string): number {
  return ((timeToMinutes(hhmm) - aperturaMin.value) / totalMin.value) * 100
}

/**
 * Dónde va «+ Click para reservar» en la fila de una sala: el espacio libre más ancho entre
 * ahora (o la apertura) y el cierre, aunque la sala ya tenga reservas, en % desde cada borde.
 * Null si ningún espacio alcanza para el texto (menos del 12 % de la línea, unas 1,5 horas).
 */
function huecoParaReservar(sala: Sala): { izquierda: number; derecha: number } | null {
  const desde = pctPasado.value
  const hasta = 100 - pctTrasCierre.value
  const ocupados = sala.tramos
    .map((t) => posicionTramo(t.hora_inicio, t.hora_fin))
    .filter((p) => p.visible)
    .map((p) => [p.izquierda, p.izquierda + p.ancho] as const)
    .sort((a, b) => a[0] - b[0])
  let mejor: [number, number] | null = null
  let cursor = desde
  const considerar = (a: number, b: number) => {
    if (b - a > (mejor ? mejor[1] - mejor[0] : 0)) mejor = [a, b]
  }
  for (const [inicio, fin] of ocupados) {
    if (inicio > cursor) considerar(cursor, Math.min(inicio, hasta))
    cursor = Math.max(cursor, fin)
  }
  considerar(cursor, hasta)
  const hueco = mejor as [number, number] | null
  return hueco && hueco[1] - hueco[0] >= 12 ? { izquierda: hueco[0], derecha: 100 - hueco[1] } : null
}

/** Ancho del bloque en % de la línea de tiempo, con un piso mínimo para que un tramo corto (ej. 30 min) siga siendo clickeable y visible. */
function anchoTramoPct(tramo: TramoReserva): number {
  return posicionTramo(tramo.hora_inicio, tramo.hora_fin).ancho
}

const modalOpen = ref(false)
const selectedSala = ref<Sala | null>(null)
const modalHoraInicio = ref('')
const modalDuracion = ref(60)
// «Ahora»: el grupo está en el mesón. La reserva parte en este minuto (no a la hora o a la
// media hora) y queda con la llegada confirmada (SalaController::storeReserva, inmediata).
const AHORA = 'ahora'
// Casilla «Escribir la hora de inicio a mano»: cualquier minuto (17:10), en vez de «Ahora» o la grilla.
const inicioAMano = ref(false)
const modalHoraInicioEscrita = ref('')
const campoHoraInicio = ref<InstanceType<typeof CampoHora> | null>(null)
watch(inicioAMano, async (aMano) => {
  if (!aMano) return
  await nextTick()
  campoHoraInicio.value?.enfocar()
})
const modalEsAhora = computed(() => !inicioAMano.value && modalHoraInicio.value === AHORA)
// Casilla «Escribir la hora de término a mano»: en vez de una duración, el personal escribe hasta qué hora (18:35).
const terminoAMano = ref(false)
const modalHoraFin = ref('')
const campoHoraFin = ref<InstanceType<typeof CampoHora> | null>(null)
watch(terminoAMano, async (aMano) => {
  if (!aMano) return
  await nextTick()
  campoHoraFin.value?.enfocar()
})
const cantidadPersonas = ref(cantidadMinima.value)
const rutsReserva = ref<string[]>(Array.from({ length: cantidadMinima.value }, () => ''))
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
// Si volvió antes de tiempo, el aviso para liberar lo que quedaba del bloque.
const tiempoSinUsar = ref<TiempoSinUsar | null>(null)
const llegadaPendiente = ref<{ reservaId: number; salaNombre: string; label: string; personas: TramoReserva['personas']; soloResponsable: boolean } | null>(null)
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

// El atajo del mesón: el código de la sala (L04, SEM…) o el número de la logia.
const codigoSala = ref('')
const registrandoLlave = ref(false)

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
    minutosPresentarse.value = data.minutos_para_presentarse
    cantidadMinima.value = data.personas_minimas ?? PERSONAS_MINIMAS_MESON
    minimoLogias.value = data.personas_minimas_logias ?? null
    cantidadMaxima.value = data.personas_maximas ?? PERSONAS_MAXIMAS_MESON
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
  relojTimer = setInterval(() => {
    ahora.value = new Date()
    // Pasada la medianoche: si se miraba el día de hoy, se pasa al nuevo. Antes «hoy» quedaba
    // fijo en el día en que se abrió la página, y a la mañana siguiente el menú de confirmación
    // y la línea de tiempo seguían en el día anterior hasta recargar.
    const fecha = enPuntaArenas(ahora.value).fecha
    if (fecha !== hoy.value) {
      const mirabaHoy = selectedDate.value === hoy.value
      hoy.value = fecha
      if (mirabaHoy) selectedDate.value = fecha
    }
  }, 1000)
})
onUnmounted(() => clearInterval(relojTimer))
useRefrescoPeriodico(cargar, REFRESCO_SEGUNDOS * 1000)
watch(selectedDate, cargar)

/** La primera vez entrega la llave de la reserva vigente; la segunda, la recibe. */
async function registrarLlave() {
  if (!codigoSala.value.trim()) {
    toast.error('Escriba el código de la sala (por ejemplo, L04) o el número de la logia')
    return
  }
  registrandoLlave.value = true
  try {
    const { data } = await api.post('/salas/llave', { codigo: codigoSala.value.trim() })
    const sala = data.sala?.nombre ?? 'la sala'
    if (data.hora_devolucion_real) {
      toast.success(`Llave de ${sala} recibida`)
      atrasoDevolucion.value = data.atraso_devolucion ?? null
      tiempoSinUsar.value = data.tiempo_sin_usar ?? null
    } else toast.success(`Llave de ${sala} entregada. ${textoAsistencia(data.asistencia)}`)
    codigoSala.value = ''
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo registrar la llave')
  } finally {
    registrandoLlave.value = false
  }
}

const filteredSalas = computed(() => {
  if (!busqueda.value.trim()) return salas.value
  const q = sinTildes(busqueda.value.trim())
  return salas.value.filter((s) => sinTildes(s.nombre).includes(q) || sinTildes(s.codigo).includes(q))
})

// Una página por piso, como el plano (utils/mapaSalas.ts), con las salas que se prestan; las de
// la administración (seminarios, Cooperación, Moraga) van en la suya («Administración»). Antes eran 10 salas por
// página, sin orden de piso. Una sala que no está en el plano va en «Otras».
const CODIGOS_POR_PISO = PISOS_MAPA.map((piso) => new Set([piso.izquierda, piso.derecha].flatMap((lado) => lado.tramos.flat()).map((lugar) => lugar.codigo)))
const paginas = computed(() => {
  const prestables = salas.value.filter((s) => !s.solo_administracion)
  return [
    ...PISOS_MAPA.map((_, i) => ({ titulo: `Piso ${i + 1}`, salas: prestables.filter((s) => CODIGOS_POR_PISO[i].has(s.codigo)) })),
    { titulo: 'Otras', salas: prestables.filter((s) => !CODIGOS_POR_PISO.some((codigos) => codigos.has(s.codigo))) },
    { titulo: 'Administración', salas: salas.value.filter((s) => s.solo_administracion) },
  ].filter((p) => p.salas.length)
})
const paginaActual = computed(() => Math.min(page.value, Math.max(0, paginas.value.length - 1)))
// Buscando, salen las que coinciden de todas las páginas.
const salasPage = computed(() => (busqueda.value.trim() ? filteredSalas.value : (paginas.value[paginaActual.value]?.salas ?? [])))
function irAPagina(n: number) {
  busqueda.value = ''
  soloConReserva.value = false
  page.value = n
}

// Dentro del recuadro: todas las salas de la página o, de todos los pisos, solo las que tienen
// una reserva que importa ahora (en curso o más tarde hoy; en otro día, cualquiera de ese
// día), separadas por piso. Las páginas de arriba siguen llevando a cada piso completo.
const soloConReserva = ref(false)
function tieneReserva(sala: Sala) {
  if (selectedDate.value < hoy.value) return sala.tramos.length > 0
  return sala.tramos.some((t) => t.estado === 'activa' && (!esHoy.value || timeToMinutes(t.hora_fin) > ahoraMin.value))
}
const gruposConReserva = computed(() =>
  (busqueda.value.trim() ? [{ titulo: 'Búsqueda', salas: filteredSalas.value }] : paginas.value)
    .map((p, indice) => ({ titulo: p.titulo, indice: busqueda.value.trim() ? null : indice, salas: p.salas.filter(tieneReserva) }))
    .filter((g) => g.salas.length),
)
const totalConReserva = computed(() => gruposConReserva.value.reduce((n, g) => n + g.salas.length, 0))
/** Lo que se dibuja: la página sola, o los pisos con reserva, cada uno con su separador. */
const bloquesSalas = computed<{ titulo: string | null; indice: number | null; salas: Sala[] }[]>(() =>
  soloConReserva.value ? gruposConReserva.value : [{ titulo: null, indice: null, salas: salasPage.value }],
)

const esHoy = computed(() => selectedDate.value === hoy.value)
const ahoraEnPuntaArenas = computed(() => enPuntaArenas(ahora.value))
const ahoraMin = computed(() => ahoraEnPuntaArenas.value.minutos)
const nowPct = computed(() => Math.min(100, Math.max(0, ((ahoraMin.value - aperturaMin.value) / totalMin.value) * 100)))
/** Horas enteras dentro del horario (sin los bordes): una línea guía por cada una. */
const horasIntermedias = computed(() => {
  const primera = Math.floor(aperturaMin.value / 60) + 1
  const ultima = Math.ceil(cierreMin.value / 60) - 1
  return Array.from({ length: Math.max(0, ultima - primera + 1) }, (_, i) => primera + i)
})
const horaActual = computed(() => ahoraEnPuntaArenas.value.hora)
/** El marcador «Ahora» se centra en la línea, salvo en los bordes, donde se apoya hacia adentro. */
const alineacionAhora = computed(() => (nowPct.value < 8 ? '' : nowPct.value > 92 ? '-translate-x-full' : '-translate-x-1/2'))
/** Lo rayado desde la izquierda: hasta ahora si es hoy (o hasta que abre), entera si es un día anterior o sin atención. */
const pctPasado = computed(() => {
  const pasado = diaCerrado.value || selectedDate.value < hoy.value ? 100 : Math.max(pctAntesDeAbrir.value, esHoy.value ? nowPct.value : 0)
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
  return Math.max(0, puedeReservaLarga.value ? limite - desdeMin : Math.min(limite - desdeMin, duracionMaxima.value))
}

/** Si se puede reservar `sala` desde este minuto: hoy, con la biblioteca abierta y la sala libre al menos lo mínimo. */
const libreDesdeAhora = (sala: Sala) => esHoy.value && !diaCerrado.value && disponibilidadDesde(sala, ahoraMin.value) >= duracionMinima.value

const modalInicioMin = computed(() => {
  if (inicioAMano.value) return modalHoraInicioEscrita.value ? timeToMinutes(modalHoraInicioEscrita.value) : ahoraMin.value
  return modalEsAhora.value ? ahoraMin.value : timeToMinutes(modalHoraInicio.value || apertura.value)
})
const modalDisponibleMin = computed(() => (selectedSala.value ? disponibilidadDesde(selectedSala.value, modalInicioMin.value) : 0))
const modalDuracionesValidas = computed(() => DURACIONES.filter((d) => d >= duracionMinima.value && d <= modalDisponibleMin.value))
const textoInicioModal = computed(() => (modalEsAhora.value ? `ahora (${minutesToTime(ahoraMin.value)})` : `las ${minutesToTime(modalInicioMin.value)}`))

/** Por qué no sirve la hora de inicio escrita a mano, o null si sirve (el servidor revisa lo mismo). */
const errorInicio = computed<string | null>(() => {
  if (!inicioAMano.value || !selectedSala.value) return null
  if (!modalHoraInicioEscrita.value) return 'Escriba la hora de inicio: los cuatro números, por ejemplo 1710 para las 17:10 (de 00:00 a 23:59).'
  const inicio = modalInicioMin.value
  if (inicio < abreMin.value || inicio >= cierraMin.value) return `La biblioteca atiende de ${minutesToTime(abreMin.value)} a ${minutesToTime(cierraMin.value)}: escriba una hora dentro de ese horario.`
  if (esHoy.value && !puedeHoraPasada.value && inicio < ahoraMin.value) return `Esa hora ya pasó: escriba una desde las ${minutesToTime(ahoraMin.value)}, o desmarque la casilla y elija «Ahora».`
  if (cierraMin.value - inicio < duracionMinima.value) return `La biblioteca cierra a las ${minutesToTime(cierraMin.value)}: desde las ${minutesToTime(inicio)} ya no alcanza una reserva de al menos ${formatMinutos(duracionMinima.value)}.`
  if (disponibilidadDesde(selectedSala.value, inicio) === 0) return `A las ${minutesToTime(inicio)} esta sala ya está ocupada.`
  return null
})

/**
 * Por qué no sirve la hora de término escrita a mano, o null si sirve: las mismas reglas
 * que revisa el servidor (duración mínima y máxima, cierre de la biblioteca y la siguiente
 * reserva de la sala), dichas con la hora hasta la que sí se puede.
 */
const errorTermino = computed<string | null>(() => {
  if (!terminoAMano.value || errorInicio.value) return null
  if (!modalHoraFin.value) return 'Escriba la hora de término: los cuatro números, por ejemplo 1835 para las 18:35 (de 00:00 a 23:59).'
  const inicio = modalInicioMin.value
  const fin = timeToMinutes(modalHoraFin.value)
  const minutos = fin - inicio
  const hasta = (m: number) => minutesToTime(Math.min(inicio + m, cierraMin.value))
  if (fin <= inicio) return `Debe ser después de ${textoInicioModal.value}.`
  if (fin > cierraMin.value) return `La biblioteca cierra a las ${minutesToTime(cierraMin.value)}: puede ser hasta esa hora.`
  if (minutos < duracionMinima.value) return `La reserva dura al menos ${formatMinutos(duracionMinima.value)}: desde las ${hasta(duracionMinima.value)}.`
  if (minutos > duracionMaxima.value && !puedeReservaLarga.value) {
    return `Serían ${formatMinutos(minutos)}: el máximo es ${formatMinutos(duracionMaxima.value)}, hasta las ${hasta(duracionMaxima.value)}. Para reservar más tiempo se necesita el permiso «Reservas largas».`
  }
  if (minutos > modalDisponibleMin.value) return `A las ${hasta(modalDisponibleMin.value)} empieza otra reserva en esta sala: puede ser hasta esa hora.`
  return null
})
const modalFinMin = computed(() =>
  terminoAMano.value ? timeToMinutes(modalHoraFin.value || '00:00') : Math.min(modalInicioMin.value + modalDuracion.value, cierraMin.value),
)
const puedeConfirmarReserva = computed(() => modalDisponibleMin.value >= duracionMinima.value && !errorInicio.value && !errorTermino.value)
/** El aviso de las horas escritas a mano: primero el del inicio. */
const avisoHoras = computed(() => errorInicio.value ?? errorTermino.value)
const avisoHorasEsError = computed(() => (errorInicio.value ? !!modalHoraInicioEscrita.value : !!modalHoraFin.value))

// Al cambiar la hora de inicio, la duración elegida puede dejar de caber: se toma la mayor que sí cabe.
watch(modalInicioMin, () => {
  if (terminoAMano.value) return
  const validas = modalDuracionesValidas.value
  if (validas.length && !validas.includes(modalDuracion.value)) modalDuracion.value = validas[validas.length - 1]
})

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

/** Un seminario va a nombre del docente o funcionario que lo pide (una persona); las demás salas, un grupo. */
/** El mínimo de las logias (Administración › Salas), o null si está apagado. */
const minimoLogias = ref<number | null>(null)
const minimoDe = (sala: Sala) => (sala.solo_responsable ? 1 : sala.codigo?.toUpperCase().startsWith('L') && minimoLogias.value !== null ? minimoLogias.value : cantidadMinima.value)
/** El mínimo de la sala elegida: el de las opciones de «Cantidad de personas». */
const minimoElegida = computed(() => (selectedSala.value ? minimoDe(selectedSala.value) : cantidadMinima.value))

function abrirModalProgramado(sala: Sala, inicioSugeridoMin: number | typeof AHORA) {
  selectedSala.value = sala
  inicioAMano.value = false
  modalHoraInicioEscrita.value = ''
  if (inicioSugeridoMin === AHORA) {
    modalHoraInicio.value = AHORA
  } else {
    const alineado = Math.max(primerInicioMin.value, Math.ceil(inicioSugeridoMin / granularidad.value) * granularidad.value)
    modalHoraInicio.value = minutesToTime(Math.min(alineado, cierraMin.value - duracionMinima.value))
  }
  const disponibles = DURACIONES.filter((d) => d >= duracionMinima.value && d <= disponibilidadDesde(sala, modalInicioMin.value))
  modalHoraFin.value = ''
  terminoAMano.value = false
  modalDuracion.value = disponibles.length ? disponibles[disponibles.length - 1] : duracionMinima.value
  cantidadPersonas.value = minimoDe(sala)
  rutsReserva.value = Array.from({ length: minimoDe(sala) }, () => '')
  reiniciarModos()
  rutErrores.value = {}
  modalOpen.value = true
}

// ---------- Renovación: el mismo grupo otra vez, sin volver a escribir los RUT ----------
// Un grupo que usó la sala (o la está usando) vuelve al mesón y quiere seguir: se abre el
// formulario de siempre con la misma sala y todos sus RUT ya puestos, desde que termina su
// bloque (para extenderlo) o desde ahora si ya terminó. La hora y la duración se eligen como
// en una reserva nueva, y el servidor revisa lo de siempre (que esté libre, el máximo y la cuota).
const renovacionDe = ref<{ tramo: TramoReserva } | null>(null)
watch(modalOpen, (abierto) => {
  if (!abierto) renovacionDe.value = null
})
function renovar(sala: Sala, tramo: TramoReserva) {
  if (diaCerrado.value) {
    toast.error(textoDiaCerrado.value)
    return
  }
  const finMin = timeToMinutes(tramo.hora_fin)
  const sigueEnUso = !tramo.hora_devolucion_real && finMin > ahoraMin.value
  const inicio: number | typeof AHORA = sigueEnUso ? finMin : libreDesdeAhora(sala) ? AHORA : ahoraMin.value
  const desdeMin = inicio === AHORA ? ahoraMin.value : inicio
  if (disponibilidadDesde(sala, desdeMin) < duracionMinima.value) {
    toast.error(`${sala.nombre} no tiene un bloque libre desde las ${minutesToTime(desdeMin)}: elija otra hora en la línea de tiempo o haga una reserva nueva en otra sala.`)
    return
  }
  detalleOpen.value = false
  abrirModalProgramado(sala, inicio)
  const ruts = tramo.personas.map((p) => p.rut).filter(Boolean)
  cantidadPersonas.value = Math.max(ruts.length, minimoDe(sala))
  rutsReserva.value = [...ruts, ...Array.from({ length: Math.max(0, cantidadPersonas.value - ruts.length) }, () => '')]
  reiniciarModos(rutsReserva.value.map((r) => (r ? modoSegunValor(r) : 'rut')))
  renovacionDe.value = { tramo }
}

function onTimelineClick(sala: Sala, event: MouseEvent) {
  if (diaCerrado.value) {
    toast.error(textoDiaCerrado.value)
    return
  }
  if (sala.solo_administracion && !puedeSalasAdministracion.value) {
    toast.error(`${sala.nombre} la gestiona solo la administración: pide a un administrador que haga la reserva.`)
    return
  }
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const fraccion = (event.clientX - rect.left) / rect.width
  const minutoClickeado = aperturaMin.value + fraccion * totalMin.value

  if (!atiende(minutoClickeado)) {
    toast.error(textoFueraDeHorario.value)
    return
  }
  // Un clic en la media hora en curso es para un grupo que está en el mesón: desde ahora.
  const bloqueActual = Math.floor(ahoraMin.value / granularidad.value) * granularidad.value
  const enBloqueActual = esHoy.value && minutoClickeado >= bloqueActual && minutoClickeado < bloqueActual + granularidad.value
  if (enBloqueActual && libreDesdeAhora(sala)) {
    abrirModalProgramado(sala, AHORA)
    return
  }
  const avisoCierre = `La biblioteca cierra a las ${minutesToTime(cierraMin.value)}: ya no alcanza una reserva de al menos ${formatMinutos(duracionMinima.value)}.`
  if (enBloqueActual && disponibilidadDesde(sala, ahoraMin.value) > 0 && cierraMin.value - ahoraMin.value < duracionMinima.value) {
    toast.error(avisoCierre)
    return
  }
  if (esHoy.value && !puedeHoraPasada.value && minutoClickeado < ahoraMin.value) {
    toast.error('Esa hora ya pasó — elige un horario desde ahora en adelante.')
    return
  }
  // Se propone el bloque de media hora donde cayó el clic o, si ya empezó o está
  // tomado, el siguiente.
  const bloque = Math.floor(minutoClickeado / granularidad.value) * granularidad.value
  const inicio = [bloque, bloque + granularidad.value]
    .filter((m) => puedeHoraPasada.value || !esHoy.value || m >= ahoraMin.value)
    .find((m) => disponibilidadDesde(sala, m) > 0)
  if (inicio === undefined) {
    const desde = esHoy.value ? Math.max(minutoClickeado, ahoraMin.value) : minutoClickeado
    toast.error(cierraMin.value - desde < duracionMinima.value ? avisoCierre : 'Esa hora ya está ocupada en esta sala.')
    return
  }

  abrirModalProgramado(sala, inicio)
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
  if (avisoHoras.value) {
    toast.error(avisoHoras.value)
    return
  }

  rutErrores.value = {}
  try {
    const { data } = await api.post('/reservas', {
      sala_id: selectedSala.value.id,
      fecha: selectedDate.value,
      hora_inicio: minutesToTime(modalInicioMin.value),
      hora_fin: minutesToTime(modalFinMin.value),
      cantidad_personas: cantidadPersonas.value,
      ruts: rutsReserva.value,
      // Desde ahora, el servidor pone el inicio con su propia hora, y el término también si se
      // eligió una duración (con una hora de término escrita, es esa).
      ...(modalEsAhora.value ? { inmediata: true, ...(terminoAMano.value ? {} : { duracion: modalDuracion.value }) } : {}),
    })
    const tramo = labelTramo(data.hora_inicio.slice(0, 5), data.hora_fin.slice(0, 5))
    if (data.hora_prestamo_real) {
      toast.success(`${selectedSala.value.nombre} reservada de ${tramo}, con la llegada confirmada: entregue la llave. ${textoAsistencia(data.asistencia)}`.trim())
    } else {
      toast.success(`${selectedSala.value.nombre} reservada de ${tramo} para ${cantidadPersonas.value} persona(s)`)
    }
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
    const { data } = await api.patch<{ atraso_devolucion?: AtrasoDevolucion | null; tiempo_sin_usar?: TiempoSinUsar | null }>(
      `/reservas/${devolucionPendiente.value.reservaId}/devolver`,
    )
    toast.success('Devolución de llave confirmada')
    atrasoDevolucion.value = data.atraso_devolucion ?? null
    tiempoSinUsar.value = data.tiempo_sin_usar ?? null
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
  return fecha === hoy.value ? 'Hoy' : new Date(`${fecha}T12:00:00`).toLocaleDateString('es-CL')
}

/** Con `modificar`, el modal se abre con la lista de integrantes ya en edición. */
function pedirLlegada(sala: Sala, tramo: TramoReserva, modificar = false) {
  llegadaPendiente.value = {
    reservaId: tramo.reserva_id,
    salaNombre: sala.nombre,
    label: labelTramo(tramo.hora_inicio, tramo.hora_fin),
    personas: tramo.personas ?? [],
    soloResponsable: !!sala.solo_responsable,
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
  if (selectedDate.value !== hoy.value) return []
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
  if (selectedDate.value !== hoy.value) return null
  const proximas = salas.value
    .flatMap((sala) => sala.tramos.map((tramo) => ({ sala, tramo })))
    .filter(({ tramo }) => tramo.estado === 'activa' && !tramo.hora_prestamo_real && timeToMinutes(tramo.hora_inicio) > ahoraMin.value)
    .sort((a, b) => a.tramo.hora_inicio.localeCompare(b.tramo.hora_inicio))
  if (!proximas.length) return null
  const hora = proximas[0].tramo.hora_inicio
  return { hora, sala: proximas[0].sala.nombre, otras: proximas.filter((p) => p.tramo.hora_inicio === hora).length - 1 }
})

// Botones de las ventanas del mesón: Cancelar en blanco y la acción con un borde más oscuro abajo.
const BOTON_CANCELAR = 'flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-100 disabled:opacity-60'
const BOTON_PRINCIPAL = 'flex-1 rounded-lg px-3 py-2 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] transition-colors disabled:opacity-50'
const FILA_INTEGRANTE = 'flex items-center gap-2.5 rounded-lg border border-l-[3px] border-gray-200 border-l-indigo-600 bg-white px-3 py-2'

/** Reservada (aún sin el grupo), ocupada (con la llave) o con la llave devuelta. */
function estadoDetalle(t: { hora_prestamo_real?: string | null; hora_devolucion_real?: string | null; vencida_sin_confirmar?: boolean }) {
  if (t.hora_devolucion_real) return { texto: 'Llave devuelta', clase: 'border-emerald-300 bg-emerald-50/60 text-emerald-700' }
  if (t.hora_prestamo_real) return { texto: 'Ocupada', clase: 'border-red-300 bg-red-50/60 text-red-700' }
  if (t.vencida_sin_confirmar) return { texto: 'No llegó', clase: 'border-red-300 bg-red-50/60 text-red-700' }
  return { texto: 'Reservada', clase: 'border-indigo-300 bg-indigo-50/60 text-indigo-700' }
}
</script>

<template>
  <StaffLayout>
    <div class="max-w-6xl mx-auto">
      <!-- Encabezado: el degradado con puntos tenues y dos anillos a la derecha -->
      <div
        class="relative mb-6 overflow-hidden rounded-2xl shadow-md"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <FondoSalas />
        <!-- Del mismo alto que el de Préstamos: el horario y el largo de las reservas van junto al título. -->
        <div class="relative flex flex-wrap items-center justify-between gap-3 px-6 py-5 md:flex-nowrap">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
              <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Salas de Estudio</h1>
              <span class="inline-flex items-center gap-1 rounded-full border border-white/25 bg-blanco/10 px-2.5 py-0.5 text-[11px] font-semibold text-white">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Horario del día {{ textoHorario }}
              </span>
              <span class="inline-flex items-center gap-1 rounded-full border border-white/25 bg-blanco/10 px-2.5 py-0.5 text-[11px] font-semibold text-white">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                </svg>
                Reservas de hasta {{ formatMinutos(duracionMaxima) }}
              </span>
            </div>
            <p v-if="resumenSalas" class="mt-1 text-sm text-white/75">{{ resumenSalas }}</p>
          </div>
          <button
            type="button"
            class="inline-flex shrink-0 items-center gap-2 rounded-full border border-white/30 bg-blanco/10 px-3.5 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-blanco/20"
            :aria-expanded="guiaAbierta"
            @click="guiaAbierta = !guiaAbierta"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
            </svg>
            {{ guiaAbierta ? 'Ocultar la ayuda' : '¿Cómo se usa?' }}
          </button>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <GuiaUso
        v-model:abierta="guiaAbierta"
        :con-boton="false"
        :pasos="[
          `Para reservar, haga <strong>clic en el espacio verde</strong> de la sala a la hora que quieren. Si el grupo está en el mesón, haga clic en la media hora en curso (o elija <strong>Ahora</strong> como hora de inicio): la reserva parte en ese minuto, hasta por ${formatMinutos(duracionMaxima)}, y queda con la llegada confirmada.`,
          `Ingrese el <strong>RUT de cada integrante</strong> del grupo (de ${cantidadMinima} a ${cantidadMaxima} personas, todas registradas en la biblioteca); las salas de la administración (los seminarios, Cooperación y Moraga) van solo a nombre del docente o funcionario que las pide. Cada persona tiene <strong>una sola reserva pendiente</strong>: para entrar en otra, primero debe usar o cancelar la que tiene.`,
          `Cuando el grupo llegue, pulse <strong>Confirmar llegada</strong> y entréguele la llave: tienen <strong>${textoMinutos(minutosPresentarse)}</strong> desde la hora de inicio. Si alguien no vino o se sumó otra persona, pulse <strong>Modificar</strong> para corregir los integrantes.`,
          'Si el grupo quiere <strong>seguir o volver a usar la sala</strong>, abra su reserva y pulse <strong>Renovación</strong>: se abre el formulario con los mismos RUT ya puestos, desde que termina su bloque (o desde ahora). Solo elija la hora y la duración.',
          'Si no llegan a tiempo, la sala se libera sola (o pulse <strong>Liberar ahora</strong>). Para entregar o recibir la llave, escriba el <strong>código de la sala</strong> (L04, SEM…) o el número de la logia, o pulse la reserva.',
        ]"
        consejo="Haga clic en cualquier bloque de color para ver quiénes reservaron y las acciones disponibles."
      />

      <!-- Cifras del día y la fecha: tarjetas con franja de color arriba -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
        <div class="flex items-center gap-4 rounded-2xl border border-gray-200 border-t-4 border-t-indigo-400 bg-white p-4 shadow-sm">
          <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0013.5 3v7.5z" />
            </svg>
          </span>
          <div class="min-w-0 flex-1">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-600">Ocupación hoy</div>
            <div class="mt-0.5 text-2xl font-bold tabular-nums text-indigo-700">{{ ocupacionHoy }}%</div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100" role="presentation">
              <div class="h-full rounded-full bg-indigo-500 transition-all" :style="{ width: ocupacionHoy + '%' }"></div>
            </div>
          </div>
        </div>
        <div class="flex items-center gap-4 rounded-2xl border border-gray-200 border-t-4 border-t-emerald-400 bg-white p-4 shadow-sm">
          <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 21V4.5A1.5 1.5 0 016.5 3h11A1.5 1.5 0 0119 4.5V21M3 21h18M14.25 12h.008v.008h-.008V12z" />
            </svg>
          </span>
          <div class="min-w-0">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-600">Salas disponibles ahora</div>
            <div class="mt-0.5 flex items-baseline gap-2">
              <span class="text-2xl font-bold tabular-nums text-emerald-600">{{ salas.filter((s) => s.libre_ahora).length }}</span>
              <span class="text-sm text-gray-500">de {{ salas.length }} salas</span>
            </div>
          </div>
        </div>
        <div class="rounded-2xl border border-gray-200 border-t-4 border-t-[#b08d57] bg-white p-4 shadow-sm">
          <label for="fecha-salas" class="mb-2 block text-[11px] font-semibold uppercase tracking-wider text-gray-600">Fecha</label>
          <input
            id="fecha-salas"
            v-model="selectedDate"
            type="date"
            class="w-full px-3.5 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none"
          />
        </div>
      </div>

      <div v-if="selectedDate === hoy" class="mb-6 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="mb-3 flex items-start justify-between gap-3">
          <div class="flex items-stretch gap-3">
            <span class="w-1 shrink-0 rounded-full bg-indigo-600" aria-hidden="true"></span>
            <div>
              <h2 class="text-base font-serif font-bold text-gray-900">Menú de confirmación de asistencia</h2>
              <p class="mt-0.5 text-xs text-gray-600">
                Cada reserva aparece aquí desde su hora de inicio y tiene {{ textoMinutos(minutosPresentarse) }} para confirmar que el grupo llegó.
                Pasado ese plazo, la sala se libera sola.
              </p>
            </div>
          </div>
          <div v-if="pendientesConfirmacion.length" class="flex shrink-0 items-center gap-1.5">
            <span v-if="vencidasConfirmacion" class="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700">
              {{ vencidasConfirmacion }} {{ vencidasConfirmacion === 1 ? 'vencida' : 'vencidas' }}
            </span>
            <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700">
              {{ pendientesConfirmacion.length }} por confirmar
            </span>
          </div>
        </div>

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
        <div v-else class="flex items-center justify-center gap-2 rounded-xl border border-dashed border-indigo-200 bg-indigo-50/30 px-4 py-2.5 text-center">
          <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600" aria-hidden="true">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
          </span>
          <p class="text-sm text-gray-600">Sin reservas pendientes de confirmar por ahora.</p>
        </div>
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

      <!-- Llaves: en una sola fila (título a la izquierda, código y botón a la derecha; se apila en
           pantallas angostas) y, al pie, una nota con la otra forma: la reserva en la línea de tiempo. -->
      <div class="mb-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-col gap-3 px-4 py-3.5 sm:px-5 lg:flex-row lg:items-center lg:gap-6">
          <div class="flex min-w-0 flex-1 items-stretch gap-3">
            <span class="w-1 shrink-0 rounded-full bg-[#b08d57]" aria-hidden="true"></span>
            <div class="min-w-0">
              <h2 class="flex flex-wrap items-center gap-x-2 gap-y-1 text-base font-serif font-bold text-gray-900">
                Entregar o recibir una llave
                <span class="rounded-full bg-gray-100 px-2 py-0.5 font-sans text-[11px] font-semibold text-gray-600">Alternativa</span>
              </h2>
              <p class="mt-0.5 text-xs text-gray-600">
                Escriba el código de la sala (L04, SEM…) o el número de la logia: la primera vez entrega la llave y la segunda la recibe.
              </p>
            </div>
          </div>
        <div class="flex gap-2 lg:w-[26rem] lg:shrink-0">
          <div class="min-w-0 flex-1">
            <label for="codigo-sala" class="sr-only">Código de la sala</label>
            <div class="relative">
              <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-dorado-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
              </svg>
              <input
                id="codigo-sala"
                v-model="codigoSala"
                type="text"
                autocomplete="off"
                autocapitalize="characters"
                maxlength="20"
                placeholder="Ej: L04 o 4"
                class="w-full rounded-xl border border-gray-300 bg-gray-50 py-2 pl-10 pr-3 text-sm font-mono uppercase outline-none transition-colors placeholder:font-sans placeholder:normal-case focus:bg-white focus:ring-2 focus:ring-indigo-500"
                @keydown.enter="registrarLlave"
              />
            </div>
          </div>
          <button
            @click="registrarLlave"
            :disabled="registrandoLlave"
            class="shrink-0 rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-md shadow-indigo-600/20 transition-colors hover:bg-indigo-700 disabled:opacity-60"
          >
            {{ registrandoLlave ? 'Registrando…' : 'Registrar' }}
          </button>
        </div>
        </div>
        <!-- Nota: los colores son los de la leyenda de la línea de tiempo (tramoClases). -->
        <p class="flex items-start gap-2 border-t border-dashed border-gray-200 bg-gray-50/70 px-4 py-2.5 text-xs leading-relaxed text-gray-600 sm:px-5">
          <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
          </svg>
          <span>
            <strong class="font-semibold text-gray-800">También desde la línea de tiempo:</strong>
            haga clic en la reserva. Si está
            <span class="whitespace-nowrap"><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm border border-red-300 bg-red-100 align-[-1px]" aria-hidden="true"></span><strong class="font-semibold text-gray-800">en uso</strong></span>,
            la recibe con «Confirmar devolución»; si está
            <span class="whitespace-nowrap"><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm border border-amber-300 bg-amber-100 align-[-1px]" aria-hidden="true"></span><strong class="font-semibold text-gray-800">por confirmar</strong></span>,
            la entrega con «Confirmar llegada».
          </span>
        </p>
      </div>

      <!-- Línea de tiempo: buscador y páginas (por piso) en el encabezado, las salas debajo -->
      <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
      <div class="h-1" :style="{ background: FRANJA }" aria-hidden="true"></div>
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-indigo-100/70 bg-indigo-50/50 px-4 py-3 sm:px-5">
        <div class="relative max-w-md flex-1 min-w-[200px]">
          <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
          </svg>
          <input
            v-model="busqueda"
            type="text"
            placeholder="Buscar logia..."
            aria-label="Buscar logia"
            class="w-full rounded-xl border border-gray-300 bg-white py-2 pl-10 pr-4 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>
        <div v-if="paginas.length > 1" class="flex flex-wrap items-center gap-1 rounded-xl border border-gray-200 bg-white p-1 shadow-sm">
          <button
            v-for="(p, i) in paginas"
            :key="p.titulo"
            type="button"
            @click="irAPagina(i)"
            class="inline-flex items-center gap-1.5 rounded-lg px-4 py-1.5 text-sm font-semibold transition-colors"
            :class="!busqueda.trim() && !soloConReserva && paginaActual === i ? 'bg-indigo-600 text-white shadow' : 'text-gray-700 hover:bg-gray-100'"
            :aria-pressed="!busqueda.trim() && !soloConReserva && paginaActual === i"
            :title="p.titulo.startsWith('Piso') ? `Las salas del ${p.titulo.toLowerCase()}, como en el plano` : p.titulo === 'Administración' ? 'Seminarios, Cooperación y Moraga: los reserva la administración' : 'Salas que no están en el plano'"
          >
            {{ p.titulo }}
            <span class="text-xs font-medium tabular-nums opacity-70">{{ p.salas.length }}</span>
          </button>
        </div>
      </div>

      <div class="p-4 sm:p-5">
        <!-- Qué salas mostrar. Va dentro del recuadro y con otro aspecto que las páginas de
             arriba (Piso 1, Piso 2…): un interruptor gris de esquinas rectas, no botones de color. -->
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
          <!-- Para qué sirve cada botón -->
          <p class="max-w-xl text-xs leading-relaxed text-gray-500">
            <strong class="text-gray-700">Piso 1 · Piso 2</strong> (arriba): muestran las salas de ese piso, como en el plano.
            <strong class="text-gray-700">Con reserva</strong> (aquí): de todos los pisos, solo las salas con una reserva {{ esHoy ? 'en curso o más tarde hoy' : 'ese día' }}, para encontrarlas rápido; <strong class="text-gray-700">Todas</strong> vuelve al piso.
          </p>
          <div class="inline-flex rounded-md bg-gray-100 p-0.5 text-xs font-semibold ring-1 ring-inset ring-gray-300" role="radiogroup" aria-label="Qué salas mostrar">
            <button
              type="button"
              role="radio"
              :aria-checked="!soloConReserva"
              @click="soloConReserva = false"
              class="rounded-sm px-3 py-1.5 transition-colors"
              :class="!soloConReserva ? 'bg-white text-gray-900 shadow-sm ring-1 ring-gray-300' : 'text-gray-500 hover:text-gray-800'"
            >
              Todas · {{ salasPage.length }}
            </button>
            <button
              type="button"
              role="radio"
              :aria-checked="soloConReserva"
              @click="soloConReserva = true"
              class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5 transition-colors"
              :class="soloConReserva ? 'bg-white text-gray-900 shadow-sm ring-1 ring-gray-300' : 'text-gray-500 hover:text-gray-800'"
              :title="esHoy ? 'De todos los pisos, las que tienen una reserva en curso o más tarde hoy' : 'De todos los pisos, las que tienen una reserva ese día'"
            >
              <span class="h-2 w-2 rounded-sm border border-amber-400 bg-amber-200" aria-hidden="true"></span>
              Con reserva · {{ totalConReserva }}
            </button>
          </div>
        </div>
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
          <p class="text-[13px] text-gray-600">
            {{ diaCerrado ? 'Elija otra fecha arriba para reservar.' : 'Click en cualquier espacio libre (verde) de la línea de tiempo para reservar esa sala a esa hora.' }}
          </p>
          <p v-if="actualizadoEn" class="flex items-center gap-2 text-xs text-gray-500">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
            Se actualiza sola cada {{ REFRESCO_SEGUNDOS }} s · última vez a las {{ enPuntaArenas(actualizadoEn).conSegundos }}
            <button
              type="button"
              @click="cargar"
              class="ml-1 rounded-lg border border-gray-200 bg-white p-1.5 text-gray-500 shadow-sm transition-colors hover:bg-gray-50 hover:text-indigo-700"
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
          <div class="w-[148px] shrink-0 self-stretch"></div>
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
          <div class="w-[136px] shrink-0"></div>
          <div class="relative h-4 flex-1 text-[11px] font-medium text-gray-500">
            <span
              v-for="h in horasRotuladas"
              :key="h"
              class="absolute -translate-x-1/2"
              :style="{ left: pct(`${h}:00`) + '%' }"
            >{{ String(h).padStart(2, '0') }}:00</span>
          </div>
        </div>
        <div class="space-y-1.5">
          <template v-for="bloque in bloquesSalas" :key="bloque.titulo ?? 'pagina'">
          <!-- Con reserva: un separador por piso; su nombre lleva a la página completa de ese piso -->
          <div v-if="bloque.titulo" class="flex items-center gap-2 pt-2 first:pt-0">
            <button
              v-if="bloque.indice !== null"
              type="button"
              class="shrink-0 text-xs font-semibold uppercase tracking-wider text-gray-500 hover:text-indigo-700"
              :title="`Ver todas las salas de ${bloque.titulo}`"
              @click="irAPagina(bloque.indice)"
            >{{ bloque.titulo }} · {{ bloque.salas.length }}</button>
            <span v-else class="shrink-0 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ bloque.titulo }} · {{ bloque.salas.length }}</span>
            <span class="h-px flex-1 bg-gray-200" aria-hidden="true"></span>
          </div>
          <div v-for="sala in bloque.salas" :key="sala.id" class="flex items-center gap-3">
            <!-- En una línea: el código (el que se busca y se escribe para la llave) y el nombre.
                 Sin la capacidad: no es un mínimo ni un máximo de personas (lo ajusta Administración). -->
            <div class="flex w-[136px] shrink-0 items-baseline gap-1.5" :title="sala.solo_administracion ? `${sala.nombre} · solo administración: no se presta a estudiantes ni se reserva desde el portal` : sala.nombre">
              <span class="font-mono text-xs font-bold text-gray-700">{{ sala.codigo }}</span>
              <span class="truncate text-[13px] text-gray-600">{{ sala.nombre }}</span>
            </div>
            <div
              class="group relative h-9 flex-1 bg-emerald-50/70 hover:bg-emerald-100/90 rounded-lg overflow-hidden border border-emerald-100 shadow-inner cursor-pointer transition-colors"
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
              <!-- En el espacio libre más ancho, aunque la sala ya tenga reservas (ver huecoParaReservar) -->
              <template v-for="hueco in [huecoParaReservar(sala)]" :key="`hueco-${sala.id}`">
                <p
                  v-if="hueco"
                  class="absolute inset-y-0 flex items-center justify-center whitespace-nowrap text-[11px] font-medium text-emerald-700/60 group-hover:text-emerald-700 pointer-events-none transition-colors"
                  :style="{ left: hueco.izquierda + '%', right: hueco.derecha + '%' }"
                >
                  + Click para reservar
                </p>
              </template>
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
          </template>
          <p v-if="!bloquesSalas.some((b) => b.salas.length)" class="text-sm text-gray-400 text-center py-6">
            {{ soloConReserva && !busqueda.trim() ? (esHoy ? 'Ninguna sala tiene reservas por ahora ni más tarde hoy.' : 'Ninguna sala tiene reservas ese día.') : 'Sin salas que coincidan con la búsqueda.' }}
          </p>
        </div>
        </div>
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

      <!-- Ventanas del mesón de salas: borde de color arriba, encabezado suave, contenido y pie con fondo. -->
      <div
        v-if="modalOpen && selectedSala"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="modalOpen = false"
      >
        <div class="flex max-h-[92vh] w-full max-w-md flex-col overflow-hidden rounded-2xl border-t-[3px] border-t-indigo-600 bg-white shadow-2xl">
          <div class="flex shrink-0 items-start justify-between gap-3 border-b border-gray-100 bg-indigo-50/50 px-5 pb-3 pt-4">
            <div class="min-w-0">
              <h3 class="font-serif text-lg font-bold leading-snug text-gray-900">{{ renovacionDe ? 'Renovación' : 'Reservar' }} {{ selectedSala.nombre }}</h3>
              <p class="mt-0.5 text-sm text-gray-500 first-letter:uppercase">{{ formatFechaLarga(selectedDate) }}</p>
            </div>
            <!-- La hora con la que valen las reservas, para no calcularla de memoria al reservar. -->
            <div class="shrink-0 rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-right shadow-sm" aria-live="off">
              <p class="text-[9px] font-bold uppercase tracking-widest text-gray-500">Hora actual · Punta Arenas</p>
              <p class="font-mono text-lg font-semibold leading-tight tabular-nums text-indigo-900">{{ ahoraEnPuntaArenas.conSegundos }}</p>
            </div>
          </div>

          <div class="space-y-3.5 overflow-y-auto px-5 py-4">
            <!-- Renovación: el grupo ya está puesto; solo se elige la hora -->
            <p v-if="renovacionDe" class="rounded-xl border border-violet-200 bg-violet-50 px-3 py-2.5 text-xs leading-relaxed text-violet-900">
              <strong>Renovación del grupo de las {{ renovacionDe.tramo.hora_inicio }}–{{ renovacionDe.tramo.hora_fin }}:</strong>
              sus {{ renovacionDe.tramo.personas.length }} {{ renovacionDe.tramo.personas.length === 1 ? 'RUT ya está puesto' : 'RUT ya están puestos' }} abajo.
              Elija desde qué hora y por cuánto renuevan; si alguien se va o se suma, corrija la lista antes de reservar.
            </p>
            <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-3">
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <template v-if="inicioAMano">
                    <label for="reserva-hora-inicio" class="mb-1 block text-xs font-medium text-gray-600">Desde las</label>
                    <CampoHora
                      id="reserva-hora-inicio"
                      ref="campoHoraInicio"
                      v-model="modalHoraInicioEscrita"
                      etiqueta="Hora de inicio"
                      :invalida="!!modalHoraInicioEscrita && !!errorInicio"
                    />
                  </template>
                  <template v-else>
                    <label class="mb-1 block text-xs font-medium text-gray-600">Hora de inicio</label>
                    <select
                      v-model="modalHoraInicio"
                      class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                      <option v-if="libreDesdeAhora(selectedSala) || modalEsAhora" :value="AHORA">Ahora ({{ minutesToTime(ahoraMin) }})</option>
                      <option v-for="h in horaInicioOpciones" :key="h" :value="h">{{ h }}</option>
                    </select>
                  </template>
                  <label class="mt-1.5 flex cursor-pointer items-start gap-1.5 text-[11px] text-gray-600">
                    <input v-model="inicioAMano" type="checkbox" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    Escribir a mano
                  </label>
                </div>
                <div>
                  <template v-if="terminoAMano">
                    <label for="reserva-hora-fin" class="mb-1 block text-xs font-medium text-gray-600">Hasta las</label>
                    <CampoHora id="reserva-hora-fin" ref="campoHoraFin" v-model="modalHoraFin" etiqueta="Hora de término" :invalida="!!modalHoraFin && !!errorTermino" />
                  </template>
                  <template v-else>
                    <label class="mb-1 block text-xs font-medium text-gray-600">Duración</label>
                    <select
                      v-model.number="modalDuracion"
                      class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                      <option v-for="d in DURACIONES" :key="d" :value="d" :disabled="!modalDuracionesValidas.includes(d)">
                        {{ formatMinutos(d) }}{{ !modalDuracionesValidas.includes(d) ? ' (no disponible)' : '' }}
                      </option>
                    </select>
                  </template>
                  <label class="mt-1.5 flex cursor-pointer items-start gap-1.5 text-[11px] text-gray-600">
                    <input v-model="terminoAMano" type="checkbox" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    Escribir término a mano
                  </label>
                </div>
              </div>
              <p v-if="avisoHoras" class="mt-2 text-xs" :class="avisoHorasEsError ? 'text-red-600' : 'text-gray-500'">{{ avisoHoras }}</p>
              <!-- Una reserva larga (permiso salas.sin_limite) es normal: solo se anota que pasa de las 2 h. -->
              <p v-else-if="inicioAMano || terminoAMano" class="mt-2 text-xs text-gray-600">
                {{ formatMinutos(modalFinMin - modalInicioMin) }}, de {{ minutesToTime(modalInicioMin) }} a {{ minutesToTime(modalFinMin) }}<template v-if="modalFinMin - modalInicioMin > duracionMaxima"> (más de {{ formatMinutos(duracionMaxima) }})</template>.
              </p>
              <!-- Con una hora de inicio escrita que no sirve, basta su aviso. -->
              <template v-if="!errorInicio">
                <p v-if="modalDisponibleMin < duracionMinima" class="mt-2 text-xs text-red-600">
                  Esta sala está libre solo {{ formatMinutos(modalDisponibleMin) }} desde {{ textoInicioModal }}.
                </p>
                <p v-else class="mt-2">
                  <span class="inline-block rounded border border-emerald-200 bg-emerald-50 px-1.5 py-px text-[11px] font-semibold text-emerald-700">Libre {{ formatMinutos(modalDisponibleMin) }} desde {{ textoInicioModal }}</span>
                </p>
              </template>
            </div>
            <p v-if="modalEsAhora" class="rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-xs text-emerald-900">
              El grupo está en el mesón: la reserva parte en este minuto, dura lo que elija (hasta {{ formatMinutos(duracionMaxima) }}) y queda con la
              llegada confirmada. Entregue la llave al reservar.
            </p>

            <p v-if="selectedSala.solo_responsable" class="rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs text-indigo-900">
              {{ selectedSala.nombre }} se reserva a nombre de quien la pide: basta el RUT del docente o funcionario a cargo, sin anotar a los asistentes.
            </p>
            <div v-else>
              <label class="mb-1 block text-xs font-medium text-gray-600">Cantidad de personas</label>
              <select
                v-model.number="cantidadPersonas"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
              >
                <option v-for="n in cantidadMaxima - minimoElegida + 1" :key="n" :value="minimoElegida + n - 1">
                  {{ minimoElegida + n - 1 }} {{ minimoElegida + n - 1 === 1 ? 'persona' : 'personas' }}
                </option>
              </select>
            </div>
            <div class="space-y-2">
              <label class="block text-xs font-medium text-gray-600">
                {{ selectedSala.solo_responsable ? 'RUT del docente o funcionario a cargo' : 'RUT de cada persona' }}
                <span class="font-normal text-gray-500">(extranjeros: pasaporte o DNI)</span>
              </label>
              <div v-for="(_, idx) in rutsReserva" :key="idx">
                <div class="flex flex-wrap items-center gap-2">
                  <span v-if="!selectedSala.solo_responsable" class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-indigo-50 text-[11px] font-bold text-indigo-700">{{ idx + 1 }}</span>
                  <input
                    :value="rutsReserva[idx]"
                    @input="onRutInput(idx, $event)"
                    type="text"
                    :placeholder="selectedSala.solo_responsable ? `${modosReserva[idx] === 'extranjero' ? 'Pasaporte o DNI' : 'RUT'} del docente o funcionario a cargo` : `${modosReserva[idx] === 'extranjero' ? 'Pasaporte o DNI' : 'RUT'} persona ${idx + 1}`"
                    :maxlength="largoSegunModo(modosReserva[idx] ?? 'rut')"
                    class="min-w-0 flex-1 rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2"
                    :class="rutErrores[idx] ? 'border-red-400 ring-1 ring-red-200 focus:ring-red-400' : 'border-gray-300 focus:ring-indigo-500'"
                  />
                  <SelectorDocumento :model-value="modosReserva[idx] ?? 'rut'" compacto :etiqueta="`Documento de la persona ${idx + 1}`" @update:model-value="cambiarModoFila(idx, $event)" />
                </div>
                <p v-if="rutErrores[idx]" class="mt-1 text-xs text-red-600">{{ rutErrores[idx] }}</p>
              </div>
            </div>
          </div>

          <div class="flex shrink-0 gap-3 border-t border-gray-100 bg-gray-50/70 px-5 py-3">
            <button @click="modalOpen = false" :class="BOTON_CANCELAR">Cancelar</button>
            <button @click="confirmarReserva" :disabled="!puedeConfirmarReserva" :class="BOTON_PRINCIPAL" class="bg-indigo-600 hover:bg-indigo-700">
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
        <div class="flex max-h-[92vh] w-full max-w-md flex-col overflow-hidden rounded-2xl border-t-[3px] border-t-indigo-600 bg-white shadow-2xl">
          <div class="shrink-0 border-b border-gray-100 bg-indigo-50/50 px-5 pb-3 pt-4">
            <h3 class="flex flex-wrap items-center gap-2 font-serif text-lg font-bold leading-snug text-gray-900">
              {{ detalleSala.nombre }}
              <span class="rounded border px-1.5 py-px font-sans text-[10px] font-bold uppercase tracking-widest" :class="estadoDetalle(detalleTramo).clase">{{ estadoDetalle(detalleTramo).texto }}</span>
            </h3>
            <p class="mt-0.5 text-sm text-gray-500">
              {{ labelTramo(detalleTramo.hora_inicio, detalleTramo.hora_fin) }} · {{ detalleTramo.cantidad_personas }} {{ detalleTramo.cantidad_personas === 1 ? 'persona' : 'personas' }} · {{ formatFechaLarga(selectedDate) }}
            </p>
          </div>

          <div class="space-y-3.5 overflow-y-auto px-5 py-4">
            <div>
              <div class="mb-2 flex items-center justify-between gap-3">
                <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-gray-600"><span class="h-3.5 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>Integrantes ({{ detalleTramo.personas?.length ?? 0 }})</p>
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
                :solo-responsable="!!detalleSala.solo_responsable"
                @guardado="integrantesGuardados"
                @cancelar="editandoIntegrantesDetalle = false"
              />
              <ul v-else class="space-y-2">
                <li v-for="(persona, idx) in detalleTramo.personas ?? []" :key="idx" :class="FILA_INTEGRANTE">
                  <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-indigo-50 text-[11px] font-bold text-indigo-700">{{ idx + 1 }}</span>
                  <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900">{{ persona.nombre ?? 'Sin registro en el sistema' }}</span>
                  <span class="shrink-0 rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 font-mono text-[11px] text-gray-700">{{ persona.rut }}</span>
                </li>
                <li v-if="!detalleTramo.personas?.length" class="text-sm text-gray-400">Sin integrantes registrados.</li>
              </ul>
            </div>

            <dl v-if="detalleTramo.prestado_por || detalleTramo.devuelto_por" class="divide-y divide-emerald-100 overflow-hidden rounded-lg border border-emerald-100 bg-emerald-50/50 text-sm">
              <div v-if="detalleTramo.prestado_por" class="flex justify-between gap-3 px-3 py-2">
                <dt class="text-gray-600">Llave entregada por</dt>
                <dd class="font-semibold text-emerald-800">{{ detalleTramo.prestado_por }}</dd>
              </div>
              <div v-if="detalleTramo.devuelto_por" class="flex justify-between gap-3 px-3 py-2">
                <dt class="text-gray-600">Devuelta por</dt>
                <dd class="font-semibold text-emerald-800">{{ detalleTramo.devuelto_por }}</dd>
              </div>
            </dl>

            <div v-if="detalleTramo.hora_devolucion_real" class="space-y-2">
              <p class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                Devolución de llave confirmada<template v-if="detalleTramo.hora_fin_reservada"> · se liberó el resto del bloque (era hasta las {{ detalleTramo.hora_fin_reservada }})</template>
              </p>
              <!-- Devolvieron antes y el bloque sigue reservado: el aviso de la devolución se cerró o no salió. -->
              <button
                v-if="detalleTramo.tiempo_sin_usar"
                type="button"
                class="w-full rounded-lg border border-emerald-300 bg-white px-4 py-2 text-sm font-semibold text-emerald-700 transition-colors hover:bg-emerald-50"
                @click="tiempoSinUsar = detalleTramo.tiempo_sin_usar"
              >
                Liberar el resto del bloque · {{ duracion(detalleTramo.tiempo_sin_usar.minutos) }} sin usar
              </button>
            </div>
            <!-- Reservada y esperando al grupo: qué falta, en una línea. -->
            <p
              v-else-if="!detalleTramo.hora_prestamo_real"
              class="flex items-start gap-2 rounded-lg border px-3 py-2 text-xs"
              :class="detalleTramo.vencida_sin_confirmar ? 'border-red-200 bg-red-50 text-red-700' : 'border-indigo-100 bg-indigo-50/60 text-indigo-900'"
            >
              <svg class="mt-px h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l3.500 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span v-if="detalleTramo.vencida_sin_confirmar">Venció el plazo sin confirmar la llegada.</span>
              <span v-else-if="selectedDate === hoy">Sala reservada para este bloque. Cuando el grupo llegue, pulse <strong>Confirmar llegada</strong>.</span>
              <span v-else>Sala reservada para este bloque. La llegada se confirma el día de la reserva.</span>
            </p>
          </div>

          <div class="flex shrink-0 flex-wrap gap-2 border-t border-gray-100 bg-gray-50/70 px-5 py-3">
            <button @click="detalleOpen = false" :class="BOTON_CANCELAR" class="!flex-none">Cerrar</button>
            <!-- Renovación: el mismo grupo otra vez (extender el bloque o volver a usar la sala), sin escribir los RUT -->
            <button
              v-if="selectedDate === hoy && detalleTramo.hora_prestamo_real && detalleSala"
              type="button"
              class="flex-1 rounded-lg border border-violet-300 bg-white px-3 py-2 text-sm font-semibold text-violet-700 transition-colors hover:bg-violet-50"
              title="El mismo grupo otra vez: extender su bloque o volver a usar la sala, sin escribir los RUT de nuevo"
              @click="renovar(detalleSala, detalleTramo)"
            >
              Renovación
            </button>
            <template v-if="!detalleTramo.hora_devolucion_real">
              <button
                v-if="!detalleTramo.hora_prestamo_real && detalleTramo.vencida_sin_confirmar"
                @click="pedirLiberacion(detalleSala, detalleTramo)"
                :disabled="liberando === detalleTramo.reserva_id"
                :class="BOTON_PRINCIPAL"
                class="bg-red-600 hover:bg-red-700"
              >
                {{ liberando === detalleTramo.reserva_id ? 'Liberando…' : 'Liberar (no llegó)' }}
              </button>
              <template v-else>
                <button
                  @click="pedirCancelacion(detalleSala, detalleTramo)"
                  class="flex-1 rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700 transition-colors hover:bg-red-50"
                >
                  Cancelar reserva
                </button>
                <template v-if="!detalleTramo.hora_prestamo_real">
                  <button v-if="selectedDate === hoy" @click="pedirLlegada(detalleSala, detalleTramo)" :class="BOTON_PRINCIPAL" class="bg-indigo-600 hover:bg-indigo-700">
                    Confirmar llegada
                  </button>
                </template>
                <button v-else @click="pedirDevolucion(detalleSala, detalleTramo)" :class="BOTON_PRINCIPAL" class="bg-emerald-600 hover:bg-emerald-700">
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
        <div class="w-full max-w-sm overflow-hidden rounded-2xl border-t-[3px] border-t-red-600 bg-white shadow-2xl">
          <div class="border-b border-gray-100 bg-red-50/60 px-5 pb-3 pt-4">
            <h3 class="font-serif text-lg font-bold text-gray-900">¿Liberar la sala?</h3>
          </div>
          <div class="px-5 py-4 text-sm text-gray-500">
            <p>
              Se registrará que el grupo no se presentó a <strong class="text-gray-900">{{ liberacionPendiente.salaNombre }}</strong> en el tramo
              <strong class="text-gray-900">{{ liberacionPendiente.label }}</strong>, y el bloque quedará libre para otras reservas.
            </p>
            <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2.5 text-xs">Esta acción no se puede deshacer.</p>
          </div>
          <div class="flex gap-3 border-t border-gray-100 bg-gray-50/70 px-5 py-3">
            <button @click="liberacionPendiente = null" :disabled="liberando !== null" :class="BOTON_CANCELAR">No, mantener</button>
            <button @click="confirmarLiberacion" :disabled="liberando !== null" :class="BOTON_PRINCIPAL" class="bg-red-600 hover:bg-red-700">
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
        <div class="w-full max-w-sm overflow-hidden rounded-2xl border-t-[3px] border-t-red-600 bg-white shadow-2xl">
          <div class="border-b border-gray-100 bg-red-50/60 px-5 pb-3 pt-4">
            <h3 class="font-serif text-lg font-bold text-gray-900">¿Cancelar esta reserva?</h3>
          </div>
          <div class="px-5 py-4 text-sm text-gray-500">
            <p>
              Se cancelará la reserva de <strong class="text-gray-900">{{ cancelacionPendiente.salaNombre }}</strong> para el tramo
              <strong class="text-gray-900">{{ cancelacionPendiente.label }}</strong>.
            </p>
            <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2.5 text-xs">Esta acción no se puede deshacer.</p>
          </div>
          <div class="flex gap-3 border-t border-gray-100 bg-gray-50/70 px-5 py-3">
            <button @click="cancelacionPendiente = null" :class="BOTON_CANCELAR">No, mantener</button>
            <button @click="confirmarCancelacion" :class="BOTON_PRINCIPAL" class="bg-red-600 hover:bg-red-700">Sí, cancelar</button>
          </div>
        </div>
      </div>

      <div
        v-if="devolucionPendiente"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[60] flex items-center justify-center p-4"
        @click.self="devolucionPendiente = null"
      >
        <div class="w-full max-w-sm overflow-hidden rounded-2xl border-t-[3px] border-t-emerald-600 bg-white shadow-2xl">
          <div class="border-b border-gray-100 bg-emerald-50/50 px-5 pb-3 pt-4">
            <h3 class="font-serif text-lg font-bold text-gray-900">Confirmar devolución de llave</h3>
          </div>
          <div class="px-5 py-4 text-sm text-gray-500">
            <p>
              Se registrará la devolución de <strong class="text-gray-900">{{ devolucionPendiente.salaNombre }}</strong> para el tramo
              <strong class="text-gray-900">{{ devolucionPendiente.label }}</strong>.
            </p>
            <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2.5 text-xs">La reserva queda marcada como finalizada (no se borra, a diferencia de cancelar).</p>
          </div>
          <div class="flex gap-3 border-t border-gray-100 bg-gray-50/70 px-5 py-3">
            <button @click="devolucionPendiente = null" :class="BOTON_CANCELAR">Cancelar</button>
            <button @click="confirmarDevolucion" :disabled="devolviendo" :class="BOTON_PRINCIPAL" class="bg-emerald-600 hover:bg-emerald-700">
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
        <div class="flex max-h-[90vh] w-full max-w-md flex-col overflow-hidden rounded-2xl border-t-[3px] border-t-indigo-600 bg-white shadow-2xl">
          <div class="shrink-0 border-b border-gray-100 bg-indigo-50/50 px-5 pb-3 pt-4">
            <h3 class="font-serif text-lg font-bold text-gray-900">Confirmar llegada</h3>
          </div>
          <div class="space-y-3 overflow-y-auto px-5 py-4 text-sm text-gray-500">
            <p>
              Se registrará que el grupo se presentó en <strong class="text-gray-900">{{ llegadaPendiente.salaNombre }}</strong> para el tramo
              <strong class="text-gray-900">{{ llegadaPendiente.label }}</strong>. Quienes no hayan marcado su entrada hoy quedarán presentes en la biblioteca.
            </p>
            <p class="flex items-start gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">
              <svg class="mt-px h-4 w-4 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.250 11.250l.041-.020a.750.750 0 011.063.852l-.708 2.836a.750.750 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.750h.008v.008H12V8.250z" /></svg>
              <span>Antes de entregar la llave, verifique que estén sus integrantes: si alguien no vino o se sumó otra persona, pulse <strong class="text-gray-800">Modificar</strong>.</span>
            </p>
            <div>
              <div class="mb-2 flex items-center justify-between gap-3">
                <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-gray-600"><span class="h-3.5 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>Integrantes ({{ llegadaPendiente.personas.length }})</p>
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
                :solo-responsable="llegadaPendiente.soloResponsable"
                @guardado="integrantesGuardados"
                @cancelar="editandoIntegrantesLlegada = false"
              />
              <ul v-else class="space-y-2">
                <li v-for="(persona, idx) in llegadaPendiente.personas" :key="persona.rut" :class="FILA_INTEGRANTE">
                  <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-indigo-50 text-[11px] font-bold text-indigo-700">{{ idx + 1 }}</span>
                  <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900">{{ persona.nombre ?? 'Sin registro en el sistema' }}</span>
                  <span class="shrink-0 rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 font-mono text-[11px] text-gray-700">{{ persona.rut }}</span>
                </li>
                <li v-if="!llegadaPendiente.personas.length" class="text-sm text-gray-400">Sin integrantes registrados.</li>
              </ul>
            </div>
            <p v-if="editandoIntegrantesLlegada" class="text-xs text-amber-700">
              Guarde o descarte los cambios de integrantes antes de confirmar la llegada.
            </p>
          </div>
          <div class="flex shrink-0 gap-3 border-t border-gray-100 bg-gray-50/70 px-5 py-3">
            <button @click="cerrarLlegada" :disabled="confirmandoLlegada" :class="BOTON_CANCELAR">Cancelar</button>
            <button @click="confirmarLlegadaAction" :disabled="confirmandoLlegada || editandoIntegrantesLlegada" :class="BOTON_PRINCIPAL" class="bg-indigo-600 hover:bg-indigo-700">
              {{ confirmandoLlegada ? 'Guardando…' : 'Confirmar' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <AvisoMultaAtraso :aviso="atrasoDevolucion" @cerrar="atrasoDevolucion = null" />
    <AvisoTiempoSinUsar :aviso="tiempoSinUsar" @cerrar="tiempoSinUsar = null" @liberado="detalleOpen = false; cargar()" />
  </StaffLayout>
</template>
