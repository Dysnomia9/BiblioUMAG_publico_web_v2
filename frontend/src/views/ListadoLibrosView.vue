<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import LibrosModuloNav from '@/components/libros/LibrosModuloNav.vue'
import api from '@/services/api'
import type { Categoria, EstadoLibroPersonalizado, Libro, TipoMaterial } from '@/types'

const libros = ref<Libro[]>([])
const cargando = ref(true)
const apiError = ref(false)

const filtros = reactive({
  q: '',
  estado_proceso: '',
  tipo_material_id: '' as number | '',
  categoria_id: '' as number | '',
  orden: 'titulo' as 'titulo' | 'tipo_material',
})

const categorias = ref<Categoria[]>([])
const estadosPersonalizados = ref<EstadoLibroPersonalizado[]>([])
const tiposMaterial = ref<TipoMaterial[]>([])
const expandidos = ref<Set<number>>(new Set())

const ESTADOS_PROCESO: { value: string; label: string }[] = [
  { value: '', label: 'Todos los estados' },
  { value: 'inventario', label: 'Inventario' },
  { value: 'procesos_tecnicos', label: 'En procesos técnicos' },
  { value: 'por_colocar', label: 'Por colocar' },
  { value: 'en_estante', label: 'En estante' },
  { value: 'estanteria_auxiliar', label: 'Estantería auxiliar' },
  { value: 'de_baja', label: 'De baja (obsoletos)' },
  { value: 'coleccion_movil', label: 'Colección móvil' },
  { value: 'personalizado', label: 'Personalizado (cualquiera)' },
]

const estadoBadges: Record<string, { label: string; cls: string }> = {
  inventario: { label: 'Inventario', cls: 'bg-gray-100 text-gray-700' },
  procesos_tecnicos: { label: 'En procesos técnicos', cls: 'bg-amber-100 text-amber-700' },
  por_colocar: { label: 'Por colocar', cls: 'bg-sky-100 text-sky-700' },
  en_estante: { label: 'En estante', cls: 'bg-emerald-100 text-emerald-700' },
  estanteria_auxiliar: { label: 'Estantería auxiliar', cls: 'bg-indigo-100 text-indigo-700' },
  de_baja: { label: 'De baja', cls: 'bg-red-100 text-red-700' },
  coleccion_movil: { label: 'Colección móvil', cls: 'bg-teal-100 text-teal-700' },
  personalizado: { label: 'Personalizado', cls: 'bg-fuchsia-100 text-fuchsia-700' },
}

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<Libro[]>('/libros', {
      params: {
        q: filtros.q.trim() || undefined,
        estado_proceso: filtros.estado_proceso || undefined,
        tipo_material_id: filtros.tipo_material_id || undefined,
        categoria_id: filtros.categoria_id || undefined,
        orden: filtros.orden,
      },
    })
    libros.value = data
    apiError.value = false
  } catch {
    apiError.value = true
    libros.value = []
  } finally {
    cargando.value = false
  }
}

async function cargarCatalogos() {
  try {
    const [categoriasRes, estadosRes, tiposMaterialRes] = await Promise.all([
      api.get<Categoria[]>('/categorias'),
      api.get<EstadoLibroPersonalizado[]>('/estados-libro-personalizados', { params: { activo: 1 } }),
      api.get<TipoMaterial[]>('/tipos-material'),
    ])
    categorias.value = categoriasRes.data
    estadosPersonalizados.value = estadosRes.data
    tiposMaterial.value = tiposMaterialRes.data
  } catch {
    // Los filtros de categoría/personalizado simplemente quedan vacíos.
  }
}

onMounted(() => {
  cargar()
  cargarCatalogos()
})

let buscarTimer: ReturnType<typeof setTimeout> | undefined
function onFiltroInput() {
  clearTimeout(buscarTimer)
  buscarTimer = setTimeout(cargar, 250)
}

function toggleExpandido(libroId: number) {
  if (expandidos.value.has(libroId)) expandidos.value.delete(libroId)
  else expandidos.value.add(libroId)
  expandidos.value = new Set(expandidos.value)
}

function badgeDe(ejemplar: { estado_proceso: string; estado_personalizado?: { nombre: string } | null }) {
  if (ejemplar.estado_proceso === 'personalizado') {
    return { label: ejemplar.estado_personalizado?.nombre ?? 'Personalizado', cls: estadoBadges.personalizado.cls }
  }
  return estadoBadges[ejemplar.estado_proceso] ?? { label: ejemplar.estado_proceso, cls: 'bg-gray-100 text-gray-700' }
}
</script>

<template>
  <StaffLayout>
    <LibrosModuloNav actual="listado-libros">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Listado de Libros</h1>
          <p class="text-sm text-white/60 mt-1">Catálogo completo, con filtros por estado, tipo y categoría</p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="bg-white rounded-xl shadow-md p-4 mb-6 space-y-3">
        <div class="relative">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
          </svg>
          <input
            v-model="filtros.q"
            @input="onFiltroInput"
            type="text"
            placeholder="Buscar por título, autor o código de barras..."
            class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"
          />
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <select v-model="filtros.estado_proceso" @change="cargar" class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
            <option v-for="e in ESTADOS_PROCESO" :key="e.value" :value="e.value">{{ e.label }}</option>
          </select>
          <select v-model="filtros.tipo_material_id" @change="cargar" class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
            <option value="">Todos los tipos</option>
            <option v-for="t in tiposMaterial" :key="t.id" :value="t.id">{{ t.nombre }}</option>
          </select>
          <select v-model="filtros.categoria_id" @change="cargar" class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
            <option value="">Todas las categorías</option>
            <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
          </select>
          <select v-model="filtros.orden" @change="cargar" class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
            <option value="titulo">Ordenar por título</option>
            <option value="tipo_material">Ordenar por tipo de recurso</option>
          </select>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="bg-gray-100 border-b-2 border-gray-200">
                <th class="px-4 py-3 w-8"></th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Título</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Autor(es)</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Categoría(s)</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Tipo</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Copias</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              <template v-for="(l, idx) in libros" :key="l.id">
                <tr
                  class="hover:bg-indigo-50/40 transition-colors cursor-pointer"
                  :class="idx % 2 === 0 ? 'bg-white' : 'bg-biblioteca-50'"
                  @click="toggleExpandido(l.id)"
                >
                  <td class="px-4 py-3 text-gray-400">
                    <svg class="w-4 h-4 transition-transform" :class="expandidos.has(l.id) ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-900">
                    {{ l.titulo }}
                    <p v-if="l.isbn" class="text-xs text-gray-400 font-mono">ISBN {{ l.isbn }}</p>
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-600">{{ l.autores?.map((a) => a.nombre).join(', ') || '—' }}</td>
                  <td class="px-4 py-3 text-sm text-gray-600">{{ l.categorias?.map((c) => c.nombre).join(', ') || '—' }}</td>
                  <td class="px-4 py-3 text-sm text-gray-600">{{ l.tipo_material?.nombre ?? '—' }}</td>
                  <td class="px-4 py-3 text-sm text-gray-600">{{ l.ejemplares?.length ?? 0 }}</td>
                </tr>
                <tr v-if="expandidos.has(l.id)" :class="idx % 2 === 0 ? 'bg-white' : 'bg-biblioteca-50'">
                  <td></td>
                  <td colspan="5" class="px-4 pb-4">
                    <div class="rounded-lg border border-gray-200 divide-y divide-gray-100">
                      <div v-for="e in l.ejemplares" :key="e.id" class="flex items-center justify-between px-3 py-2 text-xs">
                        <span class="font-mono text-gray-600">Copia {{ e.numero_copia }} · {{ e.codigo_barras }}</span>
                        <div class="flex items-center gap-2">
                          <span v-if="e.ubicacion" class="text-gray-400">{{ e.ubicacion.nombre }}</span>
                          <span class="px-2 py-0.5 rounded-full font-medium" :class="badgeDe(e).cls">{{ badgeDe(e).label }}</span>
                          <span class="px-2 py-0.5 rounded-full font-medium" :class="e.disponible ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">
                            {{ e.disponible ? 'Disponible' : 'Ocupado' }}
                          </span>
                        </div>
                      </div>
                      <p v-if="!l.ejemplares?.length" class="px-3 py-2 text-xs text-gray-400">Sin copias registradas.</p>
                    </div>
                  </td>
                </tr>
              </template>
              <tr v-if="!cargando && !libros.length">
                <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-400">Sin libros que coincidan con la búsqueda.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </LibrosModuloNav>
  </StaffLayout>
</template>
