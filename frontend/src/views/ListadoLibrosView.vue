<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import FichaBibliografica from '@/components/libros/FichaBibliografica.vue'
import EditarCopia from '@/components/libros/EditarCopia.vue'
import FasciculosRevista from '@/components/libros/FasciculosRevista.vue'
import ExportarLibros from '@/components/libros/ExportarLibros.vue'
import Paginador from '@/components/ui/Paginador.vue'
import PestanasLibros from '@/components/libros/PestanasLibros.vue'
import FondoEstante from '@/components/libros/FondoEstante.vue'
import api from '@/services/api'
import { esBusquedaCorta } from '@/utils/busqueda'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { ESTADOS_EJEMPLAR, situacionCopia } from '@/utils/estadosEjemplar'
import { textoIngreso } from '@/utils/ingreso'
import { partesCopia } from '@/utils/tomo'
import PortadaMaterial from '@/components/libros/PortadaMaterial.vue'
import type { Autor, Carrera, Categoria, Ejemplar, ItemFicha, Libro, Paginado, TipoMaterial, Ubicacion } from '@/types'

const router = useRouter()
const toast = useToast()
const route = useRoute()

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}

const auth = useAuthStore()

const libros = ref<Libro[]>([])
const cargando = ref(true)
const apiError = ref(false)
// El catálogo real son decenas de miles de títulos: se pide de a 50.
const POR_PAGINA = 50
const pagina = ref(1)
const meta = ref<Omit<Paginado<Libro>, 'data'>>({ current_page: 1, last_page: 1, per_page: POR_PAGINA, total: 0, from: null, to: null })

type Orden = 'titulo' | 'autor' | 'clasificacion' | 'materia' | 'tipo_material'

const filtros = reactive({
  q: '',
  estado_proceso: '',
  tipo_material_id: '' as number | '',
  categoria_id: '' as number | '',
  /** Materia escrita: todas las que traen esas palabras (con o sin subdivisiones). */
  materia: '',
  // Carrera asignada al libro al catalogarlo (ej. Pedagogía en Educación Básica).
  carrera_id: '' as number | '',
  // Al pulsar un autor en una fila: sus libros.
  autor_id: '' as number | '',
  orden: 'titulo' as Orden,
  direccion: 'asc' as 'asc' | 'desc',
})

// La categoría se busca en el servidor (son miles con el catálogo real): acá queda la elegida.
const categoriaElegida = ref<Categoria | null>(null)
const autorElegido = ref<Autor | null>(null)
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
      { valor: 'autor', texto: 'autor' },
      { valor: 'clasificacion', texto: 'Dewey' },
      { valor: 'materia', texto: 'materia' },
      { valor: 'tipo_material', texto: 'tipo de recurso' },
    ],
  },
])

function textoFiltro(f: (typeof filtrosPildora.value)[number]) {
  const elegido = f.opciones.find((o) => o.valor === filtros[f.campo])
  if (f.campo === 'orden') return `${f.nombre}: ${elegido?.texto ?? 'título'}`
  return filtros[f.campo] === '' ? f.nombre : `${f.nombre}: ${elegido?.texto ?? ''}`
}

// Al escribir salen varias búsquedas seguidas y las de palabras comunes tardan más: si no se
// cancela la anterior, su respuesta puede llegar última y reemplazar a la más nueva.
let busquedaEnCurso: AbortController | undefined

async function cargar() {
  busquedaEnCurso?.abort()
  const busqueda = new AbortController()
  busquedaEnCurso = busqueda
  cargando.value = true
  try {
    const { data } = await api.get<Paginado<Libro>>('/libros', {
      signal: busqueda.signal,
      params: {
        page: pagina.value,
        por_pagina: POR_PAGINA,
        q: filtros.q.trim() || undefined,
        estado_proceso: filtros.estado_proceso || undefined,
        tipo_material_id: filtros.tipo_material_id || undefined,
        categoria_id: filtros.categoria_id || undefined,
        materia: filtros.materia.trim() || undefined,
        carrera_id: filtros.carrera_id || undefined,
        autor_id: filtros.autor_id || undefined,
        orden: filtros.orden,
        direccion: filtros.direccion,
      },
    })
    const { data: filas, ...resto } = data
    libros.value = filas
    meta.value = resto
    apiError.value = false
  } catch {
    if (busqueda.signal.aborted) return
    apiError.value = true
    libros.value = []
  } finally {
    if (busquedaEnCurso === busqueda) cargando.value = false
  }
}

/** Encabezado de columna: ordena por ella (A→Z); pulsarlo de nuevo invierte (Z→A). */
function ordenarPor(campo: Orden) {
  filtros.direccion = filtros.orden === campo && filtros.direccion === 'asc' ? 'desc' : 'asc'
  filtros.orden = campo
  filtrar()
}

function flechaOrden(campo: Orden) {
  return filtros.orden !== campo ? '↕' : filtros.direccion === 'asc' ? '↑' : '↓'
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

function elegirCategoria(categoria: Categoria | null) {
  categoriaElegida.value = categoria
  filtros.categoria_id = categoria?.id ?? ''
  filtrar()
}

/** Pulsar una materia en una fila: el listado pasa a mostrar los libros de esa materia. */
function filtrarPorMateria(c: Categoria) {
  filtros.categoria_id = c.id
  elegirCategoria(c)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

/** Desde la ficha abierta: un autor, una materia, una carrera o (el profesor guía) un nombre a buscar. */
function filtrarDesdeFicha(item: ItemFicha) {
  if (item.autor_id) return filtrarPorAutor({ id: item.autor_id, nombre: item.texto })
  if (item.categoria_id) return filtrarPorMateria({ id: item.categoria_id, nombre: item.texto })
  if (item.carrera_id) filtros.carrera_id = item.carrera_id
  else if (item.buscar) filtros.q = item.buscar
  filtrar()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

/** Pulsar un autor en una fila: el listado pasa a mostrar sus libros. */
function filtrarPorAutor(a: Autor | null) {
  autorElegido.value = a
  filtros.autor_id = a?.id ?? ''
  filtrar()
  if (a) window.scrollTo({ top: 0, behavior: 'smooth' })
}

let buscarTimer: ReturnType<typeof setTimeout> | undefined
function onBuscarInput() {
  clearTimeout(buscarTimer)
  // 1 o 2 letras recorren todo el catálogo: se busca al seguir escribiendo o con Enter
  if (esBusquedaCorta(filtros.q) || esBusquedaCorta(filtros.materia)) return
  buscarTimer = setTimeout(filtrar, 350)
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

function claseDelTipo(nombre = 'Libro', plantilla?: string) {
  if (plantilla === 'publicacion_seriada') return 'bg-sky-50 text-sky-700'
  if (plantilla === 'trabajo_titulacion') return 'bg-violet-50 text-violet-700'
  if (nombre.toLowerCase().includes('electr')) return 'bg-emerald-50 text-emerald-700'
  return nombre === 'Libro' ? 'bg-indigo-50 text-indigo-700' : 'bg-amber-50 text-amber-800'
}

function filaDe(l: Libro) {
  const copias = l.ejemplares ?? []
  const situaciones = copias.map((e) => situacionCopia(e))
  const plantilla = l.tipo_material?.plantilla
  return {
    autores: l.autores?.map((a) => a.nombre).join(' · ') || (plantilla === 'publicacion_seriada' ? 'Publicación periódica' : 'Sin autor registrado'),
    categoria: l.categorias?.map((c) => c.nombre).join(', ') || '—',
    tipoClase: claseDelTipo(l.tipo_material?.nombre, plantilla),
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

// ---------- Volver desde Editar ficha con la misma búsqueda ----------
// Al ir a editar se guarda dónde estaba (búsqueda, filtros, materia, página y la fila abierta);
// el botón «Volver al listado» de la ficha trae ?volver=1 y se restaura tal cual.
const CLAVE_VOLVER = 'listado-libros:volver'

function guardarDondeEstaba(libroId?: number) {
  try {
    sessionStorage.setItem(CLAVE_VOLVER, JSON.stringify({ filtros: { ...filtros }, categoria: categoriaElegida.value, autor: autorElegido.value, pagina: pagina.value, libroId }))
  } catch {
    // Sin almacenamiento (modo privado): el botón vuelve al listado sin filtros.
  }
}

/** Desde otra pantalla (Materias): ?categoria_id=…&categoria=Nombre abre el listado ya filtrado. */
function filtroDesdeLaDireccion() {
  const id = Number(route.query.categoria_id)
  if (!id) return
  filtros.categoria_id = id
  categoriaElegida.value = { id, nombre: String(route.query.categoria ?? '') } as Categoria
  router.replace({ name: 'listado-libros' })
}

function restaurarDondeEstaba(): number | undefined {
  if (route.query.volver !== '1') return undefined
  try {
    const guardado = JSON.parse(sessionStorage.getItem(CLAVE_VOLVER) ?? 'null')
    if (!guardado) return undefined
    Object.assign(filtros, guardado.filtros)
    categoriaElegida.value = guardado.categoria ?? null
    autorElegido.value = guardado.autor ?? null
    pagina.value = guardado.pagina ?? 1
    if (guardado.libroId) expandidos.value = new Set([guardado.libroId])
    router.replace({ name: 'listado-libros' })
    return guardado.libroId
  } catch {
    return undefined
  }
}

onMounted(async () => {
  filtroDesdeLaDireccion()
  // Abierta en otra pestaña desde el «Editar» de una copia: el listado buscado en ese código
  const codigoCopia = typeof route.query.editar_copia === 'string' ? route.query.editar_copia : ''
  if (codigoCopia) {
    filtros.q = codigoCopia
    router.replace({ name: 'listado-libros' })
    abrirCopiaPorCodigo(codigoCopia)
  }
  const libroVuelto = restaurarDondeEstaba()
  cargarCatalogos()
  await cargar()
  // La fila del libro que se editó queda a la vista.
  if (libroVuelto) nextTick(() => document.getElementById(`libro-${libroVuelto}`)?.scrollIntoView({ block: 'center' }))
  document.addEventListener('click', alHacerClic)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', alHacerClic)
  dejarDeSeguirMenu()
})

const libroAEditar = ref<Libro | null>(null)

/** Catalogación con la ficha cargada: el enlace de «Editar», para abrirlo en otra pestaña o ventana. */
const hrefEditar = (libro: Libro) => router.resolve({ name: 'catalogacion-libros', query: { editar: libro.id } }).href

/**
 * Un clic normal pide confirmación y abre Catalogación aquí. Cmd/Ctrl/Mayús + clic (o la
 * rueda, o «Abrir en otra pestaña» del menú del navegador) la abre en otra pestaña o
 * ventana sin preguntar: elegir abrirla así ya es confirmar.
 */
function clicEditar(evento: MouseEvent, libro: Libro) {
  if (evento.button !== 0 || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) return
  evento.preventDefault()
  libroAEditar.value = libro
}

function irAEditar() {
  const libro = libroAEditar.value
  libroAEditar.value = null
  if (!libro) return
  guardarDondeEstaba(libro.id)
  router.push({ name: 'catalogacion-libros', query: { editar: libro.id, desde: 'listado' } })
}

/** «Agregar copia» como enlace: Catalogación con la obra elegida, para abrirlo en otra pestaña. */
const hrefAgregarCopia = (l: Libro) => router.resolve({ name: 'catalogacion-libros', query: { copia: l.id } }).href

/** Clic normal: Catalogación aquí, recordando dónde estaba. Cmd/Ctrl/Mayús + clic o la rueda: otra pestaña. */
function clicAgregarCopia(evento: MouseEvent, l: Libro) {
  if (evento.button !== 0 || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) {
    menuAbierto.value = null
    return
  }
  evento.preventDefault()
  agregarCopia(l)
}

function agregarCopia(l: Libro) {
  guardarDondeEstaba(l.id)
  router.push({ name: 'catalogacion-libros', query: { copia: l.id, desde: 'listado' } })
}

// ---------- Editar una copia (ubicación, volumen, colección y notas) ----------
const copiaAEditar = ref<{ copia: Ejemplar; libro: Libro } | null>(null)

/** El «Editar» de una copia como enlace: el listado con esa copia y su cuadro de edición abierto. */
const hrefEditarCopia = (e: Ejemplar) => router.resolve({ name: 'listado-libros', query: { editar_copia: e.codigo_barras } }).href

/** Clic normal: el cuadro aquí mismo. Cmd/Ctrl/Mayús + clic o la rueda: el navegador lo abre en otra pestaña. */
function clicEditarCopia(evento: MouseEvent, copia: Ejemplar, libro: Libro) {
  if (evento.button !== 0 || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) return
  evento.preventDefault()
  copiaAEditar.value = { copia, libro }
}

async function abrirCopiaPorCodigo(codigo: string) {
  try {
    const { data } = await api.get<Ejemplar & { libro: Libro }>(`/ejemplares/${encodeURIComponent(codigo)}`)
    copiaAEditar.value = { copia: data, libro: data.libro }
  } catch {
    toast.error(`No se encontró la copia ${codigo}`)
  }
}

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
      <!-- La caja de título con la textura de Salas y Préstamos; debajo, las pestañas y el buscador en la misma tarjeta. -->
      <section class="mb-5 rounded-2xl border border-gray-200 bg-white shadow-md">
        <div class="relative rounded-t-2xl" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
          <FondoEstante con-acciones />
          <div class="relative flex flex-wrap items-center justify-between gap-3 px-6 pt-4">
            <div class="min-w-0">
              <nav class="text-xs text-white/75" aria-label="Ruta">
                <button type="button" class="font-medium text-white hover:underline" @click="abrirMenuGestion">Gestión</button>
                <span class="mx-1.5">›</span>
                <span>Catálogo</span>
              </nav>
              <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Listado de material</h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <button
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-white/30 bg-white/10 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/20"
                @click="exportarAbierto = true"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
                Exportar
              </button>
              <router-link
                v-if="puedeCatalogar"
                :to="{ name: 'catalogacion-libros' }"
                class="inline-flex items-center gap-1.5 rounded-xl bg-white px-4 py-2 text-sm font-semibold text-indigo-700 shadow-sm transition hover:bg-gray-50"
              >
                <span class="text-base leading-none">+</span> Catalogar libro
              </router-link>
            </div>
          </div>
          <PestanasLibros actual="listado-libros" en-banda class="relative mt-3 px-5" />
        </div>

        <div class="flex flex-wrap items-center gap-2 rounded-b-2xl p-3">
        <form class="flex min-w-[16rem] flex-1 gap-2" role="search" @submit.prevent="buscarAhora">
          <div class="relative min-w-0 flex-1">
            <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
            <input
              v-model="filtros.q"
              type="text"
              placeholder="Título, autor, ISBN, Dewey, código o N° de registro"
              aria-label="Buscar libros"
              class="h-10 w-full rounded-xl border border-gray-200 bg-white pl-10 pr-4 text-sm text-gray-900 outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
              @input="onBuscarInput"
            />
          </div>
          <button
            type="submit"
            class="h-10 shrink-0 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700"
          >
            Buscar
          </button>
        </form>
        <template v-for="f in filtrosPildora" :key="f.campo">
          <label
            class="relative inline-flex h-10 items-center rounded-xl border bg-white pl-3.5 pr-9 text-sm transition-colors"
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
          <!-- La materia, después del tipo: se escribe y trae todas las que tienen esas palabras
               («mapuches» → MAPUCHES, MAPUCHES -- HISTORIA…), sin obligar a elegir una sola. -->
          <div v-if="f.campo === 'tipo_material_id'" class="relative">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
            </svg>
            <input
              v-model="filtros.materia"
              type="search"
              placeholder="Materia"
              aria-label="Filtrar por materia (todas las que traen esas palabras)"
              title="Escribe parte de la materia: trae todas las que la contienen, con o sin subdivisiones"
              class="h-10 w-52 rounded-xl border bg-white pl-8 pr-3 text-sm font-medium outline-none transition-colors placeholder:text-gray-700 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
              :class="filtros.materia.trim() ? 'border-indigo-300 text-indigo-800' : 'border-gray-200 text-gray-700'"
              @input="onBuscarInput"
              @keydown.enter.prevent="buscarAhora"
            />
          </div>
        </template>
          <button
            v-if="autorElegido"
            type="button"
            class="inline-flex h-10 items-center gap-2 rounded-xl border border-indigo-300 bg-indigo-50 pl-3.5 pr-2.5 text-sm font-medium text-indigo-800 hover:bg-indigo-100"
            :title="`Quitar el filtro por ${autorElegido.nombre}`"
            @click="filtrarPorAutor(null)"
          >
            Autor: {{ autorElegido.nombre }}
            <span class="text-base leading-none" aria-hidden="true">×</span>
          </button>
          <!-- Una materia exacta (desde la ficha o la pantalla Materias) -->
          <button
            v-if="filtros.categoria_id !== ''"
            type="button"
            class="inline-flex h-10 max-w-full items-center gap-2 rounded-xl border border-indigo-300 bg-indigo-50 pl-3.5 pr-2.5 text-sm font-medium text-indigo-800 hover:bg-indigo-100"
            :title="`Quitar el filtro por ${categoriaElegida?.nombre ?? 'materia'}`"
            @click="elegirCategoria(null)"
          >
            <span class="truncate">Materia: {{ categoriaElegida?.nombre ?? '…' }}</span>
            <span class="text-base leading-none" aria-hidden="true">×</span>
          </button>
        </div>
      </section>

      <ApiErrorBanner v-if="apiError" />

      <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div
          class="hidden border-b border-gray-200 bg-gray-50/80 px-5 py-3 text-[11px] font-bold uppercase tracking-wider text-gray-500 lg:grid lg:grid-cols-[2.25rem_minmax(0,2.3fr)_minmax(0,1.1fr)_minmax(0,1fr)_7.5rem_minmax(0,1.1fr)_8.5rem] lg:gap-4"
        >
          <span></span>
          <span class="flex gap-3">
            <button v-for="c in ([['titulo', 'Título'], ['autor', 'Autor']] as const)" :key="c[0]" type="button" class="inline-flex items-center gap-1 uppercase tracking-wider hover:text-indigo-700" :class="filtros.orden === c[0] ? 'text-indigo-700' : ''" :title="`Ordenar por ${c[1].toLowerCase()}`" @click="ordenarPor(c[0])">
              {{ c[1] }} <span aria-hidden="true">{{ flechaOrden(c[0]) }}</span>
            </button>
          </span>
          <button v-for="c in ([['clasificacion', 'Dewey'], ['materia', 'Materia'], ['tipo_material', 'Tipo']] as const)" :key="c[0]" type="button" class="inline-flex items-center gap-1 justify-self-start uppercase tracking-wider hover:text-indigo-700" :class="filtros.orden === c[0] ? 'text-indigo-700' : ''" :title="`Ordenar por ${c[1].toLowerCase()}`" @click="ordenarPor(c[0])">
            {{ c[1] }} <span aria-hidden="true">{{ flechaOrden(c[0]) }}</span>
          </button>
          <span>Ejemplares</span>
          <span class="text-right">Acciones</span>
        </div>

        <div class="divide-y divide-gray-100">
          <div v-for="f in filas" :id="`libro-${f.libro.id}`" :key="f.libro.id" class="scroll-mt-24 even:bg-gray-50/50">
            <div
              class="grid grid-cols-[2.25rem_minmax(0,1fr)] gap-x-3 gap-y-2.5 px-4 py-3.5 transition-colors lg:grid-cols-[2.25rem_minmax(0,2.3fr)_minmax(0,1.1fr)_minmax(0,1fr)_7.5rem_minmax(0,1.1fr)_8.5rem] lg:items-center lg:gap-4 lg:px-5"
              :class="expandidos.has(f.libro.id) ? 'bg-indigo-50/40' : 'hover:bg-gray-50/70'"
            >
              <button
                type="button"
                class="row-span-3 flex h-8 w-8 items-center justify-center self-start rounded-lg border transition-colors lg:row-span-1 lg:self-center"
                :class="expandidos.has(f.libro.id) ? 'border-indigo-200 bg-indigo-100 text-indigo-700' : 'border-gray-200 bg-white text-gray-500 hover:bg-gray-100 hover:text-gray-700'"
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
                  class="shadow-sm"
                  tamano="sm"
                  :titulo="f.libro.titulo"
                  :tipo="f.libro.tipo_material?.nombre"
                  :plantilla="f.libro.tipo_material?.plantilla"
                  :clave-tono="f.libro.categorias?.[0]?.nombre"
                />
                <div class="min-w-0">
                  <p class="text-sm font-semibold leading-snug text-gray-900">{{ f.libro.titulo }}</p>
                  <p class="truncate text-xs text-gray-500">
                    <template v-if="f.libro.autores?.length">
                      <template v-for="(a, i) in f.libro.autores" :key="a.id">
                        <button type="button" class="hover:text-indigo-700 hover:underline" :title="`Ver los libros de ${a.nombre}`" @click="filtrarPorAutor(a)">{{ a.nombre }}</button><span v-if="i < f.libro.autores.length - 1" class="mx-1.5 font-bold text-gray-300" aria-hidden="true">·</span>
                      </template>
                    </template>
                    <template v-else>{{ f.autores }}</template>
                  </p>
                </div>
              </div>

              <div class="col-start-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 lg:contents">
                <p class="justify-self-start rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 font-mono text-xs text-gray-700">{{ f.libro.clasificacion ?? '—' }}</p>
                <p class="truncate text-xs font-medium text-gray-700" :title="f.categoria">
                  <template v-if="f.libro.categorias?.length">
                    <template v-for="(c, i) in f.libro.categorias" :key="c.id">
                      <button type="button" class="hover:text-indigo-700 hover:underline" :title="`Ver los libros de la materia ${c.nombre}`" @click="filtrarPorMateria(c)">{{ c.nombre }}</button><span v-if="i < f.libro.categorias.length - 1">, </span>
                    </template>
                  </template>
                  <template v-else>—</template>
                </p>
                <span class="inline-flex items-center gap-1.5 justify-self-start whitespace-nowrap rounded-full py-0.5 font-semibold" :class="[f.tipoClase, f.tipoTamano]">
                  <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                  {{ f.libro.tipo_material?.nombre ?? 'Libro' }}
                </span>
              </div>

              <div class="col-start-2 flex items-center justify-between gap-3 lg:contents">
                <div class="min-w-0">
                  <div v-if="f.barras.length" class="mb-1 flex flex-wrap gap-1">
                    <span v-for="(b, i) in f.barras" :key="i" class="h-1.5 w-5 rounded-full" :class="b"></span>
                  </div>
                  <!-- Un libro electrónico no tiene copias: se lee en el enlace de su ficha (856) -->
                  <p v-if="!f.total && f.libro.url_acceso" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.3 3.75-5.3 3.75-9S14.5 5.3 12 3m0 18c-2.5-2.3-3.75-5.3-3.75-9S9.5 5.3 12 3M3.5 9h17m-17 6h17" /></svg>
                    En línea
                  </p>
                  <p v-else class="text-xs text-gray-500">
                    <strong class="text-gray-900">{{ f.disponibles }}</strong> de {{ f.total }} {{ f.total === 1 ? 'disponible' : 'disponibles' }}
                  </p>
                </div>
                <div class="relative flex shrink-0 items-center justify-end gap-2" data-menu-libro>
                  <a
                    v-if="puedeCatalogar"
                    :href="hrefEditar(f.libro)"
                    title="Cmd/Ctrl + clic: abrir en otra pestaña"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 border-b-indigo-200 bg-white px-3 py-1.5 text-sm font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-gray-50"
                    @click="clicEditar($event, f.libro)"
                  >
                    Editar
                  </a>
                  <button
                    v-if="puedeCatalogar || puedeVerHistorial || puedeCambiarEstado"
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 bg-gray-50 text-gray-500 transition-colors hover:bg-gray-200 hover:text-gray-800"
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
                      <a
                        v-if="puedeCatalogar"
                        role="menuitem"
                        :href="hrefEditar(f.libro)"
                        target="_blank"
                        rel="noopener"
                        class="flex items-center justify-between rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                        @click="menuAbierto = null"
                      >
                        Editar en otra pestaña
                        <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                      </a>
                      <a v-if="puedeCatalogar" role="menuitem" :href="hrefAgregarCopia(f.libro)" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50" @click="clicAgregarCopia($event, f.libro)">
                        Agregar copia
                      </a>
                      <a
                        v-if="puedeCatalogar"
                        role="menuitem"
                        :href="hrefAgregarCopia(f.libro)"
                        target="_blank"
                        rel="noopener"
                        class="flex items-center justify-between rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                        @click="menuAbierto = null"
                      >
                        Agregar copia en otra pestaña
                        <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                      </a>
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
              <div class="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] lg:items-start">
                <div class="overflow-hidden rounded-xl border border-indigo-100 bg-white">
                  <p class="flex items-center justify-between gap-2 border-b border-indigo-100 bg-indigo-50/60 px-4 py-2">
                    <span class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-indigo-800"><span class="h-3.5 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>Ficha bibliográfica</span>
                    <span class="rounded-md border border-indigo-200 bg-white px-1.5 py-px font-mono text-[11px] font-semibold text-indigo-700">MARC 21</span>
                  </p>
                  <FichaBibliografica :filas="f.libro.ficha ?? []" con-etiquetas filtrable @filtrar="filtrarDesdeFicha" />
                  <p v-if="textoIngreso(f.libro)" class="border-t border-indigo-100 px-4 py-2.5 text-xs text-gray-500">{{ textoIngreso(f.libro) }}</p>
                </div>
                <div class="overflow-hidden rounded-xl border border-emerald-100 bg-white">
                  <p class="flex items-center justify-between bg-emerald-50 px-4 py-2.5 text-[11px] font-bold uppercase tracking-wider text-emerald-800">
                    Ejemplares <span>{{ f.total }}</span>
                  </p>
                  <ul class="divide-y divide-gray-100">
                    <li v-for="e in f.libro.ejemplares" :key="e.id" class="flex items-center justify-between gap-3 px-4 py-2.5">
                      <div class="min-w-0">
                        <!-- El tomo y la copia en píldoras («Tomo 72», «Copia 2», solo si tiene «c.N»); el número de una revista («Año IV No. 4 (Jul.-Aug., 1985)») como texto -->
                        <p class="flex flex-wrap items-center gap-1.5">
                          <span class="font-mono text-sm font-semibold text-gray-900">{{ e.codigo_barras }}</span>
                          <span
                            v-if="partesCopia(e.volumen, e.etiqueta_copia).tomo"
                            class="rounded-full bg-gradient-to-b from-indigo-500 to-indigo-600 px-2.5 py-0.5 text-xs font-bold text-white shadow-sm ring-1 ring-inset ring-indigo-700/30"
                          >
                            {{ partesCopia(e.volumen, e.etiqueta_copia).tomo }}
                          </span>
                          <span v-if="partesCopia(e.volumen, e.etiqueta_copia).copia" class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200">
                            Copia <span class="font-bold tabular-nums text-emerald-900">{{ partesCopia(e.volumen, e.etiqueta_copia).copia }}</span>
                          </span>
                        </p>
                        <p v-if="partesCopia(e.volumen, e.etiqueta_copia).otro" class="text-sm text-gray-800">{{ partesCopia(e.volumen, e.etiqueta_copia).otro }}</p>
                        <p class="text-xs text-gray-500">
                          {{ e.ubicacion?.nombre ?? 'Sin ubicación' }} · lomo <span class="font-mono">{{ e.codigo_lomo }}</span>
                          <template v-if="e.coleccion || f.libro.coleccion"> · {{ e.coleccion || f.libro.coleccion }}</template>
                        </p>
                        <p v-if="e.numero_registro" class="text-xs text-gray-600">N° de registro Horizon: <span class="font-mono">{{ e.numero_registro }}</span></p>
                        <p v-if="e.nota_publica" class="text-xs text-gray-600">Nota pública: {{ e.nota_publica }}</p>
                        <p v-if="e.nota_interna" class="text-xs text-violet-700">Nota interna: {{ e.nota_interna }}</p>
                        <p v-if="textoIngreso(e)" class="text-xs text-gray-400">{{ textoIngreso(e) }}</p>
                      </div>
                      <div class="flex shrink-0 items-center gap-2">
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="situacionCopia(e).badge">{{ situacionCopia(e).texto }}</span>
                        <a
                          v-if="puedeCatalogar"
                          :href="hrefEditarCopia(e)"
                          title="Cmd/Ctrl + clic: abrir en otra pestaña"
                          class="rounded-lg px-2 py-1 text-xs font-medium text-indigo-700 transition-colors hover:bg-indigo-50"
                          :aria-label="`Editar la copia ${e.codigo_barras}`"
                          @click="clicEditarCopia($event, e, f.libro)"
                        >
                          Editar
                        </a>
                        <!-- Abre Estado de ejemplares con esta copia ya elegida (la reconoce por su código). -->
                        <router-link
                          v-if="puedeCambiarEstado"
                          :to="{ name: 'estado-libro', query: { q: e.codigo_barras } }"
                          class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-indigo-50 hover:text-indigo-700"
                          :title="`Cambiar el estado de la copia ${e.codigo_barras}`"
                          :aria-label="`Cambiar el estado de la copia ${e.codigo_barras}`"
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
                    <a
                      :href="hrefAgregarCopia(f.libro)"
                      title="Cmd/Ctrl + clic: abrir en otra pestaña"
                      class="inline-block rounded-lg border border-dashed border-emerald-300 px-3 py-2 text-xs font-semibold text-emerald-700 transition-colors hover:bg-emerald-50"
                      @click="clicAgregarCopia($event, f.libro)"
                    >
                      + Agregar copia
                    </a>
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
          <!-- Sin esto, mientras llega la primera página la tabla quedaba en blanco y parecía vacía -->
          <p v-if="cargando && !filas.length" class="flex items-center justify-center gap-2 px-6 py-10 text-sm text-gray-500" role="status">
            <svg class="h-4 w-4 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24" aria-hidden="true">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
            </svg>
            Cargando el catálogo…
          </p>
          <p v-else-if="!cargando && !apiError && !filas.length" class="px-6 py-10 text-center text-sm text-gray-400">Sin libros que coincidan con la búsqueda.</p>
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
      :inicial="{ q: filtros.q, estado_proceso: filtros.estado_proceso, tipo_material_id: filtros.tipo_material_id, categoria_id: filtros.categoria_id, carrera_id: filtros.carrera_id, materia: filtros.materia }"
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
      <p class="mt-2 text-xs text-gray-500">Para abrirla en otra pestaña sin esta pregunta: Cmd/Ctrl + clic en «Editar», o «Editar en otra pestaña» en el menú ⋯.</p>
    </DialogoConfirmacion>
  </StaffLayout>
</template>
