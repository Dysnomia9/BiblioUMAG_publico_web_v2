<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import EncabezadoGestion from '@/components/ui/EncabezadoGestion.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import PestanasLibros from '@/components/libros/PestanasLibros.vue'
import Paginador from '@/components/ui/Paginador.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { ESTADOS_EJEMPLAR, FLUJO_PROCESO, nombreEstado } from '@/utils/estadosEjemplar'
import type { Ejemplar, EjemplarEstadoHistorial, EstadoLibroPersonalizado, EstadoProcesoEjemplar, Libro, Paginado } from '@/types'

const toast = useToast()
const auth = useAuthStore()
const route = useRoute()

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

const bloqueaBaja = computed(() => !!seleccionada.value && !seleccionada.value.disponible)
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
      <EncabezadoGestion seccion="Catalogación" titulo="Estado de ejemplares">
        <template #acciones>
          <PestanasLibros actual="estado-libro" />
        </template>
      </EncabezadoGestion>

      <ApiErrorBanner v-if="apiError" />

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-[22rem_minmax(0,1fr)] lg:items-start">
        <!-- Copias -->
        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div class="space-y-3 border-b border-gray-100 p-4">
            <div class="relative">
              <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
              </svg>
              <input
                v-model="busqueda"
                type="text"
                placeholder="Título o código de barras (o escanéalo)"
                aria-label="Buscar ejemplar"
                class="h-11 w-full rounded-xl border border-gray-300 bg-white pl-10 pr-4 text-sm text-gray-900 outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
                @input="onBuscarInput"
                @keydown.enter="pagina = 1; cargar()"
              />
            </div>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="c in chips"
                :key="c.valor"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors"
                :class="filtroEstado === c.valor ? 'border-gray-900 bg-gray-900 text-white dark:border-indigo-500 dark:bg-indigo-500' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                @click="filtroEstado = c.valor"
              >
                <span class="h-1.5 w-1.5 rounded-full" :class="c.punto"></span>
                {{ c.texto }}
                <span class="font-semibold">{{ c.n }}</span>
              </button>
            </div>
          </div>
          <ul class="max-h-[26rem] divide-y divide-gray-100 overflow-y-auto lg:max-h-[calc(100vh-17rem)]">
            <li v-for="c in visibles" :key="c.id">
              <button
                type="button"
                class="flex w-full items-center justify-between gap-3 border-l-4 px-4 py-3 text-left transition-colors"
                :class="seleccionadaId === c.id ? 'border-indigo-600 bg-indigo-50/60' : 'border-transparent hover:bg-gray-50'"
                @click="seleccionar(c)"
              >
                <span class="min-w-0">
                  <span class="block truncate font-semibold text-gray-900">{{ c.libro.titulo }}</span>
                  <span class="block font-mono text-xs text-gray-500">
                    {{ c.codigo_barras }} · lomo {{ c.codigo_lomo }} · Copia {{ c.numero_copia }}<template v-if="c.prestado || c.reservado"> · <span class="font-sans font-semibold" :class="c.prestado ? 'text-amber-700' : 'text-violet-700'">{{ c.prestado ? 'Prestada' : 'Reservada' }}</span></template>
                  </span>
                </span>
                <span class="shrink-0 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :class="ESTADOS_EJEMPLAR[c.estado_proceso]?.badge">
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
        <section ref="panel" class="scroll-mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm lg:sticky lg:top-6">
          <p v-if="!seleccionada" class="px-6 py-16 text-center text-sm text-gray-400">Elige una copia de la lista para ver y cambiar su estado.</p>
          <template v-else>
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-100 bg-gray-50/60 px-6 py-5">
              <div class="min-w-0">
                <h2 class="font-serif text-xl font-bold text-gray-900 sm:text-2xl">{{ seleccionada.libro.titulo }}</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                  Copia {{ seleccionada.numero_copia }} · <span class="font-mono">{{ seleccionada.codigo_barras }}</span>
                  <span
                    v-if="seleccionada.prestado || seleccionada.reservado"
                    class="ml-1.5 rounded-full px-2 py-0.5 text-xs font-semibold"
                    :class="seleccionada.prestado ? 'bg-amber-100 text-amber-800' : 'bg-violet-100 text-violet-700'"
                  >{{ seleccionada.prestado ? 'Prestada ahora' : 'Reservada para retiro' }}</span>
                </p>
              </div>
              <div class="text-right">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Estado actual</p>
                <span class="mt-1 inline-block rounded-full px-3 py-1 text-sm font-semibold" :class="ESTADOS_EJEMPLAR[seleccionada.estado_proceso]?.badge">
                  {{ nombreEstado(seleccionada) }}
                </span>
              </div>
            </div>

            <div class="space-y-6 px-6 py-5">
              <div>
                <p class="mb-2.5 text-[11px] font-semibold uppercase tracking-wider text-gray-500">Flujo de proceso</p>
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
                <p class="mb-2.5 text-[11px] font-semibold uppercase tracking-wider text-gray-500">Otras ubicaciones</p>
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

              <div
                class="flex flex-col gap-3 rounded-xl border border-dashed p-4 sm:flex-row sm:items-center sm:justify-between"
                :class="objetivo === 'de_baja' ? 'border-red-400 bg-red-50 ring-2 ring-red-500 ring-offset-1' : 'border-red-300 bg-red-50/50'"
              >
                <div>
                  <p class="text-sm font-semibold text-red-800">Dar de baja</p>
                  <p class="text-xs text-red-700/90">
                    <template v-if="seleccionada.estado_proceso === 'de_baja'">Esta copia ya está dada de baja; para reincorporarla, elige otro estado.</template>
                    <template v-else-if="bloqueaBaja">No se puede ahora: la copia está prestada o reservada.</template>
                    <template v-else>Retira la copia de la colección. Pide un motivo y queda registrado.</template>
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

              <div>
                <label for="nota-estado" class="mb-1.5 block text-xs font-semibold text-gray-700">
                  {{ objetivo === 'de_baja' ? 'Motivo de la baja (obligatorio)' : 'Nota (opcional)' }}
                </label>
                <div class="flex flex-col gap-2 sm:flex-row">
                  <input
                    id="nota-estado"
                    v-model="nota"
                    type="text"
                    maxlength="500"
                    :placeholder="objetivo === 'de_baja' ? 'Ej: pérdida, o daño que no se puede reparar' : 'Ej: se plastificó la portada'"
                    class="h-11 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 text-sm outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
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

              <div v-if="puedeVerHistorial" class="border-t border-gray-100 pt-5">
                <p class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-gray-500">Historial de cambios</p>
                <ul v-if="historial.length" class="space-y-2.5">
                  <li v-for="h in historial" :key="h.id" class="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-x-3 text-sm">
                    <span class="text-gray-500">{{ fechaCorta(h.created_at) }}</span>
                    <span class="min-w-0">
                      <span class="flex items-center gap-2">
                        <span class="h-2 w-2 shrink-0 rounded-full" :class="ESTADOS_EJEMPLAR[h.estado_nuevo]?.punto"></span>
                        <span><strong class="text-gray-900">{{ nombreEstadoHistorial(h) }}</strong><span class="text-gray-500"> · {{ h.staff?.nombre ?? 'Sin registro' }}</span></span>
                      </span>
                      <span v-if="h.motivo" class="mt-0.5 block pl-4 text-xs text-gray-500">«{{ h.motivo }}»</span>
                    </span>
                  </li>
                </ul>
                <p v-else-if="!cargandoHistorial" class="text-sm text-gray-400">Sin cambios registrados todavía.</p>
              </div>
            </div>
          </template>
        </section>
      </div>
    </div>

    <DialogoConfirmacion
      :abierto="confirmando && !!seleccionada"
      :titulo="objetivo === 'de_baja' ? '¿Dar de baja esta copia?' : '¿Cambiar el estado de esta copia?'"
      :confirmar="objetivo === 'de_baja' ? 'Sí, dar de baja' : 'Sí, cambiar'"
      :tono="objetivo === 'de_baja' ? 'peligro' : 'primario'"
      :cargando="guardando"
      @confirmar="confirmarCambio"
      @cancelar="confirmando = false"
    >
      <p>
        <strong class="text-gray-900">{{ seleccionada?.libro.titulo }}</strong> (copia {{ seleccionada?.numero_copia }} ·
        <span class="font-mono">{{ seleccionada?.codigo_barras }}</span>) pasará de
        <strong class="text-gray-900">{{ seleccionada ? nombreEstado(seleccionada) : '' }}</strong> a <strong class="text-gray-900">{{ textoObjetivo }}</strong>.
      </p>
      <p v-if="nota.trim()" class="mt-2">{{ objetivo === 'de_baja' ? 'Motivo' : 'Nota' }}: «{{ nota.trim() }}»</p>
    </DialogoConfirmacion>
  </StaffLayout>
</template>
