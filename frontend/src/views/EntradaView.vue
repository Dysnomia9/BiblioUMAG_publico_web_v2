<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import GuiaUso from '@/components/ui/GuiaUso.vue'
import Modal from '@/components/ui/Modal.vue'
import PildoraHorario from '@/components/portal/PildoraHorario.vue'
import SelectorDocumento from '@/components/ui/SelectorDocumento.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { formatRut, validarRut } from '@/composables/useRut'
import { describirDocumento, errorDocumento, formatearInputIdentificador, formatearSegunModo, largoSegunModo, modoDe, placeholderSegunModo, TIPOS_DOCUMENTO, type TipoDocumento } from '@/utils/documento'
import { useModoDocumento } from '@/composables/useModoDocumento'
import { useRefrescoPeriodico } from '@/composables/useRefrescoPeriodico'
import { useAuthStore } from '@/stores/auth'
import { contieneCadaPalabra, sinTildes } from '@/utils/texto'
import RenovarCuenta from '@/components/usuarios/RenovarCuenta.vue'
import UsuariosMeson from '@/components/usuarios/UsuariosMeson.vue'
import type { Entrada, HorarioBiblioteca, RespuestaEntradas, Usuario } from '@/types'

const toast = useToast()
const auth = useAuthStore()
const router = useRouter()

// La opción Convenio está oculta por ahora (botón, etiqueta y ventana); el backend la sigue aceptando.
const MOSTRAR_CONVENIO = false

const hoy = new Date().toLocaleDateString('sv-SE')

const rut = ref('')
// RUT, o pasaporte/DNI de un estudiante extranjero: lo marca quien atiende.
const { modo: modoRut, escribir: escribirRut, error: errorDelRut } = useModoDocumento(rut)
const rutInput = ref<HTMLInputElement | null>(null)
const errorRut = ref('')
// La persona existe pero su cuenta no sirve (vencida o desactivada): se ofrece renovarla ahí mismo.
const cuentaInactiva = ref<Pick<Usuario, 'id' | 'nombre' | 'apellido' | 'vigente_hasta' | 'inactividad'> | null>(null)
const registrando = ref(false)
const guiaAbierta = ref(false)
// Línea morada en el borde superior del registro y de la lista de entradas.
const FRANJA = 'linear-gradient(90deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%)'

const entradas = ref<Entrada[]>([])
const resumenHoy = ref<RespuestaEntradas['hoy']>({ personas: 0, entradas: 0, qr: 0, portal: 0, manuales: 0, sala: 0 })
// Aviso (no error) cuando la persona ya estaba presente hoy: no se registra dos veces.
const yaPresente = ref('')
const horario = ref<HorarioBiblioteca | null>(null)
const apiError = ref(false)
const cargando = ref(true)

// "dia": las entradas de un día (lo de todos los días en el mesón). "busqueda": todo el
// historial por nombre/RUT y rango de fechas, para saber cuándo vino alguien.
const modo = ref<'dia' | 'busqueda'>('dia')
const fecha = ref(hoy)
const desde = ref('')
const hasta = ref('')
const busqueda = ref('')
const via = ref<'todas' | Entrada['via'] | 'externo'>('todas')

const VIAS = [
  { id: 'todas', texto: 'Todas' },
  { id: 'manual', texto: 'Manual' },
  { id: 'portal', texto: 'Portal digital' },
  { id: 'qr', texto: 'QR' },
  { id: 'sala', texto: 'Llegada a sala' },
  { id: 'externo', texto: 'Externo' },
] as const

/** Manual: la registró el personal en el mesón. Portal digital: la persona, con su RUT en el portal. */
const ETIQUETA_VIA: Record<Entrada['via'], { texto: string; clase: string }> = {
  manual: { texto: 'Manual', clase: 'bg-slate-100 text-slate-700 ring-slate-200' },
  portal: { texto: 'Portal digital', clase: 'bg-sky-50 text-sky-700 ring-sky-100' },
  qr: { texto: 'QR', clase: 'bg-violet-50 text-violet-700 ring-violet-100' },
  sala: { texto: 'Llegada a sala', clase: 'bg-amber-50 text-amber-800 ring-amber-100' },
}
const etiquetaVia = (e: Entrada) => ETIQUETA_VIA[e.via] ?? ETIQUETA_VIA.manual

const hayCriterioDeBusqueda = computed(() => !!(desde.value || hasta.value || busqueda.value.trim()))

async function cargar() {
  if (modo.value === 'busqueda' && !hayCriterioDeBusqueda.value) {
    entradas.value = []
    cargando.value = false
    return
  }
  const texto = busqueda.value.trim()
  const params =
    modo.value === 'busqueda'
      ? { desde: desde.value || undefined, hasta: hasta.value || undefined, q: texto ? (validarRut(texto) ? formatRut(texto) : texto) : undefined }
      : { fecha: fecha.value }
  try {
    const { data } = await api.get<RespuestaEntradas>('/entrada', { params })
    entradas.value = data.entradas
    resumenHoy.value = data.hoy
    horario.value = data.horario
    apiError.value = false
  } catch {
    apiError.value = true
    entradas.value = []
  } finally {
    cargando.value = false
  }
}

onMounted(() => {
  cargar()
  rutInput.value?.focus()
  document.addEventListener('click', alHacerClic)
})
onBeforeUnmount(() => document.removeEventListener('click', alHacerClic))
useRefrescoPeriodico(cargar, 30000)
watch(fecha, cargar)

let busquedaTimer: ReturnType<typeof setTimeout> | undefined
watch([busqueda, desde, hasta], () => {
  if (modo.value !== 'busqueda') return
  clearTimeout(busquedaTimer)
  busquedaTimer = setTimeout(cargar, 300)
})

function buscarEnTodo() {
  modo.value = 'busqueda'
  cargar()
}

function volverAlDia() {
  modo.value = 'dia'
  desde.value = ''
  hasta.value = ''
  busqueda.value = ''
  if (fecha.value !== hoy) fecha.value = hoy
  else cargar()
}

// ---------- Registrar ----------
function onRutInput(event: Event) {
  escribirRut(event)
  errorRut.value = ''
  yaPresente.value = ''
  cuentaInactiva.value = null
}

/** Ya renovada la cuenta, se registra la entrada que había quedado pendiente. */
function entradaTrasRenovar() {
  cuentaInactiva.value = null
  errorRut.value = ''
  registrarEntrada()
}

async function registrarEntrada() {
  const error = errorDelRut()
  if (error) {
    errorRut.value = error
    return
  }

  registrando.value = true
  errorRut.value = ''
  yaPresente.value = ''
  cuentaInactiva.value = null
  try {
    const { data } = await api.post('/entrada', { rut: rut.value, via: 'manual' })
    toast.success(`Entrada registrada: ${data.usuario.nombre} ${data.usuario.apellido}`)
    rut.value = ''
    modoRut.value = 'rut'
    rutInput.value?.focus()
    await cargar()
  } catch (e: any) {
    if (e?.response?.status === 409 && e.response.data?.entrada) {
      // Ya estaba presente hoy: no es un error de quien atiende, solo se avisa.
      yaPresente.value = e.response.data.message
      rut.value = ''
      modoRut.value = 'rut'
      rutInput.value?.focus()
      return
    }
    if (e?.response?.status === 403 && e.response.data?.usuario) {
      cuentaInactiva.value = e.response.data.usuario
      return
    }
    errorRut.value =
      e?.response?.status === 404
        ? `No hay ningún usuario registrado con ese ${modoRut.value === 'rut' ? 'RUT' : 'pasaporte o DNI'}. Si no es de la universidad, regístrelo con «Registrar visitante externo».`
        : mensajeDeError(e, 'No se pudo registrar la entrada')
  } finally {
    registrando.value = false
  }
}

// "Externo" y "Visita" comparten el mismo formulario: solo cambia el endpoint, y la
// visita queda marcada aparte en los reportes (delegaciones, colegios, etc.).
const externoModalOpen = ref(false)
const tipoExterno = ref<'externo' | 'visita'>('externo')
const rutExterno = ref('')
const nombreExterno = ref('')
// Extranjeros: pasaporte o el documento de su país (DNI argentino, cédula…).
const documentoExterno = ref<TipoDocumento>('rut')
const paisExterno = ref('')
// Sin documento (a veces son muchas personas y no todas lo quieren dar): se registra a todas de una vez.
const cantidadExterno = ref(1)
const sinDocumento = computed(() => !rutExterno.value.trim())
const TIPOS_INGRESO = [
  { id: 'externo' as const, titulo: 'Externo', detalle: 'Persona que no es de la universidad', tono: 'bg-indigo-50 text-indigo-700', icono: 'M15.750 6a3.750 3.750 0 11-7.500 0 3.750 3.750 0 017.500 0zM4.500 20.118a7.500 7.500 0 0115 0A17.933 17.933 0 0112 21.750c-2.676 0-5.216-.584-7.500-1.632z' },
  { id: 'visita' as const, titulo: 'Visita', detalle: 'Delegación o colegio; va aparte en reportes', tono: 'bg-emerald-50 text-emerald-700', icono: 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.750 0zm8.250 2.250a2.625 2.625 0 11-5.250 0 2.625 2.625 0 015.250 0z' },
]

function cambiarCantidadExterno(paso: 1 | -1) {
  cantidadExterno.value = Math.min(200, Math.max(1, (Number(cantidadExterno.value) || 1) + paso))
}
const registrandoExterno = ref(false)

function abrirModalExterno() {
  rutExterno.value = ''
  nombreExterno.value = ''
  documentoExterno.value = 'rut'
  paisExterno.value = ''
  cantidadExterno.value = 1
  tipoExterno.value = 'externo'
  externoModalOpen.value = true
}

function elegirDocumentoExterno(tipo: TipoDocumento) {
  documentoExterno.value = tipo
  rutExterno.value = ''
}

async function registrarExterno() {
  const error = sinDocumento.value ? '' : errorDocumento(rutExterno.value, documentoExterno.value)
  if (error) {
    toast.error(error)
    return
  }

  registrandoExterno.value = true
  try {
    const cantidad = sinDocumento.value ? Math.min(200, Math.max(1, Math.floor(Number(cantidadExterno.value)) || 1)) : 1
    await api.post(tipoExterno.value === 'visita' ? '/entrada/visita' : '/entrada/externo', {
      rut: sinDocumento.value ? undefined : rutExterno.value,
      tipo_documento: sinDocumento.value ? undefined : documentoExterno.value,
      pais: sinDocumento.value || documentoExterno.value === 'rut' ? undefined : paisExterno.value.trim() || undefined,
      nombre: nombreExterno.value.trim() || undefined,
      cantidad: sinDocumento.value ? cantidad : undefined,
    })
    const quien = tipoExterno.value === 'visita' ? 'visita' : 'externo'
    toast.success(cantidad > 1 ? `Entradas registradas: ${cantidad} personas (${quien}, sin documento)` : `Entrada de ${quien} registrada${sinDocumento.value ? ' (sin documento)' : ''}`)
    externoModalOpen.value = false
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo registrar la entrada'))
  } finally {
    registrandoExterno.value = false
  }
}

const convenioModalOpen = ref(false)
const rutConvenio = ref('')
const nombreConvenio = ref('')
const registrandoConvenio = ref(false)

function abrirModalConvenio() {
  rutConvenio.value = ''
  nombreConvenio.value = ''
  convenioModalOpen.value = true
}

async function registrarConvenio() {
  if (!rutConvenio.value.trim()) {
    toast.error('Ingrese el RUT de la persona de convenio')
    return
  }

  registrandoConvenio.value = true
  try {
    await api.post('/entrada/convenio', { rut: rutConvenio.value, nombre: nombreConvenio.value.trim() || undefined })
    toast.success('Entrada de convenio registrada')
    convenioModalOpen.value = false
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo registrar la entrada'))
  } finally {
    registrandoConvenio.value = false
  }
}

// ---------- Listado ----------
const TIPOS: Record<string, { texto: string; badge: string; avatar: string }> = {
  estudiante: { texto: 'Estudiante', badge: 'bg-sky-50 text-sky-700 ring-sky-100', avatar: 'bg-sky-50 text-sky-700' },
  docente: { texto: 'Docente', badge: 'bg-emerald-50 text-emerald-700 ring-emerald-100', avatar: 'bg-emerald-50 text-emerald-700' },
  funcionario: { texto: 'Funcionario', badge: 'bg-indigo-50 text-indigo-700 ring-indigo-100', avatar: 'bg-indigo-50 text-indigo-700' },
  externo: { texto: 'Externo', badge: 'bg-stone-100 text-stone-700 ring-stone-200', avatar: 'bg-stone-100 text-stone-600' },
  visita: { texto: 'Visita', badge: 'bg-teal-50 text-teal-700 ring-teal-100', avatar: 'bg-teal-50 text-teal-700' },
  convenio: { texto: 'Convenio', badge: 'bg-cyan-50 text-cyan-700 ring-cyan-100', avatar: 'bg-cyan-50 text-cyan-700' },
}

function tipo(e: Entrada) {
  if (e.usuario) return TIPOS[e.usuario.tipo] ?? TIPOS.estudiante
  if (MOSTRAR_CONVENIO && e.es_convenio) return TIPOS.convenio
  return e.es_visita ? TIPOS.visita : TIPOS.externo
}

const nombre = (e: Entrada) => (e.usuario ? `${e.usuario.nombre} ${e.usuario.apellido}` : e.nombre_externo || 'Sin nombre')
const rutDe = (e: Entrada) => e.usuario?.rut ?? e.rut_externo ?? '—'
/** El RUT, o «Pasaporte FT482913 · Brasil» si es un documento extranjero. */
const documentoDe = (e: Entrada) =>
  e.usuario ? describirDocumento(e.usuario.rut, e.usuario.tipo_documento, e.usuario.pais) : e.rut_externo ? describirDocumento(e.rut_externo, e.tipo_documento_externo, e.pais_externo) : 'Sin documento'
const hora = (e: Entrada) => new Date(e.fecha_hora_entrada).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false })
const fechaCorta = (e: Entrada) => new Date(e.fecha_hora_entrada).toLocaleDateString('es-CL')

function iniciales(e: Entrada) {
  const partes = nombre(e).split(/\s+/).filter(Boolean)
  return ((partes[0]?.[0] ?? '') + (partes.length > 1 ? partes[partes.length - 1][0] : '')).toUpperCase() || '—'
}

/** Clave de una persona: su cuenta o, si viene de afuera, el RUT que declaró. */
const persona = (e: Entrada) => (e.usuario_id ? `u${e.usuario_id}` : e.rut_externo ? `x${e.rut_externo}` : `a${e.id}`)

// Una entrada a pocos minutos de la anterior de la misma persona suele ser un escaneo repetido.
const MINUTOS_REPETIDA = 10

/** Para cada entrada del día: la cuántas es de esa persona y cuánto pasó desde la anterior. */
const ordenDelDia = computed(() => {
  const mapa = new Map<number, { orden: number; minutos: number | null }>()
  if (modo.value !== 'dia') return mapa
  const ultima = new Map<string, { orden: number; instante: number }>()
  for (const e of [...entradas.value].sort((a, b) => a.fecha_hora_entrada.localeCompare(b.fecha_hora_entrada))) {
    const instante = new Date(e.fecha_hora_entrada).getTime()
    const previa = ultima.get(persona(e))
    const orden = (previa?.orden ?? 0) + 1
    mapa.set(e.id, { orden, minutos: previa ? Math.floor((instante - previa.instante) / 60000) : null })
    ultima.set(persona(e), { orden, instante })
  }
  return mapa
})

function nota(e: Entrada) {
  const n = ordenDelDia.value.get(e.id)
  if (!n) return { texto: '', clase: '' }
  const cuando = fecha.value === hoy ? 'hoy' : 'del día'
  if (n.orden === 1) return { texto: `Primera entrada ${cuando}`, clase: 'text-gray-500' }
  const texto = `${n.orden}.ª entrada ${cuando}`
  if (n.minutos !== null && n.minutos <= MINUTOS_REPETIDA) {
    return { texto: `${texto} · ${n.minutos < 1 ? 'segundos' : `${n.minutos} min`} después de la anterior`, clase: 'font-medium text-amber-700' }
  }
  return { texto, clase: 'text-gray-500' }
}

const soloDigitos = (t: string) => t.replace(/[^0-9kK]/g, '').toLowerCase()

function coincide(e: Entrada) {
  if (via.value === 'externo' && e.usuario_id) return false
  if (via.value !== 'todas' && via.value !== 'externo' && e.via !== via.value) return false
  // En la búsqueda en todo el historial, el texto ya lo filtró el servidor.
  const q = sinTildes(busqueda.value.trim())
  if (!q || modo.value === 'busqueda') return true
  const digitos = soloDigitos(busqueda.value)
  return contieneCadaPalabra(nombre(e), q) || (digitos.length >= 3 && soloDigitos(rutDe(e)).includes(digitos))
}

const filtradas = computed(() => entradas.value.filter(coincide))

const POR_PAGINA = 10
const pagina = ref(1)
const totalPaginas = computed(() => Math.max(1, Math.ceil(filtradas.value.length / POR_PAGINA)))
const visibles = computed(() => filtradas.value.slice((pagina.value - 1) * POR_PAGINA, pagina.value * POR_PAGINA))
/** Hasta cinco números de página, alrededor de la actual. */
const paginas = computed(() => {
  const inicio = Math.max(1, Math.min(pagina.value - 2, totalPaginas.value - 4))
  return Array.from({ length: Math.min(5, totalPaginas.value) }, (_, i) => inicio + i)
})
watch([busqueda, via, fecha, modo], () => (pagina.value = 1))
watch(totalPaginas, (total) => {
  if (pagina.value > total) pagina.value = total
})

const titulo = computed(() => (modo.value === 'busqueda' ? 'Buscar en el historial' : fecha.value === hoy ? 'Entradas de hoy' : 'Entradas del día'))

const etiquetaFecha = computed(() => {
  const d = new Date(`${fecha.value}T12:00:00`)
  const dia = d.toLocaleDateString('es-CL', { weekday: 'long' })
  return `${dia.charAt(0).toUpperCase()}${dia.slice(1)} ${d.toLocaleDateString('es-CL')}`
})

function moverDia(dias: number) {
  const d = new Date(`${fecha.value}T12:00:00`)
  d.setDate(d.getDate() + dias)
  const nueva = d.toLocaleDateString('sv-SE')
  if (nueva <= hoy) fecha.value = nueva
}

const calendario = ref<HTMLInputElement | null>(null)
function abrirCalendario() {
  try {
    calendario.value?.showPicker()
  } catch {
    calendario.value?.focus()
  }
}

// ---------- Más acciones de una fila ----------
const menuAbierto = ref<number | null>(null)
function alHacerClic(e: MouseEvent) {
  if (menuAbierto.value !== null && !(e.target as HTMLElement).closest('[data-menu-entrada]')) menuAbierto.value = null
}

const puedeVerPrestamos = computed(() => auth.puede('prestamos.gestionar'))

// Crear o corregir un usuario sin salir del mesón (por ejemplo, si no aparece o cambió su nombre).
const puedeRegistrarUsuarios = computed(() => auth.puede('usuarios.registrar') || auth.puede('usuarios.gestionar'))
const usuariosMeson = ref<InstanceType<typeof UsuariosMeson> | null>(null)

/** Recién registrado: queda escrito en la casilla, listo para marcar su entrada. */
function alGuardarUsuario(u: Usuario, nuevo: boolean) {
  if (!nuevo) return
  modoRut.value = modoDe(u.tipo_documento)
  rut.value = formatearSegunModo(u.rut, modoRut.value)
  errorRut.value = ''
  cuentaInactiva.value = null
  nextTick(() => rutInput.value?.focus())
}

function verSusEntradas(e: Entrada) {
  menuAbierto.value = null
  busqueda.value = rutDe(e)
  desde.value = ''
  hasta.value = ''
  via.value = 'todas'
  modo.value = 'busqueda'
  cargar()
}

function verSusPrestamos(e: Entrada) {
  if (e.usuario) router.push({ name: 'prestamo', query: { rut: e.usuario.rut } })
}
</script>

<template>
  <StaffLayout>
    <div class="mx-auto max-w-6xl">
      <GuiaUso
        v-model:abierta="guiaAbierta"
        :con-boton="false"
        :pasos="[
          'Pida el <strong>carnet o RUT</strong> a la persona y escríbalo en la casilla de <strong>Registro de entrada</strong>. Si es extranjera, sirve su <strong>pasaporte o DNI</strong>.',
          'Presione <strong>Enter</strong> o <strong>Registrar</strong>: queda registrada con la hora y su nombre como quien la atendió. Cada persona queda presente <strong>una sola vez por día</strong>: si ya había entrado, el sistema lo avisa y no la registra de nuevo.',
          'Si no es de la universidad, use <strong>Registrar visitante externo</strong> (con RUT, pasaporte o DNI). Ahí mismo puede marcarla como <strong>Visita</strong> (delegaciones, colegios).',
          'Abajo están las entradas del día: cambie de día con las flechas y filtre por nombre, RUT o vía. Para saber cuándo vino alguien, escriba su nombre o RUT y pulse <strong>Buscar en todas las fechas</strong>.',
        ]"
        consejo="Los usuarios también pueden marcar su entrada solos desde el portal, con el <strong>código QR de acceso</strong> o con su RUT: esas aparecen como <strong>QR</strong> y <strong>Portal digital</strong>, y las del mesón como <strong>Manual</strong>. Al confirmar la llegada de un grupo a una sala reservada, quienes no habían entrado quedan presentes solos (<strong>Llegada a sala</strong>)."
      />

      <ApiErrorBanner v-if="apiError" />

      <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
        <!-- Registrar -->
        <section class="flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div class="h-1 shrink-0" :style="{ background: FRANJA }" aria-hidden="true"></div>
          <div class="flex flex-1 flex-col justify-center p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="flex items-stretch gap-3">
              <span class="w-1 shrink-0 rounded-full bg-indigo-600" aria-hidden="true"></span>
              <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-500">Control de acceso</p>
                <h1 class="font-serif text-lg font-bold leading-tight tracking-tight text-gray-900">Registro de entrada</h1>
              </div>
            </div>
            <button
              type="button"
              class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 transition-colors hover:bg-indigo-100"
              :aria-expanded="guiaAbierta"
              @click="guiaAbierta = !guiaAbierta"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
              </svg>
              ¿Cómo se usa?
            </button>
          </div>
          <!-- El registro, en un recuadro suave -->
          <div class="mt-3 rounded-xl border border-indigo-100 bg-indigo-50/40 p-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <label for="rut-entrada" class="block text-xs text-gray-500">
              {{ modoRut === 'rut' ? 'Ingresa el RUT para registrar el acceso.' : 'Ingresa el pasaporte o DNI del estudiante extranjero.' }}
            </label>
            <SelectorDocumento v-model="modoRut" />
          </div>
          <div class="mt-2 flex flex-col gap-2 sm:flex-row">
            <div class="relative flex-1">
              <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z" />
              </svg>
              <input
                id="rut-entrada"
                ref="rutInput"
                :value="rut"
                type="text"
                autocomplete="off"
                :placeholder="placeholderSegunModo(modoRut)"
                :maxlength="largoSegunModo(modoRut)"
                class="h-10 w-full rounded-xl border-2 bg-white pl-10 pr-4 text-sm font-medium tracking-wide text-gray-900 outline-none transition placeholder:font-normal placeholder:text-gray-400 focus:ring-4"
                :class="errorRut ? 'border-red-400 focus:ring-red-100' : 'border-indigo-200 focus:border-indigo-500 focus:ring-indigo-100'"
                @input="onRutInput"
                @keydown.enter="registrarEntrada"
              />
            </div>
            <button
              type="button"
              class="flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl px-4 text-sm font-semibold text-white shadow-sm transition hover:brightness-110 disabled:opacity-60"
              style="background: linear-gradient(135deg, #4338CA 0%, #4F46E5 100%);"
              :disabled="registrando"
              @click="registrarEntrada"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
              </svg>
              {{ registrando ? 'Registrando…' : 'Registrar' }}
            </button>
          </div>
          <RenovarCuenta v-if="cuentaInactiva" class="mt-3" :usuario="cuentaInactiva" @renovada="entradaTrasRenovar" />
          <p v-if="errorRut" class="mt-2.5 text-sm text-red-600" role="alert">{{ errorRut }}</p>
          <p v-else-if="yaPresente" class="mt-2.5 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200" role="status">{{ yaPresente }}</p>
          </div>
          <!-- Lo que no es marcar la entrada de un usuario registrado: una píldora con sus botones. -->
          <p class="mt-2.5 text-center text-xs text-gray-500">¿No lo encuentra o no está registrado? Regístrelo o corrija sus datos aquí:</p>
          <div class="mt-1.5 flex justify-center">
            <div class="inline-flex flex-wrap items-stretch justify-center divide-x divide-gray-200 overflow-hidden rounded-full border border-gray-200 bg-white text-xs font-semibold shadow-sm">
              <button type="button" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-indigo-700 transition-colors hover:bg-indigo-50" @click="abrirModalExterno">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />
                </svg>
                Registrar visitante externo
              </button>
              <button v-if="puedeRegistrarUsuarios" type="button" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-indigo-700 transition-colors hover:bg-indigo-50" @click="usuariosMeson?.abrir(rut)">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z" />
                </svg>
                Crear o corregir usuario
              </button>
              <button v-if="MOSTRAR_CONVENIO" type="button" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-sky-700 transition-colors hover:bg-sky-50" @click="abrirModalConvenio">
                Convenio
              </button>
            </div>
          </div>
          </div>
        </section>

        <!-- Hoy -->
        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div class="h-1 bg-emerald-400" aria-hidden="true"></div>
          <div class="p-3.5">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-600">Hoy en la biblioteca</p>
            <PildoraHorario v-if="horario" :horario="horario" />
          </div>
          <div class="mt-2 flex items-end justify-between gap-3">
            <div class="flex items-center gap-2.5">
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l3 3m0 0l-3 3m3-3H2.25" />
                </svg>
              </span>
              <p>
                <span class="font-serif text-2xl font-bold tabular-nums text-gray-900">{{ resumenHoy.entradas }}</span>
                <span class="ml-1 text-sm text-gray-500">{{ resumenHoy.entradas === 1 ? 'ingreso' : 'ingresos' }}</span>
              </p>
            </div>
          </div>
          <!-- Sin aforo: no se registra la salida, así que no se sabe cuántos siguen adentro. -->
          <p class="mt-1 text-[11px] text-gray-500">
            Ingresos totales de hoy<template v-if="resumenHoy.personas !== resumenHoy.entradas"> · {{ resumenHoy.personas }} {{ resumenHoy.personas === 1 ? 'persona distinta' : 'personas distintas' }}</template>.
          </p>
          <div class="mt-2 grid grid-cols-2 gap-1.5">
            <div
              v-for="k in [
                { valor: resumenHoy.sala, texto: 'Por llegada a sala', tono: 'bg-indigo-50 text-indigo-600', icono: 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z' },
                { valor: resumenHoy.qr, texto: 'Por QR', tono: 'bg-violet-50 text-violet-600', icono: 'M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5zM6.75 6.75h.75v.75h-.75v-.75zM6.75 16.5h.75v.75h-.75v-.75zM16.5 6.75h.75v.75h-.75v-.75zM13.5 13.5h.75v.75h-.75v-.75zM13.5 19.5h.75v.75h-.75v-.75zM19.5 13.5h.75v.75h-.75v-.75zM19.5 19.5h.75v.75h-.75v-.75zM16.5 16.5h.75v.75h-.75v-.75z' },
                { valor: resumenHoy.portal, texto: 'Portal digital', tono: 'bg-sky-50 text-sky-600', icono: 'M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3' },
                { valor: resumenHoy.manuales, texto: 'Manuales', tono: 'bg-amber-50 text-amber-600', icono: 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10' },
              ]"
              :key="k.texto"
              class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-2 py-1"
            >
              <span class="hidden h-6 w-6 shrink-0 items-center justify-center rounded-md sm:flex" :class="k.tono">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="k.icono" />
                </svg>
              </span>
              <span class="min-w-0">
                <span class="block text-sm font-bold tabular-nums leading-tight text-gray-900">{{ k.valor }}</span>
                <span class="block truncate text-[11px] leading-tight text-gray-500">{{ k.texto }}</span>
              </span>
            </div>
          </div>
          </div>
        </section>
      </div>

      <!-- Entradas: línea morada, encabezado en lavanda con el día, el buscador y la vía -->
      <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="h-1" :style="{ background: FRANJA }" aria-hidden="true"></div>
        <div class="flex flex-col gap-2 border-b border-indigo-100/70 bg-indigo-50/50 px-4 py-2.5 xl:flex-row xl:items-center xl:justify-between">
          <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <h2 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
              <span class="h-5 w-1 shrink-0 rounded-full bg-indigo-600" aria-hidden="true"></span>
              {{ titulo }}
            </h2>
            <div v-if="modo === 'dia'" class="flex items-center gap-0.5 rounded-lg border border-gray-200 bg-white p-0.5">
              <button
                type="button"
                class="flex h-7 w-7 items-center justify-center rounded-md text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900"
                aria-label="Día anterior"
                @click="moverDia(-1)"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
              </button>
              <span class="relative">
                <button type="button" class="rounded-md px-1.5 py-0.5 text-xs font-semibold text-gray-900 transition-colors hover:bg-gray-100" title="Elegir otro día" @click="abrirCalendario">
                  {{ etiquetaFecha }}
                </button>
                <input ref="calendario" v-model="fecha" type="date" :max="hoy" class="pointer-events-none absolute inset-0 opacity-0" tabindex="-1" aria-hidden="true" />
              </span>
              <button
                type="button"
                class="flex h-7 w-7 items-center justify-center rounded-md text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 disabled:pointer-events-none disabled:opacity-30"
                aria-label="Día siguiente"
                :disabled="fecha >= hoy"
                @click="moverDia(1)"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
              </button>
              <button v-if="fecha !== hoy" type="button" class="ml-1 rounded-lg px-2 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-50" @click="fecha = hoy">Hoy</button>
            </div>
            <div v-else class="flex flex-wrap items-center gap-2 text-sm">
              <label class="flex items-center gap-1.5 text-gray-600">
                Desde
                <input v-model="desde" type="date" :max="hoy" class="rounded-lg border border-gray-300 px-2 py-1 text-sm outline-none focus:ring-2 focus:ring-indigo-500" />
              </label>
              <label class="flex items-center gap-1.5 text-gray-600">
                Hasta
                <input v-model="hasta" type="date" :max="hoy" class="rounded-lg border border-gray-300 px-2 py-1 text-sm outline-none focus:ring-2 focus:ring-indigo-500" />
              </label>
              <button type="button" class="rounded-lg px-2 py-1 text-sm font-semibold text-indigo-700 hover:bg-indigo-50" @click="volverAlDia">← Volver a hoy</button>
            </div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative sm:w-52">
              <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
              </svg>
              <input
                v-model="busqueda"
                type="text"
                placeholder="Buscar por nombre o RUT..."
                aria-label="Buscar entradas por nombre o RUT"
                class="h-9 w-full rounded-xl border border-gray-200 bg-white pl-10 pr-3 text-sm text-gray-900 outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
              />
            </div>
            <div class="flex overflow-x-auto rounded-lg border border-gray-200 bg-white p-0.5" role="group" aria-label="Vía de entrada">
              <button
                v-for="v in VIAS"
                :key="v.id"
                type="button"
                class="flex-1 whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium transition-colors"
                :class="via === v.id ? 'bg-indigo-600 font-semibold text-white shadow-sm' : 'text-gray-700 hover:bg-gray-100'"
                :aria-pressed="via === v.id"
                @click="via = v.id"
              >
                {{ v.texto }}
              </button>
            </div>
          </div>
        </div>

        <div v-if="modo === 'dia' && busqueda.trim().length >= 2" class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-100 bg-indigo-50/60 px-4 py-2.5 text-sm text-indigo-900 sm:px-6">
          <span>{{ filtradas.length ? 'Se muestran las de este día.' : `No hay entradas de «${busqueda.trim()}» este día.` }}</span>
          <button type="button" class="font-semibold text-indigo-700 hover:underline" @click="buscarEnTodo">Buscar en todas las fechas →</button>
        </div>

        <div
          class="hidden border-b border-gray-100 bg-gray-50/70 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500 lg:grid lg:gap-3"
          :class="modo === 'dia' ? 'lg:grid-cols-[4rem_minmax(0,1.7fr)_8rem_7.5rem_7.5rem_minmax(0,1.6fr)_2.25rem]' : 'lg:grid-cols-[6.5rem_minmax(0,1.7fr)_8rem_7.5rem_7.5rem_2.25rem]'"
        >
          <span>{{ modo === 'dia' ? 'Hora' : 'Fecha' }}</span>
          <span>Persona</span>
          <span>RUT</span>
          <span>Tipo</span>
          <span>Vía</span>
          <span v-if="modo === 'dia'">Nota</span>
          <span></span>
        </div>

        <ul class="divide-y divide-gray-100">
          <li v-for="(e, i) in visibles" :key="e.id" class="transition-colors hover:bg-gray-50/70">
            <!-- Computador -->
            <div
              class="hidden items-center gap-3 px-4 py-1.5 lg:grid"
              :class="modo === 'dia' ? 'lg:grid-cols-[4rem_minmax(0,1.7fr)_8rem_7.5rem_7.5rem_minmax(0,1.6fr)_2.25rem]' : 'lg:grid-cols-[6.5rem_minmax(0,1.7fr)_8rem_7.5rem_7.5rem_2.25rem]'"
            >
              <span class="text-sm tabular-nums text-gray-800">
                <template v-if="modo === 'dia'">{{ hora(e) }}</template>
                <template v-else>{{ fechaCorta(e) }} <span class="block text-xs text-gray-500">{{ hora(e) }}</span></template>
              </span>
              <span class="flex min-w-0 items-center gap-2.5">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[10px] font-bold" :class="tipo(e).avatar">{{ iniciales(e) }}</span>
                <span class="truncate text-sm font-semibold text-gray-900">{{ nombre(e) }}</span>
              </span>
              <span class="truncate text-sm tabular-nums text-gray-600">{{ documentoDe(e) }}</span>
              <span><span class="inline-block whitespace-nowrap rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset" :class="tipo(e).badge">{{ tipo(e).texto }}</span></span>
              <span>
                <span class="inline-block whitespace-nowrap rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset" :class="etiquetaVia(e).clase">{{ etiquetaVia(e).texto }}</span>
              </span>
              <span v-if="modo === 'dia'" class="truncate text-sm" :class="nota(e).clase">{{ nota(e).texto }}</span>
              <span class="relative justify-self-end" data-menu-entrada>
                <button
                  type="button"
                  class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900"
                  :aria-label="`Más acciones para ${nombre(e)}`"
                  :aria-expanded="menuAbierto === e.id"
                  @click="menuAbierto = menuAbierto === e.id ? null : e.id"
                >
                  <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
                </button>
                <div
                  v-if="menuAbierto === e.id"
                  role="menu"
                  class="absolute right-0 z-20 w-56 rounded-xl border border-gray-200 bg-white p-1.5 text-left shadow-lg"
                  :class="i >= visibles.length - 2 && visibles.length > 2 ? 'bottom-full mb-1.5' : 'top-full mt-1.5'"
                >
                  <button type="button" role="menuitem" class="w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50" @click="verSusEntradas(e)">
                    Ver todas sus entradas
                  </button>
                  <button v-if="e.usuario && puedeVerPrestamos" type="button" role="menuitem" class="w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50" @click="verSusPrestamos(e)">
                    Ver sus préstamos
                  </button>
                </div>
              </span>
            </div>

            <!-- Teléfono y tablet -->
            <div class="flex items-start gap-2.5 px-4 py-2.5 lg:hidden">
              <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold" :class="tipo(e).avatar">{{ iniciales(e) }}</span>
              <div class="min-w-0 flex-1">
                <p class="flex items-baseline justify-between gap-2">
                  <span class="truncate font-semibold text-gray-900">{{ nombre(e) }}</span>
                  <span class="shrink-0 text-sm tabular-nums text-gray-700">{{ hora(e) }}</span>
                </p>
                <p class="text-xs tabular-nums text-gray-500">{{ documentoDe(e) }}<template v-if="modo === 'busqueda'"> · {{ fechaCorta(e) }}</template></p>
                <p class="mt-1.5 flex flex-wrap items-center gap-1.5">
                  <span class="rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset" :class="tipo(e).badge">{{ tipo(e).texto }}</span>
                  <span class="whitespace-nowrap rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset" :class="etiquetaVia(e).clase">{{ etiquetaVia(e).texto }}</span>
                  <span v-if="modo === 'dia'" class="text-xs" :class="nota(e).clase">{{ nota(e).texto }}</span>
                </p>
              </div>
              <span class="relative" data-menu-entrada>
                <button
                  type="button"
                  class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100"
                  :aria-label="`Más acciones para ${nombre(e)}`"
                  :aria-expanded="menuAbierto === e.id"
                  @click="menuAbierto = menuAbierto === e.id ? null : e.id"
                >
                  <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
                </button>
                <div
                  v-if="menuAbierto === e.id"
                  role="menu"
                  class="absolute right-0 z-20 w-56 rounded-xl border border-gray-200 bg-white p-1.5 text-left shadow-lg"
                  :class="i >= visibles.length - 2 && visibles.length > 2 ? 'bottom-full mb-1.5' : 'top-full mt-1.5'"
                >
                  <button type="button" role="menuitem" class="w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50" @click="verSusEntradas(e)">
                    Ver todas sus entradas
                  </button>
                  <button v-if="e.usuario && puedeVerPrestamos" type="button" role="menuitem" class="w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50" @click="verSusPrestamos(e)">
                    Ver sus préstamos
                  </button>
                </div>
              </span>
            </div>
          </li>
          <li v-if="!cargando && !visibles.length" class="p-4 sm:p-6">
            <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-indigo-200 bg-indigo-50/30 px-4 py-5 text-center">
              <span class="flex h-11 w-11 items-center justify-center rounded-full bg-indigo-50 text-indigo-600" aria-hidden="true">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14M5 5v14" /></svg>
              </span>
              <p class="text-sm text-gray-600">
                <template v-if="modo === 'busqueda' && !hayCriterioDeBusqueda">Escriba un nombre o RUT, o elija un rango de fechas.</template>
                <template v-else-if="modo === 'busqueda'">Sin resultados para esta búsqueda.</template>
                <template v-else-if="entradas.length">Ninguna entrada coincide con el filtro.</template>
                <template v-else>Sin entradas registradas {{ fecha === hoy ? 'hoy' : 'ese día' }}.</template>
              </p>
            </div>
          </li>
        </ul>

        <div v-if="filtradas.length" class="flex flex-col gap-2 border-t border-gray-100 px-4 py-2 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-xs text-gray-500">
            Mostrando {{ (pagina - 1) * POR_PAGINA + 1 }} a {{ Math.min(pagina * POR_PAGINA, filtradas.length) }} de {{ filtradas.length }}
            {{ filtradas.length === 1 ? 'entrada' : 'entradas' }}
          </p>
          <nav v-if="totalPaginas > 1" class="flex items-center gap-1" aria-label="Páginas">
            <button
              type="button"
              class="flex h-7 w-7 items-center justify-center rounded-lg border border-gray-200 text-gray-600 transition-colors hover:bg-gray-50 disabled:opacity-40"
              aria-label="Página anterior"
              :disabled="pagina === 1"
              @click="pagina--"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
            </button>
            <button
              v-for="n in paginas"
              :key="n"
              type="button"
              class="h-7 min-w-[1.75rem] rounded-lg px-1.5 text-xs font-semibold transition-colors"
              :class="n === pagina ? 'bg-indigo-600 text-white shadow-sm' : 'border border-gray-200 text-gray-700 hover:bg-gray-50'"
              :aria-current="n === pagina ? 'page' : undefined"
              @click="pagina = n"
            >
              {{ n }}
            </button>
            <button
              type="button"
              class="flex h-7 w-7 items-center justify-center rounded-lg border border-gray-200 text-gray-600 transition-colors hover:bg-gray-50 disabled:opacity-40"
              aria-label="Página siguiente"
              :disabled="pagina === totalPaginas"
              @click="pagina++"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
            </button>
          </nav>
        </div>
      </section>
    </div>

    <Modal
      :abierto="externoModalOpen"
      :titulo="tipoExterno === 'visita' ? 'Registrar visita' : 'Registrar visitante externo'"
      descripcion="Para personas que no están en el sistema institucional. Se guarda con los datos que declaren."
      ancho="md"
      :bloqueado="registrandoExterno"
      @cerrar="externoModalOpen = false"
    >
      <template #cabecera>
        <div class="relative -mx-6 -mt-6 mb-4 flex items-center gap-3 overflow-hidden rounded-t-2xl px-6 py-4" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
          <svg class="pointer-events-none absolute -bottom-2 right-16 hidden h-[4.5rem] w-24 rotate-[-8deg] sm:block" viewBox="0 0 96 72" fill="none" stroke="rgb(255 255 255 / 0.16)" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">
            <rect x="34" y="1" width="28" height="9" rx="2.500" fill="rgb(255 255 255 / 0.06)" />
            <rect x="6" y="9" width="84" height="58" rx="6" fill="rgb(255 255 255 / 0.07)" />
            <circle cx="28" cy="31" r="8" />
            <path d="M15 58c1-10 7-15 13-15s12 5 13 15M50 28h30M50 39h24" />
          </svg>
          <span class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/25 bg-blanco/15 text-white" aria-hidden="true">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.750"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.500v3m0 0v3m0-3h3m-3 0h-3m-2.250-4.125a3.375 3.375 0 11-6.750 0 3.375 3.375 0 016.750 0zM3 19.235v-.11a6.375 6.375 0 0112.750 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z" /></svg>
          </span>
          <div class="relative min-w-0 flex-1">
            <h3 class="font-serif text-lg font-bold leading-tight text-white">{{ tipoExterno === 'visita' ? 'Registrar visita' : 'Registrar visitante externo' }}</h3>
            <p class="mt-0.5 text-xs text-white/80">Para personas que no están en el sistema institucional. Se guarda con los datos que declaren.</p>
          </div>
          <button type="button" class="relative flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-white/30 text-white transition-colors hover:bg-blanco/15 disabled:opacity-50" aria-label="Cerrar" :disabled="registrandoExterno" @click="externoModalOpen = false">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>
      </template>

      <div class="space-y-3">
        <div>
          <p class="mb-2 flex items-center gap-2 text-sm font-semibold text-gray-800"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-indigo-600 text-[11px] font-bold text-white">1</span> Tipo de ingreso</p>
          <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Tipo de ingreso">
            <button
              v-for="t in TIPOS_INGRESO"
              :key="t.id"
              type="button"
              role="radio"
              :aria-checked="tipoExterno === t.id"
              class="relative flex items-start gap-2 rounded-xl border-2 bg-white p-2.5 text-left transition-colors"
              :class="tipoExterno === t.id ? 'border-indigo-600 bg-indigo-50/60' : 'border-gray-200 hover:border-gray-300'"
              @click="tipoExterno = t.id"
            >
              <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg" :class="tipoExterno === t.id ? 'bg-indigo-600 text-white' : t.tono" aria-hidden="true">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.750"><path stroke-linecap="round" stroke-linejoin="round" :d="t.icono" /></svg>
              </span>
              <span class="min-w-0 pr-3">
                <span class="block text-sm font-bold" :class="tipoExterno === t.id ? 'text-indigo-700' : 'text-gray-900'">{{ t.titulo }}</span>
                <span class="block text-[11px] leading-snug text-gray-500">{{ t.detalle }}</span>
              </span>
              <span class="absolute right-2 top-2 flex h-4 w-4 items-center justify-center rounded-full" :class="tipoExterno === t.id ? 'bg-indigo-600 text-white' : 'border-2 border-gray-300'" aria-hidden="true">
                <svg v-if="tipoExterno === t.id" class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="4"><path stroke-linecap="round" stroke-linejoin="round" d="M4.500 12.750l6 6 9-13.500" /></svg>
              </span>
            </button>
          </div>
        </div>

        <div class="space-y-2.5 rounded-xl border border-gray-100 bg-gray-50 p-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="flex items-center gap-2 text-sm font-semibold text-gray-800">
              <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-indigo-600 text-[11px] font-bold text-white">2</span> Identificación
              <span class="rounded-full border border-gray-200 bg-white px-2 text-[11px] font-medium text-gray-500">opcional</span>
            </p>
            <div class="flex gap-0.5 rounded-lg border border-gray-200 bg-white p-0.5" role="radiogroup" aria-label="Tipo de documento">
              <button
                v-for="tipo in (['rut', 'pasaporte', 'dni'] as const)"
                :key="tipo"
                type="button"
                role="radio"
                :aria-checked="documentoExterno === tipo"
                class="whitespace-nowrap rounded-md px-2 py-1 text-[11px] font-semibold transition-colors"
                :class="documentoExterno === tipo ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900'"
                @click="elegirDocumentoExterno(tipo)"
              >
                {{ tipo === 'dni' ? 'DNI / cédula' : TIPOS_DOCUMENTO[tipo] }}
              </button>
            </div>
          </div>
          <div :class="documentoExterno === 'rut' ? '' : 'grid grid-cols-1 gap-2.5 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]'">
            <div>
              <label for="rut-externo" class="mb-1 block text-xs font-medium text-gray-700">
                {{ documentoExterno === 'rut' ? 'RUT' : documentoExterno === 'pasaporte' ? 'N.º de pasaporte' : 'N.º de documento' }}
              </label>
              <input
                id="rut-externo"
                :value="rutExterno"
                type="text"
                :placeholder="documentoExterno === 'rut' ? '12.345.678-5' : documentoExterno === 'pasaporte' ? 'Ej: FT482913' : 'Ej: 38456123'"
                :maxlength="documentoExterno === 'rut' ? 12 : 20"
                class="w-full rounded-lg border border-gray-200 bg-white py-2 outline-none focus:ring-2 focus:ring-indigo-500 text-sm px-3 font-mono"
                @input="rutExterno = formatearInputIdentificador($event, documentoExterno)"
                @keydown.enter="registrarExterno"
              />
            </div>
            <div v-if="documentoExterno !== 'rut'">
              <label for="pais-externo" class="mb-1 block text-xs font-medium text-gray-700">País</label>
              <input
                id="pais-externo"
                v-model="paisExterno"
                type="text"
                placeholder="Ej: Argentina"
                maxlength="100"
                class="w-full rounded-lg border border-gray-200 bg-white py-2 outline-none focus:ring-2 focus:ring-indigo-500 text-sm px-3"
                @keydown.enter="registrarExterno"
              />
            </div>
          </div>
          <div>
            <label for="nombre-externo" class="mb-1 block text-xs font-medium text-gray-700">Nombre</label>
            <input
              id="nombre-externo"
              v-model="nombreExterno"
              type="text"
              placeholder="Nombre del visitante"
              class="w-full rounded-lg border border-gray-200 bg-white py-2 outline-none focus:ring-2 focus:ring-indigo-500 text-sm px-3"
              @keydown.enter="registrarExterno"
            />
          </div>
        </div>

        <div v-if="sinDocumento" class="rounded-xl border border-amber-200 bg-amber-50 p-3">
          <p class="text-xs text-amber-800">Sin documento queda anotada como «Sin documento»: cuenta como una persona más en el día, pero no se puede reconocer si vuelve a entrar.</p>
          <div class="mt-2 flex items-center justify-between gap-2 rounded-lg border border-amber-100 bg-white px-3 py-1.5">
            <label for="cantidad-externo" class="text-sm font-semibold text-gray-800">Cantidad de personas</label>
            <div class="flex items-center gap-1.5">
              <button type="button" class="flex h-7 w-7 items-center justify-center rounded-lg border border-amber-300 bg-white text-amber-800 transition-colors hover:bg-amber-50" aria-label="Una persona menos" @click="cambiarCantidadExterno(-1)">−</button>
              <input
                id="cantidad-externo"
                v-model.number="cantidadExterno"
                type="number"
                min="1"
                max="200"
                inputmode="numeric"
                class="h-7 w-12 rounded-lg border border-amber-200 bg-white text-center text-sm font-bold outline-none [appearance:textfield] focus:ring-2 focus:ring-indigo-500 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                @keydown.enter="registrarExterno"
              />
              <button type="button" class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#b08d57] text-white transition hover:brightness-110" aria-label="Una persona más" @click="cambiarCantidadExterno(1)">+</button>
            </div>
          </div>
        </div>
      </div>
      <template #pie>
        <button
          type="button"
          class="flex-1 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-800 shadow-sm transition-colors hover:bg-gray-100"
          :disabled="registrandoExterno"
          @click="externoModalOpen = false"
        >
          Cancelar
        </button>
        <button
          type="button"
          class="flex flex-1 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:opacity-60"
          :disabled="registrandoExterno"
          @click="registrarExterno"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14M5 5v14" /></svg>
          {{ registrandoExterno ? 'Registrando…' : 'Registrar entrada' }}
        </button>
      </template>
    </Modal>

    <Modal
      v-if="MOSTRAR_CONVENIO"
      :abierto="convenioModalOpen"
      titulo="Registrar persona de convenio"
      descripcion="Para personas cubiertas por un convenio institucional que no están en el sistema."
      :bloqueado="registrandoConvenio"
      @cerrar="convenioModalOpen = false"
    >
      <div class="space-y-3">
        <div>
          <label for="rut-convenio" class="mb-1 block text-sm font-medium text-gray-700">RUT</label>
          <input
            id="rut-convenio"
            :value="rutConvenio"
            type="text"
            placeholder="12.345.678-5"
            maxlength="12"
            class="w-full rounded-lg border border-gray-300 px-4 py-2.5 outline-none focus:ring-2 focus:ring-sky-500"
            @input="rutConvenio = formatearInputIdentificador($event)"
            @keydown.enter="registrarConvenio"
          />
        </div>
        <div>
          <label for="nombre-convenio" class="mb-1 block text-sm font-medium text-gray-700">Nombre (opcional)</label>
          <input
            id="nombre-convenio"
            v-model="nombreConvenio"
            type="text"
            placeholder="Nombre (opcional)"
            class="w-full rounded-lg border border-gray-300 px-4 py-2.5 outline-none focus:ring-2 focus:ring-sky-500"
            @keydown.enter="registrarConvenio"
          />
        </div>
      </div>
      <template #pie>
        <button
          type="button"
          class="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
          @click="convenioModalOpen = false"
        >
          Cancelar
        </button>
        <button
          type="button"
          class="flex-1 rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-sky-700 disabled:opacity-60"
          :disabled="registrandoConvenio"
          @click="registrarConvenio"
        >
          {{ registrandoConvenio ? 'Registrando…' : 'Registrar' }}
        </button>
      </template>
    </Modal>
    <UsuariosMeson v-if="puedeRegistrarUsuarios" ref="usuariosMeson" @guardado="alGuardarUsuario" />
  </StaffLayout>
</template>
