<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import SelectorBuscable from '@/components/ui/SelectorBuscable.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { construirCsv, descargarCsv, type SeccionCsv } from '@/utils/csv'
import { descargarExcel } from '@/utils/excel'
import { ESTADOS_EJEMPLAR, nombreEstado, situacionCopia } from '@/utils/estadosEjemplar'
import { fechasDeIngreso } from '@/utils/ingreso'
import type { Carrera, Categoria, Ejemplar, Libro, Paginado, TipoMaterial } from '@/types'

/**
 * Exportación del listado de libros a Excel o CSV, con sus propios filtros (parten de
 * los del listado): estado de la copia, carrera, categoría, tipo de material y
 * disponibilidad. Antes de descargar muestra cuántos títulos y copias saldrán, y se
 * confirma de forma explícita.
 */
const props = defineProps<{
  abierto: boolean
  inicial: { q: string; estado_proceso: string; tipo_material_id: number | ''; categoria_id: number | ''; carrera_id: number | '' }
  /** La categoría de inicial.categoria_id, con su nombre (las categorías se buscan, no hay lista). */
  categoriaInicial: Categoria | null
  carreras: Carrera[]
  tiposMaterial: TipoMaterial[]
}>()
const emit = defineEmits<{ 'update:abierto': [valor: boolean] }>()

const toast = useToast()

type Situacion = '' | 'disponible' | 'prestado' | 'reservado'
const filtros = reactive({
  q: '',
  estado_proceso: '',
  tipo_material_id: '' as number | '',
  categoria_id: '' as number | '',
  carrera_id: '' as number | '',
  situacion: '' as Situacion,
})
const categoriaElegida = ref<Categoria | null>(null)
const contenido = ref<'ambos' | 'titulos' | 'ejemplares'>('ambos')
const formato = ref<'excel' | 'csv'>('excel')

const libros = ref<Libro[]>([])
// Cuántos títulos coinciden con los filtros (antes de traerlos para exportar).
const coinciden = ref(0)
const cargando = ref(false)
const exportando = ref(false)
const progreso = ref('')
const error = ref('')

watch(
  () => props.abierto,
  (abierto) => {
    if (!abierto) return
    Object.assign(filtros, { ...props.inicial, situacion: '' })
    categoriaElegida.value = props.categoriaInicial
    contenido.value = 'ambos'
    formato.value = 'excel'
    cargar()
  },
)

let timer: ReturnType<typeof setTimeout> | undefined
watch(
  () => [filtros.q, filtros.estado_proceso, filtros.tipo_material_id, filtros.categoria_id, filtros.carrera_id],
  () => {
    if (!props.abierto) return
    clearTimeout(timer)
    timer = setTimeout(cargar, 250)
  },
)

const parametros = () => ({
  q: filtros.q.trim() || undefined,
  estado_proceso: filtros.estado_proceso || undefined,
  tipo_material_id: filtros.tipo_material_id || undefined,
  categoria_id: filtros.categoria_id || undefined,
  carrera_id: filtros.carrera_id || undefined,
})

/** Solo cuenta (una página de un título): traer el catálogo entero se hace al confirmar. */
async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await api.get<Paginado<Libro>>('/libros', { params: { ...parametros(), por_pagina: 1 } })
    coinciden.value = data.total
  } catch (e) {
    coinciden.value = 0
    error.value = mensajeDeError(e, 'No se pudo contar los libros a exportar')
  } finally {
    cargando.value = false
  }
}

const POR_PAGINA_EXPORTACION = 500

/** El listado completo, de a 500 títulos por petición, para que el servidor no se quede sin memoria. */
async function traerTodo(): Promise<Libro[]> {
  const todos: Libro[] = []
  let pagina = 1
  let ultima = 1
  do {
    progreso.value = ultima > 1 ? `Trayendo los libros: ${pagina} de ${ultima}…` : 'Trayendo los libros…'
    const { data } = await api.get<Paginado<Libro>>('/libros', { params: { ...parametros(), page: pagina, por_pagina: POR_PAGINA_EXPORTACION } })
    todos.push(...data.data)
    ultima = data.last_page
    pagina++
  } while (pagina <= ultima)
  return todos
}

/** Las copias que cumplen el estado y la disponibilidad elegidos. */
function copiasQueSirven(l: Libro): Ejemplar[] {
  return (l.ejemplares ?? []).filter((e) => {
    if (filtros.estado_proceso && e.estado_proceso !== filtros.estado_proceso) return false
    if (filtros.situacion === 'disponible') return situacionCopia(e).disponible
    if (filtros.situacion === 'prestado') return !!e.prestado
    if (filtros.situacion === 'reservado') return !!e.reservado
    return true
  })
}

const seleccion = computed(() =>
  libros.value
    .map((libro) => ({ libro, copias: copiasQueSirven(libro) }))
    // Con un filtro de copia (disponibilidad), un título sin copias que lo cumplan no sale.
    .filter((x) => !filtros.situacion || x.copias.length),
)
const totalCopias = computed(() => seleccion.value.reduce((n, x) => n + x.copias.length, 0))

const nombreDe = <T extends { id: number; nombre: string }>(lista: T[], id: number | '') => lista.find((x) => x.id === id)?.nombre

async function buscarCategorias(texto: string) {
  const { data } = await api.get<Categoria[]>('/categorias', { params: { q: texto || undefined } })
  return data
}

const SITUACIONES: Record<Exclude<Situacion, ''>, string> = { disponible: 'Disponibles', prestado: 'Prestadas', reservado: 'Reservadas' }

const descripcionFiltros = computed(() => {
  const partes: string[] = []
  if (filtros.q.trim()) partes.push(`búsqueda «${filtros.q.trim()}»`)
  if (filtros.estado_proceso) partes.push(`estado ${ESTADOS_EJEMPLAR[filtros.estado_proceso as keyof typeof ESTADOS_EJEMPLAR]?.label ?? filtros.estado_proceso}`)
  if (filtros.carrera_id) partes.push(`carrera ${nombreDe(props.carreras, filtros.carrera_id)}`)
  if (filtros.categoria_id) partes.push(`categoría ${categoriaElegida.value?.nombre ?? ''}`)
  if (filtros.tipo_material_id) partes.push(`tipo ${nombreDe(props.tiposMaterial, filtros.tipo_material_id)}`)
  if (filtros.situacion) partes.push(`copias ${SITUACIONES[filtros.situacion].toLowerCase()}`)
  return partes.length ? partes.join(', ') : 'sin filtros (todo el catálogo)'
})

const lista = (xs?: { nombre: string }[]) => (xs ?? []).map((x) => x.nombre).join('; ')
const FORMAS: Record<string, string> = { compra: 'Compra', donacion: 'Donación', canje: 'Canje' }

function secciones(): SeccionCsv[] {
  const resultado: SeccionCsv[] = []
  const hoy = new Date().toLocaleDateString('es-CL')

  if (contenido.value !== 'ejemplares') {
    resultado.push({
      titulo: 'Títulos',
      columnas: [
        'Título', 'Autor(es)', 'ISBN', 'ISSN', 'Clasificación (Dewey)', 'Editorial', 'Año', 'Edición', 'Lugar de publicación',
        'Tipo de material', 'Categorías', 'Carreras', 'Colección', 'Idioma', 'Copias', 'Disponibles', 'Prestadas', 'Reservadas', 'Ubicaciones', 'Catalogado por',
        'Ingreso a Horizon', 'Ingreso a BiblioUMAG',
      ],
      filas: seleccion.value.map(({ libro: l, copias }) => [
        l.titulo,
        lista(l.autores),
        l.isbn ?? '',
        l.issn ?? '',
        l.clasificacion ?? '',
        l.editorial ?? '',
        l.anio_publicacion ?? '',
        l.edicion ?? '',
        l.lugar_publicacion ?? '',
        l.tipo_material?.nombre ?? '',
        lista(l.categorias),
        lista(l.carreras),
        l.coleccion ?? '',
        l.idioma ?? '',
        copias.length,
        copias.filter((e) => situacionCopia(e).disponible).length,
        copias.filter((e) => e.prestado).length,
        copias.filter((e) => e.reservado).length,
        [...new Set(copias.map((e) => e.ubicacion?.nombre).filter(Boolean))].join('; '),
        l.catalogado_por?.nombre ?? '',
        fechasDeIngreso(l).horizon,
        fechasDeIngreso(l).biblioumag,
      ]),
    })
  }

  if (contenido.value !== 'titulos') {
    resultado.push({
      titulo: 'Ejemplares',
      columnas: [
        'Código de barras', 'Código de lomo', 'Título', 'Copia', 'Volumen', 'Estado', 'Situación', 'Ubicación', 'Forma de adquisición', 'Precio',
        'Fecha de inventario', 'Ingreso a Horizon', 'Ingreso a BiblioUMAG', 'Tipo de material', 'Clasificación (Dewey)', 'Carreras', 'Categorías',
      ],
      filas: seleccion.value.flatMap(({ libro: l, copias }) =>
        copias.map((e) => [
          e.codigo_barras,
          e.codigo_lomo,
          l.titulo,
          e.numero_copia,
          e.volumen ?? '',
          nombreEstado(e),
          situacionCopia(e).texto,
          e.ubicacion?.nombre ?? '',
          e.forma_adquisicion ? FORMAS[e.forma_adquisicion] : '',
          e.precio ? Number(e.precio) : '',
          e.fecha_inventario ? new Date(e.fecha_inventario).toLocaleDateString('es-CL') : '',
          fechasDeIngreso(e).horizon,
          fechasDeIngreso(e).biblioumag,
          l.tipo_material?.nombre ?? '',
          l.clasificacion ?? '',
          lista(l.carreras),
          lista(l.categorias),
        ]),
      ),
    })
  }

  resultado.push({
    titulo: 'Filtros',
    columnas: ['Campo', 'Valor'],
    filas: [
      ['Exportado el', hoy],
      ['Filtros', descripcionFiltros.value],
      ['Títulos', seleccion.value.length],
      ['Ejemplares', totalCopias.value],
    ],
  })
  return resultado
}

async function exportar() {
  if (!coinciden.value) {
    toast.error('No hay libros que exportar con esos filtros')
    return
  }
  exportando.value = true
  const nombre = `listado-libros-${new Date().toLocaleDateString('sv-SE')}`
  try {
    libros.value = await traerTodo()
    if (!seleccion.value.length) {
      toast.error('Ningún libro tiene copias con esa disponibilidad')
      return
    }
    progreso.value = formato.value === 'excel' ? 'Armando el Excel…' : 'Armando el CSV…'
    if (formato.value === 'excel') await descargarExcel(`${nombre}.xlsx`, secciones())
    else descargarCsv(`${nombre}.csv`, construirCsv(secciones()))
    toast.success(`Listado exportado: ${seleccion.value.length.toLocaleString('es-CL')} títulos y ${totalCopias.value.toLocaleString('es-CL')} ejemplares`)
    emit('update:abierto', false)
  } catch (e) {
    toast.error(mensajeDeError(e, formato.value === 'excel' ? 'No se pudo generar el archivo Excel' : 'No se pudo generar el archivo CSV'))
  } finally {
    exportando.value = false
    progreso.value = ''
    libros.value = []
  }
}

const claseSelect = 'w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm'
</script>

<template>
  <Modal
    :abierto="abierto"
    titulo="Exportar listado de libros"
    descripcion="Elige qué libros salen en el archivo. Parte de los filtros del listado; puedes cambiarlos acá sin tocar la pantalla."
    ancho="lg"
    pie-fijo
    :bloqueado="exportando"
    @cerrar="emit('update:abierto', false)"
  >
    <div class="space-y-4 text-sm">
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label for="exp-q" class="mb-1 block text-xs font-medium text-gray-600">Búsqueda (título, autor, ISBN, Dewey o código)</label>
          <input id="exp-q" v-model="filtros.q" type="search" placeholder="Sin búsqueda: todo el catálogo" :class="claseSelect" />
        </div>
        <div>
          <label for="exp-estado" class="mb-1 block text-xs font-medium text-gray-600">Estado de la copia</label>
          <select id="exp-estado" v-model="filtros.estado_proceso" :class="claseSelect">
            <option value="">Todos los estados</option>
            <option v-for="(e, clave) in ESTADOS_EJEMPLAR" :key="clave" :value="clave">{{ e.label }}</option>
          </select>
        </div>
        <div>
          <label for="exp-situacion" class="mb-1 block text-xs font-medium text-gray-600">Disponibilidad</label>
          <select id="exp-situacion" v-model="filtros.situacion" :class="claseSelect">
            <option value="">Todas las copias</option>
            <option v-for="(texto, clave) in SITUACIONES" :key="clave" :value="clave">Solo {{ texto.toLowerCase() }}</option>
          </select>
        </div>
        <div>
          <label for="exp-carrera" class="mb-1 block text-xs font-medium text-gray-600">Carrera</label>
          <select id="exp-carrera" v-model="filtros.carrera_id" :class="claseSelect">
            <option value="">Todas las carreras</option>
            <option v-for="c in carreras" :key="c.id" :value="c.id">{{ c.nombre }}</option>
          </select>
        </div>
        <div>
          <label for="exp-categoria" class="mb-1 block text-xs font-medium text-gray-600">Categoría</label>
          <SelectorBuscable
            v-model="filtros.categoria_id"
            id-campo="exp-categoria"
            :buscar="buscarCategorias"
            :nombre-elegido="categoriaElegida?.nombre"
            todas="Todas las categorías"
            placeholder="Todas las categorías"
            :clase-campo="`${claseSelect} placeholder:text-gray-900`"
            @elegir="categoriaElegida = $event"
          />
        </div>
        <div>
          <label for="exp-tipo" class="mb-1 block text-xs font-medium text-gray-600">Tipo de material</label>
          <select id="exp-tipo" v-model="filtros.tipo_material_id" :class="claseSelect">
            <option value="">Todos los tipos</option>
            <option v-for="t in tiposMaterial" :key="t.id" :value="t.id">{{ t.nombre }}</option>
          </select>
        </div>
        <div>
          <label for="exp-contenido" class="mb-1 block text-xs font-medium text-gray-600">Contenido</label>
          <select id="exp-contenido" v-model="contenido" :class="claseSelect">
            <option value="ambos">Títulos y ejemplares</option>
            <option value="titulos">Solo títulos (una fila por libro)</option>
            <option value="ejemplares">Solo ejemplares (una fila por copia)</option>
          </select>
        </div>
      </div>

      <div>
        <p class="mb-1 text-xs font-medium text-gray-600">Formato</p>
        <div class="grid grid-cols-2 gap-1 rounded-lg bg-gray-100 p-1 sm:w-72" role="radiogroup" aria-label="Formato">
          <button
            v-for="f in (['excel', 'csv'] as const)"
            :key="f"
            type="button"
            role="radio"
            :aria-checked="formato === f"
            class="rounded-md px-2 py-1.5 text-xs font-semibold transition-colors"
            :class="formato === f ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
            @click="formato = f"
          >
            {{ f === 'excel' ? 'Excel (.xlsx)' : 'CSV' }}
          </button>
        </div>
      </div>

      <div class="rounded-lg bg-gray-50 px-4 py-3 text-gray-700" aria-live="polite">
        <p v-if="exportando">{{ progreso }}</p>
        <p v-else-if="cargando">Contando los libros…</p>
        <p v-else-if="error" class="text-red-700">{{ error }}</p>
        <template v-else>
          <p>
            Coinciden <strong class="text-gray-900">{{ coinciden.toLocaleString('es-CL') }} {{ coinciden === 1 ? 'título' : 'títulos' }}</strong>; se
            exportarán en {{ formato === 'excel' ? 'Excel' : 'CSV' }} con sus ejemplares<template v-if="filtros.situacion">
            (solo los que tengan copias {{ SITUACIONES[filtros.situacion].toLowerCase() }})</template>.
          </p>
          <p v-if="coinciden > 5000" class="mt-1 text-xs text-amber-700">Son muchos: armar el archivo puede tardar un minuto.</p>
          <p class="mt-1 text-xs text-gray-500">Filtros: {{ descripcionFiltros }}.</p>
        </template>
      </div>
    </div>

    <template #pie>
      <button
        type="button"
        class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
        :disabled="exportando"
        @click="emit('update:abierto', false)"
      >
        Cancelar
      </button>
      <button
        type="button"
        class="flex-1 px-4 py-2.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors font-medium text-sm disabled:opacity-60"
        :disabled="exportando || cargando || !coinciden"
        @click="exportar"
      >
        {{ exportando ? 'Generando…' : 'Confirmar y exportar' }}
      </button>
    </template>
  </Modal>
</template>
