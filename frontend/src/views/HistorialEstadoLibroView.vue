<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import EncabezadoGestion from '@/components/ui/EncabezadoGestion.vue'
import PestanasLibros from '@/components/libros/PestanasLibros.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import { ESTADOS_EJEMPLAR } from '@/utils/estadosEjemplar'
import { inicialesPortada, tonoPortada } from '@/utils/portada'
import type { EjemplarEstadoHistorial, EstadoProcesoEjemplar } from '@/types'

const route = useRoute()
const toast = useToast()

const historial = ref<EjemplarEstadoHistorial[]>([])
const cargando = ref(true)
const apiError = ref(false)

const filtros = reactive({
  q: '',
  lote_id: typeof route.query.lote_id === 'string' ? route.query.lote_id : '',
  desde: '',
  hasta: '',
  estado: '' as '' | EstadoProcesoEjemplar,
})

// Los chips siguen el camino normal de una copia, más la baja; los demás estados se ven en «Todos».
const CHIPS_ESTADO: EstadoProcesoEjemplar[] = ['inventario', 'procesos_tecnicos', 'por_colocar', 'en_estante', 'de_baja']

// Borde izquierdo de cada fila: el color del estado al que pasó la copia.
const BORDE_ESTADO: Record<EstadoProcesoEjemplar, string> = {
  inventario: 'border-l-gray-400',
  procesos_tecnicos: 'border-l-violet-500',
  por_colocar: 'border-l-amber-500',
  en_estante: 'border-l-emerald-600',
  estanteria_auxiliar: 'border-l-teal-600',
  coleccion_movil: 'border-l-blue-700',
  personalizado: 'border-l-pink-600',
  de_baja: 'border-l-red-600',
}

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<EjemplarEstadoHistorial[]>('/ejemplares/historial-estado', {
      params: {
        q: filtros.q.trim() || undefined,
        lote_id: filtros.lote_id.trim() || undefined,
        desde: filtros.desde || undefined,
        hasta: filtros.hasta || undefined,
        estado: filtros.estado || undefined,
      },
    })
    historial.value = data
    apiError.value = false
  } catch {
    apiError.value = true
    historial.value = []
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

let timer: ReturnType<typeof setTimeout> | undefined
function onFiltroInput() {
  clearTimeout(timer)
  timer = setTimeout(cargar, 250)
}

function elegirEstado(estado: '' | EstadoProcesoEjemplar) {
  filtros.estado = estado
  cargar()
}

// ---------- Presentación ----------
function etiquetaEstado(h: EjemplarEstadoHistorial, cual: 'anterior' | 'nuevo') {
  const estado = cual === 'anterior' ? h.estado_anterior : h.estado_nuevo
  if (estado === 'personalizado') {
    return (cual === 'anterior' ? h.estado_personalizado_anterior?.nombre : h.estado_personalizado_nuevo?.nombre) ?? 'Personalizado'
  }
  return ESTADOS_EJEMPLAR[estado]?.label ?? estado
}

const claveDia = (fecha: Date) => fecha.toLocaleDateString('sv-SE')
const hora = (iso: string) => new Date(iso).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
const fechaCorta = (iso: string) => new Date(iso).toLocaleDateString('es-CL')

/** «Hoy · viernes 02-10-2026», «Ayer · jueves 01-10-2026» o «Miércoles 30-09-2026». */
function tituloDia(fecha: Date) {
  const nombre = fecha.toLocaleDateString('es-CL', { weekday: 'long' })
  const corta = fecha.toLocaleDateString('es-CL')
  const ayer = new Date()
  ayer.setDate(ayer.getDate() - 1)
  if (claveDia(fecha) === claveDia(new Date())) return `Hoy · ${nombre} ${corta}`
  if (claveDia(fecha) === claveDia(ayer)) return `Ayer · ${nombre} ${corta}`
  return `${nombre.charAt(0).toUpperCase()}${nombre.slice(1)} ${corta}`
}

function iniciales(nombre: string | undefined) {
  return (nombre ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p.charAt(0).toUpperCase())
    .join('') || '—'
}

/**
 * Los cambios agrupados por día. Las filas seguidas de un mismo lote muestran el motivo y
 * el lote solo en la primera; las demás dicen «Mismo lote que la fila anterior».
 */
const grupos = computed(() => {
  const lista: { clave: string; titulo: string; filas: { h: EjemplarEstadoHistorial; mismoLote: boolean }[] }[] = []
  let anterior: EjemplarEstadoHistorial | null = null
  for (const h of historial.value) {
    const fecha = new Date(h.created_at)
    let grupo = lista[lista.length - 1]
    if (!grupo || grupo.clave !== claveDia(fecha)) {
      grupo = { clave: claveDia(fecha), titulo: tituloDia(fecha), filas: [] }
      lista.push(grupo)
      anterior = null
    }
    grupo.filas.push({ h, mismoLote: !!h.lote_id && anterior?.lote_id === h.lote_id })
    anterior = h
  }
  return lista
})

// ---------- Lotes ----------
// El detalle de cada lote se despliega bajo su primera fila, solo cuando se pide.
const lotesAbiertos = reactive(new Set<number>())

function alternarLote(id: number) {
  if (lotesAbiertos.has(id)) lotesAbiertos.delete(id)
  else lotesAbiertos.add(id)
}

const COPIAS_EN_RESUMEN = 6

/** Resumen del lote para el detalle desplegado: total de copias, de qué estado a cuál y cuáles. */
function resumenLote(h: EjemplarEstadoHistorial) {
  const filas = historial.value.filter((x) => x.lote_id === h.lote_id)
  const total = Math.max(h.lote_total ?? 0, filas.length)
  const anteriores = new Set(filas.map((x) => etiquetaEstado(x, 'anterior')))
  const copias = filas.slice(0, COPIAS_EN_RESUMEN).map((x) => {
    const palabras = (x.ejemplar?.libro?.titulo ?? 'Libro eliminado').split(/\s+/)
    return `${palabras.slice(0, 3).join(' ')}${palabras.length > 3 ? '…' : ''} (c.${x.ejemplar?.numero_copia ?? '?'})`
  })
  return {
    total,
    desde: anteriores.size === 1 ? [...anteriores][0] : 'Varios estados',
    hacia: etiquetaEstado(h, 'nuevo'),
    copias,
    faltan: total - copias.length,
  }
}

async function copiarId(loteId: string) {
  try {
    await navigator.clipboard.writeText(loteId)
    toast.success('ID del lote copiado')
  } catch {
    toast.error('No se pudo copiar: selecciona el ID y cópialo a mano')
  }
}

/** Muestra todos los cambios del mismo cambio masivo (sin los demás filtros, que podrían esconder parte). */
function verLote(loteId: string) {
  Object.assign(filtros, { q: '', lote_id: loteId, desde: '', hasta: '', estado: '' })
  cargar()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const CAMPO =
  'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30'
</script>

<template>
  <StaffLayout>
    <div class="mx-auto max-w-[88rem]">
      <EncabezadoGestion seccion="Catálogo" titulo="Historial de estado" descripcion="Todos los cambios de estado de ejemplares, individuales y por lote">
        <template #acciones>
          <PestanasLibros actual="historial-estado-libro" />
        </template>
      </EncabezadoGestion>

      <ApiErrorBanner v-if="apiError" />

      <!-- Filtros -->
      <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1.1fr)_minmax(0,0.85fr)_minmax(0,0.85fr)]">
          <label class="block">
            <span class="mb-1 block text-xs font-semibold text-gray-700">Libro o código</span>
            <input v-model="filtros.q" type="text" placeholder="Título o código de barras" :class="CAMPO" @input="onFiltroInput" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-semibold text-gray-700">Lote</span>
            <input v-model="filtros.lote_id" type="text" placeholder="ID de lote (cambio masivo)" :class="[CAMPO, 'font-mono']" @input="onFiltroInput" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-semibold text-gray-700">Desde</span>
            <input v-model="filtros.desde" type="date" :class="CAMPO" @change="cargar" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-semibold text-gray-700">Hasta</span>
            <input v-model="filtros.hasta" type="date" :class="CAMPO" @change="cargar" />
          </label>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-2" role="group" aria-label="Filtrar por estado al que pasó la copia">
          <span class="text-xs font-semibold text-gray-700">Estado:</span>
          <button
            type="button"
            class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition-colors"
            :class="filtros.estado === '' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
            :aria-pressed="filtros.estado === ''"
            @click="elegirEstado('')"
          >
            Todos
          </button>
          <button
            v-for="e in CHIPS_ESTADO"
            :key="e"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition-colors"
            :class="filtros.estado === e ? 'bg-indigo-600 text-white shadow-sm' : [ESTADOS_EJEMPLAR[e].badge, 'hover:brightness-95']"
            :aria-pressed="filtros.estado === e"
            @click="elegirEstado(e)"
          >
            <span class="h-1.5 w-1.5 rounded-full" :class="filtros.estado === e ? 'bg-white' : ESTADOS_EJEMPLAR[e].punto"></span>
            {{ ESTADOS_EJEMPLAR[e].label }}
          </button>
        </div>
      </div>

      <!-- Cambios -->
      <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[56rem]">
            <thead>
              <tr
                class="text-left text-[11px] font-bold uppercase tracking-wider text-white"
                style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
              >
                <th class="px-4 py-3.5">Fecha</th>
                <th class="px-4 py-3.5">Libro · Copia</th>
                <th class="px-4 py-3.5">Cambio</th>
                <th class="px-4 py-3.5">Hecho por</th>
                <th class="px-4 py-3.5">Motivo · Lote</th>
              </tr>
            </thead>
            <tbody v-for="g in grupos" :key="g.clave">
              <tr class="border-t border-indigo-100 bg-indigo-50/70">
                <td colspan="5" class="px-4 py-2">
                  <span class="text-xs font-bold text-indigo-950">{{ g.titulo }}</span>
                  <span class="ml-2 rounded-full bg-white px-2 py-0.5 text-[11px] font-bold text-indigo-700 shadow-sm ring-1 ring-indigo-100">
                    {{ g.filas.length }} {{ g.filas.length === 1 ? 'cambio' : 'cambios' }}
                  </span>
                </td>
              </tr>
              <template v-for="{ h, mismoLote } in g.filas" :key="h.id">
                <tr class="border-t border-gray-100 transition-colors" :class="h.estado_nuevo === 'de_baja' ? 'bg-red-50/60' : 'hover:bg-gray-50/70'">
                  <td class="whitespace-nowrap border-l-4 px-4 py-3" :class="BORDE_ESTADO[h.estado_nuevo]">
                    <span class="block text-sm font-bold tabular-nums text-gray-900">{{ hora(h.created_at) }}</span>
                    <span class="block text-[11px] text-gray-500">{{ fechaCorta(h.created_at) }}</span>
                  </td>
                  <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                      <span
                        class="flex h-9 w-7 shrink-0 items-end rounded-sm border-l-[3px] px-1 pb-0.5 font-serif text-[11px] font-bold"
                        :class="tonoPortada(h.ejemplar?.libro?.titulo ?? '')"
                        aria-hidden="true"
                      >
                        {{ inicialesPortada(h.ejemplar?.libro?.titulo ?? '') }}
                      </span>
                      <div class="min-w-0">
                        <p class="text-sm font-semibold leading-snug text-gray-900">{{ h.ejemplar?.libro?.titulo ?? 'Libro eliminado' }}</p>
                        <p class="text-xs text-gray-500">Copia {{ h.ejemplar?.numero_copia }} · <span class="font-mono">{{ h.ejemplar?.codigo_barras }}</span></p>
                      </div>
                    </div>
                  </td>
                  <td class="px-4 py-3">
                    <div class="flex flex-wrap items-center gap-1.5">
                      <span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset ring-black/5" :class="ESTADOS_EJEMPLAR[h.estado_anterior]?.badge">
                        {{ etiquetaEstado(h, 'anterior') }}
                      </span>
                      <span class="text-gray-400" aria-label="pasó a">→</span>
                      <span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset ring-black/5" :class="ESTADOS_EJEMPLAR[h.estado_nuevo]?.badge">
                        {{ etiquetaEstado(h, 'nuevo') }}
                      </span>
                    </div>
                  </td>
                  <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                      <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[#b08d57] text-[10px] font-bold text-[#1a2430]" aria-hidden="true">
                        {{ iniciales(h.staff?.nombre) }}
                      </span>
                      <span class="text-sm text-gray-700">{{ h.staff?.nombre ?? 'Sin registro' }}</span>
                    </div>
                  </td>
                  <td class="px-4 py-3">
                    <p v-if="mismoLote" class="text-[11px] font-semibold text-violet-600">Mismo lote que la fila anterior</p>
                    <template v-else>
                      <span v-if="h.motivo && h.estado_nuevo === 'de_baja'" class="inline-block rounded-md bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">
                        {{ h.motivo }}
                      </span>
                      <p v-else-if="h.motivo" class="text-sm text-gray-700">{{ h.motivo }}</p>
                      <p v-else class="text-xs text-gray-400">Sin motivo</p>
                      <button
                        v-if="h.lote_id"
                        type="button"
                        class="mt-1.5 inline-flex items-center gap-1.5 rounded-md border border-violet-200 bg-violet-50 px-2 py-1 text-[11px] font-semibold text-violet-700 transition-colors hover:bg-violet-100"
                        :aria-expanded="lotesAbiertos.has(h.id)"
                        @click="alternarLote(h.id)"
                      >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M4 5h6v6H4V5zm10 0h6v6h-6V5zM4 13h6v6H4v-6zm10 0h6v6h-6v-6z" />
                        </svg>
                        Lote <span class="font-mono">{{ h.lote_id.slice(0, 8) }}</span> · {{ resumenLote(h).total }} {{ resumenLote(h).total === 1 ? 'copia' : 'copias' }}
                        <svg class="h-3 w-3 transition-transform" :class="lotesAbiertos.has(h.id) ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                        </svg>
                      </button>
                    </template>
                  </td>
                </tr>
                <tr v-if="h.lote_id && !mismoLote && lotesAbiertos.has(h.id)">
                  <td class="border-l-4 border-l-transparent"></td>
                  <td colspan="4" class="px-4 pb-3">
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-violet-200 bg-violet-50/70 px-4 py-3">
                      <div class="min-w-0">
                        <p class="text-xs font-semibold text-violet-700">
                          Cambio masivo · {{ resumenLote(h).total }} {{ resumenLote(h).total === 1 ? 'copia' : 'copias' }} ·
                          {{ resumenLote(h).desde }} → {{ resumenLote(h).hacia }}
                        </p>
                        <p class="mt-0.5 break-all font-mono text-sm text-gray-800">{{ h.lote_id }}</p>
                        <p class="mt-0.5 text-xs text-gray-500">
                          {{ resumenLote(h).copias.join(' · ') }}<template v-if="resumenLote(h).faltan > 0"> · y {{ resumenLote(h).faltan }} más</template>
                        </p>
                      </div>
                      <div class="flex shrink-0 gap-2">
                        <button
                          type="button"
                          class="rounded-lg border border-violet-300 bg-white px-3 py-1.5 text-xs font-semibold text-violet-700 transition-colors hover:bg-violet-50"
                          @click="copiarId(h.lote_id)"
                        >
                          Copiar ID
                        </button>
                        <button
                          v-if="filtros.lote_id !== h.lote_id"
                          type="button"
                          class="rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-violet-700"
                          @click="verLote(h.lote_id)"
                        >
                          Ver todo el lote
                        </button>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
            <tbody v-if="!historial.length">
              <tr>
                <td colspan="5" class="px-6 py-10 text-center text-sm text-gray-400">
                  {{ cargando ? 'Cargando cambios…' : 'Sin cambios de estado que coincidan con los filtros.' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </StaffLayout>
</template>
