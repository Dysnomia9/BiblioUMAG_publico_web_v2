<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import BarChart from '@/components/reportes/BarChart.vue'
import BreakdownList from '@/components/reportes/BreakdownList.vue'
import ReporteTabla from '@/components/reportes/ReporteTabla.vue'
import Heatmap from '@/components/reportes/Heatmap.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import { descargarExcel } from '@/utils/excel'
import { construirCsv, descargarCsv } from '@/utils/csv'
import type { Periodo, ReporteOpciones, ReporteResumen, ReporteTab } from '@/types'

const toast = useToast()
const apiError = ref(false)

const tab = ref<ReporteTab>('prestamos')
const periodo = ref<Periodo>('mes')
const vista = ref<'grafico' | 'tabla'>('grafico')

const filtros = reactive({
  carrera: '',
  anio_ingreso: '',
  sexo: '',
  tipo_usuario: '',
})

const opciones = ref<ReporteOpciones>({ carreras: [], aniosIngreso: [], sexos: [], tiposUsuario: ['estudiante', 'docente', 'funcionario'] })

const resumenVacio: ReporteResumen = {
  total: 0,
  promedioPorPeriodo: 0,
  categoriaMasFrecuente: '—',
  serie: [],
  porCarrera: [],
  porSexo: [],
  porAnioIngreso: [],
  porTipoUsuario: [],
  porHora: [],
  porSala: [],
  porLibro: [],
  porHoraPorSala: [],
}

const resumen = ref<ReporteResumen>({ ...resumenVacio })
const cargando = ref(true)

const TABS: { id: ReporteTab; label: string; color: string }[] = [
  { id: 'prestamos', label: 'Número de Préstamos', color: 'emerald' },
  { id: 'ingresos', label: 'Ingresos a Biblioteca', color: 'emerald' },
  { id: 'logias', label: 'Uso de Logias', color: 'amber' },
  { id: 'libros', label: 'Libros Más Prestados', color: 'indigo' },
]

const PERIODOS: { id: Periodo; label: string }[] = [
  { id: 'dia', label: 'Día' },
  { id: 'semana', label: 'Semana' },
  { id: 'mes', label: 'Mes' },
  { id: 'semestre', label: 'Semestre' },
  { id: 'anio', label: 'Año' },
]

const colorBar: Record<ReporteTab, string> = { prestamos: '#065F46', ingresos: '#059669', logias: '#D97706', libros: '#4338CA' }
const tabTitulo: Record<ReporteTab, string> = { prestamos: 'Total préstamos', ingresos: 'Total ingresos', logias: 'Total usos de logias', libros: 'Total préstamos de libros' }

const PERIODO_ADJETIVO: Record<Periodo, string> = {
  dia: 'diaria',
  semana: 'semanal',
  mes: 'mensual',
  semestre: 'semestral',
  anio: 'anual',
}

// Paleta categórica fija (orden estable) — cada carrera queda ligada a un color
// según su posición en el listado de opciones, no según su valor en el reporte
// actual, para que el color de una carrera no cambie al cambiar filtros.
const PALETA_CATEGORICA = ['#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#e34948', '#e87ba4', '#eb6834']
const COLOR_NEUTRO = '#898781'
const COLORES_SEXO: Record<string, string> = { Femenino: '#e87ba4', Masculino: '#2a78d6' }

const carreraColorMap = computed(() => {
  const map: Record<string, string> = {}
  opciones.value.carreras.forEach((c, i) => {
    map[c] = PALETA_CATEGORICA[i % PALETA_CATEGORICA.length]
  })
  return map
})

function colorCarrera(label: string) {
  return carreraColorMap.value[label] ?? COLOR_NEUTRO
}

function colorSexo(label: string) {
  return COLORES_SEXO[label] ?? COLOR_NEUTRO
}

async function cargarOpciones() {
  try {
    const { data } = await api.get<ReporteOpciones>('/reportes/opciones')
    opciones.value = data
  } catch {
    apiError.value = true
  }
}

async function cargarResumen() {
  cargando.value = true
  try {
    const { data } = await api.get<ReporteResumen>('/reportes/resumen', {
      params: {
        tab: tab.value,
        periodo: periodo.value,
        carrera: filtros.carrera || undefined,
        anio_ingreso: filtros.anio_ingreso || undefined,
        sexo: filtros.sexo || undefined,
        tipo_usuario: filtros.tipo_usuario || undefined,
      },
    })
    resumen.value = data
    apiError.value = false
  } catch {
    apiError.value = true
    resumen.value = { ...resumenVacio }
  } finally {
    cargando.value = false
  }
}

onMounted(async () => {
  await cargarOpciones()
  await cargarResumen()
})

watch([tab, periodo, filtros], cargarResumen, { deep: true })

const exportModalOpen = ref(false)
const exportando = ref(false)
const formatoExportar = ref<'excel' | 'csv'>('excel')

function abrirModalExportar(formato: 'excel' | 'csv') {
  formatoExportar.value = formato
  exportModalOpen.value = true
}

const descripcionFiltros = computed(() => {
  const partes: string[] = []
  if (filtros.carrera) partes.push(`carrera "${filtros.carrera}"`)
  if (filtros.anio_ingreso) partes.push(`año de ingreso ${filtros.anio_ingreso}`)
  if (filtros.sexo) partes.push(`sexo ${filtros.sexo}`)
  if (filtros.tipo_usuario) partes.push(`tipo de usuario "${filtros.tipo_usuario}"`)
  return partes.length ? partes.join(', ') : 'del total (sin filtros de carrera, sexo, año o tipo de usuario)'
})

const descripcionExportacion = computed(() => {
  const tabLabel = TABS.find((t) => t.id === tab.value)?.label ?? ''
  const adjetivo = PERIODO_ADJETIVO[periodo.value]
  const formatoLabel = formatoExportar.value === 'excel' ? 'Excel (.xlsx)' : 'CSV'
  return `Se va a exportar en formato ${formatoLabel} la tabla de "${tabLabel}" con vista ${adjetivo}, ${descripcionFiltros.value}.`
})

function porcentaje(valor: number, total: number) {
  return total ? Math.round((valor / total) * 100) : 0
}

// Vista tabular alternativa al heatmap (respeta el toggle Gráfico/Tabla de la página):
// aplana sala x hora a filas ordenadas por cantidad, reutilizando ReporteTabla.
const porHoraPorSalaFlat = computed(() => {
  return resumen.value.porHoraPorSala
    .flatMap((fila) => fila.horas.map((h) => ({ label: `${fila.sala} · ${h.label}`, value: h.value })))
    .filter((item) => item.value > 0)
    .sort((a, b) => b.value - a.value)
})

async function confirmarExportar() {
  const tabLabel = TABS.find((t) => t.id === tab.value)?.label ?? tab.value
  const periodoLabel = PERIODOS.find((p) => p.id === periodo.value)?.label ?? periodo.value
  const total = resumen.value.total

  const secciones = [
    {
      titulo: 'Resumen del reporte',
      columnas: ['Campo', 'Valor'],
      filas: [
        ['Reporte', tabLabel],
        ['Periodo', periodoLabel],
        ['Filtros aplicados', descripcionFiltros.value],
        ['Total', total],
        ['Promedio por periodo', resumen.value.promedioPorPeriodo],
        ['Categoría más frecuente', resumen.value.categoriaMasFrecuente],
      ],
    },
    {
      titulo: `Serie temporal (${periodoLabel.toLowerCase()})`,
      columnas: ['Periodo', 'Cantidad'],
      filas: resumen.value.serie.map((d) => [d.label, d.value]),
    },
    {
      titulo: 'Por carrera',
      columnas: ['Carrera', 'Cantidad', '%'],
      filas: resumen.value.porCarrera.map((d) => [d.label, d.value, porcentaje(d.value, total)]),
    },
    {
      titulo: 'Por sexo',
      columnas: ['Sexo', 'Cantidad', '%'],
      filas: resumen.value.porSexo.map((d) => [d.label, d.value, porcentaje(d.value, total)]),
    },
    {
      titulo: 'Por año de ingreso',
      columnas: ['Año de ingreso', 'Cantidad', '%'],
      filas: resumen.value.porAnioIngreso.map((d) => [d.label, d.value, porcentaje(d.value, total)]),
    },
    {
      titulo: 'Por tipo de usuario',
      columnas: ['Tipo de usuario', 'Cantidad', '%'],
      filas: resumen.value.porTipoUsuario.map((d) => [d.label, d.value, porcentaje(d.value, total)]),
    },
    {
      titulo: 'Por hora del día',
      columnas: ['Hora', 'Cantidad', '%'],
      filas: resumen.value.porHora.map((d) => [d.label, d.value, porcentaje(d.value, total)]),
    },
  ]

  if (tab.value === 'logias') {
    secciones.push({
      titulo: 'Por sala',
      columnas: ['Sala', 'Cantidad', '%'],
      filas: resumen.value.porSala.map((d) => [d.label, d.value, porcentaje(d.value, total)]),
    })
    secciones.push({
      titulo: 'Horas más solicitadas por logia',
      columnas: ['Sala y hora', 'Cantidad', '%'],
      filas: porHoraPorSalaFlat.value.map((d) => [d.label, d.value, porcentaje(d.value, total)]),
    })
  }

  if (tab.value === 'libros') {
    secciones.push({
      titulo: 'Libros más prestados',
      columnas: ['Libro', 'Cantidad', '%'],
      filas: resumen.value.porLibro.map((d) => [d.label, d.value, porcentaje(d.value, total)]),
    })
  }

  const fecha = new Date().toISOString().slice(0, 10)
  exportando.value = true
  try {
    if (formatoExportar.value === 'excel') {
      await descargarExcel(`reporte-${tab.value}-${periodo.value}-${fecha}.xlsx`, secciones)
    } else {
      descargarCsv(`reporte-${tab.value}-${periodo.value}-${fecha}.csv`, construirCsv(secciones))
    }
    exportModalOpen.value = false
    toast.success('Reporte exportado')
  } catch {
    toast.error(formatoExportar.value === 'excel' ? 'No se pudo generar el archivo Excel' : 'No se pudo generar el archivo CSV')
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
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Reportes</h1>
          <p class="text-sm text-white/60 mt-1">Estadísticas y métricas de la biblioteca</p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="flex gap-2 mb-6 border-b border-gray-200">
        <button
          v-for="t in TABS"
          :key="t.id"
          @click="tab = t.id"
          class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
          :class="tab === t.id ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800'"
        >
          {{ t.label }}
        </button>
      </div>

      <div class="flex items-center gap-2 mb-4 flex-wrap justify-between">
        <div class="flex items-center gap-2 flex-wrap">
          <span class="text-xs font-medium text-gray-500 mr-1">Periodo:</span>
          <div class="flex gap-1 bg-gray-100 rounded-full p-1 shadow-sm">
            <button
              v-for="p in PERIODOS"
              :key="p.id"
              @click="periodo = p.id"
              class="px-3 py-1.5 rounded-full text-xs font-medium transition-colors"
              :class="periodo === p.id ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-500 hover:text-gray-700'"
            >
              {{ p.label }}
            </button>
          </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
          <div class="flex gap-1 bg-gray-100 rounded-full p-1">
            <button
              @click="vista = 'grafico'"
              class="px-3 py-1.5 rounded-full text-xs font-medium transition-colors"
              :class="vista === 'grafico' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
            >
              Gráfico
            </button>
            <button
              @click="vista = 'tabla'"
              class="px-3 py-1.5 rounded-full text-xs font-medium transition-colors"
              :class="vista === 'tabla' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
            >
              Tabla
            </button>
          </div>
          <button
            @click="abrirModalExportar('excel')"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-emerald-600 text-white hover:bg-emerald-700 transition-colors"
          >
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2-8H8a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V8l-4-4z" />
            </svg>
            Exportar a Excel
          </button>
          <button
            @click="abrirModalExportar('csv')"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition-colors"
          >
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2-8H8a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V8l-4-4z" />
            </svg>
            Exportar a CSV
          </button>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-md p-4 mb-6">
        <div class="flex items-center gap-2 mb-3 text-sm font-medium text-gray-700">
          Filtros
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Carrera</label>
            <select v-model="filtros.carrera" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
              <option value="">Todas</option>
              <option v-for="c in opciones.carreras" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Año de ingreso</label>
            <select v-model="filtros.anio_ingreso" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
              <option value="">Todos</option>
              <option v-for="a in opciones.aniosIngreso" :key="a" :value="a">{{ a }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Sexo</label>
            <select v-model="filtros.sexo" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
              <option value="">Todos</option>
              <option v-for="s in opciones.sexos" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Tipo de usuario</label>
            <select v-model="filtros.tipo_usuario" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
              <option value="">Todos</option>
              <option v-for="tu in opciones.tiposUsuario" :key="tu" :value="tu">{{ tu.charAt(0).toUpperCase() + tu.slice(1) }}</option>
            </select>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-md p-5">
          <p class="text-xs text-gray-500">{{ tabTitulo[tab] }}</p>
          <p class="text-2xl font-mono font-bold text-gray-900">{{ resumen.total }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-md p-5">
          <p class="text-xs text-gray-500">Promedio ({{ PERIODOS.find((p) => p.id === periodo)?.label.toLowerCase() }})</p>
          <p class="text-2xl font-mono font-bold text-gray-900">{{ resumen.promedioPorPeriodo }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-md p-5">
          <p class="text-xs text-gray-500">Categoría más frecuente</p>
          <p class="text-sm font-mono font-bold text-gray-900 truncate">{{ resumen.categoriaMasFrecuente }}</p>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-md p-6 mb-6">
        <h3 class="font-medium text-gray-900 mb-1">
          {{ TABS.find((t) => t.id === tab)?.label }} — {{ PERIODOS.find((p) => p.id === periodo)?.label }}
        </h3>
        <p class="text-xs text-gray-400 mb-4">Mostrando los últimos {{ resumen.serie.length }} periodos según filtros aplicados</p>
        <div v-if="vista === 'grafico'" class="h-72">
          <BarChart :data="resumen.serie" :color="colorBar[tab]" />
        </div>
        <ReporteTabla v-else :data="resumen.serie" :total="resumen.total" columna="Periodo" :item-color="() => colorBar[tab]" />
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl shadow-md p-6">
          <h3 class="font-medium text-gray-900 mb-4">Por carrera</h3>
          <BreakdownList v-if="vista === 'grafico'" :data="resumen.porCarrera" :total="resumen.total" :item-color="colorCarrera" />
          <ReporteTabla v-else :data="resumen.porCarrera" :total="resumen.total" columna="Carrera" :item-color="colorCarrera" />
        </div>

        <div class="bg-white rounded-xl shadow-md p-6">
          <h3 class="font-medium text-gray-900 mb-4">Por sexo</h3>
          <BreakdownList v-if="vista === 'grafico'" :data="resumen.porSexo" :total="resumen.total" :item-color="colorSexo" />
          <ReporteTabla v-else :data="resumen.porSexo" :total="resumen.total" columna="Sexo" :item-color="colorSexo" />
        </div>

        <div class="bg-white rounded-xl shadow-md p-6">
          <h3 class="font-medium text-gray-900 mb-4">Por año de ingreso</h3>
          <BreakdownList v-if="vista === 'grafico'" :data="resumen.porAnioIngreso" :total="resumen.total" color="purple" />
          <ReporteTabla v-else :data="resumen.porAnioIngreso" :total="resumen.total" columna="Año de ingreso" :item-color="() => '#a855f7'" />
        </div>

        <div class="bg-white rounded-xl shadow-md p-6">
          <h3 class="font-medium text-gray-900 mb-4">Por tipo de usuario</h3>
          <BreakdownList v-if="vista === 'grafico'" :data="resumen.porTipoUsuario" :total="resumen.total" :color="tab === 'ingresos' ? 'emerald' : 'amber'" />
          <ReporteTabla v-else :data="resumen.porTipoUsuario" :total="resumen.total" columna="Tipo de usuario" :item-color="() => (tab === 'ingresos' ? '#10b981' : '#f59e0b')" />
        </div>

        <div v-if="tab === 'logias'" class="bg-white rounded-xl shadow-md p-6 md:col-span-2">
          <h3 class="font-medium text-gray-900 mb-4">Sala más solicitada</h3>
          <BreakdownList v-if="vista === 'grafico'" :data="resumen.porSala" :total="resumen.total" color="amber" />
          <ReporteTabla v-else :data="resumen.porSala" :total="resumen.total" columna="Sala" :item-color="() => '#D97706'" />
        </div>

        <div v-if="tab === 'logias'" class="bg-white rounded-xl shadow-md p-6 md:col-span-2">
          <h3 class="font-medium text-gray-900 mb-1">Horas más solicitadas por logia</h3>
          <p class="text-xs text-gray-400 mb-4">Cantidad de reservas por bloque horario, desglosado por sala (08:00 – 21:00)</p>
          <Heatmap v-if="vista === 'grafico'" :data="resumen.porHoraPorSala" />
          <ReporteTabla v-else :data="porHoraPorSalaFlat" :total="resumen.total" columna="Sala y hora" :item-color="() => '#3987e5'" />
        </div>

        <div v-if="tab === 'libros'" class="bg-white rounded-xl shadow-md p-6 md:col-span-2">
          <h3 class="font-medium text-gray-900 mb-1">Libros más prestados</h3>
          <p class="text-xs text-gray-400 mb-4">Ranking por cantidad de préstamos (incluye cada copia por separado)</p>
          <BreakdownList v-if="vista === 'grafico'" :data="resumen.porLibro" :total="resumen.total" color="indigo" />
          <ReporteTabla v-else :data="resumen.porLibro" :total="resumen.total" columna="Libro" :item-color="() => '#4338CA'" />
        </div>

        <div class="bg-white rounded-xl shadow-md p-6 md:col-span-2">
          <h3 class="font-medium text-gray-900 mb-1">Distribución por hora del día</h3>
          <p class="text-xs text-gray-400 mb-4">Cantidad de registros por hora (08:00 – 21:00, horario de biblioteca)</p>
          <div v-if="vista === 'grafico'" class="h-64">
            <BarChart :data="resumen.porHora" :color="colorBar[tab]" />
          </div>
          <ReporteTabla v-else :data="resumen.porHora" :total="resumen.total" columna="Hora" :item-color="() => colorBar[tab]" />
        </div>
      </div>

      <div
        v-if="exportModalOpen"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="exportModalOpen = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-md">
          <h3 class="text-lg font-bold text-gray-900 mb-1">Confirmar exportación</h3>
          <p class="text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-5">
            {{ descripcionExportacion }}
          </p>
          <div class="flex gap-3">
            <button
              @click="exportModalOpen = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="confirmarExportar"
              :disabled="exportando"
              class="flex-1 px-4 py-2.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ exportando ? 'Generando…' : 'Confirmar y exportar' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </StaffLayout>
</template>
