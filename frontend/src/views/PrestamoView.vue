<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import FondoPrestamos from '@/components/ui/FondoPrestamos.vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import GuiaUso from '@/components/ui/GuiaUso.vue'
import SelectorDocumento from '@/components/ui/SelectorDocumento.vue'
import CampoSugerencias from '@/components/ui/CampoSugerencias.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { formatearSegunModo, largoSegunModo, modoSegunValor, placeholderSegunModo } from '@/utils/documento'
import { useModoDocumento } from '@/composables/useModoDocumento'
import { useAuthStore } from '@/stores/auth'
import { diaEnPlural, diaIso } from '@/utils/horario'
import { devolucionDe, estadoDe, fechaCorta } from '@/utils/prestamos'
import RenovarCuenta from '@/components/usuarios/RenovarCuenta.vue'
import CerrarPrestamoInterbibliotecario from '@/components/libros/CerrarPrestamoInterbibliotecario.vue'
import { COLORES_RECURSO, ICONOS_RECURSO, itemPrestamo, textoHoras, useCategoriasRecurso } from '@/utils/recursos'
import type { CategoriaRecurso, CondicionesPrestamo, DiaSinAtencion, Equipo, HorarioBiblioteca, Prestamo, ReservaLibro, Usuario } from '@/types'

const toast = useToast()
const auth = useAuthStore()
const route = useRoute()

const rut = ref('')
// RUT, o pasaporte/DNI de un estudiante extranjero: lo marca quien atiende.
const { modo: modoRut, escribir: escribirRut, error: errorDelRut } = useModoDocumento(rut)
const rutInput = ref<HTMLInputElement | null>(null)
const errorRut = ref('')
const usuario = ref<Usuario | null>(null)
const buscando = ref(false)
const apiError = ref(false)
const guiaAbierta = ref(false)
// Línea morada en el borde superior de la búsqueda y de las pestañas.
const FRANJA = 'linear-gradient(90deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%)'

const prestamos = ref<Prestamo[]>([])
const reservas = ref<ReservaLibro[]>([])

const showForm = ref(false)
const showReservaForm = ref(false)
const activeTab = ref<'recientes' | 'prestamos' | 'reservas' | 'recursos'>('recientes')

const today = new Date().toLocaleDateString('sv-SE')

const codigoBarrasPrestamo = ref('')
const libroEncontradoPrestamo = ref('')
const libroDisponiblePrestamo = ref(true)
const libroEnEstantePrestamo = ref(true)
const ubicacionPrestamo = ref<number | null>(null)
const fechaPrestamo = ref(today)
const fechaDevolucion = ref('')
// Para llevar (a domicilio, con fecha de devolución) o uso en sala: se lee en la biblioteca y se
// devuelve hoy. Después de prestar en sala (un juego, una obra de referencia) vuelve a «Para llevar».
const modoPrestamo = ref<'domicilio' | 'sala'>('domicilio')
const enSala = computed(() => modoPrestamo.value === 'sala')

const codigoBarras = ref('')
const libroEncontrado = ref('')
const libroDisponible = ref(true)
const libroEnEstante = ref(true)
const fechaReserva = ref(today)
const fechaRetiro = ref('')

const prestamosLibros = computed(() => prestamos.value.filter((p) => p.tipo_item === 'libro' || !p.tipo_item))
const librosEnPrestamo = computed(() => prestamosLibros.value.filter((p) => p.estado !== 'devuelto').length)

// Con multas pendientes no se presta ni se reserva nada (el backend lo rechaza igual).
const bloqueadoPorMultas = computed(() => (usuario.value?.multas_pendientes?.cantidad ?? 0) > 0)
const MENSAJE_BLOQUEO = 'Tiene multas pendientes: no puede llevarse ni reservar material hasta pagarlas'

// ---------- Horario: la devolución y el retiro no pueden caer en un día sin atención ----------
// Ni en los que la biblioteca no abre cada semana ni en los feriados, vacaciones y
// cierres de Administración (de toda la biblioteca o de la ubicación de la copia).
const horario = ref<HorarioBiblioteca | null>(null)
const diasSinAtencion = ref<DiaSinAtencion[]>([])

async function cargarHorario() {
  try {
    const [{ data: h }, { data: d }] = await Promise.all([
      api.get<HorarioBiblioteca>('/configuracion/horario'),
      api.get<DiaSinAtencion[]>('/dias-sin-atencion', { params: { desde: today } }),
    ])
    horario.value = h
    diasSinAtencion.value = d
  } catch {
    horario.value = null
  }
}

function avisoDiaSinAtencion(fecha: string, para: string, ubicacionId: number | null = null) {
  if (!fecha || !horario.value) return ''
  const dia = new Date(`${fecha}T12:00:00`)
  if (!horario.value[diaIso(dia)]) return `La biblioteca no atiende los ${diaEnPlural(dia)}: elige otro día ${para}.`
  const cierre = diasSinAtencion.value.find((d) => d.fecha === fecha && (d.ubicacion_id === null || d.ubicacion_id === ubicacionId))
  if (!cierre) return ''
  return `La biblioteca no atiende el ${dia.toLocaleDateString('es-CL')} (${cierre.motivo || 'día sin atención'}): elige otro día ${para}.`
}
const avisoDevolucion = computed(() => {
  // Lo de otra biblioteca se devuelve a más tardar un día hábil antes de que tenga que volver a ella.
  const tope = condiciones.value?.interbibliotecario?.fecha_tope
  if (tope && fechaDevolucion.value > tope) {
    return `Es de ${condiciones.value!.interbibliotecario!.biblioteca} (préstamo interbibliotecario): se devuelve a más tardar el ${formatFecha(`${tope}T12:00:00`)}.`
  }
  return avisoDiaSinAtencion(fechaDevolucion.value, 'para la devolución', ubicacionPrestamo.value)
})
const avisoRetiro = computed(() => avisoDiaSinAtencion(fechaRetiro.value, 'para el retiro'))

// ---------- Recursos de apoyo ----------
// Todo lo que se presta y no es un libro (notebooks, audífonos, bicicletas…). Las
// categorías se administran en Administración → Catálogos; aquí sale un recuadro por
// cada categoría activa.
const { categorias, cargar: cargarCategorias } = useCategoriasRecurso()
const categoriasActivas = computed(() => categorias.value.filter((c) => c.activo))
// Los recursos que se devuelven al término de la estadía (audífonos, notebooks, cargadores…) van
// juntos en una fila, en el orden en que se crearon; los que tienen tope por persona (bicicletas:
// una a la vez) van debajo.
const gruposCategorias = computed(() => {
  const porCreacion = [...categoriasActivas.value].sort((a, b) => a.id - b.id)
  return [porCreacion.filter((c) => !c.limite_por_persona), porCreacion.filter((c) => c.limite_por_persona)].filter((g) => g.length)
})
const puedeAdministrarCategorias = computed(() => auth.puede('catalogos.gestionar'))

const codigos = reactive<Record<string, string>>({})
const recursosLibres = ref<Equipo[]>([])
const libresDe = (clave: string) => recursosLibres.value.filter((e) => e.tipo === clave)

async function cargarRecursosDisponibles() {
  try {
    const { data } = await api.get<Equipo[]>('/equipos', { params: { activo: 1, disponible: 1 } })
    recursosLibres.value = data
  } catch {
    recursosLibres.value = []
  }
}

const prestamosRecursos = computed(() => prestamos.value.filter((p) => p.tipo_item !== 'libro' && !!p.tipo_item))
const recursosEnUso = computed(() => prestamosRecursos.value.filter((p) => p.estado !== 'devuelto'))
const enUsoDe = (clave: string) => recursosEnUso.value.filter((p) => p.tipo_item === clave)

/** Debajo de cada recuadro: cuántos quedan libres y, con un usuario elegido, cuál tiene. */
function lineaRecurso(c: CategoriaRecurso) {
  const libres = libresDe(c.clave).length
  if (!usuario.value) return `${libres} ${libres === 1 ? 'disponible' : 'disponibles'}`
  const enUso = enUsoDe(c.clave)
  const libresTexto = `${libres} ${libres === 1 ? 'libre' : 'libres'}`
  return enUso.length ? `Tiene ${enUso.map((p) => p.libro_titulo).join(', ')} · ${libresTexto}` : `Sin préstamos de ${c.nombre_plural.toLowerCase()} · ${libresTexto}`
}

/** Ya llegó al máximo por persona de esa categoría (ej. una bicicleta a la vez). */
const enTope = (c: CategoriaRecurso) => !!c.limite_por_persona && enUsoDe(c.clave).length >= c.limite_por_persona
const mensajeTope = (c: CategoriaRecurso) => `Ya tiene ${enUsoDe(c.clave).length === 1 ? `1 ${c.nombre.toLowerCase()}` : `${enUsoDe(c.clave).length} ${c.nombre_plural.toLowerCase()}`} sin devolver: se presta un máximo de ${c.limite_por_persona} por persona a la vez`
/** Cuándo vence un recurso que se presta ahora: «hoy a las 17:30» o «el 31/10 a las 14:00». */
function devolucionRecurso(horas: number) {
  const vence = new Date(Date.now() + horas * 3_600_000)
  const hora = vence.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })
  return vence.toDateString() === new Date().toDateString() ? `hoy a las ${hora}` : `el ${vence.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit' })} a las ${hora}`
}
const fondoCategoria = (c: CategoriaRecurso) => (COLORES_RECURSO[c.color] ?? COLORES_RECURSO.slate).fondo
const puntoCategoria = (c: CategoriaRecurso) => (COLORES_RECURSO[c.color] ?? COLORES_RECURSO.slate).punto
const iconoCategoria = (c: CategoriaRecurso) => (ICONOS_RECURSO[c.icono] ?? ICONOS_RECURSO.caja).trazo

// Al ir a Multas desde la ficha, se recuerda a la persona para retomarla al volver a Préstamos.
// Sirve una sola vez y por poco rato: quien viene después en el mesón parte con la pantalla limpia.
const CLAVE_FICHA_PENDIENTE = 'prestamo:ficha-pendiente'
const MINUTOS_FICHA_PENDIENTE = 15

function recordarFichaAlIrAMultas() {
  if (!usuario.value) return
  try {
    sessionStorage.setItem(CLAVE_FICHA_PENDIENTE, JSON.stringify({ rut: usuario.value.rut, hasta: Date.now() + MINUTOS_FICHA_PENDIENTE * 60_000 }))
  } catch {
    // Sin almacenamiento, simplemente no se retoma.
  }
}

function tomarFichaPendiente(): string | null {
  try {
    const guardada = sessionStorage.getItem(CLAVE_FICHA_PENDIENTE)
    sessionStorage.removeItem(CLAVE_FICHA_PENDIENTE)
    if (!guardada) return null
    const { rut: rutGuardado, hasta } = JSON.parse(guardada) as { rut: string; hasta: number }
    return Date.now() < hasta ? rutGuardado : null
  } catch {
    return null
  }
}

onMounted(() => {
  cargarRecursosDisponibles()
  cargarCategorias()
  cargarHorario()
  cargarPrestadosAhora()
  const pendiente = tomarFichaPendiente()
  // Se puede llegar con ?rut=… (ej. desde Multas o Entradas) para abrir directo los préstamos de esa persona.
  const rutInicial = typeof route.query.rut === 'string' && route.query.rut ? route.query.rut : pendiente
  if (rutInicial) {
    modoRut.value = modoSegunValor(rutInicial)
    rut.value = formatearSegunModo(rutInicial, modoRut.value)
    buscarUsuario()
  } else {
    rutInput.value?.focus()
  }
})

function onRutInput(event: Event) {
  escribirRut(event)
  errorRut.value = ''
}

/** Vuelve a pedir el usuario para actualizar el aviso de multas después de devolver o pagar. */
async function refrescarUsuario() {
  if (!usuario.value) return
  try {
    const { data } = await api.get<Usuario>(`/usuarios/rut/${usuario.value.rut}`)
    usuario.value = data
  } catch {
    // El resto de la pantalla sigue siendo válido; el aviso se corrige en la próxima búsqueda.
  }
}

function buscarOtroUsuario() {
  usuario.value = null
  condiciones.value = null
  prestamos.value = []
  reservas.value = []
  showForm.value = false
  showReservaForm.value = false
  modoPrestamo.value = 'domicilio'
  mostrarDevueltos.value = false
  mostrarTerminadas.value = false
  activeTab.value = 'recientes'
  rut.value = ''
  modoRut.value = 'rut'
  errorRut.value = ''
  cargarPrestadosAhora()
  nextTick(() => rutInput.value?.focus())
}

async function cargarPrestamosYReservas() {
  if (!usuario.value) return
  try {
    const [{ data: p }, { data: r }] = await Promise.all([
      api.get<Prestamo[]>('/prestamos', { params: { usuario_id: usuario.value.id } }),
      api.get<ReservaLibro[]>('/reservas-libro', { params: { usuario_id: usuario.value.id } }),
    ])
    prestamos.value = p
    reservas.value = r
    apiError.value = false
  } catch {
    apiError.value = true
    prestamos.value = []
    reservas.value = []
  }
}

async function buscarUsuario() {
  const error = errorDelRut()
  if (error) {
    errorRut.value = error
    return
  }
  buscando.value = true
  errorRut.value = ''
  try {
    const { data } = await api.get<Usuario>(`/usuarios/rut/${encodeURIComponent(rut.value)}`)
    usuario.value = data
    // Con el RUT puesto, el formulario de préstamo ya queda abierto para escanear.
    showForm.value = true
    showReservaForm.value = false
    mostrarDevueltos.value = false
    mostrarTerminadas.value = false
    activeTab.value = 'recientes'
    await cargarPrestamosYReservas()
    nextTick(() => codigoPrestamoInput.value?.focus())
  } catch (e: any) {
    usuario.value = null
    errorRut.value =
      e?.response?.status === 404
        ? `No hay ningún usuario registrado con ese ${modoRut.value === 'rut' ? 'RUT' : 'pasaporte o DNI'}. Revise que esté bien escrito.`
        : mensajeDeError(e, 'No se pudo buscar el usuario')
  } finally {
    buscando.value = false
  }
}

// ---------- Ficha del usuario ----------
const TIPOS_USUARIO: Record<Usuario['tipo'], string> = { estudiante: 'Estudiante', docente: 'Docente', funcionario: 'Funcionario' }

const inicialesUsuario = computed(() => `${usuario.value?.nombre?.charAt(0) ?? ''}${usuario.value?.apellido?.charAt(0) ?? ''}`.toUpperCase())

/** La cuenta: habilitada para pedir, bloqueada por multas o inactiva. */
const estadoCuenta = computed(() => {
  if (usuario.value?.activo === false) return { texto: usuario.value.inactividad === 'vencida' ? 'Cuenta vencida' : 'Inactiva', clase: 'bg-amber-50 text-amber-800' }
  if (bloqueadoPorMultas.value) return { texto: 'Bloqueada por multa', clase: 'bg-red-50 text-red-700' }
  return { texto: 'Habilitada', clase: 'bg-emerald-50 text-emerald-700' }
})

const prestamosActivos = computed(() => prestamos.value.filter((p) => p.estado !== 'devuelto'))
const reservasVigentes = computed(() => reservas.value.filter((r) => r.estado === 'pendiente' || r.estado === 'en_cola'))
const reservasListas = computed(() => reservas.value.filter((r) => r.estado === 'pendiente' && r.ejemplar))

const resumenUsuario = computed(() => {
  const n = prestamosActivos.value.length
  const r = reservasVigentes.value.length
  const multas = usuario.value?.multas_pendientes
  return [
    { texto: `${n} ${n === 1 ? 'préstamo activo' : 'préstamos activos'}`, clase: 'bg-indigo-50 text-indigo-700' },
    { texto: `${r} ${r === 1 ? 'reserva' : 'reservas'}`, clase: 'bg-violet-50 text-violet-700' },
    multas?.cantidad
      ? { texto: `Multa ${formatMonto(multas.monto_total)}`, clase: 'bg-red-50 text-red-700' }
      : { texto: 'Sin multas', clase: 'bg-gray-100 text-gray-600' },
  ]
})

// ---------- Préstamos y reservas de la tabla ----------
// Lo vigente siempre a la vista; lo terminado, al pedirlo (salvo un préstamo con multa por pagar).
const mostrarDevueltos = ref(false)
const mostrarTerminadas = ref(false)
const tieneMultaPendiente = (p: Prestamo) => !!p.multas?.some((m) => m.estado === 'pendiente')

const ordenPrestamos = (a: Prestamo, b: Prestamo) => Number(a.estado === 'devuelto') - Number(b.estado === 'devuelto') || b.fecha_prestamo.localeCompare(a.fecha_prestamo)

// Pestaña Recientes: todo lo de la persona (libros y recursos), del más nuevo al más viejo.
const filasRecientes = computed(() => [...prestamos.value].sort((a, b) => b.fecha_prestamo.localeCompare(a.fecha_prestamo) || b.id - a.id))

// Sin usuario: todo lo prestado ahora en la biblioteca.
const prestadosAhora = ref<Prestamo[]>([])
const totalPrestadosAhora = ref(0)
const cargandoPrestados = ref(false)
const textoPrestadosAhora = computed(() => {
  if (cargandoPrestados.value) return 'Cargando…'
  const n = totalPrestadosAhora.value
  const extra = n > prestadosAhora.value.length ? ` · se muestran los ${prestadosAhora.value.length} más recientes` : ''
  return `${n} sin devolver${extra}`
})
async function cargarPrestadosAhora() {
  cargandoPrestados.value = true
  try {
    const { data } = await api.get<{ data: Prestamo[]; total: number }>('/prestamos', { params: { vista: 'activos', por_pagina: 100 } })
    prestadosAhora.value = data.data
    totalPrestadosAhora.value = data.total
  } catch {
    prestadosAhora.value = []
  } finally {
    cargandoPrestados.value = false
  }
}

// Pestaña Libros: solo los libros (los recursos de apoyo tienen su propia pestaña).
const devueltosOcultables = computed(() => prestamosLibros.value.filter((p) => p.estado === 'devuelto' && !tieneMultaPendiente(p)).length)
const filasPrestamos = computed(() =>
  prestamosLibros.value.filter((p) => p.estado !== 'devuelto' || tieneMultaPendiente(p) || mostrarDevueltos.value).sort(ordenPrestamos),
)

const mostrarRecursosDevueltos = ref(false)
const recursosDevueltos = computed(() => prestamosRecursos.value.filter((p) => p.estado === 'devuelto').length)
const filasRecursos = computed(() =>
  prestamosRecursos.value.filter((p) => p.estado !== 'devuelto' || mostrarRecursosDevueltos.value).sort(ordenPrestamos),
)

const terminadasOcultables = computed(() => reservas.value.length - reservasVigentes.value.length)
const filasReservas = computed(() =>
  reservas.value
    .filter((r) => r.estado === 'pendiente' || r.estado === 'en_cola' || mostrarTerminadas.value)
    .sort((a, b) => Number(!['pendiente', 'en_cola'].includes(a.estado)) - Number(!['pendiente', 'en_cola'].includes(b.estado)) || b.fecha_reserva.localeCompare(a.fecha_reserva)),
)

const reservaBadges: Record<string, { label: string; cls: string }> = {
  pendiente: { label: 'Pendiente', cls: 'bg-amber-50 text-amber-700' },
  retirado: { label: 'Retirado', cls: 'bg-emerald-50 text-emerald-700' },
  cancelado: { label: 'Cancelado', cls: 'bg-gray-100 text-gray-600' },
  en_cola: { label: 'En lista de espera', cls: 'bg-sky-50 text-sky-700' },
}

const subtituloItem = (p: Prestamo) => `${itemPrestamo(p.tipo_item).nombre} · ${p.codigo_barras ?? '—'}`

// ---------- Nuevo préstamo de libro ----------
/**
 * Se busca la copia apenas el código está completo: el de 8 dígitos de la etiqueta de
 * lomo o, desde 10 dígitos, el código de barras de siempre (14 dígitos).
 */
const codigoCompleto = (codigo: string) => codigo.length === 8 || codigo.length >= 10

async function buscarLibroApi(codigo: string): Promise<{ titulo: string; disponible: boolean; enEstante: boolean; ubicacionId: number | null } | null> {
  try {
    const { data } = await api.get(`/ejemplares/${codigo}`)
    const titulo = data.numero_copia > 0 ? `${data.libro?.titulo} (Copia ${data.numero_copia})` : data.libro?.titulo
    // Solo una copia en estante puede estar prestada o reservada: la que está en inventario, procesos técnicos, etc. simplemente no está disponible.
    const enEstante = data.estado_proceso === 'en_estante'
    return { titulo, disponible: data.disponible && enEstante, enEstante, ubicacionId: data.ubicacion_id ?? null }
  } catch {
    return null
  }
}

let buscarLibroPrestamoTimer: ReturnType<typeof setTimeout> | undefined
function buscarLibroPorCodigoPrestamo(valor: string) {
  codigoBarrasPrestamo.value = valor.replace(/\D/g, '')
  libroEncontradoPrestamo.value = ''
  libroDisponiblePrestamo.value = true
  libroEnEstantePrestamo.value = true
  ubicacionPrestamo.value = null
  condiciones.value = null
  clearTimeout(buscarLibroPrestamoTimer)
  if (!codigoCompleto(codigoBarrasPrestamo.value)) return
  buscarLibroPrestamoTimer = setTimeout(async () => {
    const codigo = codigoBarrasPrestamo.value
    const libro = await buscarLibroApi(codigo)
    if (codigo !== codigoBarrasPrestamo.value) return
    libroEncontradoPrestamo.value = libro?.titulo ?? ''
    libroDisponiblePrestamo.value = libro?.disponible ?? true
    libroEnEstantePrestamo.value = libro?.enEstante ?? true
    ubicacionPrestamo.value = libro?.ubicacionId ?? null
    if (libro) {
      cargarCondiciones()
      return
    }
    // No es una copia de la colección: quizá es un recurso de apoyo (notebook, audífonos, bicicleta…).
    const recurso = await buscarRecursoApi(codigo)
    if (recurso && codigo === codigoBarrasPrestamo.value) pasarARecursos(recurso)
  }, 250)
}

// ---------- Un recurso de apoyo escaneado en el lugar equivocado ----------
type RecursoEscaneado = Equipo & { categoria?: CategoriaRecurso | null }

async function buscarRecursoApi(codigo: string): Promise<RecursoEscaneado | null> {
  try {
    const { data } = await api.get<RecursoEscaneado>(`/equipos/${codigo}`)
    return data
  } catch {
    return null
  }
}

const nombreRecurso = (r: RecursoEscaneado) => `${(r.categoria?.nombre ?? r.tipo).toLowerCase()} ${r.codigo_inventario}`

/** Resalta unos segundos el recuadro de la categoría donde quedó el código. */
const recursoResaltado = ref('')
let recursoResaltadoTimer: ReturnType<typeof setTimeout> | undefined

/**
 * Escanearon un recurso de apoyo en "Nuevo préstamo" (que es de libros): en vez de decir que el
 * código no existe, se abre Recursos de apoyo con el código ya puesto en el recuadro de su categoría.
 * No se presta solo: el mesón confirma con «Prestar».
 */
async function pasarARecursos(recurso: RecursoEscaneado) {
  if (!categoriasActivas.value.some((c) => c.clave === recurso.tipo)) {
    toast.error(`Es un recurso de apoyo (${nombreRecurso(recurso)}), pero su categoría está desactivada`)
    return
  }
  codigoBarrasPrestamo.value = ''
  activeTab.value = 'recursos'
  codigos[recurso.tipo] = recurso.codigo_barras
  if (!recurso.activo) toast.error(`Es un recurso de apoyo (${nombreRecurso(recurso)}), pero está dado de baja`)
  else if (!recurso.disponible) toast.error(`Es un recurso de apoyo (${nombreRecurso(recurso)}), pero ya está prestado`)
  else toast.info(`Es un recurso de apoyo (${nombreRecurso(recurso)}): lo pasé a Recursos de apoyo, solo falta «Prestar»`)
  await nextTick()
  recursoResaltado.value = recurso.tipo
  clearTimeout(recursoResaltadoTimer)
  recursoResaltadoTimer = setTimeout(() => (recursoResaltado.value = ''), 4000)
  const entrada = document.getElementById(`recurso-${recurso.tipo}`)
  entrada?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  entrada?.focus({ preventScroll: true })
}

// ---------- Regla de préstamo (Administración → Reglas de préstamo) ----------
// Con la copia y la persona, el servidor dice qué regla se aplica: si se presta, cuántos
// libros puede tener a la vez y la fecha de devolución que propone (ya corrida al siguiente
// día con atención). La fecha propuesta se puede cambiar; si no hay regla, la elige el mesón.
const condiciones = ref<CondicionesPrestamo | null>(null)
let fechaPropuesta = ''

async function cargarCondiciones() {
  if (!usuario.value || !codigoBarrasPrestamo.value) return
  const codigo = codigoBarrasPrestamo.value
  try {
    const { data } = await api.get<CondicionesPrestamo>('/prestamos/condiciones', {
      params: { usuario_id: usuario.value.id, codigo_barras: codigo, fecha_prestamo: fechaPrestamo.value || undefined },
    })
    if (codigo !== codigoBarrasPrestamo.value) return
    condiciones.value = data
    // Solo se reemplaza una fecha vacía o la que propuso la regla antes, no la que eligió el mesón.
    if (data.fecha_devolucion && (!fechaDevolucion.value || fechaDevolucion.value === fechaPropuesta)) {
      fechaDevolucion.value = data.fecha_devolucion
      fechaPropuesta = data.fecha_devolucion
    }
  } catch {
    condiciones.value = null
  }
}

/** Tras renovar la cuenta, las condiciones ya pedidas todavía dicen «inactivo»: se vuelven a pedir. */
function alRenovar(renovado: Usuario) {
  usuario.value = renovado
  if (condiciones.value) cargarCondiciones()
}

function cambiarFechaPrestamo() {
  if (condiciones.value) cargarCondiciones()
}

const rechazoRegla = computed(() => (enSala.value ? condiciones.value?.motivo_rechazo_en_sala : condiciones.value?.motivo_rechazo) ?? '')
/** Para llevar no se puede (no se presta a domicilio o ya tiene el máximo), pero en sala sí. */
const sePuedeEnSala = computed(() => !enSala.value && !!condiciones.value?.motivo_rechazo && !condiciones.value.motivo_rechazo_en_sala)

/** «Regla Docentes · todo material · toda ubicación: 14 días · hasta 3 a la vez». */
const textoRegla = computed(() => {
  const c = condiciones.value
  if (enSala.value) return 'Uso en sala: se lee en la biblioteca y se devuelve hoy antes del cierre. No cuenta para el máximo de libros a domicilio; si no lo devuelve hoy, queda atrasado.'
  if (!c || !c.prestable) return ''
  const plazo = c.dias_prestamo !== null ? `${c.dias_prestamo} ${c.dias_prestamo === 1 ? 'día' : 'días'}` : 'plazo a elección del mesón'
  return `${c.regla ? `Regla ${c.regla.descripcion}` : 'Regla general'}: ${plazo} · hasta ${c.max_ejemplares} ${c.max_ejemplares === 1 ? 'libro' : 'libros'} a la vez · multa ${formatMonto(c.multa_por_dia)} por día`
})

// La copia apartada para esta misma persona (reserva lista para retirar) sí se le presta.
const reservaPropiaPrestamo = computed(() =>
  reservas.value.find(
    (r) => r.estado === 'pendiente' && !!r.ejemplar && [r.ejemplar.codigo_barras, r.ejemplar.codigo_lomo].includes(codigoBarrasPrestamo.value),
  ),
)
const puedePrestarLibro = computed(() => libroDisponiblePrestamo.value || (!enSala.value && !!reservaPropiaPrestamo.value))

/** Abre "Nuevo préstamo" con la copia que estaba reservada para esta persona. */
function entregarReserva(reserva: ReservaLibro) {
  if (!reserva.ejemplar?.codigo_barras) return
  showReservaForm.value = false
  showForm.value = true
  modoPrestamo.value = 'domicilio'
  buscarLibroPorCodigoPrestamo(reserva.ejemplar.codigo_barras)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const prestamoPendiente = ref<{ codigoBarras: string; titulo: string; fechaPrestamo: string; fechaDevolucion: string; enSala: boolean } | null>(null)
const creandoPrestamo = ref(false)

function pedirConfirmacionPrestamo() {
  if (bloqueadoPorMultas.value) {
    toast.error(MENSAJE_BLOQUEO)
    return
  }
  if (!codigoBarrasPrestamo.value.trim() || !libroEncontradoPrestamo.value) {
    toast.error('Escanee o ingrese un código de barras válido')
    return
  }
  if (!puedePrestarLibro.value) {
    if (enSala.value && reservaPropiaPrestamo.value) toast.error('Esta copia está apartada para esta persona: para entregársela use «Para llevar».')
    else toast.error(libroEnEstantePrestamo.value ? 'Este libro ya está reservado/prestado por otra persona' : 'Este libro no está disponible')
    return
  }
  if (rechazoRegla.value) {
    toast.error(rechazoRegla.value)
    return
  }
  if (!enSala.value && (!fechaPrestamo.value || !fechaDevolucion.value)) {
    toast.error('Seleccione la fecha de préstamo y de devolución')
    return
  }
  if (!enSala.value && avisoDevolucion.value) {
    toast.error(avisoDevolucion.value)
    return
  }
  prestamoPendiente.value = {
    codigoBarras: codigoBarrasPrestamo.value,
    titulo: libroEncontradoPrestamo.value,
    fechaPrestamo: enSala.value ? today : fechaPrestamo.value,
    fechaDevolucion: enSala.value ? today : fechaDevolucion.value,
    enSala: enSala.value,
  }
}

async function confirmarPrestamo() {
  if (!prestamoPendiente.value) return
  creandoPrestamo.value = true
  try {
    await api.post('/prestamos', {
      usuario_id: usuario.value!.id,
      codigo_barras: prestamoPendiente.value.codigoBarras,
      ...(prestamoPendiente.value.enSala
        ? { en_sala: true }
        : { fecha_prestamo: prestamoPendiente.value.fechaPrestamo, fecha_devolucion: prestamoPendiente.value.fechaDevolucion }),
    })
    toast.success(`${prestamoPendiente.value.enSala ? 'Uso en sala registrado' : 'Préstamo registrado'}: "${prestamoPendiente.value.titulo}"`)
    modoPrestamo.value = 'domicilio'
    codigoBarrasPrestamo.value = ''
    libroEncontradoPrestamo.value = ''
    fechaPrestamo.value = today
    fechaDevolucion.value = ''
    fechaPropuesta = ''
    condiciones.value = null
    prestamoPendiente.value = null
    await Promise.all([cargarPrestamosYReservas(), cargarPrestadosAhora()])
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo crear el préstamo'))
    prestamoPendiente.value = null
  } finally {
    creandoPrestamo.value = false
  }
}

// ---------- Devolver y pagar ----------
const devolucionPendiente = ref<Prestamo | null>(null)
const codigoPrestamoInput = ref<HTMLInputElement | null>(null)

/** Tercera alternativa del formulario: los recursos de apoyo (tablets, notebooks…) se prestan en su pestaña. */
function irARecursos() {
  activeTab.value = 'recursos'
  nextTick(() => document.getElementById('pestanas-prestamo')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}
const devolviendo = ref(false)

// Un libro de otra biblioteca que figura prestado pero el usuario ya no tiene (volvió sin
// registrarse, o se perdió): se cierra sin multa y se devuelve a su biblioteca, con motivo y CONFIRMAR.
const interbibliotecarioDe = (p: Prestamo) => {
  const i = p.ejemplar?.prestamo_interbibliotecario
  return i && !i.devuelto_el ? i : null
}
const cerrandoInterbibliotecario = ref<Prestamo | null>(null)
const interbibliotecarioACerrar = computed(() => (cerrandoInterbibliotecario.value ? interbibliotecarioDe(cerrandoInterbibliotecario.value) : null))

async function alCerrarInterbibliotecario() {
  cerrandoInterbibliotecario.value = null
  await Promise.all([cargarPrestamosYReservas(), refrescarUsuario()])
}

/** A quién se le prestó lo que se va a devolver: el del préstamo o, desde la ficha, el usuario abierto. */
const usuarioDevolucion = computed(() => devolucionPendiente.value?.usuario ?? usuario.value)

// ---------- Devolución rápida por código de barras ----------
const codigoRapido = ref('')
const buscandoRapido = ref(false)
const prestamoRapido = ref<Prestamo | null>(null)
const mensajeRapido = ref('')
const codigoRapidoInput = ref<HTMLInputElement | null>(null)

async function buscarPrestamoPorCodigo() {
  const codigo = codigoRapido.value.trim()
  prestamoRapido.value = null
  mensajeRapido.value = ''
  if (!codigo) return
  buscandoRapido.value = true
  try {
    const { data } = await api.get<Prestamo>('/prestamos/por-codigo', { params: { codigo } })
    prestamoRapido.value = data
  } catch (e) {
    mensajeRapido.value = mensajeDeError(e, 'No se pudo buscar el código')
  } finally {
    buscandoRapido.value = false
  }
}

function limpiarRapido() {
  codigoRapido.value = ''
  prestamoRapido.value = null
  mensajeRapido.value = ''
  nextTick(() => codigoRapidoInput.value?.focus())
}

async function confirmarDevolucion() {
  if (!devolucionPendiente.value) return
  const esRapida = prestamoRapido.value?.id === devolucionPendiente.value.id
  devolviendo.value = true
  try {
    const { data } = await api.patch<Prestamo>(`/prestamos/${devolucionPendiente.value.id}/devolver`)
    const multa = data.multas?.find((m) => m.tipo === 'atraso')
    toast.success(multa ? `Devolución registrada con multa de ${formatMonto(multa.monto)} por atraso` : 'Devolución registrada')
    devolucionPendiente.value = null
    if (esRapida) limpiarRapido()
    await Promise.all([cargarPrestamosYReservas(), cargarRecursosDisponibles(), refrescarUsuario(), cargarPrestadosAhora()])
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo registrar la devolución'))
  } finally {
    devolviendo.value = false
  }
}

function formatMonto(monto: number) {
  return new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP' }).format(monto)
}

const puedeCobrar = computed(() => auth.puede('multas.cobrar'))
const pagoMultaPendiente = ref<{ id: number; monto: number; titulo: string } | null>(null)
const pagandoMulta = ref(false)

function pedirConfirmacionPagoMulta(multa: { id: number; monto: number }, prestamo: Prestamo) {
  pagoMultaPendiente.value = { id: multa.id, monto: multa.monto, titulo: prestamo.libro_titulo }
}

async function confirmarPagoMulta() {
  if (!pagoMultaPendiente.value) return
  pagandoMulta.value = true
  try {
    await api.patch(`/multas/${pagoMultaPendiente.value.id}/pagar`)
    toast.success('Multa marcada como pagada')
    pagoMultaPendiente.value = null
    await Promise.all([cargarPrestamosYReservas(), refrescarUsuario()])
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo registrar el pago de la multa'))
  } finally {
    pagandoMulta.value = false
  }
}

// ---------- Préstamo de recursos de apoyo ----------
const prestamoRecursoPendiente = ref<{ categoria: CategoriaRecurso; codigo: string; inventario: string | null } | null>(null)
const prestandoRecurso = ref(false)

function pedirPrestamoRecurso(c: CategoriaRecurso) {
  if (bloqueadoPorMultas.value) {
    toast.error(MENSAJE_BLOQUEO)
    return
  }
  if (enTope(c)) {
    toast.error(mensajeTope(c))
    return
  }
  const codigo = (codigos[c.clave] ?? '').trim()
  if (!codigo) {
    toast.error(`Escanee o ingrese el código de barras (${c.nombre.toLowerCase()})`)
    return
  }
  const inventario = libresDe(c.clave).find((e) => e.codigo_barras === codigo)?.codigo_inventario ?? null
  prestamoRecursoPendiente.value = { categoria: c, codigo, inventario }
}

async function confirmarPrestamoRecurso() {
  const pendiente = prestamoRecursoPendiente.value
  if (!pendiente) return
  prestandoRecurso.value = true
  try {
    const { data } = await api.post<Prestamo>('/prestamos', {
      usuario_id: usuario.value!.id,
      codigo_barras: pendiente.codigo,
      tipo_item: pendiente.categoria.clave,
    })
    toast.success(`Préstamo registrado: ${data.libro_titulo}`)
    codigos[pendiente.categoria.clave] = ''
    await Promise.all([cargarPrestamosYReservas(), cargarRecursosDisponibles()])
  } catch (e) {
    toast.error(mensajeDeError(e, `No se pudo registrar el préstamo (${pendiente.categoria.nombre.toLowerCase()})`))
  } finally {
    prestandoRecurso.value = false
    prestamoRecursoPendiente.value = null
  }
}

// ---------- Reservas ----------
let buscarLibroTimer: ReturnType<typeof setTimeout> | undefined
/** Código de un recurso de apoyo escrito en el formulario de reservas (solo se reservan libros). */
const recursoEnReserva = ref('')
function buscarLibroPorCodigo(valor: string) {
  codigoBarras.value = valor.replace(/\D/g, '')
  libroEncontrado.value = ''
  libroDisponible.value = true
  libroEnEstante.value = true
  recursoEnReserva.value = ''
  clearTimeout(buscarLibroTimer)
  if (!codigoCompleto(codigoBarras.value)) return
  buscarLibroTimer = setTimeout(async () => {
    const codigo = codigoBarras.value
    const libro = await buscarLibroApi(codigo)
    if (codigo !== codigoBarras.value) return
    libroEncontrado.value = libro?.titulo ?? ''
    libroDisponible.value = libro?.disponible ?? true
    libroEnEstante.value = libro?.enEstante ?? true
    if (!libro) {
      const recurso = await buscarRecursoApi(codigo)
      if (recurso && codigo === codigoBarras.value) recursoEnReserva.value = nombreRecurso(recurso)
    }
  }, 250)
}

const reservaPendiente = ref<{ titulo: string; disponible: boolean } | null>(null)
const creandoReserva = ref(false)

function pedirConfirmacionReserva() {
  if (bloqueadoPorMultas.value) {
    toast.error(MENSAJE_BLOQUEO)
    return
  }
  if (!codigoBarras.value.trim() || !libroEncontrado.value) {
    toast.error('Escanee o ingrese un código de barras válido')
    return
  }
  // Si el libro está disponible ahora, se reserva con fechas explícitas. Si no, se une
  // a la cola de espera: la fecha de retiro se calcula cuando le toque el turno.
  if (libroDisponible.value && (!fechaReserva.value || !fechaRetiro.value)) {
    toast.error('Seleccione las fechas de reserva y retiro')
    return
  }
  if (libroDisponible.value && avisoRetiro.value) {
    toast.error(avisoRetiro.value)
    return
  }
  reservaPendiente.value = { titulo: libroEncontrado.value, disponible: libroDisponible.value }
}

async function crearReserva() {
  if (!reservaPendiente.value) return
  creandoReserva.value = true
  try {
    await api.post('/reservas-libro', {
      usuario_id: usuario.value!.id,
      codigo_barras: codigoBarras.value,
      fecha_reserva: fechaReserva.value || undefined,
      fecha_retiro: libroDisponible.value ? fechaRetiro.value : undefined,
    })
    toast.success(
      libroDisponible.value ? `Reserva registrada: "${libroEncontrado.value}"` : `Te uniste a la lista de espera de "${libroEncontrado.value}"`,
    )
    codigoBarras.value = ''
    libroEncontrado.value = ''
    fechaReserva.value = today
    fechaRetiro.value = ''
    await cargarPrestamosYReservas()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo registrar la reserva'))
  } finally {
    creandoReserva.value = false
    reservaPendiente.value = null
  }
}

const reservaACancelar = ref<ReservaLibro | null>(null)
const cancelandoReserva = ref(false)

async function cancelarReserva() {
  const reserva = reservaACancelar.value
  if (!reserva) return
  cancelandoReserva.value = true
  try {
    await api.patch(`/reservas-libro/${reserva.id}/cancelar`)
    toast.success(reserva.estado === 'en_cola' ? 'Salió de la lista de espera' : 'Reserva cancelada')
    await cargarPrestamosYReservas()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo cancelar la reserva')
    // Ya no estaba activa (la retiraron o la cancelaron en otra parte): se muestra como quedó.
    if (e?.response?.status === 409) await cargarPrestamosYReservas()
  } finally {
    cancelandoReserva.value = false
    reservaACancelar.value = null
  }
}

function formatFecha(iso: string | null) {
  return iso ? fechaCorta(iso) : '—'
}

/** Los recursos de apoyo se prestan por horas: la hora de salida importa. */
function formatHora(iso: string | null) {
  return iso ? new Date(iso).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false }) : ''
}
</script>

<template>
  <StaffLayout>
    <div class="mx-auto max-w-5xl">
      <!-- Encabezado: el degradado con puntos tenues y dos anillos a la derecha, como en Salas -->
      <div class="relative mb-4 overflow-hidden rounded-2xl shadow-md" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
        <FondoPrestamos />
        <div class="relative flex flex-wrap items-center justify-between gap-3 px-6 py-4">
          <div>
            <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Préstamos, devoluciones y reservas</h1>
            <p class="mt-1 text-sm text-white/75">Gestionar préstamos y reservas de material bibliográfico</p>
          </div>
          <button
            type="button"
            class="inline-flex shrink-0 items-center gap-2 rounded-full border border-white/30 bg-blanco/10 px-3.5 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-blanco/20"
            :aria-expanded="guiaAbierta"
            @click="guiaAbierta = !guiaAbierta"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
            </svg>
            ¿Cómo se usa?
          </button>
        </div>
      </div>

      <GuiaUso
        v-model:abierta="guiaAbierta"
        :con-boton="false"
        :pasos="[
          'Escriba o escanee el <strong>RUT del usuario</strong> y presione <strong>Buscar usuario</strong> (o Enter).',
          'Revise su ficha: <strong>préstamos, reservas y multas</strong>. Si hay algo pendiente verá un aviso en rojo.',
          'Para <strong>prestar</strong>, pulse «Nuevo préstamo», escanee el código de barras del libro y elija la fecha de devolución: tiene que ser un día en que la biblioteca atiende.',
          'Para <strong>recibir</strong> un libro o un recurso de apoyo, pulse «Devolver» en su fila. Si un libro llega atrasado, la multa se calcula sola.',
          'Los <strong>recursos de apoyo</strong> (notebooks, audífonos, cargadores, bicicletas…) se prestan en su propia pestaña, escaneando el código en el recuadro de su categoría.',
          'Si el libro está prestado, puede <strong>reservarlo</strong>: la persona queda en la lista de espera. Cuando venga a retirarlo, pulse <strong>Entregar</strong> en la pestaña Reservas.',
        ]"
        consejo="Cada usuario puede tener <strong>un solo libro</strong> a la vez, y con <strong>multas pendientes</strong> no puede llevarse ni reservar material hasta pagarlas."
      />

      <!-- Devolución rápida: escanear el libro o recurso sin buscar antes al usuario -->
      <div class="relative mb-4 overflow-hidden rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
        <span class="absolute inset-x-0 top-0 h-1 bg-emerald-500" aria-hidden="true"></span>
        <div class="mb-3 flex items-stretch gap-3">
          <span class="w-1 shrink-0 rounded-full bg-emerald-600" aria-hidden="true"></span>
          <div>
            <label for="codigo-devolucion" class="block font-semibold text-gray-900">Devolución rápida por código de barras</label>
            <p class="mt-0.5 text-xs text-gray-500">
              Escanee el libro o recurso: si está prestado, se puede devolver sin buscar al usuario.
              Presiona <kbd class="rounded border border-gray-300 bg-white px-1.5 py-0.5 font-sans text-[10px] font-semibold text-gray-700 shadow-sm">Enter</kbd> para buscar.
            </p>
          </div>
        </div>
        <div class="flex flex-col gap-3 sm:flex-row">
          <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.5v15m3-15v15m4.5-15v15m3-15v15m4.5-15v15" />
            </svg>
            <input
              id="codigo-devolucion"
              ref="codigoRapidoInput"
              v-model="codigoRapido"
              type="text"
              inputmode="numeric"
              autocomplete="off"
              placeholder="Ej. 30000002025988 o 02025988"
              maxlength="64"
              class="h-10 w-full rounded-xl border border-gray-300 bg-white pl-11 pr-4 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100"
              @input="prestamoRapido = null; mensajeRapido = ''"
              @keydown.enter="buscarPrestamoPorCodigo"
            />
          </div>
          <button
            type="button"
            class="flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl border border-emerald-600 px-4 text-sm font-semibold text-emerald-700 transition-colors hover:bg-emerald-50 disabled:opacity-60"
            :disabled="buscandoRapido || !codigoRapido.trim()"
            @click="buscarPrestamoPorCodigo"
          >
            {{ buscandoRapido ? 'Buscando…' : 'Buscar préstamo' }}
          </button>
        </div>
        <p v-if="mensajeRapido" class="mt-2 text-sm text-amber-700" role="status">{{ mensajeRapido }}</p>
        <div
          v-if="prestamoRapido"
          class="mt-3 flex flex-col gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-3 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="min-w-0 text-sm">
            <p class="truncate font-semibold text-gray-900">{{ prestamoRapido.libro_titulo }}</p>
            <p class="text-gray-600">
              Prestado a {{ prestamoRapido.usuario ? `${prestamoRapido.usuario.nombre} ${prestamoRapido.usuario.apellido} (${prestamoRapido.usuario.rut})` : '—' }}
              <template v-if="prestamoRapido.fecha_devolucion"> · vence el {{ fechaCorta(prestamoRapido.fecha_devolucion) }}</template>
            </p>
          </div>
          <button
            type="button"
            class="h-10 shrink-0 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700"
            @click="devolucionPendiente = prestamoRapido"
          >
            Devolver
          </button>
        </div>
      </div>

      <!-- Buscar al usuario: línea morada arriba y barra junto al título -->
      <div class="relative mb-4 overflow-hidden rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
        <span class="absolute inset-x-0 top-0 h-1" :style="{ background: FRANJA }" aria-hidden="true"></span>
        <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
          <div class="flex items-stretch gap-3">
            <span class="w-1 shrink-0 rounded-full bg-indigo-600" aria-hidden="true"></span>
            <div>
              <label for="rut-prestamos" class="block font-semibold text-gray-900">{{ modoRut === 'rut' ? 'RUT del usuario' : 'Pasaporte o DNI del usuario' }}</label>
              <p class="mt-0.5 text-xs text-gray-500">
                {{ modoRut === 'rut' ? 'Con o sin puntos y guion.' : 'Tal como aparece en su documento.' }}
                Presiona <kbd class="rounded border border-gray-300 bg-white px-1.5 py-0.5 font-sans text-[10px] font-semibold text-gray-700 shadow-sm">Enter</kbd> para buscar.
              </p>
            </div>
          </div>
          <SelectorDocumento v-model="modoRut" />
        </div>
        <div class="flex flex-col gap-3 sm:flex-row">
          <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
            <input
              id="rut-prestamos"
              ref="rutInput"
              :value="rut"
              type="text"
              autocomplete="off"
              :placeholder="placeholderSegunModo(modoRut)"
              :maxlength="largoSegunModo(modoRut)"
              class="h-10 w-full rounded-xl border bg-white pl-11 pr-4 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:ring-4"
              :class="errorRut ? 'border-red-400 focus:ring-red-100' : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-100'"
              @input="onRutInput"
              @keydown.enter="buscarUsuario"
            />
          </div>
          <button
            type="button"
            class="flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:opacity-60"
            :disabled="buscando"
            @click="buscarUsuario"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
            {{ buscando ? 'Buscando…' : 'Buscar usuario' }}
            <kbd class="hidden rounded-md bg-blanco/20 px-1.5 py-0.5 font-sans text-[10px] font-semibold sm:inline">Enter</kbd>
          </button>
        </div>
        <p v-if="errorRut" class="mt-2 text-sm text-red-600" role="alert">{{ errorRut }}</p>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <!-- Ficha -->
      <div
        v-if="!usuario"
        class="mb-4 flex flex-col gap-3 rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50/70 p-3 sm:flex-row sm:items-center sm:justify-between"
      >
        <div class="flex items-center gap-3">
          <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 border-dashed border-gray-300 text-gray-400">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
            </svg>
          </span>
          <div>
            <p class="font-semibold text-gray-800">Ningún usuario seleccionado</p>
            <p class="text-sm text-gray-500">Busca un RUT arriba para ver su ficha y habilitar los préstamos.</p>
          </div>
        </div>
        <div class="flex gap-2">
          <button type="button" disabled class="cursor-not-allowed rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-400">Nuevo préstamo</button>
          <button type="button" disabled class="cursor-not-allowed rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-400">Reservar libro</button>
        </div>
      </div>

      <div v-else class="relative mb-4 overflow-hidden rounded-2xl border border-indigo-100 bg-gradient-to-r from-indigo-50/80 via-white to-white p-3.5 shadow-sm">
        <span class="absolute inset-x-0 top-0 h-1 bg-emerald-400" aria-hidden="true"></span>
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div class="flex min-w-0 items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#b08d57] text-sm font-bold text-[#1a2430]">{{ inicialesUsuario }}</span>
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                <span class="font-serif text-lg font-bold text-gray-900">{{ usuario.nombre }} {{ usuario.apellido }}</span>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="estadoCuenta.clase">{{ estadoCuenta.texto }}</span>
              </p>
              <p class="mt-0.5 text-sm text-gray-500">RUT {{ usuario.rut }} · {{ TIPOS_USUARIO[usuario.tipo] }} · {{ usuario.email ?? 'sin email' }}</p>
              <p class="mt-2 flex flex-wrap gap-1.5">
                <span v-for="c in resumenUsuario" :key="c.texto" class="rounded-md px-2 py-0.5 text-xs font-semibold" :class="c.clase">{{ c.texto }}</span>
              </p>
            </div>
          </div>
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="bloqueadoPorMultas"
              :title="bloqueadoPorMultas ? MENSAJE_BLOQUEO : undefined"
              @click="showForm = true; showReservaForm = false"
            >
              Nuevo préstamo
            </button>
            <button
              type="button"
              class="rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="bloqueadoPorMultas"
              :title="bloqueadoPorMultas ? MENSAJE_BLOQUEO : undefined"
              @click="showReservaForm = !showReservaForm; showForm = false"
            >
              Reservar libro
            </button>
            <button
              type="button"
              class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50"
              @click="buscarOtroUsuario"
            >
              Buscar otro usuario
            </button>
          </div>
        </div>

        <RenovarCuenta v-if="usuario.activo === false" class="mt-3" :usuario="usuario" @renovada="alRenovar" />

        <div v-if="showForm && !bloqueadoPorMultas" class="mt-3 border-t border-indigo-100 pt-3">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h4 class="text-sm font-semibold text-gray-800">Nuevo préstamo por código de barras</h4>
            <div class="grid grid-cols-2 gap-1 rounded-lg bg-gray-100 p-1 sm:grid-cols-3" role="radiogroup" aria-label="Tipo de préstamo">
              <button
                v-for="m in ([
                  { id: 'domicilio', texto: 'Para llevar' },
                  { id: 'sala', texto: 'Uso en sala' },
                ] as const)"
                :key="m.id"
                type="button"
                role="radio"
                :aria-checked="modoPrestamo === m.id"
                class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
                :class="modoPrestamo === m.id ? (m.id === 'sala' ? 'bg-sky-600 text-white shadow-sm' : 'bg-white text-gray-900 shadow-sm') : 'text-gray-500 hover:text-gray-800'"
                @click="modoPrestamo = m.id"
              >
                {{ m.texto }}
              </button>
              <button
                type="button"
                class="col-span-2 rounded-md px-3 py-1.5 text-xs font-semibold text-gray-500 transition-colors hover:bg-white hover:text-gray-800 sm:col-span-1"
                title="Tablets, notebooks, audífonos…: se prestan en la pestaña Recursos de apoyo"
                @click="irARecursos"
              >
                Recurso de apoyo
              </button>
            </div>
          </div>
          <p class="mb-3 mt-1 text-xs text-gray-500">
            <template v-if="enSala">Para leer aquí mismo: escanee el libro y confirme; se devuelve hoy antes del cierre. Sirve también para lo que no se presta a domicilio (referencia).</template>
            <template v-else>Escanee el código de barras de la etiqueta del libro (si el lector no funciona, escriba los números) y elija hasta cuándo se lo lleva.</template>
            Si escanea un recurso de apoyo (notebook, audífonos, bicicleta…), se pasa solo a Recursos de apoyo.
          </p>
          <div class="space-y-3">
            <div class="relative">
              <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h1m0 0h1M5 6v12M8 6v12M11 6v12m3-12v12m3-12v12m3-12h1v12h-1" />
              </svg>
              <input
                ref="codigoPrestamoInput"
                :value="codigoBarrasPrestamo"
                placeholder="Escanear o ingresar código de barras"
                aria-label="Código de barras del libro"
                class="w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-10 pr-4 font-mono outline-none placeholder:font-sans focus:border-transparent focus:ring-2 focus:ring-indigo-500"
                @input="buscarLibroPorCodigoPrestamo(($event.target as HTMLInputElement).value)"
              />
            </div>
            <div
              v-if="codigoCompleto(codigoBarrasPrestamo)"
              class="rounded-lg px-3 py-2 text-sm"
              :class="libroEncontradoPrestamo && puedePrestarLibro ? 'border border-emerald-200 bg-emerald-50 text-emerald-700' : 'border border-red-200 bg-red-50 text-red-700'"
            >
              <span v-if="libroEncontradoPrestamo && reservaPropiaPrestamo && enSala">
                <strong>{{ libroEncontradoPrestamo }}</strong> está apartado para esta persona: para entregárselo use «Para llevar»
              </span>
              <span v-else-if="libroEncontradoPrestamo && reservaPropiaPrestamo">
                <strong>{{ libroEncontradoPrestamo }}</strong> estaba reservado para esta persona: al confirmar se entrega su reserva
              </span>
              <span v-else-if="libroEncontradoPrestamo && libroDisponiblePrestamo">Libro encontrado: <strong>{{ libroEncontradoPrestamo }}</strong></span>
              <span v-else-if="libroEncontradoPrestamo && !libroDisponiblePrestamo"><strong>{{ libroEncontradoPrestamo }}</strong> {{ libroEnEstantePrestamo ? 'ya está reservado/prestado por otra persona' : 'no está disponible' }}</span>
              <span v-else>Código de barras no encontrado en el sistema</span>
            </div>
            <div v-if="libroEncontradoPrestamo && rechazoRegla" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
              {{ rechazoRegla }}
              <button v-if="sePuedeEnSala" type="button" class="ml-1 font-semibold text-sky-700 underline hover:text-sky-800" @click="modoPrestamo = 'sala'">
                Prestarlo para uso en sala
              </button>
            </div>
            <p v-else-if="libroEncontradoPrestamo && textoRegla" class="text-xs" :class="enSala ? 'text-sky-700' : 'text-gray-500'">
              {{ textoRegla }}<template v-if="!enSala && condiciones?.fecha_devolucion"> · devolución propuesta el {{ formatFecha(`${condiciones.fecha_devolucion}T12:00:00`) }}</template>
            </p>
            <p
              v-if="libroEncontradoPrestamo && !rechazoRegla && condiciones?.interbibliotecario"
              class="rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs text-indigo-900"
            >
              Préstamo interbibliotecario de <strong>{{ condiciones.interbibliotecario.biblioteca }}</strong>: vuelve a su biblioteca el
              {{ formatFecha(`${condiciones.interbibliotecario.fecha_limite}T12:00:00`) }}, así que se devuelve a más tardar el
              <strong>{{ formatFecha(`${condiciones.interbibliotecario.fecha_tope}T12:00:00`) }}</strong>.
            </p>
            <div v-if="enSala" class="flex justify-end">
              <button
                type="button"
                class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-sky-700 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="!libroEncontradoPrestamo || !puedePrestarLibro || !!rechazoRegla"
                @click="pedirConfirmacionPrestamo"
              >
                Confirmar uso en sala
              </button>
            </div>
            <div v-else class="flex flex-wrap items-start gap-3">
              <div class="min-w-[160px] flex-1">
                <label for="fecha-prestamo" class="mb-1 block text-xs font-medium text-gray-600">Fecha de préstamo</label>
                <input
                  id="fecha-prestamo"
                  v-model="fechaPrestamo"
                  type="date"
                  :min="today"
                  @change="cambiarFechaPrestamo"
                  class="w-full rounded-lg border border-gray-300 px-4 py-2.5 outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
              <div class="min-w-[160px] flex-1">
                <label for="fecha-devolucion" class="mb-1 block text-xs font-medium text-gray-600">Fecha de devolución</label>
                <input
                  id="fecha-devolucion"
                  v-model="fechaDevolucion"
                  type="date"
                  :min="fechaPrestamo || today"
                  :max="condiciones?.interbibliotecario?.fecha_tope"
                  class="w-full rounded-lg border px-4 py-2.5 outline-none focus:ring-2"
                  :class="avisoDevolucion ? 'border-red-400 focus:ring-red-400' : 'border-gray-300 focus:ring-indigo-500'"
                  :aria-invalid="!!avisoDevolucion"
                />
                <p v-if="avisoDevolucion" class="mt-1 text-xs text-red-600" role="alert">{{ avisoDevolucion }}</p>
              </div>
              <button
                type="button"
                class="mt-5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="!libroEncontradoPrestamo || !puedePrestarLibro || !fechaPrestamo || !fechaDevolucion || !!rechazoRegla || !!avisoDevolucion"
                @click="pedirConfirmacionPrestamo"
              >
                Confirmar préstamo
              </button>
            </div>
          </div>
        </div>

        <div v-if="showReservaForm && !bloqueadoPorMultas" class="mt-3 border-t border-indigo-100 pt-3">
          <h4 class="text-sm font-semibold text-gray-800">Nueva reserva por código de barras</h4>
          <p class="mb-3 mt-0.5 text-xs text-gray-500">Si el libro está disponible queda apartado hasta la fecha límite; si está prestado, la persona entra a la lista de espera y se le reserva sola cuando se devuelva.</p>
          <div class="space-y-3">
            <div class="relative">
              <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h1m0 0h1M5 6v12M8 6v12M11 6v12m3-12v12m3-12v12m3-12h1v12h-1" />
              </svg>
              <input
                :value="codigoBarras"
                placeholder="Escanear o ingresar código de barras"
                aria-label="Código de barras del libro a reservar"
                class="w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-10 pr-4 font-mono outline-none placeholder:font-sans focus:border-transparent focus:ring-2 focus:ring-emerald-500"
                @input="buscarLibroPorCodigo(($event.target as HTMLInputElement).value)"
              />
            </div>
            <div
              v-if="codigoCompleto(codigoBarras)"
              class="rounded-lg px-3 py-2 text-sm"
              :class="libroEncontrado && libroDisponible ? 'border border-emerald-200 bg-emerald-50 text-emerald-700' : 'border border-red-200 bg-red-50 text-red-700'"
            >
              <span v-if="libroEncontrado && libroDisponible">Libro encontrado: <strong>{{ libroEncontrado }}</strong></span>
              <span v-else-if="libroEncontrado && !libroDisponible"><strong>{{ libroEncontrado }}</strong> {{ libroEnEstante ? 'ya está reservado/prestado por otra persona — se puede unir a la lista de espera' : 'no está disponible' }}</span>
              <span v-else-if="recursoEnReserva">Es un recurso de apoyo (<strong>{{ recursoEnReserva }}</strong>): los recursos no se reservan, se prestan desde la pestaña «Recursos de apoyo»</span>
              <span v-else>Código de barras no encontrado en el sistema</span>
            </div>
            <div class="flex flex-wrap items-start gap-3">
              <template v-if="libroDisponible">
                <div class="min-w-[180px] flex-1">
                  <label for="fecha-reserva" class="mb-1 block text-xs font-medium text-gray-600">Fecha de reserva</label>
                  <input
                    id="fecha-reserva"
                    v-model="fechaReserva"
                    type="date"
                    :min="today"
                    class="w-full rounded-lg border border-gray-300 px-4 py-2.5 outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                </div>
                <div class="min-w-[180px] flex-1">
                  <label for="fecha-retiro" class="mb-1 block text-xs font-medium text-gray-600">Fecha límite de retiro</label>
                  <input
                    id="fecha-retiro"
                    v-model="fechaRetiro"
                    type="date"
                    :min="fechaReserva || today"
                    class="w-full rounded-lg border px-4 py-2.5 outline-none focus:ring-2"
                    :class="avisoRetiro ? 'border-red-400 focus:ring-red-400' : 'border-gray-300 focus:ring-emerald-500'"
                    :aria-invalid="!!avisoRetiro"
                  />
                  <p v-if="avisoRetiro" class="mt-1 text-xs text-red-600" role="alert">{{ avisoRetiro }}</p>
                </div>
              </template>
              <button
                v-if="libroEnEstante"
                type="button"
                class="mt-5 rounded-lg px-4 py-2 text-sm font-semibold text-white transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                :class="libroDisponible ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-sky-600 hover:bg-sky-700'"
                :disabled="!libroEncontrado || (libroDisponible && (!fechaReserva || !fechaRetiro || !!avisoRetiro))"
                @click="pedirConfirmacionReserva"
              >
                {{ libroDisponible ? 'Confirmar reserva' : 'Unirse a la lista de espera' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="usuario?.multas_pendientes?.cantidad" class="mb-4 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-3.5 py-2 text-sm text-red-700">
        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
        </svg>
        <span>
          Este usuario tiene
          {{ usuario.multas_pendientes.cantidad === 1 ? '1 multa pendiente' : `${usuario.multas_pendientes.cantidad} multas pendientes` }}
          por {{ formatMonto(usuario.multas_pendientes.monto_total) }}: no puede llevarse ni reservar material hasta pagar.
          <router-link v-if="auth.puede('multas.ver')" :to="{ name: 'multas', query: { rut: usuario.rut } }" class="font-semibold underline" @click="recordarFichaAlIrAMultas">
            {{ puedeCobrar ? 'Registrar el pago' : 'Ver detalle de sus multas' }}
          </router-link>
        </span>
      </div>

      <!-- Préstamos y reservas -->
      <div id="pestanas-prestamo" class="mb-4 scroll-mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="h-1" :style="{ background: FRANJA }" aria-hidden="true"></div>
        <div v-if="usuario" class="border-b border-indigo-100/70 bg-indigo-50/50 px-3 py-2">
        <div class="grid grid-cols-2 gap-1 sm:grid-cols-4 rounded-xl border border-gray-200 bg-white p-1 shadow-sm" role="tablist">
          <button
            v-for="t in [
              { id: 'recientes', texto: 'Recientes', n: prestamos.length, icono: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z' },
              { id: 'prestamos', texto: 'Libros', n: librosEnPrestamo, icono: 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25' },
              { id: 'recursos', texto: 'Recursos de apoyo', n: recursosEnUso.length, icono: 'M20.25 7.5l-8.25 4.5L3.75 7.5M3.75 7.5l8.25-4.5 8.25 4.5M3.75 7.5v9l8.25 4.5m0-9v9m0-9l8.25-4.5m-8.25 4.5l8.25 4.5m0 0v-9' },
              { id: 'reservas', texto: 'Reservas', n: reservasVigentes.length, icono: 'M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z' },
            ] as const"
            :key="t.id"
            type="button"
            role="tab"
            :aria-selected="!!usuario && activeTab === t.id"
            :disabled="!usuario"
            class="flex items-center justify-center gap-2 rounded-lg py-2.5 text-sm font-semibold transition-colors disabled:cursor-default"
            :class="usuario && activeTab === t.id ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-700 enabled:hover:bg-gray-50'"
            @click="activeTab = t.id"
          >
            <svg class="hidden h-4 w-4 shrink-0 sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="t.icono" />
            </svg>
            {{ t.texto }}
            <span
              class="min-w-[1.5rem] rounded-full px-1.5 py-0.5 text-xs font-bold"
              :class="usuario && activeTab === t.id ? 'bg-blanco/20 text-white' : 'bg-gray-100 text-gray-600'"
            >{{ usuario ? t.n : '–' }}</span>
          </button>
        </div>
        </div>

        <template v-if="!usuario">
          <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3">
            <h3 class="font-semibold text-gray-900">Todo lo prestado ahora</h3>
            <span class="text-xs text-gray-500">{{ textoPrestadosAhora }}</span>
          </div>
          <div class="hidden border-b border-gray-100 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500 md:grid md:grid-cols-[minmax(0,2fr)_minmax(0,1.4fr)_6.5rem_8.5rem_6.5rem_7rem] md:gap-3">
            <span>Material</span><span>Usuario</span><span>Préstamo</span><span>Devolución</span><span>Estado</span><span>Acción</span>
          </div>
          <ul class="divide-y divide-gray-100">
            <li v-for="p in prestadosAhora" :key="p.id" class="grid grid-cols-2 gap-x-3 gap-y-2 px-4 py-2 md:grid-cols-[minmax(0,2fr)_minmax(0,1.4fr)_6.5rem_8.5rem_6.5rem_7rem] md:items-center">
              <div class="col-span-2 flex min-w-0 items-center gap-3 md:col-span-1">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="itemPrestamo(p.tipo_item).fondo">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" :d="itemPrestamo(p.tipo_item).icono" /></svg>
                </span>
                <span class="min-w-0">
                  <span class="block truncate font-semibold text-gray-900">{{ p.libro_titulo }}</span>
                  <span class="block truncate text-xs text-gray-500">{{ subtituloItem(p) }}</span>
                </span>
              </div>
              <div class="col-span-2 min-w-0 text-sm md:col-span-1">
                <span class="block truncate text-gray-900">{{ p.usuario ? `${p.usuario.nombre} ${p.usuario.apellido}` : '—' }}</span>
                <span class="block truncate font-mono text-xs text-gray-500">{{ p.usuario?.rut }}</span>
              </div>
              <div class="text-sm text-gray-700"><span class="block text-[11px] text-gray-400 md:hidden">Préstamo</span>{{ formatFecha(p.fecha_prestamo) }}</div>
              <div class="text-sm">
                <span class="block text-[11px] text-gray-400 md:hidden">Devolución</span>
                <span class="text-gray-700">{{ devolucionDe(p).linea1 }}</span>
                <span class="block text-xs font-semibold" :class="devolucionDe(p).clase">{{ devolucionDe(p).linea2 }}</span>
              </div>
              <div><span class="inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :class="estadoDe(p).clase">{{ estadoDe(p).texto }}</span></div>
              <div class="flex justify-end md:justify-start">
                <button v-if="p.estado !== 'devuelto'" type="button" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700" @click="devolucionPendiente = p">Devolver</button>
              </div>
            </li>
            <li v-if="!cargandoPrestados && !prestadosAhora.length" class="p-4 text-center text-sm text-gray-500">No hay nada prestado en este momento.</li>
          </ul>
        </template>

        <template v-else-if="activeTab === 'recientes'">
          <div class="hidden border-b border-gray-100 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500 md:grid md:grid-cols-[minmax(0,2.2fr)_6.5rem_8.5rem_6.5rem_7rem] md:gap-3">
            <span>Material</span><span>Préstamo</span><span>Devolución</span><span>Estado</span><span>Acción</span>
          </div>
          <ul class="divide-y divide-gray-100">
            <li v-for="p in filasRecientes" :key="p.id" class="grid grid-cols-2 gap-x-3 gap-y-2 px-4 py-2 md:grid-cols-[minmax(0,2.2fr)_6.5rem_8.5rem_6.5rem_7rem] md:items-center" :class="p.estado === 'devuelto' ? 'bg-gray-50/50' : ''">
              <div class="col-span-2 flex min-w-0 items-center gap-3 md:col-span-1">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="itemPrestamo(p.tipo_item).fondo">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" :d="itemPrestamo(p.tipo_item).icono" /></svg>
                </span>
                <span class="min-w-0">
                  <span class="block truncate font-semibold text-gray-900">{{ p.libro_titulo }}</span>
                  <span class="block truncate text-xs text-gray-500">{{ subtituloItem(p) }}</span>
                </span>
              </div>
              <div class="text-sm text-gray-700"><span class="block text-[11px] text-gray-400 md:hidden">Préstamo</span>{{ formatFecha(p.fecha_prestamo) }}</div>
              <div class="text-sm">
                <span class="block text-[11px] text-gray-400 md:hidden">Devolución</span>
                <span class="text-gray-700">{{ devolucionDe(p).linea1 }}</span>
                <span class="block text-xs font-semibold" :class="devolucionDe(p).clase">{{ devolucionDe(p).linea2 }}</span>
              </div>
              <div><span class="inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :class="estadoDe(p).clase">{{ estadoDe(p).texto }}</span></div>
              <div class="flex justify-end md:justify-start">
                <button v-if="p.estado !== 'devuelto'" type="button" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700" @click="devolucionPendiente = p">Devolver</button>
              </div>
            </li>
            <li v-if="!filasRecientes.length" class="p-4 text-center text-sm text-gray-500">Todavía no tiene préstamos.</li>
          </ul>
        </template>

        <template v-else-if="activeTab === 'prestamos'">
          <div class="hidden border-b border-gray-100 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500 md:grid md:grid-cols-[minmax(0,2.2fr)_6.5rem_8.5rem_6.5rem_minmax(0,1fr)_7rem] md:gap-3">
            <span>Libro</span><span>Préstamo</span><span>Devolución</span><span>Estado</span><span>Multa</span><span>Acción</span>
          </div>

                    <template v-if="usuario">
            <ul class="divide-y divide-gray-100">
              <li
                v-for="p in filasPrestamos"
                :key="p.id"
                class="grid grid-cols-2 gap-x-3 gap-y-2 px-4 py-2 md:grid-cols-[minmax(0,2.2fr)_6.5rem_8.5rem_6.5rem_minmax(0,1fr)_7rem] md:items-center"
                :class="p.estado === 'devuelto' ? 'bg-gray-50/50' : ''"
              >
                <div class="col-span-2 flex min-w-0 items-center gap-3 md:col-span-1">
                  <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="itemPrestamo(p.tipo_item).fondo">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="itemPrestamo(p.tipo_item).icono" />
                    </svg>
                  </span>
                  <span class="min-w-0">
                    <span class="block truncate font-semibold text-gray-900">{{ p.libro_titulo }}</span>
                    <span class="block truncate text-xs text-gray-500">{{ subtituloItem(p) }}</span>
                    <span v-if="p.estado !== 'devuelto' && interbibliotecarioDe(p)" class="block truncate text-xs text-indigo-700">
                      De {{ interbibliotecarioDe(p)!.biblioteca }} · vuelve a su biblioteca el {{ formatFecha(`${interbibliotecarioDe(p)!.fecha_limite}T12:00:00`) }}
                    </span>
                  </span>
                </div>
                <div class="text-sm text-gray-700"><span class="block text-[11px] text-gray-400 md:hidden">Préstamo</span>{{ formatFecha(p.fecha_prestamo) }}</div>
                <div class="text-sm">
                  <span class="block text-[11px] text-gray-400 md:hidden">Devolución</span>
                  <span class="text-gray-700">{{ devolucionDe(p).linea1 }}</span>
                  <span class="block text-xs font-semibold" :class="devolucionDe(p).clase">{{ devolucionDe(p).linea2 }}</span>
                </div>
                <div><span class="inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :class="estadoDe(p).clase">{{ estadoDe(p).texto }}</span></div>
                <div class="text-sm">
                  <div v-if="p.multas?.length" class="flex flex-col gap-1">
                    <div v-for="m in p.multas" :key="m.id" class="flex flex-wrap items-center gap-x-2 gap-y-1">
                      <span
                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="m.estado === 'pagada' ? 'bg-emerald-50 text-emerald-700' : m.estado === 'anulada' ? 'bg-gray-100 text-gray-500 line-through' : 'bg-red-50 text-red-700'"
                      >{{ formatMonto(m.monto) }}</span>
                      <button
                        v-if="m.estado === 'pendiente' && puedeCobrar"
                        type="button"
                        class="text-xs font-semibold text-indigo-700 hover:text-indigo-900"
                        @click="pedirConfirmacionPagoMulta(m, p)"
                      >
                        Marcar pagada
                      </button>
                    </div>
                  </div>
                  <span v-else class="text-gray-400">$0</span>
                </div>
                <div class="col-span-2 flex flex-col items-end gap-1 md:col-span-1 md:items-start">
                  <button
                    v-if="p.estado !== 'devuelto'"
                    type="button"
                    class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700"
                    @click="devolucionPendiente = p"
                  >
                    Devolver
                  </button>
                  <button
                    v-if="p.estado !== 'devuelto' && interbibliotecarioDe(p)"
                    type="button"
                    class="text-xs font-semibold text-red-700 hover:text-red-800 hover:underline"
                    title="El usuario ya no lo tiene: cerrar el préstamo y devolverlo a su biblioteca"
                    @click="cerrandoInterbibliotecario = p"
                  >
                    Ya volvió a su biblioteca
                  </button>
                </div>
              </li>
              <li v-if="!filasPrestamos.length" class="p-3">
                <div class="flex items-center justify-center gap-2 rounded-xl border border-dashed border-indigo-200 bg-indigo-50/30 px-4 py-2.5 text-center">
                  <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-indigo-600" aria-hidden="true">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
                  </span>
                  <p class="text-sm text-gray-600">No tiene préstamos activos.</p>
                </div>
              </li>
            </ul>
            <div class="flex flex-col gap-2 border-t border-gray-100 bg-gray-50/60 px-4 py-2 text-xs sm:flex-row sm:items-center sm:justify-between">
              <p class="text-gray-500">
                {{ librosEnPrestamo }} {{ librosEnPrestamo === 1 ? 'libro' : 'libros' }} en préstamo · {{ condiciones ? `máximo ${condiciones.max_ejemplares} a la vez` : 'el máximo a la vez depende de la regla de préstamo' }}
                <template v-if="devueltosOcultables">
                  ·
                  <button type="button" class="font-semibold text-indigo-700 hover:underline" @click="mostrarDevueltos = !mostrarDevueltos">
                    {{ mostrarDevueltos ? 'Ocultar devueltos' : `Ver devueltos (${devueltosOcultables})` }}
                  </button>
                </template>
              </p>
              <button v-if="reservasListas.length" type="button" class="text-left font-semibold text-violet-700 hover:underline" @click="activeTab = 'reservas'">
                {{ reservasListas.length === 1 ? '1 reserva lista para entregar' : `${reservasListas.length} reservas listas para entregar` }} →
              </button>
            </div>
          </template>
        </template>

        <template v-else-if="activeTab === 'reservas'">
          <div class="hidden border-b border-gray-100 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500 md:grid md:grid-cols-[minmax(0,2.2fr)_6.5rem_8.5rem_minmax(0,1.3fr)_9.5rem] md:gap-3">
            <span>Libro</span><span>Reservada</span><span>Retiro hasta</span><span>Estado</span><span>Acción</span>
          </div>
          <ul class="divide-y divide-gray-100">
            <li
              v-for="r in filasReservas"
              :key="r.id"
              class="grid grid-cols-2 gap-x-3 gap-y-2 px-4 py-2 md:grid-cols-[minmax(0,2.2fr)_6.5rem_8.5rem_minmax(0,1.3fr)_9.5rem] md:items-center"
              :class="r.estado === 'retirado' || r.estado === 'cancelado' ? 'bg-gray-50/50' : ''"
            >
              <div class="col-span-2 flex min-w-0 items-center gap-3 md:col-span-1">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="itemPrestamo('libro').fondo">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="itemPrestamo('libro').icono" />
                  </svg>
                </span>
                <span class="min-w-0">
                  <span class="block truncate font-semibold text-gray-900">{{ r.libro?.titulo }}</span>
                  <span class="block truncate text-xs text-gray-500">{{ r.ejemplar?.codigo_barras ? `Copia · ${r.ejemplar.codigo_barras}` : 'Sin copia asignada todavía' }}</span>
                </span>
              </div>
              <div class="text-sm text-gray-700"><span class="block text-[11px] text-gray-400 md:hidden">Reservada</span>{{ formatFecha(r.fecha_reserva) }}</div>
              <div class="text-sm text-gray-700"><span class="block text-[11px] text-gray-400 md:hidden">Retiro hasta</span>{{ formatFecha(r.fecha_retiro) }}</div>
              <div class="col-span-2 md:col-span-1">
                <span class="inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :class="reservaBadges[r.estado]?.cls">{{ reservaBadges[r.estado]?.label }}</span>
                <span v-if="r.estado === 'en_cola' && r.posicion" class="mt-0.5 block text-[11px] text-gray-500">
                  Lugar #{{ r.posicion }} en la fila
                  <template v-if="r.proxima_fecha_devolucion"> · una copia debería devolverse el {{ formatFecha(r.proxima_fecha_devolucion) }}</template>
                </span>
              </div>
              <div class="col-span-2 flex items-center justify-end gap-3 md:col-span-1 md:justify-start">
                <button
                  v-if="r.estado === 'pendiente' && r.ejemplar"
                  type="button"
                  class="rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                  :disabled="bloqueadoPorMultas"
                  :title="bloqueadoPorMultas ? MENSAJE_BLOQUEO : 'Prestarle esta copia a quien la reservó'"
                  @click="entregarReserva(r)"
                >
                  Entregar
                </button>
                <button
                  v-if="r.estado === 'pendiente' || r.estado === 'en_cola'"
                  type="button"
                  class="text-sm font-semibold text-red-600 hover:text-red-700"
                  @click="reservaACancelar = r"
                >
                  {{ r.estado === 'en_cola' ? 'Salir de la fila' : 'Cancelar' }}
                </button>
              </div>
            </li>
            <li v-if="!filasReservas.length" class="p-3">
              <div class="flex items-center justify-center gap-2 rounded-xl border border-dashed border-indigo-200 bg-indigo-50/30 px-4 py-2.5 text-center">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-indigo-600" aria-hidden="true">
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" /></svg>
                </span>
                <p class="text-sm text-gray-600">No tiene reservas vigentes.</p>
              </div>
            </li>
          </ul>
          <div v-if="terminadasOcultables" class="border-t border-gray-100 bg-gray-50/60 px-4 py-2 text-xs">
            <button type="button" class="font-semibold text-indigo-700 hover:underline" @click="mostrarTerminadas = !mostrarTerminadas">
              {{ mostrarTerminadas ? 'Ocultar retiradas y canceladas' : `Ver retiradas y canceladas (${terminadasOcultables})` }}
            </button>
          </div>
        </template>
        <!-- Recursos de apoyo: recursos no bibliográficos (notebooks, bicicletas…) -->
        <template v-else>
          <template v-if="usuario">
            <p class="px-5 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-gray-500">
              Recursos de apoyo de {{ usuario.nombre }}
            </p>
            <ul class="divide-y divide-gray-100">
              <li
                v-for="p in filasRecursos"
                :key="p.id"
                class="grid grid-cols-2 gap-x-3 gap-y-2 px-4 py-2 md:grid-cols-[minmax(0,2.2fr)_8.5rem_9.5rem_6.5rem_7rem] md:items-center"
                :class="p.estado === 'devuelto' ? 'bg-gray-50/50' : ''"
              >
                <div class="col-span-2 flex min-w-0 items-center gap-3 md:col-span-1">
                  <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="itemPrestamo(p.tipo_item).fondo">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="itemPrestamo(p.tipo_item).icono" />
                    </svg>
                  </span>
                  <span class="min-w-0">
                    <span class="block truncate font-semibold text-gray-900">{{ p.libro_titulo }}</span>
                    <span class="block truncate text-xs text-gray-500">{{ subtituloItem(p) }}</span>
                  </span>
                </div>
                <div class="text-sm text-gray-700">
                  <span class="block text-[11px] text-gray-400 md:hidden">Préstamo</span>{{ formatFecha(p.fecha_prestamo) }}
                  <span class="text-xs text-gray-500">{{ formatHora(p.fecha_prestamo) }}</span>
                </div>
                <div class="text-sm">
                  <span class="block text-[11px] text-gray-400 md:hidden">Devolución</span>
                  <span class="text-gray-700">{{ devolucionDe(p).linea1 }}</span>
                  <span class="block text-xs font-semibold" :class="devolucionDe(p).clase">{{ devolucionDe(p).linea2 }}</span>
                </div>
                <div><span class="inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :class="estadoDe(p).clase">{{ estadoDe(p).texto }}</span></div>
                <div class="col-span-2 flex justify-end md:col-span-1 md:justify-start">
                  <button
                    v-if="p.estado !== 'devuelto'"
                    type="button"
                    class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700"
                    @click="devolucionPendiente = p"
                  >
                    Devolver
                  </button>
                </div>
              </li>
              <li v-if="!filasRecursos.length" class="p-3">
                <div class="flex items-center justify-center gap-2 rounded-xl border border-dashed border-indigo-200 bg-indigo-50/30 px-4 py-2.5 text-center">
                  <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-indigo-600" aria-hidden="true">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-8.25 4.5L3.75 7.5M3.75 7.5l8.25-4.5 8.25 4.5M3.75 7.5v9l8.25 4.5m0-9v9m0-9l8.25-4.5m-8.25 4.5l8.25 4.5m0 0v-9" /></svg>
                  </span>
                  <p class="text-sm text-gray-600">No tiene recursos de apoyo prestados.</p>
                </div>
              </li>
            </ul>
            <div v-if="recursosDevueltos" class="border-t border-gray-100 bg-gray-50/60 px-4 py-2 text-xs">
              <button type="button" class="font-semibold text-indigo-700 hover:underline" @click="mostrarRecursosDevueltos = !mostrarRecursosDevueltos">
                {{ mostrarRecursosDevueltos ? 'Ocultar devueltos' : `Ver devueltos (${recursosDevueltos})` }}
              </button>
            </div>
          </template>
          <div class="p-3.5" :class="usuario ? 'border-t border-gray-100' : ''">
            <p class="mb-4 text-sm text-gray-500">
              Préstamo de recursos no bibliográficos: escanee el código de barras del recurso en el recuadro de su categoría.
              <router-link
                v-if="puedeAdministrarCategorias"
                :to="{ name: 'administracion', query: { seccion: 'catalogos' } }"
                class="font-semibold text-indigo-700 hover:underline"
              >Administrar categorías</router-link>
            </p>
            <div v-for="(grupo, i) in gruposCategorias" :key="i" class="grid grid-cols-1 gap-3 md:grid-cols-3" :class="i ? 'mt-3' : ''">
              <div
                v-for="c in grupo"
                :key="c.clave"
                class="rounded-xl border bg-white p-3 shadow-sm transition-shadow"
                :class="recursoResaltado === c.clave ? 'border-indigo-400 ring-2 ring-indigo-300' : 'border-gray-200'"
              >
                <div class="flex items-start gap-3">
                  <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="fondoCategoria(c)">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="iconoCategoria(c)" />
                    </svg>
                  </span>
                  <div class="min-w-0">
                    <p class="font-semibold text-gray-900">{{ c.nombre_plural }}</p>
                    <p class="text-xs text-gray-500">
                      {{ c.indicaciones || 'Se devuelve al término de la estadía' }}<template v-if="c.horas_maximas"> · hasta {{ textoHoras(c.horas_maximas) }}</template><template v-if="c.limite_por_persona"> · máximo {{ c.limite_por_persona }} por persona</template>
                    </p>
                  </div>
                </div>
                <div class="mt-2 flex gap-2">
                  <CampoSugerencias
                    v-model="codigos[c.clave]"
                    class="min-w-0 flex-1"
                    :id-campo="`recurso-${c.clave}`"
                    :sugerencias="libresDe(c.clave).map((e) => ({ valor: e.codigo_barras, nombre: e.codigo_inventario }))"
                    :titulo="`${c.nombre_plural} libres`"
                    :sin-sugerencias="`No quedan ${c.nombre_plural.toLowerCase()} libres`"
                    :placeholder="usuario ? 'Código de barras' : 'Primero busca un usuario'"
                    :etiqueta="`Código de barras: ${c.nombre.toLowerCase()}`"
                    :disabled="!usuario || bloqueadoPorMultas"
                    clase-campo="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm outline-none placeholder:font-sans focus:ring-2 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-100/70 disabled:font-sans"
                    @enviar="pedirPrestamoRecurso(c)"
                  />
                  <button
                    type="button"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400"
                    :disabled="!usuario || bloqueadoPorMultas || enTope(c)"
                    :title="bloqueadoPorMultas ? MENSAJE_BLOQUEO : enTope(c) ? mensajeTope(c) : undefined"
                    @click="pedirPrestamoRecurso(c)"
                  >
                    Prestar
                  </button>
                </div>
                <p class="mt-3 flex items-center gap-1.5 text-xs text-gray-500">
                  <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="puntoCategoria(c)"></span>
                  {{ lineaRecurso(c) }}
                </p>
              </div>
            </div>
            <p v-if="!categoriasActivas.length" class="rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">
              No hay categorías de recursos de apoyo activas. Se crean en Administración → Catálogos.
            </p>
          </div>
        </template>
      </div>

      <div
        v-if="prestamoPendiente"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
        @click.self="prestamoPendiente = null"
      >
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
          <h3 class="mb-1 text-lg font-bold text-gray-900">{{ prestamoPendiente.enSala ? '¿Confirmar uso en sala?' : '¿Confirmar préstamo?' }}</h3>
          <p v-if="prestamoPendiente.enSala" class="mb-6 text-sm text-gray-500">
            <strong>{{ usuario?.nombre }} {{ usuario?.apellido }}</strong> leerá <strong class="font-mono">{{ prestamoPendiente.titulo }}</strong> en la
            biblioteca y lo devuelve <strong>hoy, antes del cierre</strong>.
          </p>
          <p v-else class="mb-6 text-sm text-gray-500">
            Se prestará <strong class="font-mono">{{ prestamoPendiente.titulo }}</strong> a
            <strong>{{ usuario?.nombre }} {{ usuario?.apellido }}</strong>, con devolución acordada para el
            {{ formatFecha(`${prestamoPendiente.fechaDevolucion}T12:00:00`) }}.
          </p>
          <div class="flex gap-3">
            <button
              type="button"
              class="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
              @click="prestamoPendiente = null"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-indigo-700 disabled:opacity-60"
              :disabled="creandoPrestamo"
              @click="confirmarPrestamo"
            >
              {{ creandoPrestamo ? 'Confirmando…' : 'Sí, prestar' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="devolucionPendiente"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
        @click.self="devolucionPendiente = null"
      >
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
          <h3 class="mb-1 text-lg font-bold text-gray-900">¿Confirmar devolución?</h3>
          <p class="mb-4 text-sm text-gray-500">
            Se registrará la devolución de <strong class="font-mono">{{ devolucionPendiente.libro_titulo }}</strong>.
          </p>
          <dl v-if="usuarioDevolucion" class="mb-6 space-y-1 rounded-xl border border-gray-200 bg-gray-50 p-3 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-gray-500">Prestado a</dt><dd class="text-right font-medium text-gray-900">{{ usuarioDevolucion.nombre }} {{ usuarioDevolucion.apellido }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500">RUT</dt><dd class="font-mono text-gray-900">{{ usuarioDevolucion.rut }}</dd></div>
            <div v-if="devolucionPendiente.codigo_barras" class="flex justify-between gap-3"><dt class="text-gray-500">Código</dt><dd class="font-mono text-gray-900">{{ devolucionPendiente.codigo_barras }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500">Prestado el</dt><dd class="text-gray-900">{{ fechaCorta(devolucionPendiente.fecha_prestamo) }}</dd></div>
            <div v-if="devolucionPendiente.fecha_devolucion" class="flex justify-between gap-3"><dt class="text-gray-500">Vence el</dt><dd class="text-gray-900">{{ fechaCorta(devolucionPendiente.fecha_devolucion) }}</dd></div>
          </dl>
          <div class="flex gap-3">
            <button
              type="button"
              class="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
              @click="devolucionPendiente = null"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-indigo-700 disabled:opacity-60"
              :disabled="devolviendo"
              @click="confirmarDevolucion"
            >
              {{ devolviendo ? 'Confirmando…' : 'Sí, devolver' }}
            </button>
          </div>
        </div>
      </div>

      <CerrarPrestamoInterbibliotecario
        :abierto="!!interbibliotecarioACerrar"
        :id="interbibliotecarioACerrar?.id ?? null"
        :biblioteca="interbibliotecarioACerrar?.biblioteca ?? ''"
        :titulo="cerrandoInterbibliotecario?.libro_titulo ?? ''"
        :usuario="usuario ? `${usuario.nombre} ${usuario.apellido}` : null"
        :fecha-devolucion="cerrandoInterbibliotecario?.fecha_devolucion ?? null"
        @listo="alCerrarInterbibliotecario"
        @cancelar="cerrandoInterbibliotecario = null"
      />

      <div
        v-if="pagoMultaPendiente"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
        @click.self="pagoMultaPendiente = null"
      >
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
          <h3 class="mb-1 text-lg font-bold text-gray-900">¿Confirmar pago de multa?</h3>
          <p class="mb-6 text-sm text-gray-500">
            Se registrará el pago de <strong>{{ formatMonto(pagoMultaPendiente.monto) }}</strong> por
            <strong class="font-mono">{{ pagoMultaPendiente.titulo }}</strong>. Queda registrado a tu nombre.
          </p>
          <div class="flex gap-3">
            <button
              type="button"
              class="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
              @click="pagoMultaPendiente = null"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-indigo-700 disabled:opacity-60"
              :disabled="pagandoMulta"
              @click="confirmarPagoMulta"
            >
              {{ pagandoMulta ? 'Confirmando…' : 'Sí, pagada' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="reservaPendiente"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
        @click.self="!creandoReserva && (reservaPendiente = null)"
      >
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
          <h3 class="mb-1 text-lg font-bold text-gray-900">{{ reservaPendiente.disponible ? '¿Confirmar reserva?' : '¿Unir a la lista de espera?' }}</h3>
          <p class="mb-6 text-sm text-gray-500">
            <template v-if="reservaPendiente.disponible">
              Se reservará <strong class="font-mono">{{ reservaPendiente.titulo }}</strong> para
              <strong>{{ usuario?.nombre }} {{ usuario?.apellido }}</strong>, con retiro hasta el {{ formatFecha(`${fechaRetiro}T12:00:00`) }}.
              Mientras tanto, nadie más podrá llevarse esa copia.
            </template>
            <template v-else>
              <strong>{{ usuario?.nombre }} {{ usuario?.apellido }}</strong> quedará en la lista de espera de
              <strong class="font-mono">{{ reservaPendiente.titulo }}</strong>; cuando le toque, se le guardará una copia.
            </template>
          </p>
          <div class="flex gap-3">
            <button
              type="button"
              class="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
              :disabled="creandoReserva"
              @click="reservaPendiente = null"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-emerald-700 disabled:opacity-60"
              :disabled="creandoReserva"
              @click="crearReserva"
            >
              {{ creandoReserva ? 'Confirmando…' : reservaPendiente.disponible ? 'Sí, reservar' : 'Sí, unir' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="reservaACancelar"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
        @click.self="!cancelandoReserva && (reservaACancelar = null)"
      >
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
          <h3 class="mb-1 text-lg font-bold text-gray-900">{{ reservaACancelar.estado === 'en_cola' ? '¿Sacar de la lista de espera?' : '¿Cancelar esta reserva?' }}</h3>
          <p class="mb-6 text-sm text-gray-500">
            <template v-if="reservaACancelar.estado === 'en_cola'">
              <strong>{{ usuario?.nombre }} {{ usuario?.apellido }}</strong> saldrá de la lista de espera de
              <strong class="font-mono">{{ reservaACancelar.libro?.titulo }}</strong> y perderá su lugar en la fila.
            </template>
            <template v-else>
              Se cancelará la reserva de <strong class="font-mono">{{ reservaACancelar.libro?.titulo }}</strong> de
              <strong>{{ usuario?.nombre }} {{ usuario?.apellido }}</strong>, y la copia quedará para la siguiente persona.
            </template>
            Esta acción no se puede deshacer.
          </p>
          <div class="flex gap-3">
            <button
              type="button"
              class="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
              :disabled="cancelandoReserva"
              @click="reservaACancelar = null"
            >
              No, mantener
            </button>
            <button
              type="button"
              class="flex-1 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-red-700 disabled:opacity-60"
              :disabled="cancelandoReserva"
              @click="cancelarReserva"
            >
              {{ cancelandoReserva ? 'Confirmando…' : reservaACancelar.estado === 'en_cola' ? 'Sí, sacar' : 'Sí, cancelar' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="prestamoRecursoPendiente"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
        @click.self="!prestandoRecurso && (prestamoRecursoPendiente = null)"
      >
        <div class="w-full max-w-sm overflow-hidden rounded-2xl bg-white shadow-2xl">
          <div class="relative flex items-center gap-3 overflow-hidden px-5 py-3.5" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
            <svg class="pointer-events-none absolute -bottom-3 right-10 h-[4.5rem] w-[4.5rem] rotate-[-8deg] text-white/[0.14]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="0.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="iconoCategoria(prestamoRecursoPendiente.categoria)" /></svg>
            <span class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-white/25 bg-blanco/15 text-white" aria-hidden="true">
              <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.750"><path stroke-linecap="round" stroke-linejoin="round" :d="iconoCategoria(prestamoRecursoPendiente.categoria)" /></svg>
            </span>
            <div class="relative min-w-0 flex-1">
              <h3 class="font-serif text-lg font-bold leading-tight text-white">¿Confirmar préstamo?</h3>
              <p class="text-xs text-white/80">{{ prestamoRecursoPendiente.categoria.nombre }} · recurso de apoyo</p>
            </div>
            <button type="button" class="relative flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-white/30 text-white transition-colors hover:bg-blanco/15 disabled:opacity-50" aria-label="Cerrar" :disabled="prestandoRecurso" @click="prestamoRecursoPendiente = null">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>

          <div class="space-y-2.5 p-4">
          <dl class="divide-y divide-gray-200 rounded-xl border border-gray-200 bg-gray-50 text-sm">
            <div class="flex items-center gap-2.5 px-3 py-2">
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="fondoCategoria(prestamoRecursoPendiente.categoria)" aria-hidden="true">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.750"><path stroke-linecap="round" stroke-linejoin="round" :d="iconoCategoria(prestamoRecursoPendiente.categoria)" /></svg>
              </span>
              <div class="min-w-0 flex-1">
                <dt class="text-[11px] text-gray-500">Se prestará</dt>
                <dd class="truncate font-semibold text-gray-900">{{ prestamoRecursoPendiente.inventario ?? prestamoRecursoPendiente.categoria.nombre }}</dd>
              </div>
              <span class="shrink-0 rounded-md border border-gray-200 bg-white px-1.5 py-0.5 font-mono text-[11px] text-gray-700">{{ prestamoRecursoPendiente.codigo }}</span>
            </div>
            <div class="flex items-center gap-2.5 px-3 py-2">
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#b08d57] text-[11px] font-bold text-[#1a2430]" aria-hidden="true">{{ inicialesUsuario }}</span>
              <div class="min-w-0 flex-1">
                <dt class="text-[11px] text-gray-500">Para</dt>
                <dd class="font-semibold leading-snug text-gray-900">{{ usuario?.nombre }} {{ usuario?.apellido }}</dd>
              </div>
            </div>
          </dl>
          <p v-if="prestamoRecursoPendiente.categoria.indicaciones || prestamoRecursoPendiente.categoria.horas_maximas" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            <svg class="mt-px h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.500m4.500 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <span>
            Recuérdele:
            <template v-if="prestamoRecursoPendiente.categoria.horas_maximas">
              se devuelve a más tardar {{ devolucionRecurso(prestamoRecursoPendiente.categoria.horas_maximas) }} ({{ textoHoras(prestamoRecursoPendiente.categoria.horas_maximas) }})<template v-if="prestamoRecursoPendiente.categoria.indicaciones">. </template>
            </template>
            <template v-if="prestamoRecursoPendiente.categoria.indicaciones">{{ prestamoRecursoPendiente.categoria.indicaciones }}</template>.
            </span>
          </p>
          <div class="flex gap-2.5 pt-1">
            <button
              type="button"
              class="flex-1 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-800 shadow-sm transition-colors hover:bg-gray-100"
              :disabled="prestandoRecurso"
              @click="prestamoRecursoPendiente = null"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="flex-1 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:opacity-60"
              :disabled="prestandoRecurso"
              @click="confirmarPrestamoRecurso"
            >
              {{ prestandoRecurso ? 'Confirmando…' : 'Sí, prestar' }}
            </button>
          </div>
          </div>
        </div>
      </div>
    </div>
  </StaffLayout>
</template>
