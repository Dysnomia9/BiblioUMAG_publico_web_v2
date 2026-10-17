<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
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
  entradas: 'bg-emerald-100 text-emerald-700',
  prestamos: 'bg-purple-100 text-purple-700',
  reservas_libro: 'bg-teal-100 text-teal-700',
  salas: 'bg-amber-100 text-amber-800',
  multas: 'bg-red-100 text-red-700',
  libros: 'bg-indigo-100 text-indigo-700',
  equipos: 'bg-sky-100 text-sky-700',
  usuarios: 'bg-pink-100 text-pink-700',
  configuracion: 'bg-gray-200 text-gray-700',
  personal: 'bg-slate-200 text-slate-800',
  sesion: 'bg-stone-100 text-stone-600',
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

    <div class="bg-white rounded-xl shadow-md p-4 mb-6 space-y-3">
      <div class="flex flex-wrap gap-3">
        <div>
          <label for="bit-desde" class="block text-xs font-medium text-gray-500 mb-1">Desde</label>
          <input id="bit-desde" v-model="filtros.desde" type="date" class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
        </div>
        <div>
          <label for="bit-hasta" class="block text-xs font-medium text-gray-500 mb-1">Hasta</label>
          <input id="bit-hasta" v-model="filtros.hasta" type="date" class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
        </div>
        <div>
          <label for="bit-staff" class="block text-xs font-medium text-gray-500 mb-1">Quién</label>
          <select id="bit-staff" v-model="filtros.staff_id" class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
            <option value="">Todo el personal</option>
            <option v-for="p in opciones.personal" :key="p.id" :value="p.id">{{ p.nombre }}{{ p.eliminada ? ' (eliminada)' : p.activo ? '' : ' (inactiva)' }}</option>
          </select>
        </div>
        <div class="flex-1 min-w-[200px]">
          <label for="bit-q" class="block text-xs font-medium text-gray-500 mb-1">Buscar (qué se hizo, sobre quién o quién)</label>
          <input
            id="bit-q"
            v-model="filtros.q"
            type="text"
            placeholder="Ej: multa, Cálculo I, Valentina Soto, 11.111.111-1"
            class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
          />
        </div>
      </div>
      <div>
        <p class="block text-xs font-medium text-gray-500 mb-1.5">Módulo <span class="font-normal">(sin marcar: todos)</span></p>
        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="m in opciones.modulos"
            :key="m.clave"
            type="button"
            class="rounded-full px-3 py-1 text-xs font-medium ring-1 transition"
            :class="filtros.modulos.includes(m.clave) ? `${COLORES[m.clave] ?? 'bg-gray-100 text-gray-700'} ring-transparent` : 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50'"
            :aria-pressed="filtros.modulos.includes(m.clave)"
            @click="alternarModulo(m.clave)"
          >
            {{ m.nombre }}
          </button>
        </div>
      </div>
    </div>

    <div class="flex items-center justify-between mb-2 gap-3">
      <p class="text-xs text-gray-500">
        <template v-if="cargando">Cargando…</template>
        <template v-else>{{ total }} {{ total === 1 ? 'acción' : 'acciones' }}</template>
      </p>
      <button
        type="button"
        :disabled="exportando || !acciones.length"
        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors font-medium text-xs disabled:opacity-60"
        @click="pedirExportar"
      >
        Exportar a Excel
      </button>
    </div>

    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead>
            <tr class="bg-gray-100 border-b-2 border-gray-200">
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Fecha / Hora</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Quién</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Módulo</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Qué hizo</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <template v-for="(a, idx) in acciones" :key="a.id">
              <tr class="align-top transition-colors hover:bg-indigo-50/40" :class="idx % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                <td class="px-5 py-3 text-sm font-mono text-gray-600 whitespace-nowrap">{{ formatFechaHora(a.fecha_hora) }}</td>
                <td class="px-5 py-3 text-sm text-gray-900">
                  <span class="font-medium">{{ a.actor }}</span>
                  <span v-if="a.actor_tipo !== 'staff'" class="block text-xs text-gray-500">{{ a.actor_tipo === 'usuario' ? 'usuario, desde el portal' : 'automático' }}</span>
                </td>
                <td class="px-5 py-3">
                  <span class="text-xs px-2.5 py-1 rounded-full font-medium whitespace-nowrap" :class="COLORES[a.modulo] ?? 'bg-gray-100 text-gray-700'">
                    {{ nombreModulo(a.modulo) }}
                  </span>
                </td>
                <td class="px-5 py-3 text-sm text-gray-700">
                  <p>{{ a.descripcion }}</p>
                  <p v-if="a.afectado" class="mt-0.5 text-xs text-gray-500">Sobre: {{ a.afectado }}</p>
                  <button
                    v-if="a.datos && Object.keys(a.datos).length"
                    type="button"
                    class="mt-1 text-xs font-medium text-indigo-700 hover:underline"
                    :aria-expanded="expandidas.has(a.id)"
                    @click="alternarDetalle(a.id)"
                  >
                    {{ expandidas.has(a.id) ? 'Ocultar detalle' : 'Ver detalle' }}
                  </button>
                  <dl v-if="expandidas.has(a.id)" class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg bg-white px-3 py-2 text-xs ring-1 ring-gray-200">
                    <template v-for="d in detalle(a)" :key="d.campo">
                      <dt class="font-medium capitalize text-gray-500">{{ d.campo }}</dt>
                      <dd class="break-words text-gray-800">{{ d.valor }}</dd>
                    </template>
                  </dl>
                </td>
              </tr>
            </template>
            <tr v-if="!cargando && !acciones.length">
              <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-400">Sin acciones que coincidan con los filtros.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Paginador
      class="mt-4"
      :pagina="meta.current_page"
      :paginas="meta.last_page"
      :total="total"
      :desde="meta.from"
      :hasta="meta.to"
      unidad="acciones"
      :cargando="cargando"
      @update:pagina="irAPagina"
    />

    <DialogoConfirmacion
      :abierto="confirmandoExportar"
      titulo="¿Exportar las acciones del personal?"
      confirmar="Confirmar y exportar"
      tono="exito"
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
