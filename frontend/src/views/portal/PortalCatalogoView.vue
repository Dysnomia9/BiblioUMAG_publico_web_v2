<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PortalLayout from '@/components/layout/PortalLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import FichaBibliografica from '@/components/libros/FichaBibliografica.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import Modal from '@/components/ui/Modal.vue'
import apiUsuario from '@/services/apiUsuario'
import { useToast } from '@/composables/useToast'
import { inicialesPortada, tonoPortada } from '@/utils/portada'
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

const parametros = (numero: number) => ({
  q: busqueda.value || undefined,
  disponibles: soloDisponibles.value ? 1 : undefined,
  categoria_id: areaFiltro.value || undefined,
  page: numero,
  por_pagina: POR_PAGINA,
})

async function cargar(agregar = false) {
  cargando.value = true
  try {
    const { data } = await apiUsuario.get<Paginado<LibroCatalogo>>('/mi/catalogo', { params: parametros(agregar ? pagina.value + 1 : 1) })
    libros.value = agregar ? [...libros.value, ...data.data] : data.data
    pagina.value = data.current_page
    ultimaPagina.value = data.last_page
    total.value = data.total
    apiError.value = false
  } catch {
    apiError.value = true
    if (!agregar) libros.value = []
  } finally {
    cargando.value = false
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
  debounceTimer = setTimeout(() => cargar(), 300)
})
onUnmounted(() => clearTimeout(debounceTimer))

onMounted(() => {
  cargar()
  cargarAreas()
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

// Las áreas con algo en estante, de todo el catálogo (no solo de la página cargada).
const areas = ref<{ id: number; nombre: string }[]>([])
async function cargarAreas() {
  try {
    const { data } = await apiUsuario.get<{ id: number; nombre: string }[]>('/mi/catalogo/areas')
    areas.value = data
  } catch {
    areas.value = []
  }
}

function tonoDe(libro: LibroCatalogo) {
  return tonoPortada(libro.categorias?.[0]?.nombre ?? libro.tipo_material ?? libro.titulo)
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
    return { texto: 'Lista para retirar', detalle: 'Reservado para ti', punto: 'bg-indigo-500', color: 'text-indigo-700' }
  }
  if (reserva?.estado === 'en_cola') {
    return {
      texto: 'En lista de espera',
      detalle: reserva.posicion ? `Lugar #${reserva.posicion} en la fila` : 'Te avisaremos cuando vuelva',
      punto: 'bg-sky-500',
      color: 'text-sky-700',
    }
  }
  if (hayDisponible(libro)) {
    const n = libro.ejemplares_disponibles
    return { texto: 'Disponible', detalle: `${n} ${n === 1 ? 'ejemplar' : 'ejemplares'} en estantería`, punto: 'bg-emerald-500', color: 'text-emerald-700' }
  }
  // Sin préstamo activo, la copia está apartada para otra persona o fuera de circulación.
  if (!libro.proxima_devolucion) {
    return { texto: 'No disponible', detalle: 'Sin copias disponibles por ahora', punto: 'bg-amber-500', color: 'text-amber-700' }
  }
  const hoy = new Date().toLocaleDateString('sv-SE')
  const detalle = libro.proxima_devolucion >= hoy
    ? `Vuelve aprox. el ${fechaLarga(libro.proxima_devolucion)}`
    : `Debía volver el ${fechaLarga(libro.proxima_devolucion)}`
  return { texto: 'Prestado', detalle, punto: 'bg-amber-500', color: 'text-amber-700' }
}

const filas = computed(() =>
  libros.value.map((libro) => ({
      libro,
      reserva: reservaDe(libro),
      estado: estadoLibro(libro),
      tono: tonoDe(libro),
      iniciales: inicialesPortada(libro.titulo),
      detalle: [libro.tipo_material, libro.categorias?.map((c) => c.nombre).join(', ')].filter(Boolean).join(' · '),
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

// Al abrir el diálogo se vuelve a pedir el plazo, por si la página quedó abierta de un día para otro.
watch(libroPorConfirmar, (libro) => {
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
  await reservar(libroPorConfirmar.value)
  libroPorConfirmar.value = null
}

async function reservar(libro: LibroCatalogo) {
  reservandoId.value = libro.id
  try {
    const { data } = await apiUsuario.post<ReservaLibro>('/mi/reservas-libro', { libro_id: libro.id })
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
    <div class="max-w-2xl mx-auto lg:max-w-[1100px]">
      <button
        @click="router.push({ name: 'portal-home' })"
        class="md:hidden mb-5 flex items-center gap-2 text-sm text-gray-600 hover:text-indigo-700"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
        Volver al inicio
      </button>

      <div class="mb-5">
        <h1 class="text-2xl sm:text-3xl font-serif font-bold tracking-tight text-gray-900">Catálogo</h1>
        <p class="mt-1 text-sm text-gray-500">Busca libros y revistas, revisa su disponibilidad y resérvalos para retirar en el mesón.</p>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="relative mb-3">
        <svg class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
        </svg>
        <input
          v-model="busqueda"
          type="text"
          aria-label="Buscar en el catálogo"
          placeholder="Título, autor, área o ISBN"
          class="w-full pl-12 pr-28 py-3 bg-white border border-gray-300 rounded-xl shadow-sm text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
        />
        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-xs text-gray-500" aria-live="polite">
          {{ cargando && !libros.length ? 'Buscando…' : `${total.toLocaleString('es-CL')} ${total === 1 ? 'resultado' : 'resultados'}` }}
        </span>
      </div>

      <div class="mb-5 flex flex-wrap items-center gap-2">
        <button
          type="button"
          @click="soloDisponibles = false"
          :aria-pressed="!soloDisponibles"
          class="rounded-full border px-4 py-1.5 text-xs font-semibold transition-colors"
          :class="!soloDisponibles ? 'bg-gray-900 border-gray-900 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'"
        >
          Todos
        </button>
        <button
          type="button"
          @click="soloDisponibles = true"
          :aria-pressed="soloDisponibles"
          class="inline-flex items-center gap-1.5 rounded-full border px-4 py-1.5 text-xs font-semibold transition-colors"
          :class="soloDisponibles ? 'bg-gray-900 border-gray-900 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'"
        >
          <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
          Disponibles ahora
        </button>
        <div class="flex items-center gap-2">
          <span class="mx-1 hidden sm:block h-5 w-px bg-gray-300" aria-hidden="true"></span>
          <label for="filtro-area" class="text-xs text-gray-600">Área</label>
          <select
            id="filtro-area"
            v-model="areaFiltro"
            class="rounded-lg border border-gray-300 bg-white py-1.5 pl-3 pr-8 text-xs text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            <option value="">Todas las áreas</option>
            <option v-for="area in areas" :key="area.id" :value="area.id">{{ area.nombre }}</option>
          </select>
        </div>
      </div>

      <div v-if="bloqueo" class="mb-5 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <svg class="mt-0.5 w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
        </svg>
        <p>{{ bloqueo }}</p>
      </div>
      <div v-else-if="espera" class="mb-5 flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <svg class="mt-0.5 w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p>{{ espera }}</p>
      </div>

      <!-- En celular, "Mis reservas" va sobre la lista; en pantallas grandes, a la derecha. -->
      <div class="flex flex-col lg:grid lg:grid-cols-[minmax(0,1fr)_18rem] lg:gap-6 lg:items-start">
        <aside class="lg:col-start-2 lg:row-start-1 lg:sticky lg:top-6 mb-5 lg:mb-0 space-y-4" :class="misReservas.length ? '' : 'hidden lg:block'">
          <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-5">
            <div class="flex items-center justify-between gap-3">
              <h2 class="font-sans text-base font-bold text-gray-900">Mis reservas</h2>
              <span
                v-if="misReservas.length"
                class="flex h-6 min-w-[1.5rem] items-center justify-center rounded-full bg-indigo-50 px-2 text-xs font-semibold text-indigo-700"
              >
                {{ misReservas.length }}
              </span>
            </div>
            <div class="mt-3 border-t border-gray-100 divide-y divide-gray-100">
              <div v-for="r in misReservas" :key="r.id" class="py-3">
                <p class="text-sm font-semibold text-gray-900">{{ r.libro?.titulo }}</p>
                <span class="mt-1.5 inline-block text-[11px] font-medium px-2 py-0.5 rounded-full border" :class="reservaBadges[r.estado]?.cls">
                  {{ reservaBadges[r.estado]?.label }}
                </span>
                <p class="mt-1.5 text-xs text-gray-500">
                  <template v-if="r.estado === 'pendiente'">
                    Retira en el mesón de préstamo<template v-if="r.fecha_retiro"> hasta el {{ fechaConDia(r.fecha_retiro) }}</template>.
                  </template>
                  <template v-else>
                    <template v-if="r.posicion">Lugar #{{ r.posicion }} en la fila.</template>
                    <template v-if="r.proxima_fecha_devolucion"> Una copia debería volver el {{ new Date(r.proxima_fecha_devolucion).toLocaleDateString('es-CL') }}.</template>
                  </template>
                </p>
                <button
                  @click="reservaPorCancelar = r"
                  :disabled="cancelandoId === r.id"
                  class="mt-2.5 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition-colors disabled:opacity-50"
                >
                  {{ r.estado === 'en_cola' ? 'Salir de la fila' : 'Cancelar reserva' }}
                </button>
              </div>
              <p v-if="!misReservas.length" class="py-4 text-sm text-gray-500">
                Todavía no tienes reservas. Reserva un libro disponible para retirarlo en el mesón, o pide que te avisemos
                cuando vuelva uno prestado.
              </p>
            </div>
          </div>

          <div class="rounded-2xl bg-gray-100 px-5 py-4">
            <p class="text-sm font-semibold text-gray-900">¿Cómo retiro un libro?</p>
            <p class="mt-1 text-xs text-gray-600">
              Cuando tu reserva diga «Lista para retirar», pasa al mesón de préstamo con tu RUT dentro del plazo indicado.
            </p>
            <p v-if="condiciones" class="mt-2 text-xs text-gray-600">
              <template v-if="condiciones.maximo_por_persona === 1">Puedes tener una reserva de libro a la vez: por retirar o en lista de espera.</template>
              <template v-else>Puedes tener hasta {{ condiciones.maximo_por_persona }} reservas de libros a la vez, sumando las por retirar y las listas de espera.</template>
            </p>
          </div>
        </aside>

        <div class="lg:col-start-1 lg:row-start-1 min-w-0">
          <div class="space-y-3 sm:space-y-0 sm:bg-white sm:border sm:border-gray-200 sm:rounded-2xl sm:shadow-sm sm:overflow-hidden sm:divide-y sm:divide-gray-100">
            <div
              v-for="fila in filas"
              :key="fila.libro.id"
              class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden sm:border-0 sm:rounded-none sm:shadow-none"
              :class="fila.reserva ? 'sm:bg-indigo-50/40' : ''"
            >
              <div class="p-4 sm:px-5 sm:grid sm:grid-cols-[minmax(0,1fr)_11rem_10rem] sm:gap-5 sm:items-center">
                <div class="flex items-start gap-4 min-w-0">
                  <div
                    class="flex h-14 w-10 shrink-0 items-end rounded-sm border-l-4 px-1.5 pb-1 font-serif text-sm font-bold"
                    :class="fila.tono"
                    aria-hidden="true"
                  >
                    {{ fila.iniciales }}
                  </div>
                  <div class="min-w-0">
                    <p class="text-[15px] sm:text-sm font-semibold text-gray-900 leading-snug">{{ fila.libro.titulo }}</p>
                    <p class="mt-0.5 text-xs text-gray-600 truncate">{{ fila.libro.autores?.map((a) => a.nombre).join(', ') }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">
                      {{ fila.detalle }}<template v-if="fila.detalle"> · </template>
                      <button
                        class="font-medium text-indigo-700 hover:underline"
                        :aria-expanded="fichaAbierta === fila.libro.id"
                        @click="verFicha(fila)"
                      >
                        {{ fichaAbierta === fila.libro.id ? 'Ocultar ficha' : 'Ver ficha' }}
                      </button>
                    </p>
                  </div>
                </div>
                <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between gap-3 sm:contents">
                  <div class="min-w-0">
                    <p class="flex items-center gap-1.5 text-xs font-semibold" :class="fila.estado.color">
                      <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="fila.estado.punto"></span>
                      {{ fila.estado.texto }}
                    </p>
                    <p class="mt-0.5 text-xs text-gray-500">{{ fila.estado.detalle }}</p>
                  </div>
                  <div class="shrink-0 sm:text-center">
                    <span v-if="fila.reserva" class="text-xs font-semibold text-indigo-700">En tus reservas</span>
                    <button
                      v-else
                      @click="libroPorConfirmar = fila.libro"
                      :disabled="reservandoId === fila.libro.id || !!motivoSinReservar"
                      :title="motivoSinReservar ?? undefined"
                      class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50 whitespace-nowrap sm:w-full"
                      :class="hayDisponible(fila.libro)
                        ? 'bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm'
                        : 'bg-white text-gray-800 border border-gray-300 hover:bg-gray-50'"
                    >
                      {{ hayDisponible(fila.libro) ? 'Reservar' : 'Avisarme al volver' }}
                    </button>
                  </div>
                </div>
              </div>
              <div v-if="fichaAbierta === fila.libro.id" class="hidden sm:block px-5 pb-4 bg-gray-50/60">
                <FichaBibliografica :filas="fila.libro.ficha" />
              </div>
            </div>
            <div
              v-if="!cargando && !filas.length"
              class="px-4 py-10 text-center text-sm text-gray-400 bg-white border border-gray-200 rounded-2xl sm:border-0 sm:rounded-none"
            >
              {{ soloDisponibles || areaFiltro ? 'Ningún título coincide con los filtros elegidos.' : 'Sin resultados para tu búsqueda.' }}
            </div>
            <div v-if="pagina < ultimaPagina" class="pt-4 text-center">
              <button
                type="button"
                class="rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-800 shadow-sm transition-colors hover:bg-gray-50 disabled:opacity-60"
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
      :descripcion="filaFicha?.libro.autores?.map((a) => a.nombre).join(', ') || undefined"
      ancho="md"
      pie-fijo
      @cerrar="filaFicha = null"
    >
      <template v-if="filaFicha">
        <div class="flex items-start gap-4">
          <div
            class="flex h-20 w-14 shrink-0 items-end rounded-sm border-l-4 px-2 pb-1.5 font-serif text-lg font-bold"
            :class="filaFicha.tono"
            aria-hidden="true"
          >
            {{ filaFicha.iniciales }}
          </div>
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
      </template>
      <template #pie>
        <button
          class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
          @click="filaFicha = null"
        >
          Cerrar
        </button>
        <p v-if="filaFicha?.reserva" class="flex-1 self-center text-center text-sm font-semibold text-indigo-700">En tus reservas</p>
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
      :titulo="libroPorConfirmar && hayDisponible(libroPorConfirmar) ? '¿Reservar este libro?' : '¿Te avisamos cuando vuelva?'"
      confirmar="Confirmar"
      tono="primario"
      :cargando="reservandoId !== null"
      :deshabilitado="limiteAlcanzado !== null"
      @confirmar="confirmarReserva"
      @cancelar="libroPorConfirmar = null"
    >
      <template v-if="libroPorConfirmar">
        <p class="font-medium text-gray-900">{{ libroPorConfirmar.titulo }}</p>
        <p v-if="libroPorConfirmar.autores.length" class="text-xs">{{ libroPorConfirmar.autores.map((a) => a.nombre).join(', ') }}</p>
        <p v-if="limiteAlcanzado" class="mt-3 flex gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-900">
          <svg class="mt-0.5 w-4 h-4 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
          </svg>
          {{ limiteAlcanzado }}
        </p>
        <template v-else-if="hayDisponible(libroPorConfirmar)">
          <p class="mt-3">
            Se apartará una copia a tu nombre. Retírala en el mesón de préstamo con tu RUT<template v-if="condiciones">
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
