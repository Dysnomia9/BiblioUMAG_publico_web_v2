<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import { descargarExcel } from '@/utils/excel'
import type { CategoriaOperacionRegistro, OperacionRegistro, RegistroAbsolutoResumen } from '@/types'

const toast = useToast()

const operaciones = ref<OperacionRegistro[]>([])
const total = ref(0)
const cargando = ref(true)
const apiError = ref(false)

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
    const { data } = await api.get<RegistroAbsolutoResumen>('/registro-absoluto', {
      params: {
        desde: filtros.desde || undefined,
        hasta: filtros.hasta || undefined,
        tipo: filtros.tipos,
        q: filtros.q.trim() || undefined,
      },
    })
    operaciones.value = data.operaciones
    total.value = data.total
    apiError.value = false
  } catch {
    apiError.value = true
    operaciones.value = []
    total.value = 0
  } finally {
    cargando.value = false
  }
}

watch(filtros, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(cargar, 300)
}, { deep: true })

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

// Exporta exactamente lo que está cargado en pantalla — mismo rango de fechas,
// tipo y búsqueda que los filtros activos, sin volver a pedirle nada al usuario.
async function exportarExcel() {
  if (!operaciones.value.length) {
    toast.error('No hay operaciones para exportar con los filtros actuales')
    return
  }
  exportando.value = true
  try {
    await descargarExcel(`registro-absoluto_${filtros.desde}_a_${filtros.hasta}.xlsx`, [
      {
        titulo: 'Registro Absoluto',
        columnas: ['Fecha / Hora', 'Tipo', 'Usuario', 'RUT', 'Detalle', 'Estado', 'Atendido por'],
        filas: operaciones.value.map((op) => [
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
    toast.success('Excel descargado')
  } catch {
    toast.error('No se pudo generar el Excel')
  } finally {
    exportando.value = false
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
            Todas las operaciones solicitadas por usuarios — préstamos, reservas de sala, reservas de libro y entradas — en un solo lugar
          </p>
        </div>
      </div>

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
          {{ cargando ? 'Cargando…' : `${total} operación(es) encontrada(s)` }}
        </p>
        <button
          @click="exportarExcel"
          :disabled="exportando || !operaciones.length"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors font-medium text-xs disabled:opacity-60"
        >
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2-8H8a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V8l-4-4z" />
          </svg>
          {{ exportando ? 'Generando…' : 'Exportar a Excel' }}
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
                :class="idx % 2 === 0 ? 'bg-white' : 'bg-biblioteca-50'"
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
    </div>
  </StaffLayout>
</template>
