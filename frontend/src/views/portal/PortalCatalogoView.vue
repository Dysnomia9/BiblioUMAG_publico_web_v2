<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PortalLayout from '@/components/layout/PortalLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import FichaBibliografica from '@/components/libros/FichaBibliografica.vue'
import CopiasPortal from '@/components/portal/CopiasPortal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import Modal from '@/components/ui/Modal.vue'
import SelectorBuscable from '@/components/ui/SelectorBuscable.vue'
import apiUsuario from '@/services/apiUsuario'
import { esBusquedaCorta, tituloAlternativoEncontrado } from '@/utils/busqueda'
import { useToast } from '@/composables/useToast'
import PortadaMaterial from '@/components/libros/PortadaMaterial.vue'
import type { CondicionesReservaLibro, LibroCatalogo, Paginado, ReservaLibro } from '@/types'

const router = useRouter()
const route = useRoute()
const toast = useToast()

const busqueda = ref(typeof route.query.q === 'string' ? route.query.q : '')
const libros = ref<LibroCatalogo[]>([])
const fichaAbierta = ref<number | null>(null)
const apiError = ref(false)
const cargando = ref(false)

const misReservas = ref<ReservaLibro[]>([])
const reservandoId = ref<number | null>(null)
const cancelandoId = ref<number | null>(null)

const reservaBadges: Record<string, { label: string; cls: string }> = {
  pendiente: { label: 'Lista para retirar', cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  en_cola: { label: 'En lista de espera', cls: 'bg-sky-50 text-sky-700 border-sky-200' },
}

// De a 24 títulos: «Ver más títulos» suma la página siguiente. «Solo disponibles» y el
// área se filtran en el servidor, así que valen para todo el catálogo y no solo lo cargado.
const POR_PAGINA = 24
const pagina = ref(1)
const ultimaPagina = ref(1)
const total = ref(0)
/** Títulos de todo el catálogo (la primera carga, sin búsqueda ni filtros), para la cabecera. */
const totalCatalogo = ref(0)

const parametros = (numero: number) => ({
  q: busqueda.value || undefined,
  disponibles: soloDisponibles.value ? 1 : undefined,
  categoria_id: areaFiltro.value || undefined,
  page: numero,
  por_pagina: POR_PAGINA,
})

// Al escribir salen varias búsquedas seguidas: si no se cancela la anterior, su respuesta
// puede llegar última y reemplazar la lista de la búsqueda nueva (el «tirón»).
let busquedaEnCurso: AbortController | undefined
/** Lo buscado en la lista que se muestra (no lo que se está escribiendo), para el 246 que coincidió. */
const busquedaHecha = ref('')

async function cargar(agregar = false) {
  busquedaEnCurso?.abort()
  const peticion = new AbortController()
  busquedaEnCurso = peticion
  cargando.value = true
  try {
    const { data } = await apiUsuario.get<Paginado<LibroCatalogo>>('/mi/catalogo', {
      signal: peticion.signal,
      params: parametros(agregar ? pagina.value + 1 : 1),
    })
    busquedaHecha.value = busqueda.value
    libros.value = agregar ? [...libros.value, ...data.data] : data.data
    pagina.value = data.current_page
    ultimaPagina.value = data.last_page
    total.value = data.total
    if (!busqueda.value && !soloDisponibles.value && !areaFiltro.value) totalCatalogo.value = data.total
    apiError.value = false
  } catch {
    if (peticion.signal.aborted) return
    apiError.value = true
    if (!agregar) libros.value = []
  } finally {
    if (busquedaEnCurso === peticion) cargando.value = false
  }
}

/** Tras reservar: vuelve a pedir las páginas ya vistas, sin perder dónde se estaba. */
async function refrescar() {
  try {
    const paginas = await Promise.all(
      Array.from({ length: pagina.value }, (_, i) => apiUsuario.get<Paginado<LibroCatalogo>>('/mi/catalogo', { params: parametros(i + 1) })),
    )
    libros.value = paginas.flatMap(({ data }) => data.data)
    total.value = paginas[0]?.data.total ?? total.value
  } catch {
    // Si falla, queda lo que se veía; la próxima búsqueda lo actualiza.
  }
}

async function cargarMisReservas() {
  try {
    const { data } = await apiUsuario.get<ReservaLibro[]>('/mi/reservas-libro')
    misReservas.value = data
  } catch {
    misReservas.value = []
  }
}

function reservaDe(libro: LibroCatalogo) {
  return misReservas.value.find((r) => r.libro_id === libro.id)
}

const condiciones = ref<CondicionesReservaLibro | null>(null)
// Con multas pendientes no se reserva nada: se apagan los botones (el backend lo rechaza igual).
const bloqueo = computed(() => condiciones.value?.bloqueo ?? null)
// Tras cancelar, unos minutos sin poder pedir otro libro (config/reservas_libro.php).
const espera = computed(() => condiciones.value?.espera ?? null)
/** La espera tras cancelar, en dos partes: la hora en que termina (título) y el motivo. */
const horaFinEspera = computed(() => {
  const hasta = condiciones.value?.espera_hasta
  return hasta ? new Date(hasta).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' }) : null
})
const textoEspera = computed(() => {
  const texto = espera.value ?? ''
  const corte = texto.indexOf(': podrás')
  return horaFinEspera.value && corte > 0 ? `${texto.slice(0, corte)}.` : texto
})
/** Por qué no se puede reservar ahora (multas o la espera tras cancelar), o null. */
const motivoSinReservar = computed(() => bloqueo.value ?? espera.value)

// Cumplida la espera, se vuelven a pedir las condiciones y los botones se reactivan solos.
let finEspera: ReturnType<typeof setTimeout> | undefined
watch(condiciones, (c) => {
  clearTimeout(finEspera)
  if (!c?.espera_hasta) return
  finEspera = setTimeout(cargarCondiciones, Math.max(0, new Date(c.espera_hasta).getTime() - Date.now()) + 1000)
})
onUnmounted(() => clearTimeout(finEspera))

async function cargarCondiciones() {
  try {
    const { data } = await apiUsuario.get<CondicionesReservaLibro>('/mi/reservas-libro/condiciones')
    condiciones.value = data
  } catch {
    // Sin condiciones, el diálogo muestra el texto genérico; el servidor igual aplica los máximos.
  }
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined
watch(busqueda, () => {
  clearTimeout(debounceTimer)
  // 1 o 2 letras recorren todo el catálogo: se busca al seguir escribiendo
  if (esBusquedaCorta(busqueda.value)) return
  debounceTimer = setTimeout(() => cargar(), 350)
})
onUnmounted(() => clearTimeout(debounceTimer))

onMounted(() => {
  cargar()
  cargarMisReservas()
  cargarCondiciones()
})

function hayDisponible(libro: LibroCatalogo) {
  return (libro.ejemplares_disponibles ?? 0) > 0
}

// --- Filtros y presentación de cada fila ---
const soloDisponibles = ref(false)
const areaFiltro = ref<number | ''>('')
watch([soloDisponibles, areaFiltro], () => cargar())

// Las áreas con algo en estante, de todo el catálogo: se buscan en el servidor a medida
// que se escribe, porque con el catálogo real son miles.
async function buscarAreas(texto: string) {
  const { data } = await apiUsuario.get<{ id: number; nombre: string }[]>('/mi/catalogo/areas', { params: { q: texto || undefined } })
  return data
}

function fechaLarga(fecha: string) {
  return new Date(`${fecha}T12:00:00`).toLocaleDateString('es-CL', { day: 'numeric', month: 'long' })
}

/** «lunes 5 de octubre». Una fecha sola (AAAA-MM-DD) se lee a mediodía para que la zona horaria no la corra de día. */
function fechaConDia(fecha: string) {
  return new Date(fecha.length === 10 ? `${fecha}T12:00:00` : fecha).toLocaleDateString('es-CL', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  })
}

function estadoLibro(libro: LibroCatalogo) {
  const reserva = reservaDe(libro)
  if (reserva?.estado === 'pendiente') {
    return { texto: 'Lista para retirar', detalle: 'Reservado para ti', punto: 'bg-violet-500', color: 'text-violet-700', pildora: 'bg-violet-50 text-violet-700' }
  }
  if (reserva?.estado === 'en_cola') {
    return {
      texto: 'En lista de espera',
      detalle: reserva.posicion ? `Lugar #${reserva.posicion} en la fila` : 'Te avisaremos cuando vuelva',
      punto: 'bg-sky-500',
      color: 'text-sky-700',
      pildora: 'bg-sky-50 text-sky-700',
    }
  }
  if (libro.digital) {
    return { texto: 'Libro electrónico', detalle: 'Se lee en línea, sin reserva', punto: 'bg-violet-500', color: 'text-violet-700', pildora: 'bg-violet-50 text-violet-700' }
  }
  if (hayDisponible(libro)) {
    const n = libro.ejemplares_disponibles
    return { texto: 'Disponible', detalle: `${n} ${n === 1 ? 'ejemplar' : 'ejemplares'} en estantería`, punto: 'bg-emerald-500', color: 'text-emerald-700', pildora: 'bg-emerald-50 text-emerald-700' }
  }
  // Sin préstamo activo, la copia está apartada para otra persona o fuera de circulación.
  if (!libro.proxima_devolucion) {
    return { texto: 'No disponible', detalle: 'Sin copias disponibles por ahora', punto: 'bg-amber-500', color: 'text-amber-700', pildora: 'bg-amber-50 text-amber-800' }
  }
  const hoy = new Date().toLocaleDateString('sv-SE')
  const detalle = libro.proxima_devolucion >= hoy
    ? `Vuelve aprox. el ${fechaLarga(libro.proxima_devolucion)}`
    : `Debía volver el ${fechaLarga(libro.proxima_devolucion)}`
  return { texto: 'Prestado', detalle, punto: 'bg-amber-500', color: 'text-amber-700', pildora: 'bg-amber-50 text-amber-800' }
}

const filas = computed(() =>
  libros.value.map((libro) => ({
      libro,
      reserva: reservaDe(libro),
      estado: estadoLibro(libro),
      detalle: [libro.tipo_material, libro.categorias?.map((c) => c.nombre).join(', ')].filter(Boolean).join(' · '),
      areas: libro.categorias?.map((c) => c.nombre).join(', ') ?? '',
      alternativo: tituloAlternativoEncontrado(libro, busquedaHecha.value),
    })),
)

// "Ver ficha": en el teléfono abre una ventana con el resumen del título; desde sm se
// despliega dentro de la lista.
type FilaCatalogo = (typeof filas.value)[number]
const filaFicha = ref<FilaCatalogo | null>(null)

function verFicha(fila: FilaCatalogo) {
  if (window.matchMedia('(min-width: 640px)').matches) {
    fichaAbierta.value = fichaAbierta.value === fila.libro.id ? null : fila.libro.id
  } else {
    filaFicha.value = fila
  }
}

function reservarDesdeFicha() {
  if (!filaFicha.value) return
  libroPorConfirmar.value = filaFicha.value.libro
  filaFicha.value = null
}

// Reservar o unirse a la espera siempre pasa por una confirmación.
const libroPorConfirmar = ref<LibroCatalogo | null>(null)

// Obra en varios tomos: al reservar se elige el tomo (la fila espera una copia de ese tomo).
const tomoElegido = ref('')
const tomosDe = (libro: LibroCatalogo | null) => (libro?.tomos?.length ?? 0) > 1 ? libro!.tomos! : []
const tomoActual = computed(() => tomosDe(libroPorConfirmar.value).find((t) => t.tomo === tomoElegido.value) ?? null)
/** Si hay copia libre para lo que se va a reservar: del tomo elegido o, sin tomos, del título. */
const disponibleParaReservar = computed(() =>
  !libroPorConfirmar.value ? false : tomoActual.value ? tomoActual.value.disponibles > 0 : hayDisponible(libroPorConfirmar.value),
)

// Al abrir el diálogo se vuelve a pedir el plazo, por si la página quedó abierta de un día para otro.
watch(libroPorConfirmar, (libro) => {
  tomoElegido.value = ''
  if (libro) cargarCondiciones()
})

/** Por qué no se puede confirmar la reserva del diálogo (multas o máximos de config/reservas_libro.php), o null. */
const limiteAlcanzado = computed(() => {
  const libro = libroPorConfirmar.value
  if (!libro || !condiciones.value) return null
  if (motivoSinReservar.value) return motivoSinReservar.value
  const maximo = condiciones.value.maximo_por_persona
  if (misReservas.value.filter((r) => r.estado === 'pendiente' || r.estado === 'en_cola').length < maximo) return null
  return maximo === 1
    ? 'Ya tienes una reserva de libro activa (por retirar o en lista de espera). Retírala o cancélala en «Mis reservas» para reservar este.'
    : `Ya tienes ${maximo} reservas de libros activas (por retirar o en lista de espera), el máximo a la vez. Retira o cancela una en «Mis reservas» para reservar este.`
})

async function confirmarReserva() {
  if (!libroPorConfirmar.value) return
  await reservar(libroPorConfirmar.value, tomoElegido.value || null)
  libroPorConfirmar.value = null
}

async function reservar(libro: LibroCatalogo, tomo: string | null = null) {
  reservandoId.value = libro.id
  try {
    const { data } = await apiUsuario.post<ReservaLibro>('/mi/reservas-libro', { libro_id: libro.id, tomo })
    toast.success(
      data.estado === 'pendiente' && data.fecha_retiro
        ? `Reservaste "${libro.titulo}". Retíralo hasta el ${fechaConDia(data.fecha_retiro)}.`
        : `Te avisaremos cuando vuelva "${libro.titulo}"`,
    )
    await Promise.all([refrescar(), cargarMisReservas()])
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo registrar la reserva')
    // Canceló hace poco (quizás en otra pestaña): se trae la hora desde la que puede pedir.
    if (e?.response?.status === 429) cargarCondiciones()
  } finally {
    reservandoId.value = null
  }
}

// Cancelar o salir de la fila también pide confirmación.
const reservaPorCancelar = ref<ReservaLibro | null>(null)

async function confirmarCancelacion() {
  if (!reservaPorCancelar.value) return
  await cancelar(reservaPorCancelar.value)
  reservaPorCancelar.value = null
}

async function cancelar(reserva: ReservaLibro) {
  cancelandoId.value = reserva.id
  try {
    await apiUsuario.patch(`/mi/reservas-libro/${reserva.id}/cancelar`)
    toast.success(reserva.estado === 'en_cola' ? 'Saliste de la lista de espera' : 'Reserva cancelada')
    await Promise.all([refrescar(), cargarMisReservas(), cargarCondiciones()])
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo cancelar la reserva')
  } finally {
    cancelandoId.value = null
  }
}
</script>

<template>
  <PortalLayout>
    <div class="mx-auto max-w-2xl lg:max-w-6xl">
      <!-- Cabecera: el degradado morado de la portada con trama de puntos, brillos y un estante
           de libros dibujado a la derecha. -->
      <section
        class="cabecera-catalogo relative mb-4 overflow-hidden rounded-2xl px-5 py-5 text-white shadow-lg shadow-indigo-900/25 ring-1 ring-inset ring-white/10 sm:px-7 sm:py-6"
      >
        <span class="pointer-events-none absolute -left-16 -top-20 h-56 w-56 rounded-full bg-fuchsia-400/20 blur-3xl" aria-hidden="true"></span>
        <span class="pointer-events-none absolute -bottom-24 right-24 h-56 w-72 rounded-full bg-sky-300/20 blur-3xl" aria-hidden="true"></span>
        <span class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/40 to-transparent" aria-hidden="true"></span>

        <div class="relative sm:pr-64">
          <button
            type="button"
            class="md:hidden mb-2 flex items-center gap-1 text-xs font-medium text-indigo-100 hover:text-white"
            @click="router.push({ name: 'portal-home' })"
          >
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
            Volver al inicio
          </button>
          <p class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-blanco/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-widest text-indigo-100">
            <svg class="h-3 w-3 text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
            Biblioteca UMAG · En línea
          </p>
          <h1 class="mt-2 font-serif text-2xl font-bold tracking-tight text-white sm:text-3xl">Catálogo</h1>
          <p class="mt-1 max-w-xl text-sm text-indigo-100">
            Busca libros y revistas, revisa su disponibilidad y resérvalos<span class="hidden sm:inline"> para retirar en el mesón</span>.
          </p>
          <ul class="mt-3.5 flex flex-wrap gap-1.5 text-[11px] font-semibold text-white/90">
            <li v-if="totalCatalogo" class="inline-flex items-center gap-1.5 rounded-lg border border-white/15 bg-blanco/10 px-2 py-1 backdrop-blur-sm">
              <svg class="h-3.5 w-3.5 text-indigo-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v12a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18V6zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v12A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18V6z" /></svg>
              {{ totalCatalogo.toLocaleString('es-CL') }} títulos
            </li>
            <li class="inline-flex items-center gap-1.5 rounded-lg border border-white/15 bg-blanco/10 px-2 py-1 backdrop-blur-sm">
              <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_0_3px_rgb(52_211_153/0.25)]" aria-hidden="true"></span>
              Reserva y retira en el mesón
            </li>
            <li class="hidden items-center gap-1.5 rounded-lg border border-white/15 bg-blanco/10 px-2 py-1 backdrop-blur-sm sm:inline-flex">
              <svg class="h-3.5 w-3.5 text-violet-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" /></svg>
              Libros electrónicos sin reserva
            </li>
          </ul>
        </div>

        <!-- Estante: lomos con bandas doradas, uno inclinado, un libro acostado y la lupa. -->
        <svg class="estante pointer-events-none absolute bottom-0 right-6 hidden h-[7.5rem] w-60 sm:block" viewBox="0 0 240 120" fill="none" aria-hidden="true">
          <defs>
            <linearGradient id="lomo-cat" x1="0" y1="0" x2="1" y2="0">
              <stop offset="0" stop-color="#ffffff" stop-opacity="0.22" />
              <stop offset="1" stop-color="#ffffff" stop-opacity="0.06" />
            </linearGradient>
          </defs>
          <g stroke="#ffffff" stroke-opacity="0.35" stroke-width="1">
            <rect x="14" y="40" width="15" height="66" rx="2" fill="url(#lomo-cat)" />
            <rect x="31" y="26" width="18" height="80" rx="2" fill="url(#lomo-cat)" />
            <rect x="51" y="48" width="12" height="58" rx="2" fill="url(#lomo-cat)" />
            <rect x="65" y="20" width="16" height="86" rx="2" fill="url(#lomo-cat)" />
            <rect x="83" y="36" width="14" height="70" rx="2" fill="url(#lomo-cat)" />
            <rect x="104" y="30" width="15" height="76" rx="2" fill="url(#lomo-cat)" transform="rotate(-12 111 106)" />
            <rect x="128" y="44" width="13" height="62" rx="2" fill="url(#lomo-cat)" />
            <rect x="143" y="24" width="18" height="82" rx="2" fill="url(#lomo-cat)" />
            <rect x="163" y="38" width="12" height="68" rx="2" fill="url(#lomo-cat)" />
            <!-- libro acostado con la lupa encima -->
            <rect x="182" y="94" width="46" height="12" rx="2" fill="url(#lomo-cat)" />
            <rect x="186" y="84" width="38" height="10" rx="2" fill="url(#lomo-cat)" />
          </g>
          <!-- bandas doradas y títulos de los lomos -->
          <g stroke="#fcd34d" stroke-opacity="0.75" stroke-width="1.6" stroke-linecap="round">
            <path d="M17 50h9M17 54h9M34 36h12M34 92h12M68 30h10M68 34h10M86 96h8M146 34h12M146 38h12M146 96h12M131 54h7M166 48h6" />
          </g>
          <g stroke="#ffffff" stroke-opacity="0.45" stroke-width="1.4" stroke-linecap="round">
            <path d="M40 48v30M73 44v34M151 48v34M57 60v20M90 50v24M168 56v22M135 64v20" />
          </g>
          <path d="M193 100h32M196 89h22" stroke="#fcd34d" stroke-opacity="0.6" stroke-width="1.2" stroke-linecap="round" />
          <g class="lupa" stroke="#ffffff" stroke-width="2.4" stroke-linecap="round">
            <circle cx="208" cy="62" r="11" fill="#ffffff" fill-opacity="0.12" stroke-opacity="0.85" />
            <path d="M216 70l8 8" stroke-opacity="0.85" />
            <path d="M203 57a6 6 0 016-2" stroke-opacity="0.6" stroke-width="1.6" />
          </g>
          <!-- repisa -->
          <rect x="4" y="106" width="232" height="5" rx="2.5" fill="#ffffff" fill-opacity="0.28" />
          <rect x="10" y="111" width="220" height="5" rx="2" fill="#1e1b4b" fill-opacity="0.25" />
          <!-- destellos -->
          <g fill="#fde68a">
            <path d="M118 10l1.6 3.4L123 15l-3.4 1.6L118 20l-1.6-3.4L113 15l3.4-1.6z" fill-opacity="0.8" />
            <circle cx="96" cy="14" r="1.5" fill-opacity="0.6" />
            <circle cx="186" cy="30" r="1.3" fill-opacity="0.5" />
          </g>
        </svg>
      </section>

      <ApiErrorBanner v-if="apiError" />

      <div class="mb-3 flex items-center gap-3 rounded-2xl border border-gray-200 bg-white py-2 pl-4 pr-2 shadow-md shadow-indigo-900/5 focus-within:ring-2 focus-within:ring-indigo-500">
        <svg class="h-5 w-5 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
        </svg>
        <input
          v-model="busqueda"
          type="text"
          aria-label="Buscar en el catálogo"
          placeholder="Título, autor, área o ISBN"
          class="min-w-0 flex-1 border-0 bg-transparent py-1.5 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-0"
        />
        <span class="shrink-0 whitespace-nowrap rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700" aria-live="polite">
          {{ cargando && !libros.length ? 'Buscando…' : `${total.toLocaleString('es-CL')} ${total === 1 ? 'resultado' : 'resultados'}` }}
        </span>
      </div>

      <div class="mb-4 flex flex-wrap items-center gap-2">
        <button
          type="button"
          @click="soloDisponibles = false"
          :aria-pressed="!soloDisponibles"
          class="rounded-full border px-4 py-1.5 text-xs font-semibold transition-colors"
          :class="!soloDisponibles ? 'border-indigo-600 bg-indigo-600 text-white shadow-sm shadow-indigo-900/20' : 'border-gray-200 bg-white text-gray-700 shadow-sm hover:border-indigo-300'"
        >
          Todos
        </button>
        <button
          type="button"
          @click="soloDisponibles = true"
          :aria-pressed="soloDisponibles"
          class="inline-flex items-center gap-1.5 rounded-full border px-4 py-1.5 text-xs font-semibold transition-colors"
          :class="soloDisponibles ? 'border-indigo-600 bg-indigo-600 text-white shadow-sm shadow-indigo-900/20' : 'border-gray-200 bg-white text-gray-700 shadow-sm hover:border-indigo-300'"
        >
          <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
          Disponibles<span class="hidden sm:inline"> ahora</span>
        </button>
        <div class="flex items-center gap-2">
          <span class="mx-1 hidden h-5 w-px bg-gray-300 sm:block" aria-hidden="true"></span>
          <label for="filtro-area" class="hidden text-xs text-gray-600 sm:block">Área</label>
          <SelectorBuscable
            v-model="areaFiltro"
            id-campo="filtro-area"
            :buscar="buscarAreas"
            todas="Todas las áreas"
            placeholder="Todas las áreas"
            clase-campo="w-44 rounded-full border border-gray-200 bg-white py-1.5 pl-3.5 pr-3 text-xs text-gray-800 shadow-sm placeholder:text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>
      </div>

      <div v-if="bloqueo" class="mb-4 flex items-start gap-2.5 rounded-xl border border-l-4 border-red-200 border-l-red-500 bg-red-50 px-4 py-3 text-sm text-red-800">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
        </svg>
        <p>{{ bloqueo }}</p>
      </div>
      <div v-else-if="espera" class="mb-4 flex items-start gap-2.5 rounded-xl border border-l-4 border-amber-200 border-l-amber-400 bg-amber-50 px-4 py-3 text-amber-900">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <div class="min-w-0">
          <p v-if="horaFinEspera" class="text-sm font-bold">Podrás reservar de nuevo a las <span class="font-mono">{{ horaFinEspera }}</span></p>
          <p class="text-xs leading-relaxed" :class="horaFinEspera ? 'mt-0.5' : ''">{{ textoEspera }}</p>
        </div>
      </div>

      <!-- En celular, "Mis reservas" va sobre la lista; en pantallas grandes, a la derecha. -->
      <div class="flex flex-col lg:grid lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start lg:gap-5">
        <aside class="mb-4 space-y-4 lg:sticky lg:top-6 lg:col-start-2 lg:row-start-1 lg:mb-0" :class="misReservas.length ? '' : 'hidden lg:block'">
          <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-md shadow-indigo-900/5">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
              <h2 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
                <span class="h-4 w-1 rounded-full bg-violet-500" aria-hidden="true"></span>
                Mis reservas
              </h2>
              <span
                v-if="misReservas.length"
                class="flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-violet-50 px-1.5 text-[11px] font-bold text-violet-700"
              >
                {{ misReservas.length }}
              </span>
            </div>
            <div class="space-y-2.5 p-3">
              <div
                v-for="r in misReservas"
                :key="r.id"
                class="rounded-xl border border-l-4 border-gray-200 bg-gradient-to-r from-violet-50/70 to-white px-3.5 py-3"
                :class="r.estado === 'en_cola' ? 'border-l-sky-500' : 'border-l-violet-500'"
              >
                <div class="flex items-start justify-between gap-2">
                  <p class="min-w-0 text-sm font-bold text-gray-900">{{ r.libro?.titulo }}<span v-if="r.tomo" class="ml-1.5 rounded bg-indigo-50 px-1.5 py-0.5 text-xs font-semibold text-indigo-700">{{ r.tomo }}</span></p>
                  <span class="shrink-0 whitespace-nowrap rounded-full border px-2 py-0.5 text-[11px] font-semibold" :class="reservaBadges[r.estado]?.cls">
                    {{ reservaBadges[r.estado]?.label }}
                  </span>
                </div>
                <p class="mt-1.5 text-xs leading-relaxed text-gray-600">
                  <template v-if="r.estado === 'pendiente'">
                    Retira en el mesón de préstamo<template v-if="r.fecha_retiro"> hasta el <strong class="font-semibold text-gray-900">{{ fechaConDia(r.fecha_retiro) }}</strong></template>.
                  </template>
                  <template v-else>
                    <template v-if="r.posicion">Lugar #{{ r.posicion }} en la fila.</template>
                    <template v-if="r.proxima_fecha_devolucion"> Una copia debería volver el {{ new Date(r.proxima_fecha_devolucion).toLocaleDateString('es-CL') }}.</template>
                  </template>
                </p>
                <button
                  @click="reservaPorCancelar = r"
                  :disabled="cancelandoId === r.id"
                  class="mt-2.5 rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-600 transition-colors hover:bg-red-50 disabled:opacity-50"
                >
                  {{ r.estado === 'en_cola' ? 'Salir de la fila' : 'Cancelar reserva' }}
                </button>
              </div>
              <p v-if="!misReservas.length" class="px-1 py-2 text-sm text-gray-500">
                Todavía no tienes reservas. Reserva un libro disponible para retirarlo en el mesón, o pide que te avisemos
                cuando vuelva uno prestado.
              </p>
            </div>
          </div>

          <div class="rounded-2xl border border-amber-200/80 bg-gradient-to-b from-amber-50 to-amber-50/40 px-4 py-4">
            <p class="font-serif text-base font-bold text-amber-950">¿Cómo retiro un libro?</p>
            <ol class="mt-2.5 space-y-2 text-xs leading-relaxed text-amber-950/80">
              <li class="flex gap-2.5">
                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-amber-300 bg-white text-[10px] font-bold text-amber-800">1</span>
                <span>Espera a que tu reserva diga <strong class="text-amber-950">«Lista para retirar»</strong>.</span>
              </li>
              <li class="flex gap-2.5">
                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-amber-300 bg-white text-[10px] font-bold text-amber-800">2</span>
                <span>Pasa al mesón de préstamo con tu RUT dentro del plazo indicado.</span>
              </li>
            </ol>
            <p v-if="condiciones" class="mt-3 border-t border-dashed border-amber-300 pt-2.5 text-[11px] leading-relaxed text-amber-800">
              <template v-if="condiciones.maximo_por_persona === 1">Puedes tener una reserva de libro a la vez: por retirar o en lista de espera.</template>
              <template v-else>Puedes tener hasta {{ condiciones.maximo_por_persona }} reservas de libros a la vez, sumando las por retirar y las listas de espera.</template>
            </p>
          </div>
        </aside>

        <div class="min-w-0 lg:col-start-1 lg:row-start-1">
          <div class="space-y-3 sm:space-y-0 sm:divide-y sm:divide-gray-100 sm:overflow-hidden sm:rounded-2xl sm:border sm:border-gray-200 sm:bg-white sm:shadow-md sm:shadow-indigo-900/5">
            <div
              v-for="fila in filas"
              :key="fila.libro.id"
              class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-md shadow-indigo-900/5 sm:rounded-none sm:border-0 sm:shadow-none"
              :class="fila.reserva ? 'sm:bg-violet-50/50' : 'sm:even:bg-gray-50/40'"
            >
              <div class="sm:grid sm:grid-cols-[minmax(0,1fr)_11rem_9.5rem] sm:items-center sm:gap-5 sm:px-5 sm:py-3.5">
                <div class="flex min-w-0 items-start gap-3.5 p-4 sm:p-0">
                  <span class="shrink-0 drop-shadow-[0_2px_3px_rgb(30_27_75/0.12)]">
                    <PortadaMaterial :titulo="fila.libro.titulo" :tipo="fila.libro.tipo_material" :clave-tono="fila.libro.categorias?.[0]?.nombre" />
                  </span>
                  <div class="min-w-0">
                    <p class="text-[15px] font-bold leading-snug text-gray-900 sm:text-sm">{{ fila.libro.titulo }}</p>
                    <p v-if="fila.alternativo" class="mt-0.5 text-xs leading-snug text-gray-600">También titulado: <span class="font-medium text-gray-800">{{ fila.alternativo }}</span></p>
                    <p v-if="fila.libro.autores?.length" class="mt-0.5 truncate text-xs text-gray-600">{{ fila.libro.autores.map((a) => a.nombre).join(' · ') }}</p>
                    <p class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[11px] text-gray-500">
                      <span v-if="fila.libro.tipo_material" class="rounded-md border border-gray-200 bg-gray-50 px-1.5 py-px text-[11px] font-semibold text-gray-700">{{ fila.libro.tipo_material }}</span>
                      <span v-if="fila.areas" class="uppercase tracking-wide">{{ fila.areas }}</span>
                      <button
                        class="font-bold text-indigo-700 hover:underline"
                        :aria-expanded="fichaAbierta === fila.libro.id"
                        @click="verFicha(fila)"
                      >
                        {{ fichaAbierta === fila.libro.id ? 'Ocultar ficha' : 'Ver ficha' }} ›
                      </button>
                    </p>
                  </div>
                </div>
                <div class="flex items-center justify-between gap-3 border-t border-gray-100 bg-gray-50/70 px-4 py-3 sm:contents">
                  <div class="min-w-0">
                    <span class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold" :class="fila.estado.pildora">
                      {{ fila.estado.texto }}
                    </span>
                    <p class="mt-1 text-xs text-gray-500">{{ fila.estado.detalle }}</p>
                  </div>
                  <div class="shrink-0 sm:text-right">
                    <a
                      v-if="fila.libro.digital"
                      :href="fila.libro.url_acceso ?? undefined"
                      target="_blank"
                      rel="noopener"
                      class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.18)] transition-colors hover:bg-emerald-700 sm:w-full"
                    >
                      Leer en línea
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M9 7h8v8" /></svg>
                    </a>
                    <span v-else-if="fila.reserva" class="text-xs font-semibold text-violet-700">En tus reservas</span>
                    <button
                      v-else
                      @click="libroPorConfirmar = fila.libro"
                      :disabled="reservandoId === fila.libro.id || !!motivoSinReservar"
                      :title="motivoSinReservar ?? undefined"
                      class="whitespace-nowrap rounded-lg border px-4 py-2 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:border-indigo-200 disabled:bg-indigo-50 disabled:text-indigo-300 disabled:shadow-none sm:w-full"
                      :class="hayDisponible(fila.libro)
                        ? 'border-indigo-600 bg-indigo-600 text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] hover:bg-indigo-700'
                        : 'border-gray-300 bg-white text-gray-800 hover:bg-gray-50'"
                    >
                      {{ hayDisponible(fila.libro) ? 'Reservar' : 'Avisarme al volver' }}
                    </button>
                  </div>
                </div>
              </div>
              <div v-if="fichaAbierta === fila.libro.id" class="hidden bg-gray-50/60 px-5 pb-4 sm:block">
                <FichaBibliografica :filas="fila.libro.ficha" />
                <CopiasPortal :copias="fila.libro.copias ?? []" />
              </div>
            </div>
            <div
              v-if="!cargando && !apiError && !filas.length"
              class="rounded-2xl border border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-400 sm:rounded-none sm:border-0"
            >
              {{ soloDisponibles || areaFiltro ? 'Ningún título coincide con los filtros elegidos.' : 'Sin resultados para tu búsqueda.' }}
            </div>
            <div v-if="pagina < ultimaPagina" class="pt-4 text-center sm:py-4">
              <button
                type="button"
                class="rounded-xl border border-gray-200 bg-white px-5 py-2.5 text-sm font-semibold text-indigo-700 shadow-sm transition-colors hover:border-indigo-300 hover:bg-indigo-50 disabled:opacity-60"
                :disabled="cargando"
                @click="cargar(true)"
              >
                {{ cargando ? 'Cargando…' : `Ver más títulos (${libros.length.toLocaleString('es-CL')} de ${total.toLocaleString('es-CL')})` }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <Modal
      :abierto="filaFicha !== null"
      :titulo="filaFicha?.libro.titulo ?? ''"
      :descripcion="filaFicha?.libro.autores?.map((a) => a.nombre).join(' · ') || undefined"
      ancho="md"
      pie-fijo
      @cerrar="filaFicha = null"
    >
      <template v-if="filaFicha">
        <div class="flex items-start gap-4">
          <PortadaMaterial tamano="lg" :titulo="filaFicha.libro.titulo" :tipo="filaFicha.libro.tipo_material" :clave-tono="filaFicha.libro.categorias?.[0]?.nombre" />
          <div class="min-w-0">
            <p v-if="filaFicha.detalle" class="text-xs text-gray-500">{{ filaFicha.detalle }}</p>
            <p class="mt-2 flex items-center gap-1.5 text-sm font-semibold" :class="filaFicha.estado.color">
              <span class="h-2 w-2 shrink-0 rounded-full" :class="filaFicha.estado.punto"></span>
              {{ filaFicha.estado.texto }}
            </p>
            <p class="mt-0.5 text-xs text-gray-500">{{ filaFicha.estado.detalle }}</p>
          </div>
        </div>
        <p class="mt-5 mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">Ficha bibliográfica</p>
        <FichaBibliografica :filas="filaFicha.libro.ficha" />
        <CopiasPortal :copias="filaFicha.libro.copias ?? []" />
      </template>
      <template #pie>
        <button
          class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
          @click="filaFicha = null"
        >
          Cerrar
        </button>
        <a
          v-if="filaFicha?.libro.digital"
          :href="filaFicha.libro.url_acceso ?? undefined"
          target="_blank"
          rel="noopener"
          class="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-center text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
        >
          Leer en línea ↗
        </a>
        <p v-else-if="filaFicha?.reserva" class="flex-1 self-center text-center text-sm font-semibold text-indigo-700">En tus reservas</p>
        <button
          v-else-if="filaFicha"
          :disabled="!!motivoSinReservar"
          :title="motivoSinReservar ?? undefined"
          class="flex-1 px-4 py-2.5 rounded-lg font-semibold text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-50"
          :class="hayDisponible(filaFicha.libro) ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'bg-white text-gray-800 border border-gray-300 hover:bg-gray-50'"
          @click="reservarDesdeFicha"
        >
          {{ hayDisponible(filaFicha.libro) ? 'Reservar' : 'Avisarme al volver' }}
        </button>
      </template>
    </Modal>

    <DialogoConfirmacion
      :abierto="reservaPorCancelar !== null"
      :titulo="reservaPorCancelar?.estado === 'en_cola' ? '¿Salir de la lista de espera?' : '¿Cancelar tu reserva?'"
      :confirmar="reservaPorCancelar?.estado === 'en_cola' ? 'Sí, salir' : 'Sí, cancelar'"
      cancelar="No, mantener"
      tono="peligro"
      :cargando="cancelandoId !== null"
      @confirmar="confirmarCancelacion"
      @cancelar="reservaPorCancelar = null"
    >
      <template v-if="reservaPorCancelar">
        <p class="font-medium text-gray-900">{{ reservaPorCancelar.libro?.titulo }}</p>
        <p class="mt-3">
          <template v-if="reservaPorCancelar.estado === 'en_cola'">
            Perderás tu lugar<template v-if="reservaPorCancelar.posicion"> (#{{ reservaPorCancelar.posicion }})</template> en la
            fila. Si después te vuelves a anotar, quedarás al final.
          </template>
          <template v-else>
            La copia que estaba apartada para ti vuelve a quedar disponible para otras personas.
          </template>
        </p>
      </template>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="libroPorConfirmar !== null"
      :titulo="disponibleParaReservar ? '¿Reservar este libro?' : '¿Te avisamos cuando vuelva?'"
      confirmar="Confirmar"
      tono="primario"
      :cargando="reservandoId !== null"
      :deshabilitado="limiteAlcanzado !== null || (tomosDe(libroPorConfirmar).length > 0 && !tomoElegido)"
      @confirmar="confirmarReserva"
      @cancelar="libroPorConfirmar = null"
    >
      <template v-if="libroPorConfirmar">
        <p class="font-medium text-gray-900">{{ libroPorConfirmar.titulo }}</p>
        <p v-if="libroPorConfirmar.autores.length" class="text-xs">{{ libroPorConfirmar.autores.map((a) => a.nombre).join(' · ') }}</p>
        <div v-if="tomosDe(libroPorConfirmar).length && !limiteAlcanzado" class="mt-3">
          <p class="mb-1.5 text-xs font-semibold text-gray-700">¿Qué tomo quieres?</p>
          <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Tomo">
            <button
              v-for="t in tomosDe(libroPorConfirmar)"
              :key="t.tomo"
              type="button"
              role="radio"
              :aria-checked="tomoElegido === t.tomo"
              class="rounded-lg border px-3 py-1.5 text-left text-xs transition-colors"
              :class="tomoElegido === t.tomo ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-800 hover:bg-gray-50'"
              @click="tomoElegido = t.tomo"
            >
              <span class="block font-semibold">{{ t.tomo }}</span>
              <span class="block opacity-80">{{ t.disponibles ? `${t.disponibles} ${t.disponibles === 1 ? 'disponible' : 'disponibles'}` : 'Prestado' }}</span>
            </button>
          </div>
        </div>
        <p v-if="limiteAlcanzado" class="mt-3 flex gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-900">
          <svg class="mt-0.5 w-4 h-4 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
          </svg>
          {{ limiteAlcanzado }}
        </p>
        <template v-else-if="disponibleParaReservar">
          <p class="mt-3">
            Se apartará una copia<template v-if="tomoElegido"> del {{ tomoElegido }}</template> a tu nombre. Retírala en el mesón de préstamo con tu RUT<template v-if="condiciones">
            dentro de este plazo:</template><template v-else>, antes de la fecha límite que verás en «Mis reservas».</template>
          </p>
          <div v-if="condiciones" class="mt-3 flex items-center gap-3 rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-indigo-600 shadow-sm">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
              </svg>
            </span>
            <div>
              <p class="text-xs text-indigo-700">Plazo para retirarlo</p>
              <p class="text-sm font-semibold text-indigo-950">Hasta el {{ fechaConDia(condiciones.fecha_retiro) }}</p>
            </div>
          </div>
        </template>
        <p v-else class="mt-3">
          Todas las copias están prestadas. Quedarás en la lista de espera y, cuando llegue tu turno, se apartará una
          copia a tu nombre<template v-if="condiciones"> y tendrás {{ condiciones.dias_para_retirar }}
          {{ condiciones.dias_para_retirar === 1 ? 'día' : 'días' }} para retirarla</template>: te avisaremos por correo si
          tienes uno registrado, y lo verás en «Mis reservas».
        </p>
      </template>
    </DialogoConfirmacion>
  </PortalLayout>
</template>

<style scoped>
/* Cabecera: el degradado morado institucional con una trama de puntos que se apaga hacia la izquierda. */
.cabecera-catalogo {
  background:
    radial-gradient(circle, rgb(255 255 255 / 0.13) 1px, transparent 1.3px) 0 0 / 16px 16px,
    linear-gradient(135deg, #2d1b69 0%, #3b28a3 30%, #4338ca 60%, #4f46e5 100%);
}
.cabecera-catalogo::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, #2d1b69 0%, rgb(45 27 105 / 0.85) 30%, transparent 70%);
  opacity: 0.55;
  pointer-events: none;
}
/* La lupa se mece apenas, salvo con «reducir movimiento». */
@media (prefers-reduced-motion: no-preference) {
  .estante .lupa {
    transform-origin: 208px 62px;
    animation: mecer 5s ease-in-out infinite;
  }
}
@keyframes mecer {
  0%, 100% { transform: rotate(0deg) translateY(0); }
  50% { transform: rotate(-8deg) translateY(-2px); }
}
</style>
