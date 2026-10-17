<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import BitacoraPersonal from '@/components/registro/BitacoraPersonal.vue'
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
  { value: 'reserva_libro', label: 'Reservas de Libro' },
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
  prestamo_libro: 'Préstamo de Libro',
  prestamo_equipo: 'Préstamo de Equipo',
  reserva_sala: 'Reserva de Sala',
  reserva_libro: 'Reserva de Libro',
  entrada: 'Entrada',
}

const TIPO_BADGES: Record<string, string> = {
  prestamo_libro: 'bg-purple-100 text-purple-700',
  prestamo_equipo: 'bg-sky-100 text-sky-700',
  reserva_sala: 'bg-amber-100 text-amber-700',
  reserva_libro: 'bg-teal-100 text-teal-700',
  entrada: 'bg-emerald-100 text-emerald-700',
}

const ESTADO_LABELS: Record<string, string> = {
  activo: 'Activo',
  atrasado: 'Atrasado',
  devuelto: 'Devuelto',
  activa: 'Activa',
  finalizada: 'Finalizada',
  no_show: 'No se presentó',
  pendiente: 'Pendiente',
  en_cola: 'En cola',
  retirado: 'Retirado',
  cancelada: 'Cancelada',
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
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Registro Absoluto</h1>
          <p class="text-sm text-white/60 mt-1">
            Las operaciones que pidieron los usuarios y cada acción del personal — quién prestó, devolvió, multó, cambió estados, creó o editó — en un solo lugar
          </p>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row gap-1 mb-6 bg-white rounded-xl shadow-sm p-1" role="tablist">
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

        <div class="bg-white rounded-xl shadow-md p-4 mb-6 space-y-3">
          <div class="flex flex-wrap gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Desde</label>
              <input
                v-model="filtros.desde"
                type="date"
                class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Hasta</label>
              <input
                v-model="filtros.hasta"
                type="date"
                class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
              />
            </div>
            <div class="flex-1 min-w-[200px]">
              <label class="block text-xs font-medium text-gray-500 mb-1">Buscar por nombre o RUT</label>
              <input
                v-model="filtros.q"
                type="text"
                placeholder="Ej: Fernanda Ríos, 11.111.111-1"
                class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
              />
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1.5">Tipo de operación</label>
            <div class="flex flex-wrap gap-x-4 gap-y-1.5">
              <label v-for="t in TIPOS_DISPONIBLES" :key="t.value" class="flex items-center gap-1.5 text-sm text-gray-700 cursor-pointer">
                <input v-model="filtros.tipos" type="checkbox" :value="t.value" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                {{ t.label }}
              </label>
            </div>
          </div>
        </div>

        <div class="flex items-center justify-between mb-2">
          <p class="text-xs text-gray-500">
            {{ cargando ? 'Cargando…' : `${total.toLocaleString('es-CL')} ${total === 1 ? 'operación encontrada' : 'operaciones encontradas'}` }}
          </p>
          <button
            @click="pedirExportar"
            :disabled="exportando || !operaciones.length"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors font-medium text-xs disabled:opacity-60"
          >
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2-8H8a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V8l-4-4z" />
            </svg>
            Exportar a Excel
          </button>
        </div>

        <div v-if="!filtros.tipos.length" class="bg-white rounded-xl shadow-md border border-gray-200 px-6 py-8 text-center text-sm text-gray-400">
          Selecciona al menos un tipo de operación para ver resultados.
        </div>

        <div v-else class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr class="bg-gray-100 border-b-2 border-gray-200">
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Fecha / Hora</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Tipo</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Usuario</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Detalle</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Estado</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Atendido por</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200">
                <tr
                  v-for="(op, idx) in operaciones"
                  :key="`${op.tipo}-${op.origen_id}`"
                  class="hover:bg-indigo-50/40 transition-colors"
                  :class="idx % 2 === 0 ? 'bg-white' : 'bg-slate-50'"
                >
                  <td class="px-6 py-3 text-sm font-mono text-gray-600 whitespace-nowrap">{{ formatFechaHora(op.fecha_hora) }}</td>
                  <td class="px-6 py-3">
                    <span class="text-xs px-2.5 py-1 rounded-full font-medium whitespace-nowrap" :class="TIPO_BADGES[op.tipo]">
                      {{ TIPO_LABELS[op.tipo] ?? op.tipo }}
                    </span>
                  </td>
                  <td class="px-6 py-3 text-sm text-gray-900">
                    <div class="font-medium">{{ op.usuario_nombre ?? '—' }}</div>
                    <div class="text-xs font-mono text-gray-500">{{ op.usuario_rut ?? '—' }}</div>
                  </td>
                  <td class="px-6 py-3 text-sm text-gray-700">{{ op.detalle ?? '—' }}</td>
                  <td class="px-6 py-3 text-sm text-gray-600">{{ estadoLabel(op.estado) }}</td>
                  <td class="px-6 py-3 text-sm text-gray-600">{{ op.atendido_por ?? '—' }}</td>
                </tr>
                <tr v-if="!cargando && !operaciones.length">
                  <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-400">Sin operaciones que coincidan con los filtros.</td>
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
          unidad="operaciones"
          :cargando="cargando"
          @update:pagina="irAPagina"
        />
      </template>
    </div>

    <DialogoConfirmacion
      :abierto="confirmandoExportar"
      titulo="¿Exportar el registro?"
      confirmar="Confirmar y exportar"
      tono="exito"
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
