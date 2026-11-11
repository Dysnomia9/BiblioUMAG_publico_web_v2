<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import Paginador from '@/components/ui/Paginador.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import { descargarExcel } from '@/utils/excel'
import type { AccionBitacora, BitacoraOpciones, BitacoraResumen } from '@/types'

/**
 * Bitácora de quién hizo qué: préstamos, devoluciones, multas, cambios de estado,
 * altas y ediciones de estudiantes, configuración, cuentas del personal… Cada fila
 * dice quién actuó, cuándo, sobre quién, y lo que cambió.
 */
const toast = useToast()

const acciones = ref<AccionBitacora[]>([])
const total = ref(0)
// De a 50: la bitácora suma filas con cada acción del personal, todos los días.
const pagina = ref(1)
const meta = ref({ current_page: 1, last_page: 1, from: null as number | null, to: null as number | null })
const cargando = ref(true)
const apiError = ref(false)
const opciones = ref<BitacoraOpciones>({ modulos: [], personal: [] })
const expandidas = ref<Set<number>>(new Set())

function fechaIso(offsetDias: number): string {
  const d = new Date()
  d.setDate(d.getDate() + offsetDias)
  return d.toLocaleDateString('sv-SE')
}

const filtros = reactive({
  desde: fechaIso(-30),
  hasta: fechaIso(0),
  modulos: [] as string[],
  staff_id: '' as number | '',
  q: '',
})

const COLORES: Record<string, string> = {
  entradas: 'bg-emerald-50 text-emerald-700',
  prestamos: 'bg-indigo-50 text-indigo-700',
  reservas_libro: 'bg-purple-50 text-purple-700',
  salas: 'bg-amber-50 text-amber-800',
  multas: 'bg-red-50 text-red-700',
  libros: 'bg-indigo-50 text-indigo-700',
  equipos: 'bg-teal-50 text-teal-700',
  usuarios: 'bg-sky-50 text-sky-700',
  configuracion: 'bg-gray-100 text-gray-700',
  personal: 'bg-amber-50 text-amber-900',
  sesion: 'bg-stone-100 text-stone-600',
}

// El borde de color de cada fila, según el módulo.
const BORDES: Record<string, string> = {
  entradas: 'border-l-emerald-500', prestamos: 'border-l-indigo-500', reservas_libro: 'border-l-purple-500', salas: 'border-l-amber-500',
  multas: 'border-l-red-500', libros: 'border-l-indigo-500', equipos: 'border-l-teal-500', usuarios: 'border-l-sky-500',
  configuracion: 'border-l-gray-400', personal: 'border-l-amber-700', sesion: 'border-l-stone-400',
}
const PUNTOS: Record<string, string> = {
  entradas: 'bg-emerald-500', prestamos: 'bg-indigo-500', reservas_libro: 'bg-purple-500', salas: 'bg-amber-500', multas: 'bg-red-500',
  libros: 'bg-indigo-500', equipos: 'bg-teal-500', usuarios: 'bg-sky-500', configuracion: 'bg-gray-400', personal: 'bg-amber-700', sesion: 'bg-stone-400',
}

// Filtro de módulos: un botón que abre un panel con los módulos agrupados; lo elegido queda como etiquetas.
const GRUPOS: { titulo: string; claves: string[] }[] = [
  { titulo: 'Atención', claves: ['entradas', 'prestamos', 'reservas_libro', 'salas', 'multas'] },
  { titulo: 'Colección', claves: ['libros', 'equipos'] },
  { titulo: 'Administración', claves: ['usuarios', 'configuracion', 'personal', 'sesion'] },
]
const gruposModulos = computed(() => {
  const conocidas = new Set(GRUPOS.flatMap((g) => g.claves))
  const grupos = GRUPOS.map((g) => ({ titulo: g.titulo, modulos: opciones.value.modulos.filter((m) => g.claves.includes(m.clave)) }))
  const otros = opciones.value.modulos.filter((m) => !conocidas.has(m.clave))
  if (otros.length) grupos.push({ titulo: 'Otros', modulos: otros })
  return grupos.filter((g) => g.modulos.length)
})
const panelModulos = ref(false)
const filtroModulos = ref<HTMLElement | null>(null)
function cerrarPanelAfuera(e: MouseEvent) {
  if (panelModulos.value && filtroModulos.value && !filtroModulos.value.contains(e.target as Node)) panelModulos.value = false
}
onMounted(() => document.addEventListener('click', cerrarPanelAfuera))
onBeforeUnmount(() => document.removeEventListener('click', cerrarPanelAfuera))

/** «11-11-2026» y «05:29 a. m.» por separado, para la columna de fecha. */
function partesFecha(iso: string) {
  const d = new Date(iso)
  return { fecha: d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' }), hora: d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' }) }
}

const nombreModulo = (clave: string) => opciones.value.modulos.find((m) => m.clave === clave)?.nombre ?? clave

async function cargarOpciones() {
  try {
    const { data } = await api.get<BitacoraOpciones>('/bitacora/opciones')
    opciones.value = data
  } catch {
    // Sin opciones, los filtros de módulo y persona quedan vacíos.
  }
}

const parametros = (numero: number, porPagina: number) => ({
  desde: filtros.desde || undefined,
  hasta: filtros.hasta || undefined,
  modulo: filtros.modulos.length ? filtros.modulos : undefined,
  staff_id: filtros.staff_id || undefined,
  q: filtros.q.trim() || undefined,
  page: numero,
  por_pagina: porPagina,
})

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<BitacoraResumen>('/bitacora', { params: parametros(pagina.value, 50) })
    acciones.value = data.acciones
    total.value = data.total
    meta.value = { current_page: data.current_page, last_page: data.last_page, from: data.from, to: data.to }
    apiError.value = false
  } catch {
    apiError.value = true
    acciones.value = []
    total.value = 0
  } finally {
    cargando.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(filtros, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    pagina.value = 1
    cargar()
  }, 300)
}, { deep: true })

function irAPagina(nueva: number) {
  pagina.value = nueva
  cargar()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

onMounted(() => {
  cargarOpciones()
  cargar()
})

function alternarModulo(clave: string) {
  const i = filtros.modulos.indexOf(clave)
  if (i === -1) filtros.modulos.push(clave)
  else filtros.modulos.splice(i, 1)
}

function alternarDetalle(id: number) {
  const nuevo = new Set(expandidas.value)
  if (nuevo.has(id)) nuevo.delete(id)
  else nuevo.add(id)
  expandidas.value = nuevo
}

const formatFechaHora = (iso: string) =>
  new Date(iso).toLocaleString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })

function valorLegible(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  if (typeof v === 'boolean') return v ? 'sí' : 'no'
  if (Array.isArray(v)) return v.map(valorLegible).join(', ') || '—'
  if (typeof v === 'object') return Object.entries(v as Record<string, unknown>).map(([k, x]) => `${k.replace(/_/g, ' ')}: ${valorLegible(x)}`).join('; ')
  return String(v)
}

/** Los datos guardados, legibles: un cambio [antes, después] se muestra «antes → después». */
function detalle(a: AccionBitacora): { campo: string; valor: string }[] {
  return Object.entries(a.datos ?? {}).map(([campo, v]) => ({
    campo: campo.replace(/_/g, ' '),
    valor: Array.isArray(v) && v.length === 2 && !Array.isArray(v[0]) && typeof v[0] !== 'object' ? `${valorLegible(v[0])} → ${valorLegible(v[1])}` : valorLegible(v),
  }))
}

const confirmandoExportar = ref(false)
const exportando = ref(false)
const progreso = ref('')
const MAXIMO_EXPORTACION = 20000

function pedirExportar() {
  if (!total.value) {
    toast.error('No hay acciones para exportar con los filtros actuales')
    return
  }
  if (total.value > MAXIMO_EXPORTACION) {
    toast.error(`Son ${total.value.toLocaleString('es-CL')} acciones: acota las fechas o los filtros (máximo ${MAXIMO_EXPORTACION.toLocaleString('es-CL')} por archivo).`)
    return
  }
  confirmandoExportar.value = true
}

// Todo lo que coincide con los filtros, no solo la página en pantalla: de a 500.
async function exportarExcel() {
  exportando.value = true
  try {
    const todas: AccionBitacora[] = []
    let numero = 1
    let ultima = 1
    do {
      progreso.value = `Trayendo la bitácora: ${numero} de ${ultima}…`
      const { data } = await api.get<BitacoraResumen>('/bitacora', { params: parametros(numero, 500) })
      todas.push(...data.acciones)
      ultima = data.last_page
      numero++
    } while (numero <= ultima)

    progreso.value = 'Armando el Excel…'
    await descargarExcel(`acciones-del-personal_${filtros.desde}_a_${filtros.hasta}.xlsx`, [
      {
        titulo: 'Acciones del personal',
        columnas: ['Fecha / Hora', 'Quién', 'Módulo', 'Qué hizo', 'Sobre quién', 'Detalle'],
        filas: todas.map((a) => [
          formatFechaHora(a.fecha_hora),
          a.actor,
          nombreModulo(a.modulo),
          a.descripcion,
          a.afectado ?? '—',
          detalle(a).map((d) => `${d.campo}: ${d.valor}`).join(' | ') || '—',
        ]),
      },
    ])
    toast.success(`Excel descargado: ${todas.length.toLocaleString('es-CL')} acciones`)
    confirmandoExportar.value = false
  } catch {
    toast.error('No se pudo generar el Excel')
  } finally {
    exportando.value = false
    progreso.value = ''
  }
}

const fechaCorta = (iso: string) => (iso ? new Date(`${iso}T12:00:00`).toLocaleDateString('es-CL') : '—')
</script>

<template>
  <div>
    <ApiErrorBanner v-if="apiError" />

    <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
      <div class="space-y-2.5 rounded-t-2xl border-b border-gray-100 bg-indigo-50/40 p-4">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-[10rem_10rem_12rem_11rem_minmax(0,1fr)]">
          <div>
            <label for="bit-desde" class="mb-1 block text-xs font-medium text-gray-600">Desde</label>
            <input id="bit-desde" v-model="filtros.desde" type="date" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div>
            <label for="bit-hasta" class="mb-1 block text-xs font-medium text-gray-600">Hasta</label>
            <input id="bit-hasta" v-model="filtros.hasta" type="date" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div>
            <label for="bit-staff" class="mb-1 block text-xs font-medium text-gray-600">Quién</label>
            <select id="bit-staff" v-model="filtros.staff_id" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
              <option value="">Todo el personal</option>
              <option v-for="p in opciones.personal" :key="p.id" :value="p.id">{{ p.nombre }}{{ p.eliminada ? ' (eliminada)' : p.activo ? '' : ' (inactiva)' }}</option>
            </select>
          </div>
          <!-- Módulos: un botón con panel desplegable, en vez de una grilla de casillas siempre abierta. -->
          <div ref="filtroModulos" class="relative">
            <p class="mb-1 block text-xs font-medium text-gray-600">Módulos</p>
            <button
              type="button"
              class="flex w-full items-center justify-between gap-2 rounded-lg border bg-white px-3 py-2 text-left text-sm outline-none focus:ring-2 focus:ring-indigo-500"
              :class="filtros.modulos.length ? 'border-indigo-300 text-indigo-800' : 'border-gray-300 text-gray-700'"
              :aria-expanded="panelModulos"
              aria-haspopup="true"
              @click="panelModulos = !panelModulos"
            >
              <span class="truncate">{{ filtros.modulos.length ? `${filtros.modulos.length} ${filtros.modulos.length === 1 ? 'elegido' : 'elegidos'}` : 'Todos' }}</span>
              <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform" :class="panelModulos ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
            </button>
            <div v-if="panelModulos" class="absolute right-0 z-30 mt-1 w-[min(36rem,calc(100vw-3rem))] lg:left-0 lg:right-auto rounded-xl border border-gray-200 bg-white p-3 shadow-xl">
              <div class="grid gap-3 sm:grid-cols-3">
                <div v-for="g in gruposModulos" :key="g.titulo">
                  <p class="mb-1 text-[10px] font-bold uppercase tracking-widest text-gray-500">{{ g.titulo }}</p>
                  <label v-for="m in g.modulos" :key="m.clave" class="flex cursor-pointer items-center gap-2 rounded-md px-1.5 py-1 text-xs text-gray-700 hover:bg-gray-50">
                    <input type="checkbox" :checked="filtros.modulos.includes(m.clave)" class="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @change="alternarModulo(m.clave)" />
                    <span class="h-2 w-2 shrink-0 rounded-sm" :class="PUNTOS[m.clave] ?? 'bg-gray-400'" aria-hidden="true"></span>
                    {{ m.nombre }}
                  </label>
                </div>
              </div>
              <div class="mt-2 flex justify-between border-t border-gray-100 pt-2">
                <button type="button" class="text-xs font-semibold text-gray-500 hover:underline disabled:opacity-40" :disabled="!filtros.modulos.length" @click="filtros.modulos = []">Ver todos</button>
                <button type="button" class="text-xs font-semibold text-indigo-700 hover:underline" @click="panelModulos = false">Listo</button>
              </div>
            </div>
          </div>
          <div class="col-span-2 lg:col-span-1">
            <label for="bit-q" class="mb-1 block text-xs font-medium text-gray-600">Buscar (qué se hizo, sobre quién o quién)</label>
            <div class="relative">
              <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.350-4.350M11 19a8 8 0 100-16 8 8 0 000 16z" /></svg>
              <input
                id="bit-q"
                v-model="filtros.q"
                type="text"
                placeholder="Ej: multa, Cálculo I, Valentina Soto, 11.111.111-1"
                class="w-full rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
          </div>
        </div>
        <!-- Lo elegido, como etiquetas que se quitan con la ×. -->
        <div v-if="filtros.modulos.length" class="flex flex-wrap items-center gap-1.5">
          <span
            v-for="clave in filtros.modulos"
            :key="clave"
            class="inline-flex items-center gap-1.5 rounded-full py-0.5 pl-2.5 pr-1 text-xs font-semibold"
            :class="COLORES[clave] ?? 'bg-gray-100 text-gray-700'"
          >
            {{ nombreModulo(clave) }}
            <button type="button" class="flex h-4 w-4 items-center justify-center rounded-full hover:bg-black/10" :aria-label="`Quitar ${nombreModulo(clave)}`" @click="alternarModulo(clave)">×</button>
          </span>
          <button type="button" class="text-xs font-semibold text-gray-500 hover:underline" @click="filtros.modulos = []">Ver todos</button>
        </div>
      </div>

      <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-2">
        <p class="text-xs text-gray-500">
          <template v-if="cargando">Cargando…</template>
          <template v-else><strong class="text-gray-900">{{ total.toLocaleString('es-CL') }}</strong> {{ total === 1 ? 'acción' : 'acciones' }}</template>
        </p>
        <button
          type="button"
          :disabled="exportando || !acciones.length"
          class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50/60 px-3 py-1.5 text-xs font-semibold text-emerald-700 shadow-sm transition-colors hover:bg-emerald-100 disabled:opacity-60"
          @click="pedirExportar"
        >
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2-8H8a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V8l-4-4z" />
          </svg>
          Exportar a Excel
        </button>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full">
          <thead>
            <tr class="text-left text-[11px] font-bold uppercase tracking-wider text-white" style="background: linear-gradient(90deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
              <th class="px-4 py-2.5">Fecha y hora</th>
              <th class="px-4 py-2.5">Quién</th>
              <th class="px-4 py-2.5">Módulo</th>
              <th class="px-4 py-2.5">Qué hizo</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="a in acciones" :key="a.id" class="align-top even:bg-gray-50/50">
              <td class="whitespace-nowrap border-l-[3px] px-4 py-2.5" :class="BORDES[a.modulo] ?? 'border-l-gray-300'">
                <span class="block text-sm font-semibold text-gray-900">{{ partesFecha(a.fecha_hora).fecha }}</span>
                <span class="block font-mono text-xs text-gray-500">{{ partesFecha(a.fecha_hora).hora }}</span>
              </td>
              <td class="px-4 py-2.5 text-sm">
                <span class="font-semibold text-gray-900">{{ a.actor }}</span>
                <span v-if="a.actor_tipo !== 'staff'" class="block text-xs text-gray-500">{{ a.actor_tipo === 'usuario' ? 'usuario, desde el portal' : 'automático' }}</span>
              </td>
              <td class="px-4 py-2.5">
                <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold" :class="COLORES[a.modulo] ?? 'bg-gray-100 text-gray-700'">{{ nombreModulo(a.modulo) }}</span>
              </td>
              <td class="px-4 py-2.5 text-sm text-gray-700">
                <p>{{ a.descripcion }}</p>
                <p v-if="a.afectado" class="mt-0.5 text-xs text-gray-500">Sobre: <span class="font-semibold text-gray-700">{{ a.afectado }}</span></p>
                <button
                  v-if="a.datos && Object.keys(a.datos).length"
                  type="button"
                  class="mt-1 inline-flex items-center gap-1 rounded-md border border-indigo-100 bg-indigo-50/60 px-2 py-0.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100"
                  :aria-expanded="expandidas.has(a.id)"
                  @click="alternarDetalle(a.id)"
                >
                  {{ expandidas.has(a.id) ? 'Ocultar detalle' : 'Ver detalle' }}
                  <svg class="h-3 w-3 transition-transform" :class="expandidas.has(a.id) ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                </button>
                <dl v-if="expandidas.has(a.id)" class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs">
                  <template v-for="d in detalle(a)" :key="d.campo">
                    <dt class="font-medium capitalize text-gray-500">{{ d.campo }}</dt>
                    <dd class="break-words text-gray-800">{{ d.valor }}</dd>
                  </template>
                </dl>
              </td>
            </tr>
            <tr v-if="!cargando && !acciones.length">
              <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-400">Sin acciones que coincidan con los filtros.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Paginador
        class="overflow-x-auto border-t border-gray-100 px-4 py-2"
        :pagina="meta.current_page"
        :paginas="meta.last_page"
        :total="total"
        :desde="meta.from"
        :hasta="meta.to"
        unidad="acciones"
        :cargando="cargando"
        @update:pagina="irAPagina"
      />
    </section>

    <DialogoConfirmacion
      :abierto="confirmandoExportar"
      titulo="¿Exportar las acciones del personal?"
      confirmar="Confirmar y exportar"
      tono="exito"
      destacado
      :cargando="exportando"
      @confirmar="exportarExcel"
      @cancelar="confirmandoExportar = false"
    >
      <p>
        Se descargará en Excel (.xlsx) lo hecho entre el <strong>{{ fechaCorta(filtros.desde) }}</strong> y el
        <strong>{{ fechaCorta(filtros.hasta) }}</strong>: {{ total.toLocaleString('es-CL') }} {{ total === 1 ? 'acción' : 'acciones' }}, todas las páginas.
      </p>
      <p v-if="exportando" class="mt-2 font-medium text-gray-700">{{ progreso }}</p>
      <p class="mt-2">El archivo incluye nombres y RUT: guárdalo solo donde corresponda.</p>
    </DialogoConfirmacion>
  </div>
</template>
