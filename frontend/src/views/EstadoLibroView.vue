<script setup lang="ts">
import { nombreDeCopia, numeroDeCopia } from '@/utils/tomo'
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import DialogoConfirmacionEscrita from '@/components/ui/DialogoConfirmacionEscrita.vue'
import PestanasLibros from '@/components/libros/PestanasLibros.vue'
import FondoEstante from '@/components/libros/FondoEstante.vue'
import PortadaMaterial from '@/components/libros/PortadaMaterial.vue'
import Paginador from '@/components/ui/Paginador.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { ESTADOS_EJEMPLAR, FLUJO_PROCESO, nombreEstado } from '@/utils/estadosEjemplar'
import type { Ejemplar, EjemplarEstadoHistorial, EstadoLibroPersonalizado, EstadoProcesoEjemplar, Libro, Paginado } from '@/types'

const toast = useToast()
const auth = useAuthStore()
const route = useRoute()

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}

const busqueda = ref(typeof route.query.q === 'string' ? route.query.q : '')
const libros = ref<Libro[]>([])
const cargando = ref(true)
const apiError = ref(false)
const filtroEstado = ref<EstadoProcesoEjemplar | ''>('')
const estadosPersonalizados = ref<EstadoLibroPersonalizado[]>([])

type Copia = Ejemplar & { libro: Libro }

// El estado vive en cada copia, no en la obra: la lista es de copias.
const copias = computed<Copia[]>(() => libros.value.flatMap((libro) => (libro.ejemplares ?? []).map((e) => ({ ...e, libro }))))

const chips = computed(() => {
  const conteo = new Map<string, number>()
  for (const c of copias.value) conteo.set(c.estado_proceso, (conteo.get(c.estado_proceso) ?? 0) + 1)
  return [
    { valor: '' as const, texto: 'Todos', n: copias.value.length, punto: 'bg-indigo-500' },
    ...(Object.keys(ESTADOS_EJEMPLAR) as EstadoProcesoEjemplar[])
      .filter((e) => conteo.get(e))
      .map((e) => ({ valor: e, texto: ESTADOS_EJEMPLAR[e].label, n: conteo.get(e) ?? 0, punto: ESTADOS_EJEMPLAR[e].punto })),
  ]
})
const visibles = computed(() => (filtroEstado.value ? copias.value.filter((c) => c.estado_proceso === filtroEstado.value) : copias.value))

const seleccionadaId = ref<number | null>(null)
const seleccionada = computed(() => copias.value.find((c) => c.id === seleccionadaId.value) ?? null)

// Los títulos llegan de a 30, con botones para cambiar de página.
const POR_PAGINA = 30
const pagina = ref(1)
const meta = ref({ current_page: 1, last_page: 1, total: 0, from: null as number | null, to: null as number | null })

async function cargar(cambioDePagina = false) {
  cargando.value = true
  try {
    const { data } = await api.get<Paginado<Libro>>('/libros', {
      params: { q: busqueda.value.trim() || undefined, page: pagina.value, por_pagina: POR_PAGINA },
    })
    libros.value = data.data
    meta.value = { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to }
    apiError.value = false
    if (cambioDePagina) return
    // Con el lector de códigos: si lo escaneado es justo el código de una copia (el de
    // siempre o el de 8 dígitos de su etiqueta de lomo), queda elegida.
    const exacta = copias.value.find((c) => [c.codigo_barras, c.codigo_lomo].includes(busqueda.value.trim()))
    if (exacta) seleccionar(exacta)
    else if (filtroEstado.value && !copias.value.some((c) => c.estado_proceso === filtroEstado.value)) filtroEstado.value = ''
  } catch {
    apiError.value = true
    libros.value = []
  } finally {
    cargando.value = false
  }
}

let buscarTimer: ReturnType<typeof setTimeout> | undefined
function onBuscarInput() {
  clearTimeout(buscarTimer)
  buscarTimer = setTimeout(() => {
    pagina.value = 1
    cargar()
  }, 250)
}

function irAPagina(nueva: number) {
  pagina.value = nueva
  cargar(true)
}

onMounted(async () => {
  cargar()
  try {
    const { data } = await api.get<EstadoLibroPersonalizado[]>('/estados-libro-personalizados', { params: { activo: 1 } })
    estadosPersonalizados.value = data
  } catch {
    estadosPersonalizados.value = []
  }
})

// ---------- Copia elegida ----------
const objetivo = ref<EstadoProcesoEjemplar | null>(null)
const personalizadoId = ref<number | null>(null)
const nota = ref('')
const historial = ref<EjemplarEstadoHistorial[]>([])
const cargandoHistorial = ref(false)
const panel = ref<HTMLElement | null>(null)
const puedeVerHistorial = computed(() => auth.puede('historiales.ver'))

async function seleccionar(c: Copia) {
  seleccionadaId.value = c.id
  objetivo.value = null
  personalizadoId.value = null
  nota.value = ''
  cargarHistorial()
  if (window.matchMedia('(max-width: 1023px)').matches) {
    await nextTick()
    panel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }
}

async function cargarHistorial() {
  historial.value = []
  if (!seleccionadaId.value || !puedeVerHistorial.value) return
  cargandoHistorial.value = true
  try {
    const { data } = await api.get<EjemplarEstadoHistorial[]>('/ejemplares/historial-estado', { params: { ejemplar_id: seleccionadaId.value } })
    historial.value = data
  } catch {
    historial.value = []
  } finally {
    cargandoHistorial.value = false
  }
}

const TINTES: Record<EstadoProcesoEjemplar, string> = {
  inventario: 'border-gray-300 bg-gray-50',
  procesos_tecnicos: 'border-violet-200 bg-violet-50',
  por_colocar: 'border-amber-200 bg-amber-50',
  en_estante: 'border-emerald-200 bg-emerald-50',
  estanteria_auxiliar: 'border-teal-200 bg-teal-50',
  coleccion_movil: 'border-blue-200 bg-blue-50',
  personalizado: 'border-pink-200 bg-pink-50',
  de_baja: 'border-red-200 bg-red-50',
  devuelto_propietaria: 'border-slate-200 bg-slate-50',
}
const BORDES: Record<EstadoProcesoEjemplar, string> = {
  inventario: 'border-gray-300',
  procesos_tecnicos: 'border-violet-200',
  por_colocar: 'border-amber-200',
  en_estante: 'border-emerald-200',
  estanteria_auxiliar: 'border-teal-200',
  coleccion_movil: 'border-blue-200',
  personalizado: 'border-pink-200',
  de_baja: 'border-red-200',
  devuelto_propietaria: 'border-slate-200',
}

/** Cómo se ve cada paso del flujo para la copia elegida: hecho, actual o pendiente. */
function paso(estado: EstadoProcesoEjemplar) {
  const actual = seleccionada.value?.estado_proceso
  const iActual = actual ? FLUJO_PROCESO.indexOf(actual) : -1
  const i = FLUJO_PROCESO.indexOf(estado)
  return { actual: actual === estado, hecho: iActual > -1 && i > -1 && i < iActual }
}

function claseTarjeta(estado: EstadoProcesoEjemplar) {
  const { actual, hecho } = paso(estado)
  // Los pasos ya hechos y las otras ubicaciones llevan el borde de su color; los pasos pendientes, gris.
  const base = actual ? TINTES[estado] : hecho || !FLUJO_PROCESO.includes(estado) ? `${BORDES[estado]} bg-white` : 'border-gray-200 bg-white hover:border-gray-300'
  return [base, objetivo.value === estado ? 'ring-2 ring-indigo-500 ring-offset-1' : '', actual ? 'cursor-default' : 'cursor-pointer']
}

function elegir(estado: EstadoProcesoEjemplar) {
  if (seleccionada.value?.estado_proceso === estado && estado !== 'personalizado') return
  objetivo.value = objetivo.value === estado ? null : estado
}

// Una copia prestada sí se da de baja (a veces no vuelve); la apartada para retirar, no.
const bloqueaBaja = computed(() => !!seleccionada.value?.reservado && !seleccionada.value.prestado)
const textoObjetivo = computed(() => {
  if (!objetivo.value) return ''
  if (objetivo.value === 'personalizado') return estadosPersonalizados.value.find((e) => e.id === personalizadoId.value)?.nombre ?? 'Personalizado'
  return ESTADOS_EJEMPLAR[objetivo.value].label
})
const listoParaCambiar = computed(() => {
  if (!objetivo.value || !seleccionada.value) return false
  if (objetivo.value === 'de_baja') return !bloqueaBaja.value && !!nota.value.trim()
  if (objetivo.value === 'personalizado') {
    return !!personalizadoId.value && !(seleccionada.value.estado_proceso === 'personalizado' && seleccionada.value.estado_personalizado_id === personalizadoId.value)
  }
  return true
})

const confirmando = ref(false)
const guardando = ref(false)

async function confirmarCambio() {
  const copia = seleccionada.value
  if (!copia || !objetivo.value) return
  guardando.value = true
  try {
    const { data } = await api.patch<Ejemplar & { apartada_para: string | null }>(`/ejemplares/${copia.id}/estado`, {
      estado_proceso: objetivo.value,
      estado_personalizado_id: objetivo.value === 'personalizado' ? personalizadoId.value : null,
      motivo: nota.value.trim() || null,
      confirmacion: objetivo.value === 'de_baja' ? 'CONFIRMAR' : undefined,
    })
    const { apartada_para: apartadaPara, ...actualizada } = data
    const libro = libros.value.find((l) => l.id === copia.libro_id)
    const i = libro?.ejemplares?.findIndex((e) => e.id === actualizada.id) ?? -1
    if (libro?.ejemplares && i !== -1) libro.ejemplares[i] = { ...libro.ejemplares[i], ...actualizada }
    if (apartadaPara) {
      toast.success(`Estado cambiado a «${textoObjetivo.value}». La copia quedó apartada para ${apartadaPara}, primera en la lista de espera.`)
    } else {
      toast.success(objetivo.value === 'de_baja' ? 'Copia dada de baja' : `Estado cambiado a «${textoObjetivo.value}»`)
    }
    objetivo.value = null
    personalizadoId.value = null
    nota.value = ''
    confirmando.value = false
    cargarHistorial()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo cambiar el estado'))
    confirmando.value = false
  } finally {
    guardando.value = false
  }
}

function fechaCorta(iso: string) {
  return new Date(iso).toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function nombreEstadoHistorial(h: EjemplarEstadoHistorial) {
  if (h.estado_nuevo === 'personalizado') return h.estado_personalizado_nuevo?.nombre ?? 'Personalizado'
  return ESTADOS_EJEMPLAR[h.estado_nuevo]?.label ?? h.estado_nuevo
}
</script>

<template>
  <StaffLayout>
    <div class="mx-auto max-w-[88rem]">
      <!-- La caja de título con la textura de Salas y Préstamos; debajo, las pestañas, el buscador y el filtro por estado. -->
      <section class="mb-5 rounded-2xl border border-gray-200 bg-white shadow-md">
        <div class="relative rounded-t-2xl" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
          <FondoEstante />
          <div class="relative min-w-0 px-6 pt-4">
            <nav class="text-xs text-white/75" aria-label="Ruta">
              <button type="button" class="font-medium text-white hover:underline" @click="abrirMenuGestion">Gestión</button>
              <span class="mx-1.5">›</span>
              <span>Catálogo</span>
            </nav>
            <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Estado de ejemplares</h1>
          </div>
          <PestanasLibros actual="estado-libro" en-banda class="relative mt-3 px-5" />
        </div>
        <div class="flex flex-wrap items-center gap-3 rounded-b-2xl p-3">
          <div class="relative min-w-[16rem] max-w-xl flex-1">
            <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" d="M4 5v14M7.5 5v14M11 5v14M14.5 5v14M17 5v14M20 5v14" />
            </svg>
            <input autocomplete="off"
              v-model="busqueda"
              type="text"
              placeholder="Título o código de barras (o escanéalo)"
              aria-label="Buscar ejemplar"
              class="h-10 w-full rounded-xl border border-gray-200 bg-white pl-10 pr-4 text-sm text-gray-900 outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
              @input="onBuscarInput"
              @keydown.enter="pagina = 1; cargar()"
            />
          </div>
          <div class="flex w-fit max-w-full flex-wrap gap-0.5 rounded-xl border border-gray-200 bg-gray-50 p-1" role="group" aria-label="Filtrar por estado">
            <button
              v-for="c in chips"
              :key="c.valor"
              type="button"
              class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold transition-colors"
              :class="filtroEstado === c.valor ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
              :aria-pressed="filtroEstado === c.valor"
              @click="filtroEstado = c.valor"
            >
              <span class="h-1.5 w-1.5 rounded-full" :class="c.punto"></span>
              {{ c.texto }}
              <span class="rounded-full px-1.5 text-[11px] tabular-nums" :class="filtroEstado === c.valor ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600'">{{ c.n }}</span>
            </button>
          </div>
        </div>
      </section>

      <ApiErrorBanner v-if="apiError" />

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-[22rem_minmax(0,1fr)] lg:items-start">
        <!-- Copias -->
        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
          <p class="flex items-center justify-between border-b border-gray-100 bg-indigo-50/40 px-4 py-2.5 text-[11px] font-bold uppercase tracking-wider text-indigo-700">
            Ejemplares
            <span class="font-semibold normal-case tracking-normal text-gray-500">Estado</span>
          </p>
          <ul class="max-h-[26rem] space-y-0.5 overflow-y-auto p-2 lg:max-h-[calc(100vh-17rem)]">
            <li v-for="c in visibles" :key="c.id">
              <button
                type="button"
                class="flex w-full items-center justify-between gap-3 rounded-xl px-2.5 py-2.5 text-left transition-colors"
                :class="seleccionadaId === c.id ? 'bg-indigo-50/60 shadow-sm ring-1 ring-indigo-200' : 'hover:bg-gray-50'"
                @click="seleccionar(c)"
              >
                <span class="min-w-0">
                  <span class="block truncate text-sm text-gray-900" :class="seleccionadaId === c.id ? 'font-semibold' : 'font-medium'">{{ c.libro.titulo }}</span>
                  <span class="mt-1 flex flex-wrap gap-1 text-[11px] text-gray-600">
                    <span class="rounded border border-gray-200 bg-gray-50 px-1.5 font-mono">{{ c.codigo_barras }}</span>
                    <span class="rounded border border-gray-200 bg-white px-1.5">lomo {{ c.codigo_lomo }}</span>
                    <span v-if="nombreDeCopia(c)" class="rounded border border-gray-200 bg-white px-1.5">{{ nombreDeCopia(c) }}</span>
                    <span v-if="c.prestado || c.reservado" class="rounded px-1.5 font-semibold" :class="c.prestado ? 'bg-amber-100 text-amber-800' : 'bg-violet-100 text-violet-700'">{{ c.prestado ? 'Prestada' : 'Reservada' }}</span>
                  </span>
                </span>
                <span class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="ESTADOS_EJEMPLAR[c.estado_proceso]?.badge">
                  <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                  {{ nombreEstado(c) }}
                </span>
              </button>
            </li>
            <li v-if="!cargando && !visibles.length" class="px-4 py-8 text-center text-sm text-gray-400">Sin copias que coincidan.</li>
          </ul>
          <Paginador
            class="border-t border-gray-100 px-4 py-3"
            compacto
            :pagina="meta.current_page"
            :paginas="meta.last_page"
            :total="meta.total"
            :desde="meta.from"
            :hasta="meta.to"
            unidad="títulos"
            :cargando="cargando"
            @update:pagina="irAPagina"
          />
        </section>

        <!-- Copia elegida -->
        <section ref="panel" class="scroll-mt-4 overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm lg:sticky lg:top-6">
          <p v-if="!seleccionada" class="px-6 py-16 text-center text-sm text-gray-400">Elige una copia de la lista para ver y cambiar su estado.</p>
          <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-100 bg-indigo-50/40 px-6 py-4 sm:flex-nowrap">
              <div class="flex min-w-0 items-center gap-4">
                <PortadaMaterial
                  class="shadow-sm"
                  :titulo="seleccionada.libro.titulo"
                  :tipo="seleccionada.libro.tipo_material?.nombre"
                  :plantilla="seleccionada.libro.tipo_material?.plantilla"
                  :clave-tono="seleccionada.libro.categorias?.[0]?.nombre"
                />
                <div class="min-w-0">
                  <h2 class="font-serif text-xl font-bold text-gray-900 sm:text-2xl">{{ seleccionada.libro.titulo }}</h2>
                  <p class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-gray-600">
                    <span v-if="nombreDeCopia(seleccionada)" class="rounded-md border border-gray-200 bg-white px-2 py-0.5">{{ nombreDeCopia(seleccionada) }}</span>
                    <span class="rounded-md border border-gray-200 bg-white px-2 py-0.5 font-mono text-gray-800">{{ seleccionada.codigo_barras }}</span>
                    <span
                      v-if="seleccionada.prestado || seleccionada.reservado"
                      class="rounded-full px-2 py-0.5 font-semibold"
                      :class="seleccionada.prestado ? 'bg-amber-100 text-amber-800' : 'bg-violet-100 text-violet-700'"
                    >{{ seleccionada.prestado ? 'Prestada ahora' : 'Reservada para retiro' }}</span>
                  </p>
                </div>
              </div>
              <div class="shrink-0 sm:text-right">
                <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500">Estado actual</p>
                <span class="mt-1 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold" :class="ESTADOS_EJEMPLAR[seleccionada.estado_proceso]?.badge">
                  <span class="h-2 w-2 rounded-full bg-current" aria-hidden="true"></span>
                  {{ nombreEstado(seleccionada) }}
                </span>
              </div>
            </div>

            <div class="space-y-6 px-6 py-5">
              <p v-if="seleccionada.estado_proceso === 'devuelto_propietaria'" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                Esta copia era de otra biblioteca y ya se le devolvió (Catalogación › Préstamo interbibliotecario): no está en la biblioteca y su estado no cambia.
                Sus préstamos siguen en el historial y en los reportes.
              </p>
              <template v-else>
              <div>
                <p class="mb-2.5 flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-indigo-700"><span class="h-3.5 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>Flujo de proceso</p>
                <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 xl:grid-cols-4">
                  <button
                    v-for="e in FLUJO_PROCESO"
                    :key="e"
                    type="button"
                    class="rounded-xl border p-3 text-left transition-shadow"
                    :class="claseTarjeta(e)"
                    @click="elegir(e)"
                  >
                    <span class="flex items-center gap-2">
                      <span
                        class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full"
                        :class="paso(e).actual || paso(e).hecho ? `${ESTADOS_EJEMPLAR[e].punto} text-white` : 'border-2 border-gray-300'"
                      >
                        <svg v-if="paso(e).hecho" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                        <span v-else-if="paso(e).actual" class="h-1.5 w-1.5 rounded-full bg-white"></span>
                      </span>
                      <span class="text-sm font-semibold text-gray-900">{{ ESTADOS_EJEMPLAR[e].label }}</span>
                    </span>
                    <span class="mt-1.5 block text-xs text-gray-500">{{ paso(e).actual ? 'Estado actual' : ESTADOS_EJEMPLAR[e].descripcion }}</span>
                  </button>
                </div>
              </div>

              <div>
                <p class="mb-2.5 flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-indigo-700"><span class="h-3.5 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>Otras ubicaciones</p>
                <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                  <button
                    v-for="e in (['estanteria_auxiliar', 'coleccion_movil', 'personalizado'] as const)"
                    :key="e"
                    type="button"
                    class="rounded-xl border p-3 text-left transition-shadow"
                    :class="claseTarjeta(e)"
                    @click="elegir(e)"
                  >
                    <span class="flex items-center gap-2">
                      <span class="h-2 w-2 shrink-0 rounded-full" :class="ESTADOS_EJEMPLAR[e].punto"></span>
                      <span class="text-sm font-semibold text-gray-900">{{ ESTADOS_EJEMPLAR[e].label }}</span>
                    </span>
                    <span class="mt-1.5 block text-xs text-gray-500">
                      {{ paso(e).actual ? `Estado actual${e === 'personalizado' ? ': ' + nombreEstado(seleccionada) : ''}` : ESTADOS_EJEMPLAR[e].descripcion }}
                    </span>
                  </button>
                </div>
                <div v-if="objetivo === 'personalizado'" class="mt-3">
                  <label for="estado-personalizado" class="mb-1.5 block text-xs font-semibold text-gray-700">¿Cuál estado personalizado?</label>
                  <select
                    id="estado-personalizado"
                    v-model="personalizadoId"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30 sm:max-w-sm"
                  >
                    <option :value="null" disabled>Elige uno…</option>
                    <option v-for="ep in estadosPersonalizados" :key="ep.id" :value="ep.id">{{ ep.nombre }}</option>
                  </select>
                  <p v-if="!estadosPersonalizados.length" class="mt-1.5 text-xs text-amber-700">Todavía no hay estados personalizados: se crean en Administración.</p>
                </div>
              </div>

              <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                <label for="nota-estado" class="mb-1.5 block text-xs font-semibold text-gray-700">
                  {{ objetivo === 'de_baja' ? 'Motivo de la baja (obligatorio)' : 'Nota (opcional)' }}
                </label>
                <div class="flex flex-col gap-2 sm:flex-row">
                  <input autocomplete="off"
                    id="nota-estado"
                    v-model="nota"
                    type="text"
                    maxlength="500"
                    :placeholder="objetivo === 'de_baja' ? 'Ej: pérdida, o daño que no se puede reparar' : 'Ej: se plastificó la portada'"
                    class="h-11 w-full min-w-0 rounded-lg border border-gray-300 bg-white px-3 text-sm sm:flex-1 outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
                  />
                  <button
                    type="button"
                    class="h-11 shrink-0 rounded-lg px-5 text-sm font-semibold transition-colors disabled:cursor-not-allowed"
                    :class="!objetivo ? 'bg-gray-200 text-gray-500' : objetivo === 'de_baja' ? 'bg-red-600 text-white hover:bg-red-700 disabled:opacity-50' : 'bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50'"
                    :disabled="!listoParaCambiar"
                    @click="confirmando = true"
                  >
                    {{ !objetivo ? 'Elige un estado' : objetivo === 'de_baja' ? 'Dar de baja' : `Cambiar a ${textoObjetivo}` }}
                  </button>
                </div>
              </div>

              <div
                class="flex flex-col gap-3 rounded-xl border border-dashed p-4 sm:flex-row sm:items-center sm:justify-between"
                :class="objetivo === 'de_baja' ? 'border-red-400 bg-red-50 ring-2 ring-red-500 ring-offset-1' : 'border-red-300 bg-red-50/50'"
              >
                <div>
                  <p class="text-sm font-semibold text-red-800">Dar de baja</p>
                  <p class="text-xs text-red-700/90">
                    <template v-if="seleccionada.estado_proceso === 'de_baja'">Esta copia ya está dada de baja; para reincorporarla, elige otro estado.</template>
                    <template v-else-if="bloqueaBaja">No se puede ahora: la copia está apartada para una reserva lista para retirar. Cancela esa reserva primero.</template>
                    <template v-else-if="seleccionada.prestado">Está prestada. Si se perdió, la baja la saca de circulación y cierra el préstamo (sin multa automática: se cursa en Multas). Pide un motivo y escribir CONFIRMAR.</template>
                    <template v-else>Retira la copia de la colección. Pide un motivo y escribir CONFIRMAR; queda registrado y se puede reincorporar desde Administración › Eliminados.</template>
                  </p>
                </div>
                <button
                  v-if="seleccionada.estado_proceso !== 'de_baja'"
                  type="button"
                  class="shrink-0 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 transition-colors hover:bg-red-50 disabled:opacity-50"
                  :disabled="bloqueaBaja"
                  @click="elegir('de_baja')"
                >
                  Dar de baja
                </button>
              </div>
              </template>

              <div v-if="puedeVerHistorial">
                <p class="mb-2.5 flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-indigo-700"><span class="h-3.5 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>Historial de cambios</p>
                <ul v-if="historial.length" class="divide-y divide-gray-100 rounded-xl border border-gray-200">
                  <li v-for="h in historial" :key="h.id" class="px-3 py-2.5 text-sm">
                    <span class="flex flex-wrap items-center gap-2">
                      <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium tabular-nums text-gray-700">{{ fechaCorta(h.created_at) }}</span>
                      <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="ESTADOS_EJEMPLAR[h.estado_nuevo]?.badge">
                        <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                        {{ nombreEstadoHistorial(h) }}
                      </span>
                      <span class="text-gray-500">{{ h.staff?.nombre ?? 'Sin registro' }}</span>
                    </span>
                    <span v-if="h.motivo" class="mt-1 block text-xs text-gray-500">«{{ h.motivo }}»</span>
                  </li>
                </ul>
                <p v-else-if="!cargandoHistorial" class="text-sm text-gray-400">Sin cambios registrados todavía.</p>
              </div>
            </div>
          </template>
        </section>
      </div>
    </div>

    <DialogoConfirmacionEscrita
      :abierto="confirmando && !!seleccionada && objetivo === 'de_baja'"
      titulo="¿Dar de baja esta copia?"
      confirmar="Dar de baja"
      :cargando="guardando"
      @confirmar="confirmarCambio"
      @cancelar="confirmando = false"
    >
      <p>
        <strong class="text-gray-900">{{ seleccionada?.libro.titulo }}</strong> (<template v-if="nombreDeCopia(seleccionada)">{{ nombreDeCopia(seleccionada)!.toLowerCase() }} · </template>
        <span class="font-mono">{{ seleccionada?.codigo_barras }}</span>) saldrá de circulación y dejará de verse en el catálogo y el portal.
      </p>
      <p v-if="seleccionada?.prestado" class="rounded-lg bg-amber-50 px-3 py-2 text-amber-800">
        Está prestada: su préstamo quedará cerrado por la baja. No se genera multa automática.
      </p>
      <p>Motivo: «{{ nota.trim() }}»</p>
    </DialogoConfirmacionEscrita>

    <DialogoConfirmacion
      :abierto="confirmando && !!seleccionada && objetivo !== 'de_baja'"
      titulo="¿Cambiar el estado de esta copia?"
      confirmar="Sí, cambiar"
      tono="primario"
      :cargando="guardando"
      @confirmar="confirmarCambio"
      @cancelar="confirmando = false"
    >
      <p>
        <strong class="text-gray-900">{{ seleccionada?.libro.titulo }}</strong> (<template v-if="nombreDeCopia(seleccionada)">{{ nombreDeCopia(seleccionada)!.toLowerCase() }} · </template>
        <span class="font-mono">{{ seleccionada?.codigo_barras }}</span>) pasará de
        <strong class="text-gray-900">{{ seleccionada ? nombreEstado(seleccionada) : '' }}</strong> a <strong class="text-gray-900">{{ textoObjetivo }}</strong>.
      </p>
      <p v-if="nota.trim()" class="mt-2">{{ objetivo === 'de_baja' ? 'Motivo' : 'Nota' }}: «{{ nota.trim() }}»</p>
    </DialogoConfirmacion>
  </StaffLayout>
</template>
