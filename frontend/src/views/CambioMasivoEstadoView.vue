<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import LibrosModuloNav from '@/components/libros/LibrosModuloNav.vue'
import FondoCambioMasivo from '@/components/libros/FondoCambioMasivo.vue'
import SelectorBuscable from '@/components/ui/SelectorBuscable.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import { ESTADOS_EJEMPLAR } from '@/utils/estadosEjemplar'
import type { CambioMasivoPreview, Categoria, EstadoLibroPersonalizado, EstadoProcesoEjemplar, TipoMaterial, Ubicacion } from '@/types'

const toast = useToast()

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}
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

// La categoría se busca en el servidor (son miles con el catálogo real): acá queda la elegida.
const categoriaElegida = ref<Categoria | null>(null)
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
    const [estadosRes, ubicacionesRes, tiposMaterialRes] = await Promise.all([
      api.get<EstadoLibroPersonalizado[]>('/estados-libro-personalizados', { params: { activo: 1 } }),
      api.get<Ubicacion[]>('/ubicaciones'),
      api.get<TipoMaterial[]>('/tipos-material'),
    ])
    estadosPersonalizados.value = estadosRes.data
    ubicaciones.value = ubicacionesRes.data
    tiposMaterial.value = tiposMaterialRes.data
  } catch {
    apiError.value = true
  }
}

onMounted(cargarCatalogos)

async function buscarCategorias(texto: string) {
  const { data } = await api.get<Categoria[]>('/categorias', { params: { q: texto || undefined } })
  return data
}

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
const resultado = ref<{ total_actualizados: number; lote_id: string; apartadas: number } | null>(null)

function abrirModalConfirmacion() {
  if (nuevoEstado.value === 'personalizado' && !estadoPersonalizadoId.value) {
    toast.error('Selecciona cuál estado personalizado aplicar')
    return
  }
  if (nuevoEstado.value === 'de_baja' && !motivo.value.trim()) {
    toast.error('Indica el motivo de la baja: queda registrado en el historial de cada copia')
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
  if (filtro.categoria_id) partes.push(`categoría "${categoriaElegida.value?.nombre}"`)
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
      <!-- La caja de título con motivo propio: un lote de libros que cambia de estado. -->
      <div class="relative mb-5 overflow-hidden rounded-2xl shadow-md" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
        <FondoCambioMasivo />
        <div class="relative min-w-0 px-6 py-4">
          <nav class="text-xs text-white/75" aria-label="Ruta">
            <button type="button" class="font-medium text-white hover:underline" @click="abrirMenuGestion">Gestión</button>
            <span class="mx-1.5">›</span>
            <span>Catálogo</span>
          </nav>
          <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Cambio Masivo de Estado</h1>
          <p class="mt-0.5 max-w-md text-sm text-white/75">Cambia el estado de muchas copias a la vez (ej. inventario por ubicación), con confirmación obligatoria</p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <section class="mb-5 overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
        <header class="flex items-center gap-2.5 border-b border-gray-100 bg-indigo-50/40 px-5 py-3">
          <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-indigo-600 text-xs font-bold text-white">1</span>
          <h3 class="font-serif text-base font-bold text-gray-900">Filtrar los ejemplares a cambiar</h3>
        </header>
        <div class="p-5">
          <div class="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-gray-100 bg-gray-50 p-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
              <label for="masivo-ubicacion" class="block text-xs font-medium text-gray-700 mb-1">Ubicación</label>
              <select id="masivo-ubicacion" v-model="filtro.ubicacion_id" class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
                <option value="">Cualquiera</option>
                <option v-for="u in ubicaciones" :key="u.id" :value="u.id">{{ u.nombre }}</option>
              </select>
            </div>
            <div>
              <label for="masivo-estado" class="block text-xs font-medium text-gray-700 mb-1">Estado actual</label>
              <select id="masivo-estado" v-model="filtro.estado_proceso_actual" class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
                <option value="">Cualquiera</option>
                <option v-for="e in ESTADOS" :key="e.value" :value="e.value">{{ e.label }}</option>
              </select>
            </div>
            <div>
              <label for="masivo-tipo" class="block text-xs font-medium text-gray-700 mb-1">Tipo de material</label>
              <select id="masivo-tipo" v-model="filtro.tipo_material_id" class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
                <option value="">Cualquiera</option>
                <option v-for="t in tiposMaterial" :key="t.id" :value="t.id">{{ t.nombre }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Categoría</label>
              <SelectorBuscable
              v-model="filtro.categoria_id"
              :buscar="buscarCategorias"
              todas="Cualquiera"
              placeholder="Cualquiera"
              etiqueta="Categoría"
              clase-campo="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm placeholder:text-gray-900"
              @elegir="categoriaElegida = $event"
            />
            </div>
            <div class="sm:col-span-2">
              <label for="masivo-q" class="block text-xs font-medium text-gray-700 mb-1">Búsqueda por título o código de barras</label>
              <input autocomplete="off" id="masivo-q" v-model="filtro.q" placeholder="Opcional" class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            </div>
          </div>

          <button
            type="button"
            :disabled="buscandoPreview || !tieneFiltro"
            class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
            @click="buscarPreview"
          >
            {{ buscandoPreview ? 'Buscando…' : 'Vista previa' }}
          </button>
        </div>
      </section>

      <section v-if="preview" class="mb-5 overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-[#b08d57] bg-white shadow-sm">
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-amber-100 bg-amber-50/60 px-5 py-3">
          <div class="flex items-center gap-2.5">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-indigo-600 text-xs font-bold text-white">2</span>
            <h3 class="font-serif text-base font-bold text-gray-900">{{ preview.total }} {{ preview.total === 1 ? 'ejemplar coincide' : 'ejemplares coinciden' }} con el filtro</h3>
          </div>
          <span class="text-xs text-gray-500">Mostrando hasta 10 de ejemplo</span>
        </header>
        <div class="p-5">
          <div class="mb-5 divide-y divide-gray-100 rounded-xl border border-gray-200">
            <div v-for="e in preview.muestra" :key="e.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
              <span class="min-w-0 text-gray-900">
                {{ e.libro?.titulo }}
                <span class="ml-1 rounded border border-gray-200 bg-gray-50 px-1.5 font-mono text-[11px] text-gray-600">{{ e.codigo_barras }}</span>
              </span>
              <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="ESTADOS_EJEMPLAR[e.estado_proceso as EstadoProcesoEjemplar]?.badge ?? 'bg-gray-100 text-gray-700'">
                <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                {{ ESTADOS_EJEMPLAR[e.estado_proceso as EstadoProcesoEjemplar]?.label ?? e.estado_proceso }}
              </span>
            </div>
            <p v-if="!preview.muestra.length" class="px-3 py-4 text-center text-sm text-gray-400">Sin resultados.</p>
          </div>

          <div v-if="preview.total > 0">
            <p class="mb-2.5 flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-indigo-700">
              <span class="h-3.5 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
              Nuevo estado para {{ preview.total === 1 ? 'el ejemplar' : `los ${preview.total} ejemplares` }}
            </p>
            <div class="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4" role="group" aria-label="Nuevo estado">
              <button
                v-for="e in ESTADOS"
                :key="e.value"
                type="button"
                class="flex items-center gap-2 rounded-lg border bg-white px-3 py-2 text-left text-xs font-semibold shadow-sm transition-colors"
                :class="nuevoEstado === e.value ? 'border-indigo-600 bg-indigo-50 text-indigo-800 ring-1 ring-indigo-600' : 'border-gray-200 text-gray-700 hover:bg-gray-50'"
                :aria-pressed="nuevoEstado === e.value"
                @click="nuevoEstado = e.value"
              >
                <span class="h-2 w-2 shrink-0 rounded-full" :class="ESTADOS_EJEMPLAR[e.value].punto" aria-hidden="true"></span>
                {{ e.label }}
              </button>
            </div>

            <div v-if="nuevoEstado === 'personalizado'" class="mb-3">
              <select v-model="estadoPersonalizadoId" aria-label="Estado personalizado" class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
                <option :value="null" disabled>Selecciona un estado personalizado...</option>
                <option v-for="ep in estadosPersonalizados" :key="ep.id" :value="ep.id">{{ ep.nombre }}</option>
              </select>
            </div>

            <div class="mb-4 rounded-xl border border-gray-100 bg-gray-50 p-4">
              <label class="block text-xs font-medium text-gray-700 mb-1">{{ nuevoEstado === 'de_baja' ? 'Motivo de la baja (obligatorio, queda en el historial)' : 'Motivo (opcional, queda en el historial)' }}</label>
              <input autocomplete="off" v-model="motivo" :required="nuevoEstado === 'de_baja'" :placeholder="nuevoEstado === 'de_baja' ? 'Ej: deterioro, pérdida, descarte por inventario' : 'Ej: inventario anual'" class="w-full px-3 py-2 bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            </div>

            <div class="border-t border-dashed border-gray-200 pt-4">
              <button
                type="button"
                class="rounded-lg bg-red-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-red-700"
                @click="abrirModalConfirmacion"
              >
                Cambiar estado de {{ preview.total }} {{ preview.total === 1 ? 'ejemplar' : 'ejemplares' }}…
              </button>
            </div>
          </div>
        </div>
      </section>

      <div v-if="resultado" class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
        Se actualizaron {{ resultado.total_actualizados }} ejemplar(es)<template v-if="resultado.apartadas">, y {{ resultado.apartadas === 1 ? '1 quedó apartado' : `${resultado.apartadas} quedaron apartados` }} para la lista de espera de su título</template>. Lote <span class="font-mono">{{ resultado.lote_id }}</span> —
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
          <input autocomplete="off"
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
