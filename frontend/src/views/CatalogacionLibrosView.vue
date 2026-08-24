<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import LibrosModuloNav from '@/components/libros/LibrosModuloNav.vue'
import MultiSelectCombobox from '@/components/libros/MultiSelectCombobox.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import type { Autor, Carrera, Categoria, Libro, TipoMaterial, Ubicacion } from '@/types'

const toast = useToast()

const libros = ref<Libro[]>([])
const cargando = ref(true)
const apiError = ref(false)
const busqueda = ref('')

const autoresOpciones = ref<Autor[]>([])
const categoriasOpciones = ref<Categoria[]>([])
const carrerasOpciones = ref<Carrera[]>([])
const ubicacionesOpciones = ref<Ubicacion[]>([])
const tiposMaterialOpciones = ref<TipoMaterial[]>([])

const modo = ref<'nuevo' | 'copia'>('nuevo')

const editandoId = ref<number | null>(null)
const guardando = ref(false)

const formVacio = {
  codigo_barras: '',
  titulo: '',
  isbn: '',
  autores: [] as string[],
  categorias: [] as string[],
  carreras: [] as string[],
  clasificacion: '',
  coleccion: '',
  editorial: '',
  anio_publicacion: '' as number | '',
  ubicacion_id: '' as number | '',
  tipo_material_id: '' as number | '',
  volumen: '',
  precio: '' as number | '',
  nota_publica: '',
  nota_interna: '',
}
const form = reactive({ ...formVacio })

// Modo "Agregar copia": se busca la obra ya catalogada y solo se piden los datos
// propios de la copia física nueva.
const libroParaCopia = ref<Libro | null>(null)
const busquedaCopia = ref('')
const resultadosCopia = ref<Libro[]>([])
const formCopia = reactive({ codigo_barras: '', ubicacion_id: '' as number | '', volumen: '', precio: '' as number | '' })

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<Libro[]>('/libros', {
      params: { q: busqueda.value.trim() || undefined },
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
    const [autoresRes, categoriasRes, carrerasRes, ubicacionesRes, tiposMaterialRes] = await Promise.all([
      api.get<Autor[]>('/autores'),
      api.get<Categoria[]>('/categorias'),
      api.get<Carrera[]>('/carreras'),
      api.get<Ubicacion[]>('/ubicaciones'),
      api.get<TipoMaterial[]>('/tipos-material'),
    ])
    autoresOpciones.value = autoresRes.data
    categoriasOpciones.value = categoriasRes.data
    carrerasOpciones.value = carrerasRes.data
    ubicacionesOpciones.value = ubicacionesRes.data
    tiposMaterialOpciones.value = tiposMaterialRes.data
  } catch {
    // Los combobox simplemente no ofrecen sugerencias — no bloquea catalogar.
  }
}

onMounted(() => {
  cargar()
  cargarCatalogos()
})

let buscarTimer: ReturnType<typeof setTimeout> | undefined
function onBuscarInput() {
  clearTimeout(buscarTimer)
  buscarTimer = setTimeout(cargar, 250)
}

async function generarCodigoBarras(destino: 'nuevo' | 'copia') {
  try {
    const { data } = await api.get<{ codigo_barras: string }>('/ejemplares/siguiente-codigo-barras')
    if (destino === 'nuevo') form.codigo_barras = data.codigo_barras
    else formCopia.codigo_barras = data.codigo_barras
  } catch {
    toast.error('No se pudo generar un código de barras')
  }
}

function resetForm() {
  Object.assign(form, formVacio, { autores: [], categorias: [], carreras: [] })
  editandoId.value = null
}

function editar(libro: Libro) {
  modo.value = 'nuevo'
  editandoId.value = libro.id
  Object.assign(form, {
    codigo_barras: '',
    titulo: libro.titulo,
    isbn: libro.isbn ?? '',
    autores: (libro.autores ?? []).map((a) => a.nombre),
    categorias: (libro.categorias ?? []).map((c) => c.nombre),
    carreras: (libro.carreras ?? []).map((c) => c.nombre),
    clasificacion: libro.clasificacion ?? '',
    coleccion: libro.coleccion ?? '',
    editorial: libro.editorial ?? '',
    anio_publicacion: libro.anio_publicacion ?? '',
    ubicacion_id: '',
    tipo_material_id: libro.tipo_material_id ?? '',
    volumen: '',
    precio: '',
    nota_publica: libro.nota_publica ?? '',
    nota_interna: libro.nota_interna ?? '',
  })
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const confirmandoGuardar = ref(false)

function pedirConfirmacionGuardar() {
  if (!editandoId.value && !form.codigo_barras.trim()) {
    toast.error('Ingrese al menos el código de barras y el título')
    return
  }
  if (!form.titulo.trim()) {
    toast.error('Ingrese al menos el código de barras y el título')
    return
  }
  confirmandoGuardar.value = true
}

async function confirmarGuardar() {
  guardando.value = true
  try {
    const payload = {
      ...form,
      anio_publicacion: form.anio_publicacion === '' ? null : Number(form.anio_publicacion),
      precio: form.precio === '' ? null : Number(form.precio),
      ubicacion_id: form.ubicacion_id === '' ? null : Number(form.ubicacion_id),
      tipo_material_id: form.tipo_material_id === '' ? null : Number(form.tipo_material_id),
    }
    if (editandoId.value) {
      await api.patch(`/libros/${editandoId.value}`, payload)
      toast.success('Libro actualizado')
    } else {
      await api.post('/libros', payload)
      toast.success('Libro catalogado correctamente')
    }
    confirmandoGuardar.value = false
    resetForm()
    await Promise.all([cargar(), cargarCatalogos()])
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo guardar el libro')
  } finally {
    guardando.value = false
  }
}

let buscarCopiaTimer: ReturnType<typeof setTimeout> | undefined
function onBuscarCopiaInput() {
  clearTimeout(buscarCopiaTimer)
  buscarCopiaTimer = setTimeout(async () => {
    if (!busquedaCopia.value.trim()) {
      resultadosCopia.value = []
      return
    }
    try {
      const { data } = await api.get<Libro[]>('/libros', { params: { q: busquedaCopia.value.trim() } })
      resultadosCopia.value = data
    } catch {
      resultadosCopia.value = []
    }
  }, 250)
}

function seleccionarLibroParaCopia(libro: Libro) {
  libroParaCopia.value = libro
  resultadosCopia.value = []
  busquedaCopia.value = libro.titulo
}

const confirmandoCopia = ref(false)
function pedirConfirmacionCopia() {
  if (!libroParaCopia.value) {
    toast.error('Busca y selecciona el libro al que quieres agregarle una copia')
    return
  }
  if (!formCopia.codigo_barras.trim()) {
    toast.error('Ingresa el código de barras de la nueva copia')
    return
  }
  confirmandoCopia.value = true
}

async function confirmarAgregarCopia() {
  if (!libroParaCopia.value) return
  guardando.value = true
  try {
    await api.post('/ejemplares', {
      libro_id: libroParaCopia.value.id,
      codigo_barras: formCopia.codigo_barras,
      ubicacion_id: formCopia.ubicacion_id === '' ? null : Number(formCopia.ubicacion_id),
      volumen: formCopia.volumen || null,
      precio: formCopia.precio === '' ? null : Number(formCopia.precio),
    })
    toast.success('Copia agregada correctamente')
    confirmandoCopia.value = false
    libroParaCopia.value = null
    busquedaCopia.value = ''
    Object.assign(formCopia, { codigo_barras: '', ubicacion_id: '', volumen: '', precio: '' })
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo agregar la copia')
  } finally {
    guardando.value = false
  }
}

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
</script>

<template>
  <StaffLayout>
    <LibrosModuloNav actual="catalogacion-libros">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Catalogación de Libros</h1>
          <p class="text-sm text-white/60 mt-1">Alta de material bibliográfico con clasificación Dewey y código de barras</p>
        </div>
      </div>

      <div class="flex gap-2 mb-6 border-b border-gray-200">
        <button
          @click="modo = 'nuevo'"
          class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
          :class="modo === 'nuevo' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800'"
        >
          {{ editandoId ? 'Editando libro' : 'Catalogar libro nuevo' }}
        </button>
        <button
          @click="modo = 'copia'"
          class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
          :class="modo === 'copia' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800'"
        >
          Agregar copia a libro existente
        </button>
      </div>

      <div v-if="modo === 'nuevo'" class="bg-white rounded-xl shadow-md p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="font-semibold text-gray-900">
            {{ editandoId ? 'Editando libro' : 'Nuevo libro' }}
          </h3>
          <button v-if="editandoId" @click="resetForm" class="text-sm text-gray-500 hover:text-gray-700 font-medium">
            Cancelar edición
          </button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <div v-if="!editandoId">
            <label class="block text-xs font-medium text-gray-600 mb-1">Código de barras (primera copia) *</label>
            <div class="flex gap-2">
              <input v-model="form.codigo_barras" placeholder="30000003227565" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none font-mono text-sm" />
              <button type="button" @click="generarCodigoBarras('nuevo')" title="Generar código de barras" class="shrink-0 px-3 py-2.5 border border-gray-300 rounded-lg hover:bg-gray-50 text-gray-600">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
              </button>
            </div>
          </div>
          <div :class="editandoId ? 'sm:col-span-2' : ''">
            <label class="block text-xs font-medium text-gray-600 mb-1">Título *</label>
            <input v-model="form.titulo" placeholder="Título del libro" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">ISBN</label>
            <input v-model="form.isbn" placeholder="978..." class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm font-mono" />
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Autor(es)</label>
            <MultiSelectCombobox v-model="form.autores" :opciones="autoresOpciones" placeholder="Escribe para buscar o crear..." />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Categoría(s) / Área</label>
            <MultiSelectCombobox v-model="form.categorias" :opciones="categoriasOpciones" placeholder="Ej: Ciencia, Historia..." />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Carrera(s) asociadas</label>
            <MultiSelectCombobox v-model="form.carreras" :opciones="carrerasOpciones" placeholder="Escribe para buscar..." />
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Tipo de material</label>
            <select v-model="form.tipo_material_id" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
              <option value="">Sin especificar</option>
              <option v-for="t in tiposMaterialOpciones" :key="t.id" :value="t.id">{{ t.nombre }}</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-600 mb-1">Clasificación (Dewey + Cutter + año)</label>
            <input v-model="form.clasificacion" placeholder="Ej: 813.54 G216n 2020" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm font-mono" />
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Colección</label>
            <input v-model="form.coleccion" list="colecciones" placeholder="General, Referencia..." class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Editorial</label>
            <input v-model="form.editorial" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Año de publicación</label>
            <input v-model="form.anio_publicacion" type="number" placeholder="2024" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
          </div>

          <template v-if="!editandoId">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Volumen</label>
              <input v-model="form.volumen" placeholder="Tomo I" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Ubicación física</label>
              <select v-model="form.ubicacion_id" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
                <option value="">Sin especificar</option>
                <option v-for="u in ubicacionesOpciones" :key="u.id" :value="u.id">{{ u.nombre }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Precio (CLP)</label>
              <input v-model="form.precio" type="number" min="0" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            </div>
          </template>

          <div class="sm:col-span-2 lg:col-span-3">
            <label class="block text-xs font-medium text-gray-600 mb-1">Nota pública (visible en el catálogo del portal)</label>
            <textarea v-model="form.nota_publica" rows="2" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"></textarea>
          </div>
          <div class="sm:col-span-2 lg:col-span-3">
            <label class="block text-xs font-medium text-gray-600 mb-1">Nota interna (solo staff)</label>
            <textarea v-model="form.nota_interna" rows="2" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"></textarea>
          </div>
        </div>

        <div class="flex justify-end mt-4">
          <button
            @click="pedirConfirmacionGuardar"
            :disabled="guardando || !form.titulo.trim()"
            class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ editandoId ? 'Guardar cambios' : 'Catalogar libro' }}
          </button>
        </div>
      </div>

      <div v-else class="bg-white rounded-xl shadow-md p-6 mb-6">
        <h3 class="font-semibold text-gray-900 mb-4">Agregar copia a libro existente</h3>

        <label class="block text-xs font-medium text-gray-600 mb-1">Buscar libro por título o ISBN</label>
        <div class="relative mb-4">
          <input
            v-model="busquedaCopia"
            @input="onBuscarCopiaInput"
            type="text"
            placeholder="Título o ISBN del libro..."
            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"
          />
          <div v-if="resultadosCopia.length" class="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-64 overflow-y-auto">
            <button
              v-for="l in resultadosCopia"
              :key="l.id"
              @click="seleccionarLibroParaCopia(l)"
              class="w-full text-left px-3 py-2.5 text-sm hover:bg-indigo-50 border-b border-gray-100 last:border-0"
            >
              <p class="font-medium text-gray-900">{{ l.titulo }}</p>
              <p class="text-xs text-gray-500">{{ l.ejemplares_count ?? l.ejemplares?.length ?? 0 }} copia(s) existente(s)</p>
            </button>
          </div>
        </div>

        <div v-if="libroParaCopia" class="rounded-lg border border-indigo-200 bg-indigo-50/50 p-4 mb-4">
          <p class="text-sm font-semibold text-gray-900">{{ libroParaCopia.titulo }}</p>
          <p class="text-xs text-gray-500">{{ libroParaCopia.ejemplares_count ?? libroParaCopia.ejemplares?.length ?? 0 }} copia(s) ya catalogada(s)</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Código de barras de la nueva copia *</label>
            <div class="flex gap-2">
              <input v-model="formCopia.codigo_barras" placeholder="30000003227566" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none font-mono text-sm" />
              <button type="button" @click="generarCodigoBarras('copia')" title="Generar código de barras" class="shrink-0 px-3 py-2.5 border border-gray-300 rounded-lg hover:bg-gray-50 text-gray-600">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
              </button>
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Ubicación física</label>
            <select v-model="formCopia.ubicacion_id" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
              <option value="">Sin especificar</option>
              <option v-for="u in ubicacionesOpciones" :key="u.id" :value="u.id">{{ u.nombre }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Volumen</label>
            <input v-model="formCopia.volumen" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Precio (CLP)</label>
            <input v-model="formCopia.precio" type="number" min="0" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
          </div>
        </div>

        <div class="flex justify-end mt-4">
          <button
            @click="pedirConfirmacionCopia"
            :disabled="guardando"
            class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Agregar copia
          </button>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="bg-white rounded-xl shadow-md p-4 mb-4">
        <div class="relative max-w-sm">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
          </svg>
          <input
            v-model="busqueda"
            @input="onBuscarInput"
            type="text"
            placeholder="Buscar por título, autor o código de barras..."
            class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"
          />
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="bg-gray-100 border-b-2 border-gray-200">
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Título</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Clasificación</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Copias</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              <tr v-for="l in libros" :key="l.id" class="hover:bg-indigo-50/40 transition-colors align-top">
                <td class="px-4 py-3 text-sm text-gray-900">
                  {{ l.titulo }}
                  <p v-if="l.autores?.length" class="text-xs text-gray-500">{{ l.autores.map((a) => a.nombre).join(', ') }}</p>
                </td>
                <td class="px-4 py-3 text-xs font-mono text-gray-500">{{ l.clasificacion ?? '—' }}</td>
                <td class="px-4 py-3">
                  <div class="flex flex-wrap gap-1">
                    <span
                      v-for="e in l.ejemplares"
                      :key="e.id"
                      class="text-xs px-2 py-0.5 rounded-full font-mono font-medium"
                      :class="estadoBadges[e.estado_proceso]?.cls"
                      :title="`Copia ${e.numero_copia} · ${e.codigo_barras}`"
                    >
                      c.{{ e.numero_copia }}
                    </span>
                  </div>
                </td>
                <td class="px-4 py-3">
                  <button @click="editar(l)" class="text-sm text-indigo-700 hover:text-indigo-800 font-medium">Editar</button>
                </td>
              </tr>
              <tr v-if="!cargando && !libros.length">
                <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-400">Sin libros que coincidan con la búsqueda.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <datalist id="colecciones">
        <option value="General" />
        <option value="Referencia" />
        <option value="Hemeroteca" />
      </datalist>

      <div
        v-if="confirmandoGuardar"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="confirmandoGuardar = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">
            {{ editandoId ? '¿Confirmar cambios?' : '¿Confirmar catalogación?' }}
          </h3>
          <p class="text-sm text-gray-500 mb-6">
            {{ editandoId ? 'Se guardarán los cambios sobre' : 'Se agregará al catálogo' }}
            <strong>{{ form.titulo }}</strong>.
          </p>
          <div class="flex gap-3">
            <button
              @click="confirmandoGuardar = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="confirmarGuardar"
              :disabled="guardando"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ guardando ? 'Guardando…' : 'Sí, guardar' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="confirmandoCopia && libroParaCopia"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="confirmandoCopia = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Confirmar copia nueva?</h3>
          <p class="text-sm text-gray-500 mb-6">
            Se agregará una copia nueva de <strong>{{ libroParaCopia.titulo }}</strong> con código
            <span class="font-mono">{{ formCopia.codigo_barras }}</span>.
          </p>
          <div class="flex gap-3">
            <button
              @click="confirmandoCopia = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="confirmarAgregarCopia"
              :disabled="guardando"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ guardando ? 'Guardando…' : 'Sí, agregar' }}
            </button>
          </div>
        </div>
      </div>
    </LibrosModuloNav>
  </StaffLayout>
</template>
