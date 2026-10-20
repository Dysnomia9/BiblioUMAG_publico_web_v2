<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import GuiaUso from '@/components/ui/GuiaUso.vue'
import SelectorDocumento from '@/components/ui/SelectorDocumento.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { formatearSegunModo, largoSegunModo, modoSegunValor, placeholderSegunModo } from '@/utils/documento'
import { useModoDocumento } from '@/composables/useModoDocumento'
import { useAuthStore } from '@/stores/auth'
import { diaEnPlural, diaIso } from '@/utils/horario'
import { devolucionDe, estadoDe, fechaCorta } from '@/utils/prestamos'
import { COLORES_RECURSO, ICONOS_RECURSO, itemPrestamo, useCategoriasRecurso } from '@/utils/recursos'
import type { CategoriaRecurso, Equipo, HorarioBiblioteca, Prestamo, ReservaLibro, Usuario } from '@/types'

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

const prestamos = ref<Prestamo[]>([])
const reservas = ref<ReservaLibro[]>([])

const showForm = ref(false)
const showReservaForm = ref(false)
const activeTab = ref<'prestamos' | 'reservas' | 'recursos'>('prestamos')

const today = new Date().toLocaleDateString('sv-SE')

const codigoBarrasPrestamo = ref('')
const libroEncontradoPrestamo = ref('')
const libroDisponiblePrestamo = ref(true)
const fechaPrestamo = ref(today)
const fechaDevolucion = ref('')

const codigoBarras = ref('')
const libroEncontrado = ref('')
const libroDisponible = ref(true)
const fechaReserva = ref(today)
const fechaRetiro = ref('')

const prestamosLibros = computed(() => prestamos.value.filter((p) => p.tipo_item === 'libro' || !p.tipo_item))
const librosEnPrestamo = computed(() => prestamosLibros.value.filter((p) => p.estado !== 'devuelto').length)
const tieneLibroActivo = computed(() => librosEnPrestamo.value > 0)

// Con multas pendientes no se presta ni se reserva nada (el backend lo rechaza igual).
const bloqueadoPorMultas = computed(() => (usuario.value?.multas_pendientes?.cantidad ?? 0) > 0)
const MENSAJE_BLOQUEO = 'Tiene multas pendientes: no puede llevarse ni reservar material hasta pagarlas'

// ---------- Horario: la devolución y el retiro no pueden caer en un día sin atención ----------
const horario = ref<HorarioBiblioteca | null>(null)

async function cargarHorario() {
  try {
    const { data } = await api.get<HorarioBiblioteca>('/configuracion/horario')
    horario.value = data
  } catch {
    horario.value = null
  }
}

function avisoDiaSinAtencion(fecha: string, para: string) {
  if (!fecha || !horario.value) return ''
  const dia = new Date(`${fecha}T12:00:00`)
  return horario.value[diaIso(dia)] ? '' : `La biblioteca no atiende los ${diaEnPlural(dia)}: elige otro día ${para}.`
}
const avisoDevolucion = computed(() => avisoDiaSinAtencion(fechaDevolucion.value, 'para la devolución'))
const avisoRetiro = computed(() => avisoDiaSinAtencion(fechaRetiro.value, 'para el retiro'))

// ---------- Recursos de apoyo ----------
// Todo lo que se presta y no es un libro (notebooks, audífonos, bicicletas…). Las
// categorías se administran en Administración → Catálogos; aquí sale un recuadro por
// cada categoría activa.
const { categorias, cargar: cargarCategorias } = useCategoriasRecurso()
const categoriasActivas = computed(() => categorias.value.filter((c) => c.activo))
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
const fondoCategoria = (c: CategoriaRecurso) => (COLORES_RECURSO[c.color] ?? COLORES_RECURSO.slate).fondo
const puntoCategoria = (c: CategoriaRecurso) => (COLORES_RECURSO[c.color] ?? COLORES_RECURSO.slate).punto
const iconoCategoria = (c: CategoriaRecurso) => (ICONOS_RECURSO[c.icono] ?? ICONOS_RECURSO.caja).trazo

onMounted(() => {
  cargarRecursosDisponibles()
  cargarCategorias()
  cargarHorario()
  // Se puede llegar con ?rut=… (ej. desde Multas o Entradas) para abrir directo los préstamos de esa persona.
  if (typeof route.query.rut === 'string' && route.query.rut) {
    modoRut.value = modoSegunValor(route.query.rut)
    rut.value = formatearSegunModo(route.query.rut, modoRut.value)
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
  prestamos.value = []
  reservas.value = []
  showForm.value = false
  showReservaForm.value = false
  mostrarDevueltos.value = false
  mostrarTerminadas.value = false
  activeTab.value = 'prestamos'
  rut.value = ''
  modoRut.value = 'rut'
  errorRut.value = ''
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
    showForm.value = false
    showReservaForm.value = false
    mostrarDevueltos.value = false
    mostrarTerminadas.value = false
    activeTab.value = 'prestamos'
    await cargarPrestamosYReservas()
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
  if (usuario.value?.activo === false) return { texto: 'Inactiva', clase: 'bg-gray-100 text-gray-600' }
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

async function buscarLibroApi(codigo: string): Promise<{ titulo: string; disponible: boolean } | null> {
  try {
    const { data } = await api.get(`/ejemplares/${codigo}`)
    const titulo = data.numero_copia > 1 ? `${data.libro?.titulo} (Copia ${data.numero_copia})` : data.libro?.titulo
    return { titulo, disponible: data.disponible && data.estado_proceso === 'en_estante' }
  } catch {
    return null
  }
}

let buscarLibroPrestamoTimer: ReturnType<typeof setTimeout> | undefined
function buscarLibroPorCodigoPrestamo(valor: string) {
  codigoBarrasPrestamo.value = valor.replace(/\D/g, '')
  libroEncontradoPrestamo.value = ''
  libroDisponiblePrestamo.value = true
  clearTimeout(buscarLibroPrestamoTimer)
  if (!codigoCompleto(codigoBarrasPrestamo.value)) return
  buscarLibroPrestamoTimer = setTimeout(async () => {
    const libro = await buscarLibroApi(codigoBarrasPrestamo.value)
    libroEncontradoPrestamo.value = libro?.titulo ?? ''
    libroDisponiblePrestamo.value = libro?.disponible ?? true
  }, 250)
}

// La copia apartada para esta misma persona (reserva lista para retirar) sí se le presta.
const reservaPropiaPrestamo = computed(() =>
  reservas.value.find(
    (r) => r.estado === 'pendiente' && !!r.ejemplar && [r.ejemplar.codigo_barras, r.ejemplar.codigo_lomo].includes(codigoBarrasPrestamo.value),
  ),
)
const puedePrestarLibro = computed(() => libroDisponiblePrestamo.value || !!reservaPropiaPrestamo.value)

/** Abre "Nuevo préstamo" con la copia que estaba reservada para esta persona. */
function entregarReserva(reserva: ReservaLibro) {
  if (!reserva.ejemplar?.codigo_barras) return
  showReservaForm.value = false
  showForm.value = true
  buscarLibroPorCodigoPrestamo(reserva.ejemplar.codigo_barras)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const prestamoPendiente = ref<{ codigoBarras: string; titulo: string; fechaPrestamo: string; fechaDevolucion: string } | null>(null)
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
    toast.error('Este libro ya está reservado/prestado por otra persona')
    return
  }
  if (tieneLibroActivo.value) {
    toast.error('Este usuario ya tiene un libro prestado sin devolver')
    return
  }
  if (!fechaPrestamo.value || !fechaDevolucion.value) {
    toast.error('Seleccione la fecha de préstamo y de devolución')
    return
  }
  if (avisoDevolucion.value) {
    toast.error(avisoDevolucion.value)
    return
  }
  prestamoPendiente.value = {
    codigoBarras: codigoBarrasPrestamo.value,
    titulo: libroEncontradoPrestamo.value,
    fechaPrestamo: fechaPrestamo.value,
    fechaDevolucion: fechaDevolucion.value,
  }
}

async function confirmarPrestamo() {
  if (!prestamoPendiente.value) return
  creandoPrestamo.value = true
  try {
    await api.post('/prestamos', {
      usuario_id: usuario.value!.id,
      codigo_barras: prestamoPendiente.value.codigoBarras,
      fecha_prestamo: prestamoPendiente.value.fechaPrestamo,
      fecha_devolucion: prestamoPendiente.value.fechaDevolucion,
    })
    toast.success(`Préstamo registrado: "${prestamoPendiente.value.titulo}"`)
    codigoBarrasPrestamo.value = ''
    libroEncontradoPrestamo.value = ''
    fechaPrestamo.value = today
    fechaDevolucion.value = ''
    showForm.value = false
    prestamoPendiente.value = null
    await cargarPrestamosYReservas()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo crear el préstamo'))
    prestamoPendiente.value = null
  } finally {
    creandoPrestamo.value = false
  }
}

// ---------- Devolver y pagar ----------
const devolucionPendiente = ref<Prestamo | null>(null)
const devolviendo = ref(false)

async function confirmarDevolucion() {
  if (!devolucionPendiente.value) return
  devolviendo.value = true
  try {
    const { data } = await api.patch<Prestamo>(`/prestamos/${devolucionPendiente.value.id}/devolver`)
    const multa = data.multas?.find((m) => m.tipo === 'atraso')
    toast.success(multa ? `Devolución registrada con multa de ${formatMonto(multa.monto)} por atraso` : 'Devolución registrada')
    devolucionPendiente.value = null
    await Promise.all([cargarPrestamosYReservas(), cargarRecursosDisponibles(), refrescarUsuario()])
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
function buscarLibroPorCodigo(valor: string) {
  codigoBarras.value = valor.replace(/\D/g, '')
  libroEncontrado.value = ''
  libroDisponible.value = true
  clearTimeout(buscarLibroTimer)
  if (!codigoCompleto(codigoBarras.value)) return
  buscarLibroTimer = setTimeout(async () => {
    const libro = await buscarLibroApi(codigoBarras.value)
    libroEncontrado.value = libro?.titulo ?? ''
    libroDisponible.value = libro?.disponible ?? true
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
    showReservaForm.value = false
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
  } catch {
    toast.error('No se pudo cancelar la reserva')
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
      <div class="mb-6 overflow-hidden rounded-2xl shadow-md" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
        <div class="flex flex-wrap items-start justify-between gap-3 px-6 py-5">
          <div>
            <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Préstamos, devoluciones y reservas</h1>
            <p class="mt-1 text-sm text-white/70">Gestionar préstamos y reservas de material bibliográfico</p>
          </div>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-white/85 transition-colors hover:bg-white/10 hover:text-white"
            :aria-expanded="guiaAbierta"
            @click="guiaAbierta = !guiaAbierta"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
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

      <div class="mb-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
          <div>
            <label for="rut-prestamos" class="block text-sm font-semibold text-gray-900">{{ modoRut === 'rut' ? 'RUT del usuario' : 'Pasaporte o DNI del usuario' }}</label>
            <p class="mt-0.5 text-xs text-gray-500">
              {{ modoRut === 'rut' ? 'Con o sin puntos y guion.' : 'Tal como aparece en su documento.' }}
              Presiona <kbd class="rounded border border-gray-300 bg-gray-50 px-1 font-mono text-[10px]">Enter</kbd> para buscar.
            </p>
          </div>
          <SelectorDocumento v-model="modoRut" />
        </div>
        <div class="flex flex-col gap-3 sm:flex-row">
          <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
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
              class="h-12 w-full rounded-xl border bg-white pl-11 pr-4 text-base text-gray-900 outline-none transition placeholder:text-gray-400 focus:ring-4"
              :class="errorRut ? 'border-red-400 focus:ring-red-100' : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-100'"
              @input="onRutInput"
              @keydown.enter="buscarUsuario"
            />
          </div>
          <button
            type="button"
            class="h-12 shrink-0 rounded-xl bg-indigo-600 px-6 font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:opacity-60"
            :disabled="buscando"
            @click="buscarUsuario"
          >
            {{ buscando ? 'Buscando…' : 'Buscar usuario' }}
          </button>
        </div>
        <p v-if="errorRut" class="mt-2 text-sm text-red-600" role="alert">{{ errorRut }}</p>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <!-- Ficha -->
      <div
        v-if="!usuario"
        class="mb-5 flex flex-col gap-4 rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50/70 p-5 sm:flex-row sm:items-center sm:justify-between"
      >
        <div class="flex items-center gap-4">
          <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border-2 border-dashed border-gray-300 text-gray-400">
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

      <div v-else class="mb-5 rounded-2xl border border-indigo-100 bg-gradient-to-r from-indigo-50/80 via-white to-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div class="flex min-w-0 items-center gap-4">
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-[#b08d57] text-lg font-bold text-[#1a2430]">{{ inicialesUsuario }}</span>
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                <span class="font-serif text-xl font-bold text-gray-900">{{ usuario.nombre }} {{ usuario.apellido }}</span>
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
              @click="showForm = !showForm; showReservaForm = false"
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

        <div v-if="showForm && !bloqueadoPorMultas" class="mt-5 border-t border-indigo-100 pt-4">
          <h4 class="text-sm font-semibold text-gray-800">Nuevo préstamo por código de barras</h4>
          <p class="mb-3 mt-0.5 text-xs text-gray-500">Escanee el código de barras de la etiqueta del libro (si el lector no funciona, escriba los números) y elija hasta cuándo se lo lleva.</p>
          <div class="space-y-3">
            <div class="relative">
              <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h1m0 0h1M5 6v12M8 6v12M11 6v12m3-12v12m3-12v12m3-12h1v12h-1" />
              </svg>
              <input
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
              <span v-if="libroEncontradoPrestamo && reservaPropiaPrestamo">
                <strong>{{ libroEncontradoPrestamo }}</strong> estaba reservado para esta persona: al confirmar se entrega su reserva
              </span>
              <span v-else-if="libroEncontradoPrestamo && libroDisponiblePrestamo">Libro encontrado: <strong>{{ libroEncontradoPrestamo }}</strong></span>
              <span v-else-if="libroEncontradoPrestamo && !libroDisponiblePrestamo"><strong>{{ libroEncontradoPrestamo }}</strong> ya está reservado/prestado por otra persona</span>
              <span v-else>Código de barras no encontrado en el sistema</span>
            </div>
            <div class="flex flex-wrap items-start gap-3">
              <div class="min-w-[160px] flex-1">
                <label for="fecha-prestamo" class="mb-1 block text-xs font-medium text-gray-600">Fecha de préstamo</label>
                <input
                  id="fecha-prestamo"
                  v-model="fechaPrestamo"
                  type="date"
                  :min="today"
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
                  class="w-full rounded-lg border px-4 py-2.5 outline-none focus:ring-2"
                  :class="avisoDevolucion ? 'border-red-400 focus:ring-red-400' : 'border-gray-300 focus:ring-indigo-500'"
                  :aria-invalid="!!avisoDevolucion"
                />
                <p v-if="avisoDevolucion" class="mt-1 text-xs text-red-600" role="alert">{{ avisoDevolucion }}</p>
              </div>
              <button
                type="button"
                class="mt-5 rounded-lg bg-indigo-600 px-5 py-2.5 font-medium text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="!libroEncontradoPrestamo || !puedePrestarLibro || !fechaPrestamo || !fechaDevolucion || tieneLibroActivo || !!avisoDevolucion"
                @click="pedirConfirmacionPrestamo"
              >
                Confirmar préstamo
              </button>
            </div>
          </div>
        </div>

        <div v-if="showReservaForm && !bloqueadoPorMultas" class="mt-5 border-t border-indigo-100 pt-4">
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
              <span v-else-if="libroEncontrado && !libroDisponible"><strong>{{ libroEncontrado }}</strong> ya está reservado/prestado por otra persona — se puede unir a la lista de espera</span>
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
                type="button"
                class="mt-5 rounded-lg px-5 py-2.5 font-medium text-white transition-colors disabled:cursor-not-allowed disabled:opacity-50"
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

      <div v-if="usuario && tieneLibroActivo" class="mb-3 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
        </svg>
        <span>Este usuario ya tiene un libro prestado sin devolver — no puede llevarse otro hasta devolverlo.</span>
      </div>

      <div v-if="usuario?.multas_pendientes?.cantidad" class="mb-5 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
        </svg>
        <span>
          Este usuario tiene
          {{ usuario.multas_pendientes.cantidad === 1 ? '1 multa pendiente' : `${usuario.multas_pendientes.cantidad} multas pendientes` }}
          por {{ formatMonto(usuario.multas_pendientes.monto_total) }}: no puede llevarse ni reservar material hasta pagar.
          <router-link v-if="auth.puede('multas.ver')" :to="{ name: 'multas', query: { rut: usuario.rut } }" class="font-semibold underline">
            {{ puedeCobrar ? 'Registrar el pago' : 'Ver detalle de sus multas' }}
          </router-link>
        </span>
      </div>

      <!-- Préstamos y reservas -->
      <div class="mb-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="grid grid-cols-3 gap-1 border-b border-gray-200 bg-gray-100/80 p-1.5" role="tablist">
          <button
            v-for="t in [
              { id: 'prestamos', texto: 'Libros', n: librosEnPrestamo },
              { id: 'reservas', texto: 'Reservas', n: reservasVigentes.length },
              { id: 'recursos', texto: 'Recursos de apoyo', n: recursosEnUso.length },
            ] as const"
            :key="t.id"
            type="button"
            role="tab"
            :aria-selected="!!usuario && activeTab === t.id"
            :disabled="!usuario"
            class="flex items-center justify-center gap-2 rounded-xl py-2.5 text-sm font-semibold transition-colors disabled:cursor-default"
            :class="usuario && activeTab === t.id ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 enabled:hover:text-gray-900'"
            @click="activeTab = t.id"
          >
            {{ t.texto }}
            <span
              class="min-w-[1.5rem] rounded-full px-1.5 py-0.5 text-xs font-bold"
              :class="usuario && activeTab === t.id ? 'bg-white/20 text-white' : 'bg-gray-200/80 text-gray-600'"
            >{{ usuario ? t.n : '–' }}</span>
          </button>
        </div>

        <template v-if="!usuario || activeTab === 'prestamos'">
          <div class="hidden border-b border-gray-100 px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-gray-500 md:grid md:grid-cols-[minmax(0,2.2fr)_6.5rem_8.5rem_6.5rem_minmax(0,1fr)_7rem] md:gap-3">
            <span>Libro</span><span>Préstamo</span><span>Devolución</span><span>Estado</span><span>Multa</span><span>Acción</span>
          </div>

          <div v-if="!usuario" class="px-5 pb-6 pt-4">
            <div class="hidden space-y-4 md:block" aria-hidden="true">
              <div v-for="n in 2" :key="n" class="grid grid-cols-[minmax(0,2.2fr)_6.5rem_8.5rem_6.5rem_minmax(0,1fr)_7rem] gap-3 border-b border-gray-50 pb-4">
                <span class="h-2.5 rounded-full bg-gray-100" :class="n === 1 ? 'w-3/4' : 'w-1/2'"></span>
                <span class="h-2.5 w-16 rounded-full bg-gray-100"></span>
                <span class="h-2.5 w-16 rounded-full bg-gray-100"></span>
                <span class="h-2.5 w-14 rounded-full bg-gray-100"></span>
                <span class="h-2.5 w-8 rounded-full bg-gray-100"></span>
                <span class="h-2.5 w-14 rounded-full bg-gray-100"></span>
              </div>
            </div>
            <p class="pt-2 text-center text-sm text-gray-500">Aquí aparecerán los préstamos del usuario que busques.</p>
          </div>

          <template v-else>
            <ul class="divide-y divide-gray-100">
              <li
                v-for="p in filasPrestamos"
                :key="p.id"
                class="grid grid-cols-2 gap-x-3 gap-y-2.5 px-5 py-3.5 md:grid-cols-[minmax(0,2.2fr)_6.5rem_8.5rem_6.5rem_minmax(0,1fr)_7rem] md:items-center"
                :class="p.estado === 'devuelto' ? 'bg-gray-50/50' : ''"
              >
                <div class="col-span-2 flex min-w-0 items-center gap-3 md:col-span-1">
                  <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="itemPrestamo(p.tipo_item).fondo">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="itemPrestamo(p.tipo_item).icono" />
                    </svg>
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
              <li v-if="!filasPrestamos.length" class="px-5 py-8 text-center text-sm text-gray-400">No tiene préstamos activos.</li>
            </ul>
            <div class="flex flex-col gap-2 border-t border-gray-100 bg-gray-50/60 px-5 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
              <p class="text-gray-500">
                {{ librosEnPrestamo }} {{ librosEnPrestamo === 1 ? 'libro' : 'libros' }} en préstamo · máximo 1 a la vez
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
          <div class="hidden border-b border-gray-100 px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-gray-500 md:grid md:grid-cols-[minmax(0,2.2fr)_6.5rem_8.5rem_minmax(0,1.3fr)_9.5rem] md:gap-3">
            <span>Libro</span><span>Reservada</span><span>Retiro hasta</span><span>Estado</span><span>Acción</span>
          </div>
          <ul class="divide-y divide-gray-100">
            <li
              v-for="r in filasReservas"
              :key="r.id"
              class="grid grid-cols-2 gap-x-3 gap-y-2.5 px-5 py-3.5 md:grid-cols-[minmax(0,2.2fr)_6.5rem_8.5rem_minmax(0,1.3fr)_9.5rem] md:items-center"
              :class="r.estado === 'retirado' || r.estado === 'cancelado' ? 'bg-gray-50/50' : ''"
            >
              <div class="col-span-2 flex min-w-0 items-center gap-3 md:col-span-1">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="itemPrestamo('libro').fondo">
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
            <li v-if="!filasReservas.length" class="px-5 py-8 text-center text-sm text-gray-400">No tiene reservas vigentes.</li>
          </ul>
          <div v-if="terminadasOcultables" class="border-t border-gray-100 bg-gray-50/60 px-5 py-3 text-sm">
            <button type="button" class="font-semibold text-indigo-700 hover:underline" @click="mostrarTerminadas = !mostrarTerminadas">
              {{ mostrarTerminadas ? 'Ocultar retiradas y canceladas' : `Ver retiradas y canceladas (${terminadasOcultables})` }}
            </button>
          </div>
        </template>
        <!-- Recursos de apoyo: recursos no bibliográficos (notebooks, bicicletas…) -->
        <template v-else>
          <div class="p-5">
            <p class="mb-4 text-sm text-gray-500">
              Préstamo de recursos no bibliográficos: escanee el código de barras del recurso en el recuadro de su categoría.
              <router-link
                v-if="puedeAdministrarCategorias"
                :to="{ name: 'administracion', query: { seccion: 'catalogos' } }"
                class="font-semibold text-indigo-700 hover:underline"
              >Administrar categorías</router-link>
            </p>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
              <div v-for="c in categoriasActivas" :key="c.clave" class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-start gap-3">
                  <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="fondoCategoria(c)">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="iconoCategoria(c)" />
                    </svg>
                  </span>
                  <div class="min-w-0">
                    <p class="font-semibold text-gray-900">{{ c.nombre_plural }}</p>
                    <p class="text-xs text-gray-500">
                      {{ c.indicaciones || 'Se devuelve al término de la estadía' }}<template v-if="c.limite_por_persona"> · máximo {{ c.limite_por_persona }} por persona</template>
                    </p>
                  </div>
                </div>
                <div class="mt-4 flex gap-2">
                  <input
                    v-model="codigos[c.clave]"
                    :list="`recursos-${c.clave}`"
                    :placeholder="usuario ? 'Código de barras' : 'Primero busca un usuario'"
                    :aria-label="`Código de barras: ${c.nombre.toLowerCase()}`"
                    :disabled="!usuario || bloqueadoPorMultas"
                    class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm outline-none placeholder:font-sans focus:ring-2 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-100/70 disabled:font-sans"
                    @keydown.enter="pedirPrestamoRecurso(c)"
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
              <p v-if="!categoriasActivas.length" class="col-span-full rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">
                No hay categorías de recursos de apoyo activas. Se crean en Administración → Catálogos.
              </p>
            </div>
          </div>

          <template v-if="usuario">
            <p class="border-t border-gray-100 px-5 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-gray-500">
              Recursos de apoyo de {{ usuario.nombre }}
            </p>
            <ul class="divide-y divide-gray-100">
              <li
                v-for="p in filasRecursos"
                :key="p.id"
                class="grid grid-cols-2 gap-x-3 gap-y-2.5 px-5 py-3.5 md:grid-cols-[minmax(0,2.2fr)_8.5rem_9.5rem_6.5rem_7rem] md:items-center"
                :class="p.estado === 'devuelto' ? 'bg-gray-50/50' : ''"
              >
                <div class="col-span-2 flex min-w-0 items-center gap-3 md:col-span-1">
                  <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="itemPrestamo(p.tipo_item).fondo">
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
              <li v-if="!filasRecursos.length" class="px-5 py-6 text-center text-sm text-gray-400">No tiene recursos de apoyo prestados.</li>
            </ul>
            <div v-if="recursosDevueltos" class="border-t border-gray-100 bg-gray-50/60 px-5 py-3 text-sm">
              <button type="button" class="font-semibold text-indigo-700 hover:underline" @click="mostrarRecursosDevueltos = !mostrarRecursosDevueltos">
                {{ mostrarRecursosDevueltos ? 'Ocultar devueltos' : `Ver devueltos (${recursosDevueltos})` }}
              </button>
            </div>
          </template>
        </template>
      </div>

      <div
        v-if="prestamoPendiente"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
        @click.self="prestamoPendiente = null"
      >
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
          <h3 class="mb-1 text-lg font-bold text-gray-900">¿Confirmar préstamo?</h3>
          <p class="mb-6 text-sm text-gray-500">
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
          <p class="mb-6 text-sm text-gray-500">
            Se registrará la devolución de <strong class="font-mono">{{ devolucionPendiente.libro_titulo }}</strong>.
          </p>
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
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
          <h3 class="mb-1 text-lg font-bold text-gray-900">¿Confirmar préstamo?</h3>
          <p class="text-sm text-gray-500" :class="prestamoRecursoPendiente.categoria.indicaciones ? 'mb-3' : 'mb-6'">
            Se prestará {{ prestamoRecursoPendiente.inventario ?? `${prestamoRecursoPendiente.categoria.nombre.toLowerCase()}` }} (código
            <strong class="font-mono">{{ prestamoRecursoPendiente.codigo }}</strong>) a <strong>{{ usuario?.nombre }} {{ usuario?.apellido }}</strong>.
          </p>
          <p v-if="prestamoRecursoPendiente.categoria.indicaciones" class="mb-6 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
            Recuérdele: {{ prestamoRecursoPendiente.categoria.indicaciones }}.
          </p>
          <div class="flex gap-3">
            <button
              type="button"
              class="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
              :disabled="prestandoRecurso"
              @click="prestamoRecursoPendiente = null"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-indigo-700 disabled:opacity-60"
              :disabled="prestandoRecurso"
              @click="confirmarPrestamoRecurso"
            >
              {{ prestandoRecurso ? 'Confirmando…' : 'Sí, prestar' }}
            </button>
          </div>
        </div>
      </div>

      <datalist v-for="c in categoriasActivas" :id="`recursos-${c.clave}`" :key="c.clave">
        <option v-for="e in libresDe(c.clave)" :key="e.id" :value="e.codigo_barras">{{ e.codigo_inventario }}</option>
      </datalist>
    </div>
  </StaffLayout>
</template>
