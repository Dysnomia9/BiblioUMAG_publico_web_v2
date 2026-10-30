<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import EncabezadoGestion from '@/components/ui/EncabezadoGestion.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import FichaBibliografica from '@/components/libros/FichaBibliografica.vue'
import CarrerasAsociadas from '@/components/libros/CarrerasAsociadas.vue'
import EditarCopia from '@/components/libros/EditarCopia.vue'
import FasciculosRevista from '@/components/libros/FasciculosRevista.vue'
import ExportarLibros from '@/components/libros/ExportarLibros.vue'
import Paginador from '@/components/ui/Paginador.vue'
import PestanasLibros from '@/components/libros/PestanasLibros.vue'
import SelectorBuscable from '@/components/ui/SelectorBuscable.vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { ESTADOS_EJEMPLAR, situacionCopia } from '@/utils/estadosEjemplar'
import { textoIngreso } from '@/utils/ingreso'
import PortadaMaterial from '@/components/libros/PortadaMaterial.vue'
import type { Carrera, Categoria, Ejemplar, Libro, Paginado, TipoMaterial, Ubicacion } from '@/types'

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

// La categoría se busca en el servidor (son miles con el catálogo real): acá queda la elegida.
const categoriaElegida = ref<Categoria | null>(null)
const carreras = ref<Carrera[]>([])
const exportarAbierto = ref(false)
const tiposMaterial = ref<TipoMaterial[]>([])
const ubicaciones = ref<Ubicacion[]>([])
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
    const [tiposMaterialRes, carrerasRes, ubicacionesRes] = await Promise.all([
      api.get<TipoMaterial[]>('/tipos-material'),
      api.get<Carrera[]>('/carreras'),
      api.get<Ubicacion[]>('/ubicaciones'),
    ])
    tiposMaterial.value = tiposMaterialRes.data
    carreras.value = carrerasRes.data
    ubicaciones.value = ubicacionesRes.data
  } catch {
    // Sin catálogos, esos filtros quedan solo con «Todos».
  }
}

async function buscarCategorias(texto: string) {
  const { data } = await api.get<Categoria[]>('/categorias', { params: { q: texto || undefined } })
  return data
}

function elegirCategoria(categoria: Categoria | null) {
  categoriaElegida.value = categoria
  filtrar()
}

let buscarTimer: ReturnType<typeof setTimeout> | undefined
function onBuscarInput() {
  clearTimeout(buscarTimer)
  buscarTimer = setTimeout(filtrar, 250)
}

/** El botón «Buscar» y Enter: sin esperar la pausa al escribir. */
function buscarAhora() {
  clearTimeout(buscarTimer)
  filtrar()
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
    // «Libro electrónico» y «Juego educativo» no caben en la columna Tipo (6rem) con la letra de los demás
    tipoTamano: (l.tipo_material?.nombre ?? 'Libro').length > 12 ? 'px-2 text-[11px]' : 'px-2.5 text-xs',
    barras: situaciones.map((s) => s.barra),
    disponibles: situaciones.filter((s) => s.disponible).length,
    total: copias.length,
  }
}

const filas = computed(() => libros.value.map((l) => ({ libro: l, ...filaDe(l) })))

// «⋯»: atajos a lo que se hace con el título en otras pantallas. El menú va al <body> con
// posición fija: dentro de la tabla (overflow-hidden, por sus bordes redondeados) quedaba
// cortado cuando la lista tenía una o dos filas. Si abajo no cabe, se abre hacia arriba.
const menuAbierto = ref<number | null>(null)
const posicionMenu = ref<Record<string, string>>({})
let botonMenu: HTMLElement | null = null
const ANCHO_MENU = 224
const ALTO_MENU = 180

function ubicarMenu() {
  const r = botonMenu?.getBoundingClientRect()
  if (!r) return
  const izquierda = `${Math.max(8, Math.min(r.right - ANCHO_MENU, window.innerWidth - ANCHO_MENU - 8))}px`
  const abajo = window.innerHeight - r.bottom
  posicionMenu.value =
    abajo < ALTO_MENU && r.top > abajo
      ? { left: izquierda, bottom: `${window.innerHeight - r.top + 6}px` }
      : { left: izquierda, top: `${r.bottom + 6}px` }
}

function alternarMenu(id: number, e: MouseEvent) {
  if (menuAbierto.value === id) {
    menuAbierto.value = null
    return
  }
  botonMenu = e.currentTarget as HTMLElement
  menuAbierto.value = id
  ubicarMenu()
}

function dejarDeSeguirMenu() {
  window.removeEventListener('scroll', ubicarMenu, true)
  window.removeEventListener('resize', ubicarMenu)
}

watch(menuAbierto, (id) => {
  if (id === null) return dejarDeSeguirMenu()
  window.addEventListener('scroll', ubicarMenu, true)
  window.addEventListener('resize', ubicarMenu)
})

function alHacerClic(e: MouseEvent) {
  if (menuAbierto.value !== null && !(e.target as HTMLElement).closest('[data-menu-libro]')) menuAbierto.value = null
}

onMounted(() => {
  cargar()
  cargarCatalogos()
  document.addEventListener('click', alHacerClic)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', alHacerClic)
  dejarDeSeguirMenu()
})

const libroAEditar = ref<Libro | null>(null)

function irAEditar() {
  const libro = libroAEditar.value
  libroAEditar.value = null
  if (libro) router.push({ name: 'catalogacion-libros', query: { editar: libro.id } })
}

function agregarCopia(l: Libro) {
  router.push({ name: 'catalogacion-libros', query: { copia: l.id } })
}

// ---------- Editar una copia (ubicación, volumen, colección y notas) ----------
const copiaAEditar = ref<{ copia: Ejemplar; libro: Libro } | null>(null)

function copiaGuardada(actualizada: Ejemplar) {
  const libro = copiaAEditar.value?.libro
  const i = libro?.ejemplares?.findIndex((e) => e.id === actualizada.id) ?? -1
  if (libro?.ejemplares && i >= 0) libro.ejemplares[i] = { ...libro.ejemplares[i], ...actualizada, libro: undefined }
  copiaAEditar.value = null
}
</script>

<template>
  <StaffLayout>
    <div class="mx-auto max-w-[88rem]">
      <EncabezadoGestion seccion="Catálogo" titulo="Listado de material">
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
        <form class="flex min-w-[16rem] flex-1 gap-2" role="search" @submit.prevent="buscarAhora">
          <div class="relative min-w-0 flex-1">
            <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
            <input
              v-model="filtros.q"
              type="text"
              placeholder="Título, autor, ISBN, Dewey, código de barras o N° de registro"
              aria-label="Buscar libros"
              class="h-11 w-full rounded-xl border border-gray-200 bg-white pl-10 pr-4 text-sm text-gray-900 shadow-sm outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
              @input="onBuscarInput"
            />
          </div>
          <button
            type="submit"
            :disabled="cargando"
            class="h-11 shrink-0 rounded-xl bg-indigo-600 px-5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:opacity-60"
          >
            Buscar
          </button>
        </form>
        <template v-for="f in filtrosPildora" :key="f.campo">
          <label
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
          <!-- La categoría, después del tipo: se busca escribiendo, son miles. -->
          <SelectorBuscable
            v-if="f.campo === 'tipo_material_id'"
            v-model="filtros.categoria_id"
            :buscar="buscarCategorias"
            todas="Todas las categorías"
            placeholder="Categoría"
            etiqueta="Filtrar por categoría"
            :clase-campo="`h-11 w-56 rounded-xl border bg-white pl-3.5 pr-3 text-sm font-medium shadow-sm outline-none transition-colors placeholder:text-gray-700 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30 ${filtros.categoria_id !== '' ? 'border-indigo-300 text-indigo-800' : 'border-gray-200 text-gray-700'}`"
            @elegir="elegirCategoria"
          />
        </template>
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
                <PortadaMaterial
                  tamano="sm"
                  :titulo="f.libro.titulo"
                  :tipo="f.libro.tipo_material?.nombre"
                  :plantilla="f.libro.tipo_material?.plantilla"
                  :clave-tono="f.libro.categorias?.[0]?.nombre"
                />
                <div class="min-w-0">
                  <p class="font-semibold leading-snug text-gray-900">{{ f.libro.titulo }}</p>
                  <p class="truncate text-xs text-gray-500">{{ f.autores }}</p>
                </div>
              </div>

              <div class="col-start-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 lg:contents">
                <p class="font-mono text-xs text-gray-600">{{ f.libro.clasificacion ?? '—' }}</p>
                <p class="truncate text-sm text-gray-600">{{ f.categoria }}</p>
                <span class="justify-self-start whitespace-nowrap rounded-full py-0.5 font-medium ring-1 ring-inset" :class="[f.tipoClase, f.tipoTamano]">
                  {{ f.libro.tipo_material?.nombre ?? 'Libro' }}
                </span>
              </div>

              <div class="col-start-2 flex items-center justify-between gap-3 lg:contents">
                <div class="min-w-0">
                  <div v-if="f.barras.length" class="mb-1 flex flex-wrap gap-1">
                    <span v-for="(b, i) in f.barras" :key="i" class="h-1.5 w-5 rounded-full" :class="b"></span>
                  </div>
                  <!-- Un libro electrónico no tiene copias: se lee en el enlace de su ficha (856) -->
                  <p v-if="!f.total && f.libro.url_acceso" class="text-xs font-medium text-indigo-700">En línea</p>
                  <p v-else class="text-xs text-gray-500">
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
                    @click="alternarMenu(f.libro.id, $event)"
                  >
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
                  </button>
                  <Teleport to="body">
                    <div
                      v-if="menuAbierto === f.libro.id"
                      role="menu"
                      data-menu-libro
                      class="fixed z-50 w-56 rounded-xl border border-gray-200 bg-white p-1.5 text-left shadow-lg"
                      :style="posicionMenu"
                    >
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
                  </Teleport>
                </div>
              </div>
            </div>

            <div v-if="expandidos.has(f.libro.id)" class="border-t border-indigo-100/70 bg-indigo-50/30 px-4 pb-5 pt-4 lg:pl-[4.5rem] lg:pr-5">
              <CarrerasAsociadas :carreras="(f.libro.carreras ?? []).map((c) => c.nombre)" />
              <div class="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] lg:items-start">
                <div class="overflow-hidden rounded-xl border border-indigo-100 bg-white">
                  <p class="bg-indigo-50 px-4 py-2.5 text-[11px] font-bold uppercase tracking-wider text-indigo-700">Ficha bibliográfica · MARC 21</p>
                  <div class="px-4">
                    <FichaBibliografica :filas="f.libro.ficha ?? []" con-etiquetas />
                  </div>
                  <p v-if="textoIngreso(f.libro)" class="border-t border-indigo-100 px-4 py-2.5 text-xs text-gray-500">{{ textoIngreso(f.libro) }}</p>
                </div>
                <div class="overflow-hidden rounded-xl border border-emerald-100 bg-white">
                  <p class="flex items-center justify-between bg-emerald-50 px-4 py-2.5 text-[11px] font-bold uppercase tracking-wider text-emerald-800">
                    Ejemplares <span>{{ f.total }}</span>
                  </p>
                  <ul class="divide-y divide-gray-100">
                    <li v-for="e in f.libro.ejemplares" :key="e.id" class="flex items-center justify-between gap-3 px-4 py-2.5">
                      <div class="min-w-0">
                        <p class="font-mono text-sm font-semibold text-gray-900">{{ e.codigo_barras }}</p>
                        <!-- El tomo («v.2») o el número de la revista («Año IV No. 4 (Jul.-Aug., 1985)») -->
                        <p v-if="e.volumen" class="text-sm text-gray-800">{{ e.volumen }}</p>
                        <p class="text-xs text-gray-500">
                          {{ e.ubicacion?.nombre ?? 'Sin ubicación' }} · c.{{ e.numero_copia }} · lomo <span class="font-mono">{{ e.codigo_lomo }}</span>
                          <template v-if="e.coleccion || f.libro.coleccion"> · {{ e.coleccion || f.libro.coleccion }}</template>
                        </p>
                        <p v-if="e.numero_registro" class="text-xs text-gray-600">N° de registro Horizon: <span class="font-mono">{{ e.numero_registro }}</span></p>
                        <p v-if="e.nota_publica" class="text-xs text-gray-600">Nota pública: {{ e.nota_publica }}</p>
                        <p v-if="e.nota_interna" class="text-xs text-violet-700">Nota interna: {{ e.nota_interna }}</p>
                        <p v-if="textoIngreso(e)" class="text-xs text-gray-400">{{ textoIngreso(e) }}</p>
                      </div>
                      <div class="flex shrink-0 items-center gap-2">
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="situacionCopia(e).badge">{{ situacionCopia(e).texto }}</span>
                        <button
                          v-if="puedeCatalogar"
                          type="button"
                          class="rounded-lg px-2 py-1 text-xs font-medium text-indigo-700 transition-colors hover:bg-indigo-50"
                          :aria-label="`Editar la copia ${e.numero_copia} (${e.codigo_barras})`"
                          @click="copiaAEditar = { copia: e, libro: f.libro }"
                        >
                          Editar
                        </button>
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
                    <li v-if="!f.total" class="px-4 py-3 text-xs text-gray-400">
                      {{ f.libro.url_acceso ? 'Sin copias físicas: se lee en línea, con el enlace de la ficha.' : 'Sin copias registradas.' }}
                    </li>
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
                <FasciculosRevista
                  v-if="f.libro.tipo_material?.plantilla === 'publicacion_seriada'"
                  class="lg:col-span-2"
                  :libro-id="f.libro.id"
                  :copias="f.libro.ejemplares ?? []"
                  :puede-editar="puedeCatalogar"
                />
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
      :categoria-inicial="categoriaElegida"
      :carreras="carreras"
      :tipos-material="tiposMaterial"
    />

    <EditarCopia
      :copia="copiaAEditar?.copia ?? null"
      :coleccion-libro="copiaAEditar?.libro.coleccion ?? null"
      :ubicaciones="ubicaciones"
      @cerrar="copiaAEditar = null"
      @guardada="copiaGuardada"
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
