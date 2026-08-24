<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import LibrosModuloNav from '@/components/libros/LibrosModuloNav.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import type { CambioMasivoPreview, Categoria, EstadoLibroPersonalizado, EstadoProcesoEjemplar, TipoMaterial, Ubicacion } from '@/types'

const toast = useToast()
const apiError = ref(false)

const filtro = reactive({
  ubicacion_id: '' as number | '',
  estado_proceso_actual: '',
  tipo_material_id: '' as number | '',
  categoria_id: '' as number | '',
  q: '',
})

const nuevoEstado = ref<EstadoProcesoEjemplar>('inventario')
const estadoPersonalizadoId = ref<number | null>(null)
const motivo = ref('')

const categorias = ref<Categoria[]>([])
const estadosPersonalizados = ref<EstadoLibroPersonalizado[]>([])
const ubicaciones = ref<Ubicacion[]>([])
const tiposMaterial = ref<TipoMaterial[]>([])

const ESTADOS: { value: EstadoProcesoEjemplar; label: string }[] = [
  { value: 'inventario', label: 'Inventario' },
  { value: 'procesos_tecnicos', label: 'En procesos técnicos' },
  { value: 'por_colocar', label: 'Por colocar' },
  { value: 'en_estante', label: 'En estante' },
  { value: 'estanteria_auxiliar', label: 'Estantería auxiliar' },
  { value: 'de_baja', label: 'De baja' },
  { value: 'coleccion_movil', label: 'Colección móvil' },
  { value: 'personalizado', label: 'Personalizado' },
]

async function cargarCatalogos() {
  try {
    const [categoriasRes, estadosRes, ubicacionesRes, tiposMaterialRes] = await Promise.all([
      api.get<Categoria[]>('/categorias'),
      api.get<EstadoLibroPersonalizado[]>('/estados-libro-personalizados', { params: { activo: 1 } }),
      api.get<Ubicacion[]>('/ubicaciones'),
      api.get<TipoMaterial[]>('/tipos-material'),
    ])
    categorias.value = categoriasRes.data
    estadosPersonalizados.value = estadosRes.data
    ubicaciones.value = ubicacionesRes.data
    tiposMaterial.value = tiposMaterialRes.data
  } catch {
    apiError.value = true
  }
}

onMounted(cargarCatalogos)

const tieneFiltro = computed(() =>
  Boolean(filtro.ubicacion_id || filtro.estado_proceso_actual || filtro.tipo_material_id || filtro.categoria_id || filtro.q.trim())
)

function paramsFiltro() {
  return {
    ubicacion_id: filtro.ubicacion_id || undefined,
    estado_proceso_actual: filtro.estado_proceso_actual || undefined,
    tipo_material_id: filtro.tipo_material_id || undefined,
    categoria_id: filtro.categoria_id || undefined,
    q: filtro.q.trim() || undefined,
  }
}

const preview = ref<CambioMasivoPreview | null>(null)
const buscandoPreview = ref(false)

async function buscarPreview() {
  if (!tieneFiltro.value) {
    toast.error('Indica al menos un criterio de filtro')
    return
  }
  buscandoPreview.value = true
  preview.value = null
  try {
    const { data } = await api.post<CambioMasivoPreview>('/ejemplares/cambio-masivo/preview', paramsFiltro())
    preview.value = data
    apiError.value = false
  } catch (e: any) {
    apiError.value = true
    toast.error(e?.response?.data?.message ?? 'No se pudo calcular la vista previa')
  } finally {
    buscandoPreview.value = false
  }
}

const modalAbierto = ref(false)
const textoConfirmacion = ref('')
const ejecutando = ref(false)
const resultado = ref<{ total_actualizados: number; lote_id: string } | null>(null)

function abrirModalConfirmacion() {
  if (nuevoEstado.value === 'personalizado' && !estadoPersonalizadoId.value) {
    toast.error('Selecciona cuál estado personalizado aplicar')
    return
  }
  textoConfirmacion.value = ''
  modalAbierto.value = true
}

const descripcionCriterios = computed(() => {
  const partes: string[] = []
  if (filtro.ubicacion_id) partes.push(`ubicación "${ubicaciones.value.find((u) => u.id === filtro.ubicacion_id)?.nombre}"`)
  if (filtro.estado_proceso_actual) partes.push(`estado actual "${filtro.estado_proceso_actual}"`)
  if (filtro.tipo_material_id) partes.push(`tipo "${tiposMaterial.value.find((t) => t.id === filtro.tipo_material_id)?.nombre}"`)
  if (filtro.categoria_id) partes.push(`categoría "${categorias.value.find((c) => c.id === filtro.categoria_id)?.nombre}"`)
  if (filtro.q.trim()) partes.push(`texto "${filtro.q.trim()}"`)
  return partes.join(', ')
})

const estadoDestinoLabel = computed(() =>
  nuevoEstado.value === 'personalizado'
    ? estadosPersonalizados.value.find((e) => e.id === estadoPersonalizadoId.value)?.nombre
    : ESTADOS.find((e) => e.value === nuevoEstado.value)?.label
)

async function confirmarEjecucion() {
  if (textoConfirmacion.value.trim().toUpperCase() !== 'CONFIRMAR') return
  ejecutando.value = true
  try {
    const { data } = await api.post('/ejemplares/cambio-masivo/ejecutar', {
      ...paramsFiltro(),
      nuevo_estado: nuevoEstado.value,
      estado_personalizado_id: nuevoEstado.value === 'personalizado' ? estadoPersonalizadoId.value : undefined,
      motivo: motivo.value.trim() || undefined,
    })
    resultado.value = data
    modalAbierto.value = false
    preview.value = null
    toast.success(`${data.total_actualizados} ejemplar(es) actualizados`)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo ejecutar el cambio masivo')
  } finally {
    ejecutando.value = false
  }
}
</script>

<template>
  <StaffLayout>
    <LibrosModuloNav actual="cambio-masivo-estado">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #7C2D12 0%, #9A3412 50%, #B45309 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Cambio Masivo de Estado</h1>
          <p class="text-sm text-white/70 mt-1">Cambia el estado de muchas copias a la vez (ej. inventario por ubicación) — con confirmación obligatoria</p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="bg-white rounded-xl shadow-md p-6 mb-6">
        <h3 class="font-semibold text-gray-900 mb-4">1. Filtrar los ejemplares a cambiar</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-4">
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Ubicación</label>
            <select v-model="filtro.ubicacion_id" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 outline-none text-sm">
              <option value="">Cualquiera</option>
              <option v-for="u in ubicaciones" :key="u.id" :value="u.id">{{ u.nombre }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Estado actual</label>
            <select v-model="filtro.estado_proceso_actual" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 outline-none text-sm">
              <option value="">Cualquiera</option>
              <option v-for="e in ESTADOS" :key="e.value" :value="e.value">{{ e.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Tipo de material</label>
            <select v-model="filtro.tipo_material_id" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 outline-none text-sm">
              <option value="">Cualquiera</option>
              <option v-for="t in tiposMaterial" :key="t.id" :value="t.id">{{ t.nombre }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Categoría</label>
            <select v-model="filtro.categoria_id" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 outline-none text-sm">
              <option value="">Cualquiera</option>
              <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-600 mb-1">Búsqueda por título o código de barras</label>
            <input v-model="filtro.q" placeholder="Opcional" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 outline-none text-sm" />
          </div>
        </div>

        <button
          @click="buscarPreview"
          :disabled="buscandoPreview || !tieneFiltro"
          class="px-5 py-2.5 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {{ buscandoPreview ? 'Buscando…' : 'Vista previa' }}
        </button>
      </div>

      <div v-if="preview" class="bg-white rounded-xl shadow-md p-6 mb-6">
        <h3 class="font-semibold text-gray-900 mb-1">2. {{ preview.total }} ejemplar(es) coinciden con el filtro</h3>
        <p class="text-xs text-gray-400 mb-4">Mostrando hasta 10 de ejemplo</p>

        <div class="rounded-lg border border-gray-200 divide-y divide-gray-100 mb-6">
          <div v-for="e in preview.muestra" :key="e.id" class="flex items-center justify-between px-3 py-2 text-xs">
            <span class="text-gray-700">{{ e.libro?.titulo }} <span class="text-gray-400 font-mono">· {{ e.codigo_barras }}</span></span>
            <span class="text-gray-500">{{ e.estado_proceso }}</span>
          </div>
          <p v-if="!preview.muestra.length" class="px-3 py-4 text-xs text-gray-400 text-center">Sin resultados.</p>
        </div>

        <div v-if="preview.total > 0">
          <h4 class="text-sm font-semibold text-gray-900 mb-3">Nuevo estado para los {{ preview.total }} ejemplares</h4>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">
            <button
              v-for="e in ESTADOS"
              :key="e.value"
              @click="nuevoEstado = e.value"
              class="text-left px-3 py-2 rounded-lg border text-xs font-medium transition-colors"
              :class="nuevoEstado === e.value ? 'border-amber-500 bg-amber-50 text-amber-900' : 'border-gray-200 hover:bg-gray-50 text-gray-700'"
            >
              {{ e.label }}
            </button>
          </div>

          <div v-if="nuevoEstado === 'personalizado'" class="mb-3">
            <select v-model="estadoPersonalizadoId" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 outline-none text-sm">
              <option :value="null" disabled>Selecciona un estado personalizado...</option>
              <option v-for="ep in estadosPersonalizados" :key="ep.id" :value="ep.id">{{ ep.nombre }}</option>
            </select>
          </div>

          <div class="mb-4">
            <label class="block text-xs font-medium text-gray-600 mb-1">Motivo (opcional, queda en el historial)</label>
            <input v-model="motivo" placeholder="Ej: inventario anual" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 outline-none text-sm" />
          </div>

          <button
            @click="abrirModalConfirmacion"
            class="px-5 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium"
          >
            Cambiar estado de {{ preview.total }} ejemplar(es)…
          </button>
        </div>
      </div>

      <div v-if="resultado" class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm text-emerald-800">
        Se actualizaron {{ resultado.total_actualizados }} ejemplar(es). Lote <span class="font-mono">{{ resultado.lote_id }}</span> —
        puedes verlo en <router-link :to="{ name: 'historial-estado-libro', query: { lote_id: resultado.lote_id } }" class="underline font-medium">Historial de Estado</router-link>.
      </div>

      <!-- Confirmación seria: exige tipear "CONFIRMAR", no solo un click -->
      <div
        v-if="modalAbierto"
        class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="modalAbierto = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-md border-2 border-red-200">
          <div class="flex items-center gap-2 mb-3 text-red-600">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
            </svg>
            <h3 class="text-lg font-bold">Confirmación requerida</h3>
          </div>
          <p class="text-sm text-gray-700 bg-red-50 border border-red-200 rounded-lg px-4 py-3 mb-4">
            Se cambiarán <strong>{{ preview?.total }} ejemplares</strong>
            <span v-if="descripcionCriterios"> ({{ descripcionCriterios }})</span>
            al estado <strong>{{ estadoDestinoLabel }}</strong>. Esta acción queda registrada en el historial pero no se
            puede deshacer con un solo click.
          </p>
          <label class="block text-xs font-medium text-gray-600 mb-1">
            Escribe <span class="font-mono font-bold">CONFIRMAR</span> para continuar
          </label>
          <input
            v-model="textoConfirmacion"
            type="text"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 outline-none text-sm mb-4 font-mono"
            @keydown.enter="confirmarEjecucion"
          />
          <div class="flex gap-3">
            <button
              @click="modalAbierto = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="confirmarEjecucion"
              :disabled="ejecutando || textoConfirmacion.trim().toUpperCase() !== 'CONFIRMAR'"
              class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium text-sm disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ ejecutando ? 'Aplicando…' : 'Sí, cambiar todo' }}
            </button>
          </div>
        </div>
      </div>
    </LibrosModuloNav>
  </StaffLayout>
</template>
