<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import EncabezadoGestion from '@/components/ui/EncabezadoGestion.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import FichaBibliografica from '@/components/libros/FichaBibliografica.vue'
import ExportarLibros from '@/components/libros/ExportarLibros.vue'
import Paginador from '@/components/ui/Paginador.vue'
import PestanasLibros from '@/components/libros/PestanasLibros.vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { ESTADOS_EJEMPLAR, situacionCopia } from '@/utils/estadosEjemplar'
import { inicialesPortada, tonoPortada } from '@/utils/portada'
import type { Carrera, Categoria, Libro, Paginado, TipoMaterial } from '@/types'

const router = useRouter()
const auth = useAuthStore()

const libros = ref<Libro[]>([])
const cargando = ref(true)
const apiError = ref(false)
// El catálogo real son decenas de miles de títulos: se pide de a 50.
const POR_PAGINA = 50
const pagina = ref(1)
const meta = ref<Omit<Paginado<Libro>, 'data'>>({ current_page: 1, last_page: 1, per_page: POR_PAGINA, total: 0, from: null, to: null })

const filtros = reactive({
  q: '',
  estado_proceso: '',
  tipo_material_id: '' as number | '',
  categoria_id: '' as number | '',
  // Carrera asignada al libro al catalogarlo (ej. Pedagogía en Educación Básica).
  carrera_id: '' as number | '',
  orden: 'titulo' as 'titulo' | 'tipo_material',
})

const categorias = ref<Categoria[]>([])
const carreras = ref<Carrera[]>([])
const exportarAbierto = ref(false)
const tiposMaterial = ref<TipoMaterial[]>([])
const expandidos = ref<Set<number>>(new Set())

const puedeCatalogar = computed(() => auth.puede('libros.catalogar'))
const puedeVerHistorial = computed(() => auth.puede('historiales.ver'))
const puedeCambiarEstado = computed(() => auth.puede('libros.estado'))

// Filtros en forma de píldora: «Estado: En estante», con el select invisible encima.
const filtrosPildora = computed(() => [
  {
    campo: 'estado_proceso' as const,
    nombre: 'Estado',
    opciones: [{ valor: '', texto: 'Todos' }, ...Object.entries(ESTADOS_EJEMPLAR).map(([valor, e]) => ({ valor, texto: e.label }))],
  },
  {
    campo: 'tipo_material_id' as const,
    nombre: 'Tipo',
    opciones: [{ valor: '', texto: 'Todos' }, ...tiposMaterial.value.map((t) => ({ valor: t.id, texto: t.nombre }))],
  },
  {
    campo: 'categoria_id' as const,
    nombre: 'Categoría',
    opciones: [{ valor: '', texto: 'Todas' }, ...categorias.value.map((c) => ({ valor: c.id, texto: c.nombre }))],
  },
  {
    campo: 'carrera_id' as const,
    nombre: 'Carrera',
    opciones: [{ valor: '', texto: 'Todas' }, ...carreras.value.map((c) => ({ valor: c.id, texto: c.nombre }))],
  },
  {
    campo: 'orden' as const,
    nombre: 'Ordenar',
    opciones: [
      { valor: 'titulo', texto: 'título' },
      { valor: 'tipo_material', texto: 'tipo de recurso' },
    ],
  },
])

function textoFiltro(f: (typeof filtrosPildora.value)[number]) {
  const elegido = f.opciones.find((o) => o.valor === filtros[f.campo])
  if (f.campo === 'orden') return `${f.nombre}: ${elegido?.texto ?? 'título'}`
  return filtros[f.campo] === '' ? f.nombre : `${f.nombre}: ${elegido?.texto ?? ''}`
}

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<Paginado<Libro>>('/libros', {
      params: {
        page: pagina.value,
        por_pagina: POR_PAGINA,
        q: filtros.q.trim() || undefined,
        estado_proceso: filtros.estado_proceso || undefined,
        tipo_material_id: filtros.tipo_material_id || undefined,
        categoria_id: filtros.categoria_id || undefined,
        carrera_id: filtros.carrera_id || undefined,
        orden: filtros.orden,
      },
    })
    const { data: filas, ...resto } = data
    libros.value = filas
    meta.value = resto
    apiError.value = false
  } catch {
    apiError.value = true
    libros.value = []
  } finally {
    cargando.value = false
  }
}

/** Una búsqueda o un filtro nuevo parte de la primera página. */
function filtrar() {
  pagina.value = 1
  expandidos.value = new Set()
  cargar()
}

function irAPagina(nueva: number) {
  pagina.value = nueva
  expandidos.value = new Set()
  cargar()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function cargarCatalogos() {
  try {
    const [categoriasRes, tiposMaterialRes, carrerasRes] = await Promise.all([
      api.get<Categoria[]>('/categorias'),
      api.get<TipoMaterial[]>('/tipos-material'),
      api.get<Carrera[]>('/carreras'),
    ])
    categorias.value = categoriasRes.data
    tiposMaterial.value = tiposMaterialRes.data
    carreras.value = carrerasRes.data
  } catch {
    // Sin catálogos, esos filtros quedan solo con «Todos».
  }
}

let buscarTimer: ReturnType<typeof setTimeout> | undefined
function onBuscarInput() {
  clearTimeout(buscarTimer)
  buscarTimer = setTimeout(filtrar, 250)
}

function toggleExpandido(libroId: number) {
  const nuevo = new Set(expandidos.value)
  if (nuevo.has(libroId)) nuevo.delete(libroId)
  else nuevo.add(libroId)
  expandidos.value = nuevo
}

function filaDe(l: Libro) {
  const copias = l.ejemplares ?? []
  const situaciones = copias.map((e) => situacionCopia(e))
  const plantilla = l.tipo_material?.plantilla
  return {
    tono: tonoPortada(l.categorias?.[0]?.nombre ?? l.tipo_material?.nombre ?? l.titulo),
    iniciales: inicialesPortada(l.titulo),
    autores: l.autores?.map((a) => a.nombre).join(', ') || (plantilla === 'publicacion_seriada' ? 'Publicación periódica' : 'Sin autor registrado'),
    categoria: l.categorias?.map((c) => c.nombre).join(', ') || '—',
    tipoClase:
      plantilla === 'publicacion_seriada'
        ? 'bg-sky-50 text-sky-700 ring-sky-100'
        : plantilla === 'trabajo_titulacion'
          ? 'bg-violet-50 text-violet-700 ring-violet-100'
          : plantilla === 'juego_educativo'
            ? 'bg-amber-50 text-amber-800 ring-amber-100'
            : 'bg-gray-100 text-gray-700 ring-gray-200',
    barras: situaciones.map((s) => s.barra),
    disponibles: situaciones.filter((s) => s.disponible).length,
    total: copias.length,
  }
}

const filas = computed(() => libros.value.map((l) => ({ libro: l, ...filaDe(l) })))

// «⋯»: atajos a lo que se hace con el título en otras pantallas.
const menuAbierto = ref<number | null>(null)
function alHacerClic(e: MouseEvent) {
  if (menuAbierto.value !== null && !(e.target as HTMLElement).closest('[data-menu-libro]')) menuAbierto.value = null
}

onMounted(() => {
  cargar()
  cargarCatalogos()
  document.addEventListener('click', alHacerClic)
})
onBeforeUnmount(() => document.removeEventListener('click', alHacerClic))

const libroAEditar = ref<Libro | null>(null)

function irAEditar() {
  const libro = libroAEditar.value
  libroAEditar.value = null
  if (libro) router.push({ name: 'catalogacion-libros', query: { editar: libro.id } })
}

function agregarCopia(l: Libro) {
  router.push({ name: 'catalogacion-libros', query: { copia: l.id } })
}
</script>

<template>
  <StaffLayout>
    <div class="mx-auto max-w-[88rem]">
      <EncabezadoGestion seccion="Catalogación" titulo="Listado de libros">
        <template #acciones>
          <PestanasLibros actual="listado-libros" />
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-xl border border-emerald-300 bg-white px-4 py-2.5 text-sm font-semibold text-emerald-700 shadow-sm transition hover:bg-emerald-50"
            @click="exportarAbierto = true"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2-8H8a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V8l-4-4z" />
            </svg>
            Exportar
          </button>
          <router-link
            v-if="puedeCatalogar"
            :to="{ name: 'catalogacion-libros' }"
            class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-md transition hover:brightness-110"
            style="background: linear-gradient(135deg, #3B28A3 0%, #4F46E5 100%);"
          >
            <span class="text-base leading-none">+</span> Catalogar libro
          </router-link>
        </template>
      </EncabezadoGestion>

      <ApiErrorBanner v-if="apiError" />

      <div class="mb-5 flex flex-wrap items-center gap-2">
        <div class="relative min-w-[16rem] flex-1">
          <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
          </svg>
          <input
            v-model="filtros.q"
            type="text"
            placeholder="Título, autor, ISBN, Dewey o código de barras"
            aria-label="Buscar libros"
            class="h-11 w-full rounded-xl border border-gray-200 bg-white pl-10 pr-4 text-sm text-gray-900 shadow-sm outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
            @input="onBuscarInput"
          />
        </div>
        <label
          v-for="f in filtrosPildora"
          :key="f.campo"
          class="relative inline-flex h-11 items-center rounded-xl border bg-white pl-3.5 pr-9 text-sm shadow-sm transition-colors"
          :class="f.campo !== 'orden' && filtros[f.campo] !== '' ? 'border-indigo-300 text-indigo-800' : 'border-gray-200 text-gray-700'"
        >
          <span class="max-w-[12rem] truncate font-medium">{{ textoFiltro(f) }}</span>
          <svg class="pointer-events-none absolute right-3 h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
          </svg>
          <select v-model="filtros[f.campo]" class="absolute inset-0 cursor-pointer opacity-0" :aria-label="`Filtrar por ${f.nombre.toLowerCase()}`" @change="filtrar">
            <option v-for="o in f.opciones" :key="String(o.valor)" :value="o.valor">{{ o.texto }}</option>
          </select>
        </label>
      </div>

      <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div
          class="hidden border-b border-gray-200 bg-gray-50/80 px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500 lg:grid lg:grid-cols-[2.75rem_minmax(0,2.3fr)_minmax(0,1.1fr)_minmax(0,1fr)_6rem_minmax(0,1.3fr)_8.5rem] lg:gap-4"
        >
          <span></span>
          <span>Título y autor</span>
          <span>Dewey</span>
          <span>Categoría</span>
          <span>Tipo</span>
          <span>Ejemplares</span>
          <span class="text-right">Acciones</span>
        </div>

        <div class="divide-y divide-gray-100">
          <div v-for="f in filas" :key="f.libro.id">
            <div
              class="grid grid-cols-[2.25rem_minmax(0,1fr)] gap-x-3 gap-y-2.5 px-4 py-3.5 transition-colors lg:grid-cols-[2.75rem_minmax(0,2.3fr)_minmax(0,1.1fr)_minmax(0,1fr)_6rem_minmax(0,1.3fr)_8.5rem] lg:items-center lg:gap-4 lg:px-5"
              :class="expandidos.has(f.libro.id) ? 'bg-indigo-50/40' : 'hover:bg-gray-50/70'"
            >
              <button
                type="button"
                class="row-span-3 flex h-9 w-9 items-center justify-center self-start rounded-lg transition-colors lg:row-span-1 lg:self-center"
                :class="expandidos.has(f.libro.id) ? 'bg-indigo-100 text-indigo-700' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-700'"
                :aria-expanded="expandidos.has(f.libro.id)"
                :aria-label="`${expandidos.has(f.libro.id) ? 'Ocultar' : 'Ver'} ficha y ejemplares de ${f.libro.titulo}`"
                @click="toggleExpandido(f.libro.id)"
              >
                <svg class="h-4 w-4 transition-transform" :class="expandidos.has(f.libro.id) ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
              </button>

              <div class="flex min-w-0 items-center gap-3">
                <div class="flex h-11 w-9 shrink-0 items-end rounded-sm border-l-4 px-1 pb-0.5 font-serif text-xs font-bold" :class="f.tono" aria-hidden="true">
                  {{ f.iniciales }}
                </div>
                <div class="min-w-0">
                  <p class="font-semibold leading-snug text-gray-900">{{ f.libro.titulo }}</p>
                  <p class="truncate text-xs text-gray-500">{{ f.autores }}</p>
                </div>
              </div>

              <div class="col-start-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 lg:contents">
                <p class="font-mono text-xs text-gray-600">{{ f.libro.clasificacion ?? '—' }}</p>
                <p class="truncate text-sm text-gray-600">{{ f.categoria }}</p>
                <span class="justify-self-start whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset" :class="f.tipoClase">
                  {{ f.libro.tipo_material?.nombre ?? 'Libro' }}
                </span>
              </div>

              <div class="col-start-2 flex items-center justify-between gap-3 lg:contents">
                <div class="min-w-0">
                  <div v-if="f.barras.length" class="mb-1 flex flex-wrap gap-1">
                    <span v-for="(b, i) in f.barras" :key="i" class="h-1.5 w-5 rounded-full" :class="b"></span>
                  </div>
                  <p class="text-xs text-gray-500">
                    <strong class="text-gray-900">{{ f.disponibles }}</strong> de {{ f.total }} {{ f.total === 1 ? 'disponible' : 'disponibles' }}
                  </p>
                </div>
                <div class="relative flex shrink-0 items-center justify-end gap-2" data-menu-libro>
                  <button
                    v-if="puedeCatalogar"
                    type="button"
                    class="rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-800 transition-colors hover:bg-gray-50"
                    @click="libroAEditar = f.libro"
                  >
                    Editar
                  </button>
                  <button
                    v-if="puedeCatalogar || puedeVerHistorial || puedeCambiarEstado"
                    type="button"
                    class="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-100 text-gray-500 transition-colors hover:bg-gray-200 hover:text-gray-800"
                    :aria-label="`Más acciones para ${f.libro.titulo}`"
                    :aria-expanded="menuAbierto === f.libro.id"
                    @click="menuAbierto = menuAbierto === f.libro.id ? null : f.libro.id"
                  >
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
                  </button>
                  <div v-if="menuAbierto === f.libro.id" role="menu" class="absolute right-0 top-full z-20 mt-1.5 w-56 rounded-xl border border-gray-200 bg-white p-1.5 text-left shadow-lg">
                    <button v-if="puedeCatalogar" type="button" role="menuitem" class="w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50" @click="agregarCopia(f.libro)">
                      Agregar copia
                    </button>
                    <router-link
                      v-if="puedeCatalogar"
                      role="menuitem"
                      :to="{ name: 'etiquetas', query: { lomo: f.libro.id } }"
                      class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                      Etiquetas de lomo
                    </router-link>
                    <router-link
                      v-if="puedeVerHistorial"
                      role="menuitem"
                      :to="{ name: 'historial-prestamos-libro', query: { libro: f.libro.id } }"
                      class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                      Historial de préstamos
                    </router-link>
                    <router-link
                      v-if="puedeCambiarEstado"
                      role="menuitem"
                      :to="{ name: 'estado-libro', query: { q: f.libro.titulo } }"
                      class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                      Estado de sus ejemplares
                    </router-link>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="expandidos.has(f.libro.id)" class="border-t border-indigo-100/70 bg-indigo-50/30 px-4 pb-5 pt-4 lg:pl-[4.5rem] lg:pr-5">
              <div class="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] lg:items-start">
                <div class="overflow-hidden rounded-xl border border-indigo-100 bg-white">
                  <p class="bg-indigo-50 px-4 py-2.5 text-[11px] font-bold uppercase tracking-wider text-indigo-700">Ficha bibliográfica · MARC 21</p>
                  <div class="px-4">
                    <FichaBibliografica :filas="f.libro.ficha ?? []" con-etiquetas />
                  </div>
                </div>
                <div class="overflow-hidden rounded-xl border border-emerald-100 bg-white">
                  <p class="flex items-center justify-between bg-emerald-50 px-4 py-2.5 text-[11px] font-bold uppercase tracking-wider text-emerald-800">
                    Ejemplares <span>{{ f.total }}</span>
                  </p>
                  <ul class="divide-y divide-gray-100">
                    <li v-for="e in f.libro.ejemplares" :key="e.id" class="flex items-center justify-between gap-3 px-4 py-2.5">
                      <div class="min-w-0">
                        <p class="font-mono text-sm font-semibold text-gray-900">{{ e.codigo_barras }}</p>
                        <p class="text-xs text-gray-500">{{ e.ubicacion?.nombre ?? 'Sin ubicación' }} · c.{{ e.numero_copia }} · lomo <span class="font-mono">{{ e.codigo_lomo }}</span></p>
                      </div>
                      <div class="flex shrink-0 items-center gap-2">
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="situacionCopia(e).badge">{{ situacionCopia(e).texto }}</span>
                        <!-- Abre Estado de ejemplares con esta copia ya elegida (la reconoce por su código). -->
                        <router-link
                          v-if="puedeCambiarEstado"
                          :to="{ name: 'estado-libro', query: { q: e.codigo_barras } }"
                          class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-indigo-50 hover:text-indigo-700"
                          :title="`Cambiar el estado de la copia ${e.numero_copia}`"
                          :aria-label="`Cambiar el estado de la copia ${e.numero_copia} (${e.codigo_barras})`"
                        >
                          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" />
                          </svg>
                        </router-link>
                      </div>
                    </li>
                    <li v-if="!f.total" class="px-4 py-3 text-xs text-gray-400">Sin copias registradas.</li>
                  </ul>
                  <div v-if="puedeCatalogar" class="px-4 pb-4 pt-2">
                    <button
                      type="button"
                      class="rounded-lg border border-dashed border-emerald-300 px-3 py-2 text-xs font-semibold text-emerald-700 transition-colors hover:bg-emerald-50"
                      @click="agregarCopia(f.libro)"
                    >
                      + Agregar copia
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <p v-if="!cargando && !filas.length" class="px-6 py-10 text-center text-sm text-gray-400">Sin libros que coincidan con la búsqueda.</p>
        </div>
      </div>

      <Paginador
        class="mt-4"
        :pagina="meta.current_page"
        :paginas="meta.last_page"
        :total="meta.total"
        :desde="meta.from"
        :hasta="meta.to"
        unidad="títulos"
        :cargando="cargando"
        @update:pagina="irAPagina"
      />
    </div>

    <ExportarLibros
      v-model:abierto="exportarAbierto"
      :inicial="{ q: filtros.q, estado_proceso: filtros.estado_proceso, tipo_material_id: filtros.tipo_material_id, categoria_id: filtros.categoria_id, carrera_id: filtros.carrera_id }"
      :categorias="categorias"
      :carreras="carreras"
      :tipos-material="tiposMaterial"
    />

    <DialogoConfirmacion
      :abierto="!!libroAEditar"
      titulo="¿Editar esta ficha?"
      confirmar="Sí, editar"
      @confirmar="irAEditar"
      @cancelar="libroAEditar = null"
    >
      <p>
        Se abrirá Catalogación con <strong class="text-gray-900">{{ libroAEditar?.titulo }}</strong> cargado para corregirlo. Los cambios se guardan
        recién al pulsar «Guardar cambios».
      </p>
    </DialogoConfirmacion>
  </StaffLayout>
</template>
