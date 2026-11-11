<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import BitacoraPersonal from '@/components/registro/BitacoraPersonal.vue'
import FondoRegistro from '@/components/registro/FondoRegistro.vue'
import Paginador from '@/components/ui/Paginador.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import { descargarExcel } from '@/utils/excel'
import type { CategoriaOperacionRegistro, OperacionRegistro, RegistroAbsolutoResumen } from '@/types'

const toast = useToast()
const route = useRoute()
const router = useRouter()

// Dos registros: lo que pidieron los usuarios (préstamos, reservas, entradas) y la
// bitácora de lo que hizo cada persona del personal (quién prestó, multó, editó…).
type Pestana = 'operaciones' | 'personal'
const pestana = ref<Pestana>(route.query.pestana === 'personal' ? 'personal' : 'operaciones')

function elegirPestana(p: Pestana) {
  pestana.value = p
  router.replace({ query: { ...route.query, pestana: p } })
}

const operaciones = ref<OperacionRegistro[]>([])
const total = ref(0)
const cargando = ref(true)
const apiError = ref(false)
// De a 50: con meses de entradas y préstamos el registro son miles de filas.
const POR_PAGINA = 50
const pagina = ref(1)
const meta = ref({ current_page: 1, last_page: 1, from: null as number | null, to: null as number | null })

function fechaIso(offsetDias: number): string {
  const d = new Date()
  d.setDate(d.getDate() + offsetDias)
  return d.toISOString().slice(0, 10)
}

const TIPOS_DISPONIBLES: { value: CategoriaOperacionRegistro; label: string }[] = [
  { value: 'prestamo', label: 'Préstamos' },
  { value: 'reserva_sala', label: 'Reservas de Sala' },
  { value: 'reserva_libro', label: 'Reservas de Material' },
  { value: 'entrada', label: 'Entradas' },
]

const filtros = reactive({
  desde: fechaIso(-30),
  hasta: fechaIso(0),
  tipos: TIPOS_DISPONIBLES.map((t) => t.value) as CategoriaOperacionRegistro[],
  q: '',
})

let debounceTimer: ReturnType<typeof setTimeout> | undefined

const parametros = (numero: number, porPagina: number) => ({
  desde: filtros.desde || undefined,
  hasta: filtros.hasta || undefined,
  tipo: filtros.tipos,
  q: filtros.q.trim() || undefined,
  page: numero,
  por_pagina: porPagina,
})

async function cargar() {
  if (!filtros.tipos.length) {
    operaciones.value = []
    total.value = 0
    apiError.value = false
    cargando.value = false
    return
  }

  cargando.value = true
  try {
    const { data } = await api.get<RegistroAbsolutoResumen>('/registro-absoluto', { params: parametros(pagina.value, POR_PAGINA) })
    operaciones.value = data.operaciones
    total.value = data.total
    meta.value = { current_page: data.current_page, last_page: data.last_page, from: data.from, to: data.to }
    apiError.value = false
  } catch {
    apiError.value = true
    operaciones.value = []
    total.value = 0
  } finally {
    cargando.value = false
  }
}

// Un filtro nuevo vuelve a la primera página.
watch(filtros, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    pagina.value = 1
    cargar()
  }, 300)
}, { deep: true })

function irAPagina(nueva: number) {
  pagina.value = nueva
  cargar()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

onMounted(cargar)

const TIPO_LABELS: Record<string, string> = {
  prestamo_libro: 'Préstamo de Material',
  prestamo_equipo: 'Préstamo de Equipo',
  reserva_sala: 'Reserva de Sala',
  reserva_libro: 'Reserva de Material',
  entrada: 'Entrada',
}

const TIPO_BADGES: Record<string, string> = {
  prestamo_libro: 'bg-indigo-50 text-indigo-700',
  prestamo_equipo: 'bg-sky-50 text-sky-700',
  reserva_sala: 'bg-amber-50 text-amber-800',
  reserva_libro: 'bg-purple-50 text-purple-700',
  entrada: 'bg-emerald-50 text-emerald-700',
}

const ESTADO_LABELS: Record<string, string> = {
  activo: 'Activo',
  devuelto: 'Devuelto',
  activa: 'Activa',
  finalizada: 'Finalizada',
  no_show: 'No se presentó',
  pendiente: 'Pendiente',
  en_cola: 'En cola',
  retirado: 'Retirado',
  cancelada: 'Cancelada',
}

// Color de cada tipo: su etiqueta, el borde de la fila y la casilla del filtro.
const TIPO_BORDE: Record<string, string> = {
  prestamo_libro: 'border-l-indigo-500',
  prestamo_equipo: 'border-l-sky-500',
  reserva_sala: 'border-l-amber-500',
  reserva_libro: 'border-l-purple-500',
  entrada: 'border-l-emerald-500',
}
const TIPO_FILTRO: Record<string, string> = {
  prestamo: 'border-indigo-200 bg-indigo-50 text-indigo-800',
  reserva_sala: 'border-amber-200 bg-amber-50 text-amber-800',
  reserva_libro: 'border-purple-200 bg-purple-50 text-purple-800',
  entrada: 'border-emerald-200 bg-emerald-50 text-emerald-800',
}
const ESTADO_BADGES: Record<string, string> = {
  activo: 'bg-emerald-50 text-emerald-700',
  activa: 'bg-emerald-50 text-emerald-700',
  devuelto: 'bg-sky-50 text-sky-700',
  finalizada: 'bg-sky-50 text-sky-700',
  retirado: 'bg-sky-50 text-sky-700',
  pendiente: 'bg-amber-50 text-amber-800',
  en_cola: 'bg-gray-100 text-gray-600',
  no_show: 'bg-orange-50 text-orange-700',
  cancelada: 'bg-red-50 text-red-700',
}

/** «11-11-2026» y «05:29 a. m.» por separado, para la columna de fecha. */
function partesFecha(iso: string | null) {
  if (!iso) return { fecha: '—', hora: '' }
  const d = new Date(iso)
  return {
    fecha: d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' }),
    hora: d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' }),
  }
}

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}

function estadoLabel(estado: string | null): string {
  if (!estado) return '—'
  return ESTADO_LABELS[estado] ?? estado
}

function formatFechaHora(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const exportando = ref(false)
const confirmandoExportar = ref(false)
const progreso = ref('')
// Más que esto no cabe cómodo en un Excel: se pide acotar las fechas.
const MAXIMO_EXPORTACION = 20000

const fechaCorta = (iso: string) => (iso ? new Date(`${iso}T12:00:00`).toLocaleDateString('es-CL') : '—')
const tiposElegidos = () =>
  TIPOS_DISPONIBLES.filter((t) => filtros.tipos.includes(t.value))
    .map((t) => t.label.toLowerCase())
    .join(', ')

function pedirExportar() {
  if (!total.value) {
    toast.error('No hay operaciones para exportar con los filtros actuales')
    return
  }
  if (total.value > MAXIMO_EXPORTACION) {
    toast.error(`Son ${total.value.toLocaleString('es-CL')} operaciones: acota las fechas o los filtros (máximo ${MAXIMO_EXPORTACION.toLocaleString('es-CL')} por archivo).`)
    return
  }
  confirmandoExportar.value = true
}

// Exporta todo lo que coincide con los filtros (mismo rango de fechas, tipo y
// búsqueda), no solo la página en pantalla: lo trae de a 500.
async function exportarExcel() {
  exportando.value = true
  try {
    const todas: OperacionRegistro[] = []
    let numero = 1
    let ultima = 1
    do {
      progreso.value = `Trayendo el registro: ${numero} de ${ultima}…`
      const { data } = await api.get<RegistroAbsolutoResumen>('/registro-absoluto', { params: parametros(numero, 500) })
      todas.push(...data.operaciones)
      ultima = data.last_page
      numero++
    } while (numero <= ultima)

    progreso.value = 'Armando el Excel…'
    await descargarExcel(`registro-absoluto_${filtros.desde}_a_${filtros.hasta}.xlsx`, [
      {
        titulo: 'Registro Absoluto',
        columnas: ['Fecha / Hora', 'Tipo', 'Usuario', 'RUT', 'Detalle', 'Estado', 'Atendido por'],
        filas: todas.map((op) => [
          formatFechaHora(op.fecha_hora),
          TIPO_LABELS[op.tipo] ?? op.tipo,
          op.usuario_nombre ?? '—',
          op.usuario_rut ?? '—',
          op.detalle ?? '—',
          estadoLabel(op.estado),
          op.atendido_por ?? '—',
        ]),
      },
    ])
    toast.success(`Excel descargado: ${todas.length.toLocaleString('es-CL')} operaciones`)
  } catch {
    toast.error('No se pudo generar el Excel')
  } finally {
    exportando.value = false
    confirmandoExportar.value = false
    progreso.value = ''
  }
}
</script>

<template>
  <StaffLayout>
    <div class="max-w-6xl mx-auto">
      <!-- La caja de título con motivo propio: un libro de registro y una línea de tiempo. -->
      <div
        class="relative mb-5 overflow-hidden rounded-2xl shadow-md"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <FondoRegistro />
        <div class="relative px-6 py-4">
          <nav class="text-xs text-white/75" aria-label="Ruta">
            <button type="button" class="font-medium text-white hover:underline" @click="abrirMenuGestion">Gestión</button>
            <span class="mx-1.5">›</span>
            <span>Administración del sistema</span>
          </nav>
          <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Registro absoluto</h1>
          <p class="mt-0.5 max-w-xl text-sm text-white/75">
            Las operaciones que pidieron los usuarios y cada acción del personal (quién prestó, devolvió, multó, cambió estados, creó o editó) en un solo lugar.
          </p>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row gap-1 mb-5 bg-white rounded-xl shadow-sm p-1" role="tablist">
        <button
          v-for="p in ([{ clave: 'operaciones', label: 'Operaciones de usuarios' }, { clave: 'personal', label: 'Acciones del personal' }] as const)"
          :key="p.clave"
          type="button"
          role="tab"
          :aria-selected="pestana === p.clave"
          class="flex-1 py-2.5 px-4 rounded-lg text-sm font-medium transition-colors"
          :class="pestana === p.clave ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100'"
          @click="elegirPestana(p.clave)"
        >
          {{ p.label }}
        </button>
      </div>

      <BitacoraPersonal v-if="pestana === 'personal'" />

      <template v-else>
        <ApiErrorBanner v-if="apiError" />

        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div class="space-y-3 border-b border-gray-100 bg-indigo-50/40 p-4">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-[10rem_10rem_minmax(0,1fr)]">
              <div>
                <label for="reg-desde" class="mb-1 block text-xs font-medium text-gray-600">Desde</label>
                <input id="reg-desde" v-model="filtros.desde" type="date" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500" />
              </div>
              <div>
                <label for="reg-hasta" class="mb-1 block text-xs font-medium text-gray-600">Hasta</label>
                <input id="reg-hasta" v-model="filtros.hasta" type="date" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500" />
              </div>
              <div class="col-span-2 sm:col-span-1">
                <label for="reg-q" class="mb-1 block text-xs font-medium text-gray-600">Buscar por nombre o RUT</label>
                <div class="relative">
                  <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.350-4.350M11 19a8 8 0 100-16 8 8 0 000 16z" /></svg>
                  <input id="reg-q" v-model="filtros.q" type="text" placeholder="Ej: Fernanda Ríos, 11.111.111-1" class="w-full rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500" />
                </div>
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <span class="mr-1 text-xs font-medium text-gray-600">Tipo de operación</span>
              <label
                v-for="t in TIPOS_DISPONIBLES"
                :key="t.value"
                class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors"
                :class="filtros.tipos.includes(t.value) ? TIPO_FILTRO[t.value] : 'border-gray-200 bg-white text-gray-500 hover:bg-gray-50'"
              >
                <input v-model="filtros.tipos" type="checkbox" :value="t.value" class="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                {{ t.label }}
              </label>
            </div>
          </div>

          <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-2">
            <p class="text-xs text-gray-500">
              <template v-if="cargando">Cargando…</template>
              <template v-else><strong class="text-gray-900">{{ total.toLocaleString('es-CL') }}</strong> {{ total === 1 ? 'operación encontrada' : 'operaciones encontradas' }}</template>
            </p>
            <button
              @click="pedirExportar"
              :disabled="exportando || !operaciones.length"
              class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50/60 px-3 py-1.5 text-xs font-semibold text-emerald-700 shadow-sm transition-colors hover:bg-emerald-100 disabled:opacity-60"
            >
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2-8H8a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V8l-4-4z" />
              </svg>
              Exportar a Excel
            </button>
          </div>

          <div v-if="!filtros.tipos.length" class="px-6 py-8 text-center text-sm text-gray-400">
            Selecciona al menos un tipo de operación para ver resultados.
          </div>

          <div v-else class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr class="text-left text-[11px] font-bold uppercase tracking-wider text-white" style="background: linear-gradient(90deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
                  <th class="px-4 py-2.5">Fecha y hora</th>
                  <th class="px-4 py-2.5">Tipo</th>
                  <th class="px-4 py-2.5">Usuario</th>
                  <th class="px-4 py-2.5">Detalle</th>
                  <th class="px-4 py-2.5">Estado</th>
                  <th class="px-4 py-2.5">Atendido por</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="op in operaciones" :key="`${op.tipo}-${op.origen_id}`" class="even:bg-gray-50/50">
                  <td class="whitespace-nowrap border-l-[3px] px-4 py-2.5" :class="TIPO_BORDE[op.tipo] ?? 'border-l-gray-300'">
                    <span class="block text-sm font-semibold text-gray-900">{{ partesFecha(op.fecha_hora).fecha }}</span>
                    <span class="block font-mono text-xs text-gray-500">{{ partesFecha(op.fecha_hora).hora }}</span>
                  </td>
                  <td class="px-4 py-2.5">
                    <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold" :class="TIPO_BADGES[op.tipo]">{{ TIPO_LABELS[op.tipo] ?? op.tipo }}</span>
                  </td>
                  <td class="px-4 py-2.5">
                    <span class="block text-sm font-semibold text-gray-900">{{ op.usuario_nombre ?? '—' }}</span>
                    <span v-if="op.usuario_rut" class="mt-0.5 inline-block rounded border border-gray-200 bg-gray-50 px-1.5 py-px font-mono text-[11px] text-gray-700">{{ op.usuario_rut }}</span>
                  </td>
                  <td class="px-4 py-2.5 text-sm text-gray-700">{{ op.detalle ?? '—' }}</td>
                  <td class="px-4 py-2.5">
                    <span v-if="op.estado" class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold" :class="ESTADO_BADGES[op.estado] ?? 'bg-gray-100 text-gray-600'">{{ estadoLabel(op.estado) }}</span>
                    <span v-else class="text-xs text-gray-400">Sin estado</span>
                  </td>
                  <td class="px-4 py-2.5 text-sm text-gray-700">{{ op.atendido_por ?? '—' }}</td>
                </tr>
                <tr v-if="!cargando && !operaciones.length">
                  <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-400">Sin operaciones que coincidan con los filtros.</td>
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
            unidad="operaciones"
            :cargando="cargando"
            @update:pagina="irAPagina"
          />
        </section>
      </template>
    </div>

    <DialogoConfirmacion
      :abierto="confirmandoExportar"
      titulo="¿Exportar el registro?"
      confirmar="Confirmar y exportar"
      tono="exito"
      destacado
      :cargando="exportando"
      @confirmar="exportarExcel"
      @cancelar="confirmandoExportar = false"
    >
      <p>
        Se descargará en Excel (.xlsx) el registro del <strong>{{ fechaCorta(filtros.desde) }}</strong> al <strong>{{ fechaCorta(filtros.hasta) }}</strong>:
        {{ total.toLocaleString('es-CL') }} {{ total === 1 ? 'operación' : 'operaciones' }} ({{ tiposElegidos() }}<template v-if="filtros.q.trim()">, búsqueda «{{ filtros.q.trim() }}»</template>), todas las páginas.
      </p>
      <p v-if="exportando" class="mt-2 font-medium text-gray-700">{{ progreso }}</p>
      <p class="mt-2">El archivo incluye nombres y RUT de usuarios: guárdalo solo donde corresponda.</p>
    </DialogoConfirmacion>
  </StaffLayout>
</template>
