<script setup lang="ts">
import { nombreDeCopia, numeroDeCopia } from '@/utils/tomo'
import { computed, h, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch, type FunctionalComponent } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import DialogoConfirmacionEscrita from '@/components/ui/DialogoConfirmacionEscrita.vue'
import Paginador from '@/components/ui/Paginador.vue'
import MultiSelectCombobox from '@/components/libros/MultiSelectCombobox.vue'
import PestanasLibros from '@/components/libros/PestanasLibros.vue'
import FondoEstante from '@/components/libros/FondoEstante.vue'
import SeccionFicha from '@/components/libros/SeccionFicha.vue'
import PasosCatalogacion from '@/components/libros/PasosCatalogacion.vue'
import { normalizarMateria } from '@/utils/materia'
import { TONOS_FICHA, type TonoFicha } from '@/components/libros/tonosFicha'
import api from '@/services/api'
import { esBusquedaCorta } from '@/utils/busqueda'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import PortadaMaterial from '@/components/libros/PortadaMaterial.vue'
import PrestamoInterbibliotecario from '@/components/libros/PrestamoInterbibliotecario.vue'
import type { Autor, CarreraSugerida, Categoria, Ejemplar, Libro, Paginado, PlantillaFicha, TipoMaterial, Ubicacion } from '@/types'

const toast = useToast()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const libros = ref<Libro[]>([])
// Títulos catalogados: de a 20 (el catálogo completo son miles).
const paginaLibros = ref(1)
const metaLibros = ref<Omit<Paginado<Libro>, 'data'>>({ current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null })
const cargando = ref(true)
const apiError = ref(false)
const busqueda = ref('')

// Autores y materias son miles con el catálogo real: los combobox los buscan en el
// servidor a medida que se escribe, en vez de traer la lista entera.
async function buscarAutores(texto: string) {
  const { data } = await api.get<Autor[]>('/autores', { params: { q: texto || undefined, con_libros: 1 } })
  return data
}
async function buscarMaterias(texto: string) {
  const { data } = await api.get<Categoria[]>('/categorias', { params: { q: texto || undefined, con_libros: 1 } })
  return data
}
// Las del catálogo y las que tienen los usuarios: una nueva se escribe y se agrega con Enter.
const carrerasOpciones = ref<CarreraSugerida[]>([])
const ubicacionesOpciones = ref<Ubicacion[]>([])
const tiposMaterialOpciones = ref<TipoMaterial[]>([])

// Libro nuevo, copia de uno ya catalogado o material que presta otra biblioteca.
const modo = ref<'nuevo' | 'copia' | 'interbibliotecario'>('nuevo')

const editandoId = ref<number | null>(null)
/** Se llegó desde el Listado de material (Editar o Agregar copia): se ofrece volver con la misma búsqueda. */
const vieneDelListado = route.query.desde === 'listado'
const libroEditado = ref<Libro | null>(null)
const guardando = ref(false)

// Campos de la ficha que se escriben tal cual (texto libre); el resto tiene su
// propio control en el template (autores, materias, pie de imprenta...).
const CAMPOS_TEXTO = [
  'isbn', 'otros_isbn', 'issn', 'clasificacion', 'titulo_alternativo', 'mencion_responsabilidad', 'edicion', 'lugar_publicacion',
  'descripcion_fisica', 'entidad_corporativa', 'reunion', 'entidad_secundaria',
  'materias_personales', 'materias_geograficas', 'url_acceso', 'otros_enlaces', 'designacion_volumen',
  'nota_tesis', 'profesor_guia', 'resumen', 'audiencia', 'idioma', 'serie',
  'nota_interna', 'nota_publica', 'nota_bibliografia', 'frecuencia', 'nota_idioma', 'nota_local', 'contenido',
] as const
type CampoTexto = (typeof CAMPOS_TEXTO)[number]

// Cómo llegó la copia (Ejemplar::FORMAS_ADQUISICION). El detalle va en la nota local 590.
const FORMAS_ADQUISICION = [
  { valor: 'compra', nombre: 'Compra' },
  { valor: 'donacion', nombre: 'Donación' },
  { valor: 'canje', nombre: 'Canje' },
]

const formVacio = {
  codigo_barras: '',
  titulo: '',
  autores: [] as string[],
  /** Papel de cada autor (700 $e) por su nombre; sin entrada = autor. */
  rolesAutores: {} as Record<string, string>,
  categorias: [] as string[],
  carreras: [] as string[],
  coleccion: '',
  editorial: '',
  anio_publicacion: '' as number | '',
  ubicacion_id: '' as number | '',
  tipo_material_id: '' as number | '',
  volumen: '',
  precio: '' as number | '',
  forma_adquisicion: '',
  // Notas de la primera copia (las del libro son nota_publica y nota_interna).
  copia_nota_publica: '',
  copia_nota_interna: '',
  ...(Object.fromEntries(CAMPOS_TEXTO.map((c) => [c, ''])) as Record<CampoTexto, string>),
}
const form = reactive({ ...formVacio, rolesAutores: {} as Record<string, string> })

// Papeles que puede tener una persona en la obra (los mismos de App\Support\RolesAutor).
// Papeles de cada persona en la obra (700 $e): se administran en Administración → Catálogos.
const rolesAutor = ref<{ clave: string; nombre: string }[]>([])
const ROLES_AUTOR = computed(() => [{ valor: '', texto: 'Autor' }, ...rolesAutor.value.map((r) => ({ valor: r.clave, texto: r.nombre }))])
const sinTildesMin = (t: string) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim()
/** El nombre del papel para mostrar en la caja (vacío = Autor; uno desactivado se muestra igual). */
const nombreRol = (clave?: string) => (!clave ? '' : ROLES_AUTOR.value.find((r) => r.valor === clave)?.texto ?? `${clave} (desactivado)`)
/**
 * Lo escrito en la caja del papel: el que coincide (sin tildes ni mayúsculas) o, si es el
 * comienzo de uno solo («patroc»), ese. Vacío o «Autor» = autor. Si no calza, vuelve al anterior.
 */
function elegirRol(nombre: string, caja: HTMLInputElement) {
  const texto = sinTildesMin(caja.value)
  if (!texto || texto === 'autor') {
    delete form.rolesAutores[nombre]
    caja.value = ''
    return
  }
  const exacto = ROLES_AUTOR.value.find((r) => sinTildesMin(r.texto) === texto)
  const comienzan = ROLES_AUTOR.value.filter((r) => r.valor && sinTildesMin(r.texto).includes(texto))
  const elegido = exacto ?? (comienzan.length === 1 ? comienzan[0] : null)
  if (elegido?.valor) form.rolesAutores[nombre] = elegido.valor
  else if (elegido) delete form.rolesAutores[nombre]
  caja.value = nombreRol(form.rolesAutores[nombre])
  if (!elegido) toast.error(comienzan.length ? `«${caja.value || texto}»: hay varios papeles con eso, elija uno de la lista` : 'Ese papel no existe: créelo en Administración → Catálogos')
}

const plantillas = ref<PlantillaFicha[]>([])
const catalogadorEditado = ref<string | null>(null)

const tipoActual = computed(() => tiposMaterialOpciones.value.find((t) => t.id === form.tipo_material_id) ?? null)
/** Qué ficha lleva el tipo de material elegido (sin tipo, la de libro). */
const plantillaActual = computed(() => plantillas.value.find((p) => p.clave === (tipoActual.value?.plantilla ?? 'libro')))
const camposPlantilla = computed(() => new Map((plantillaActual.value?.campos ?? []).map((c) => [c.clave, c])))
const tiene = (clave: string) => camposPlantilla.value.has(clave)
const etiqueta = (clave: string) => camposPlantilla.value.get(clave)?.etiqueta ?? ''

const PISTAS: Partial<Record<CampoTexto, string>> = {
  titulo_alternativo: 'Otro título con que se conoce la obra',
  mencion_responsabilidad: 'Como en la portada. Ej: Margot C. Howe, Shara L. Schwartzberg',
  entidad_corporativa: 'Institución autora',
  reunion: 'Congreso, jornada o seminario',
  entidad_secundaria: 'Ej: Universidad de Magallanes. Facultad de Ingeniería',
  materias_personales: 'Una por línea',
  materias_geograficas: 'Una por línea. Ej: Magallanes (Chile)',
  url_acceso: 'https://...',
  otros_enlaces: 'Uno por línea: la dirección y, si quiere, un texto. Ej: https://... Versión digital, v.2',
  designacion_volumen: 'Ej: Vol. 12, n.º 3 (2024)',
  nota_tesis: 'Ej: Tesis (Ingeniero Civil en Computación e Informática) -- Universidad de Magallanes, 2023',
  resumen: 'De qué trata y cómo se usa',
  contenido: 'Ej: Cap. 1. Introducción -- Cap. 2. Métodos -- Cap. 3. Resultados',
  audiencia: 'Ej: Niños de 8 a 12 años',
  frecuencia: 'Ej: Semestral',
  nota_local: 'Donación, canje o adquisición (de quién y cuándo)',
  nota_publica: 'Ej: Edición ilustrada · se ve en el portal',
}

const NOMBRES: Partial<Record<CampoTexto, string>> = {
  titulo_alternativo: 'Título alternativo',
  mencion_responsabilidad: 'Mención de responsabilidad',
  entidad_corporativa: 'Entidad corporativa',
  reunion: 'Reunión o congreso',
  entidad_secundaria: 'Entidad relacionada',
  materias_personales: 'Materia de persona',
  materias_geograficas: 'Materia de lugar',
  url_acceso: 'Enlace (acceso electrónico)',
  otros_enlaces: 'Otros enlaces (uno por línea)',
  nota_interna: 'Notas generales (nota interna, solo la ve el personal)',
  nota_publica: 'Nota pública (la ve el usuario en el portal)',
  nota_bibliografia: 'Nota de bibliografía (interna)',
  nota_idioma: 'Nota de idioma (interna)',
  nota_local: 'Nota local (interna)',
}

// Campos opcionales que quedan tras un botón «+ …» para no alargar el formulario.
// La entidad (110) no va aquí: está siempre a la vista junto a los autores, porque la autoría es una u otra.
const EXTRAS_TITULO = ['titulo_alternativo', 'mencion_responsabilidad', 'reunion', 'entidad_secundaria'] as const
const EXTRAS_MATERIAS = ['materias_personales', 'materias_geograficas', 'url_acceso', 'otros_enlaces'] as const
const NOTAS_INTERNAS = ['nota_interna', 'nota_bibliografia', 'nota_idioma', 'nota_local'] as const
const EN_EL_BOTON: Partial<Record<CampoTexto, string>> = {
  titulo_alternativo: 'título alternativo',
  mencion_responsabilidad: 'mención de responsabilidad',
  entidad_corporativa: 'entidad',
  reunion: 'congreso',
  entidad_secundaria: 'entidad relacionada',
  materias_personales: 'materia de persona',
  materias_geograficas: 'lugar',
  url_acceso: 'enlace',
  otros_enlaces: 'otros enlaces',
}

const extrasTitulo = computed(() => EXTRAS_TITULO.filter((c) => tiene(c)))
const extrasMaterias = computed(() => EXTRAS_MATERIAS.filter((c) => tiene(c)))
const notaPublica = computed(() => tiene('nota_publica'))
const notasInternas = computed(() => NOTAS_INTERNAS.filter((c) => tiene(c)))
const verMasTitulo = ref(false)

/** 246 es repetible: los títulos alternativos se guardan uno por línea. */
const titulosAlternativos = ref<string[]>([''])
function cargarTitulosAlternativos() {
  const lista = (form.titulo_alternativo ?? '').split(/\r?\n/).map((t) => t.trim()).filter(Boolean)
  titulosAlternativos.value = lista.length ? lista : ['']
}
function guardarTitulosAlternativos() {
  form.titulo_alternativo = titulosAlternativos.value.map((t) => t.trim()).filter(Boolean).join('\n')
}
function agregarTituloAlternativo() {
  titulosAlternativos.value.push('')
  nextTick(() => document.getElementById(`cat-titulo-alt-${titulosAlternativos.value.length - 1}`)?.focus())
}
function quitarTituloAlternativo(i: number) {
  titulosAlternativos.value.splice(i, 1)
  if (!titulosAlternativos.value.length) titulosAlternativos.value.push('')
  guardarTitulosAlternativos()
}
const verMasMaterias = ref(false)

/** «Título alternativo, entidad o congreso (246 · 110 · 111)». */
function textoExtras(claves: readonly CampoTexto[]) {
  const nombres = claves.map((c) => EN_EL_BOTON[c] ?? c)
  const lista = nombres.length > 1 ? `${nombres.slice(0, -1).join(', ')} o ${nombres[nombres.length - 1]}` : nombres[0]
  return `${lista.charAt(0).toUpperCase()}${lista.slice(1)} (${claves.map((c) => etiqueta(c)).join(' · ')})`
}

function abrirExtrasConDatos() {
  verMasTitulo.value = EXTRAS_TITULO.some((c) => form[c].trim())
  cargarTitulosAlternativos()
  verMasMaterias.value = EXTRAS_MATERIAS.some((c) => form[c].trim())
}

/** Etiqueta MARC del campo, con el color de su sección. */
const Marc: FunctionalComponent<{ codigo: string; tono: TonoFicha }> = (props) =>
  h('span', { class: ['rounded px-1.5 py-0.5 font-mono text-[10px] font-semibold ring-1 ring-inset', TONOS_FICHA[props.tono].etiqueta] }, props.codigo)

const CAMPO = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none transition-shadow placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30'
const ETIQUETA = 'mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-gray-700'

// ---------- Secciones e índice ----------
type Seccion = { clave: string; titulo: string; detalle: string; tono: TonoFicha }

const seccionesNuevo = computed<Seccion[]>(() => {
  const lista: Seccion[] = [
    { clave: 'identificacion', titulo: 'Identificación', detalle: 'Tipo de material y códigos', tono: 'indigo' },
    { clave: 'titulo', titulo: 'Título y autoría', detalle: 'Lo que el usuario ve en el catálogo', tono: 'dorado' },
    {
      clave: 'publicacion',
      titulo: 'Publicación',
      detalle: etiqueta('pie_imprenta') === '264' ? 'Producción y descripción física' : 'Pie de imprenta y descripción física',
      tono: 'teal',
    },
  ]
  if (tiene('materias')) lista.push({ clave: 'contenido', titulo: 'Materias', detalle: 'Ayudan a encontrar el libro por tema', tono: 'pizarra' })
  else if (tiene('resumen') || tiene('audiencia')) lista.push({ clave: 'contenido', titulo: 'Contenido', detalle: 'De qué trata y para quién es', tono: 'pizarra' })
  lista.push({
    clave: 'ejemplar',
    titulo: 'Colección y notas',
    detalle: 'Datos internos del título · las copias se agregan en «Nuevo ejemplar»',
    tono: 'violeta',
  })
  return lista
})
const SECCIONES_COPIA: Seccion[] = [
  { clave: 'obra', titulo: 'Obra', detalle: 'Busca el título ya catalogado', tono: 'indigo' },
  { clave: 'copia', titulo: 'Ejemplar nuevo', detalle: 'Datos de la copia física · solo uso interno', tono: 'violeta' },
]
const secciones = computed(() => (modo.value === 'copia' ? SECCIONES_COPIA : seccionesNuevo.value))
const numeroDe = (clave: string) => secciones.value.findIndex((s) => s.clave === clave) + 1
const seccionDe = (clave: string) => secciones.value.find((s) => s.clave === clave)

// La sección en pantalla se marca en el índice; las anteriores, con ✓ o con «!» si les falta algo obligatorio.
const seccionActual = ref('identificacion')
const numeroSeccionActual = computed(() => Math.max(0, secciones.value.findIndex((s) => s.clave === seccionActual.value)) + 1)
let cuadro = 0
function medirSeccionActual() {
  cuadro = 0
  let actual = secciones.value[0]?.clave ?? ''
  for (const s of secciones.value) {
    const el = document.getElementById(`seccion-${s.clave}`)
    if (el && el.getBoundingClientRect().top <= 160) actual = s.clave
  }
  seccionActual.value = actual
}
function alDesplazar() {
  if (!cuadro) cuadro = requestAnimationFrame(medirSeccionActual)
}
watch(secciones, () => nextTick(medirSeccionActual))

function irA(clave: string) {
  document.getElementById(`seccion-${clave}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// ---------- Obligatorios ----------
type Requisito = { texto: string; listo: boolean; seccion: string }

// Una ficha nueva no tiene campos obligatorios: sin título queda «Sin título» (lo pone
// el servidor) y el autor principal (100) o la entidad (110) se pueden dejar vacíos.
const obligatoriosNuevo = computed<Requisito[]>(() => [])

const libroParaCopia = ref<Libro | null>(null)
const busquedaCopia = ref('')
const resultadosCopia = ref<Libro[]>([])
const copiaVacia = () => ({ codigo_barras: '', ubicacion_id: '' as number | '', volumen: '', tomo: '', precio: '' as number | '', forma_adquisicion: '', coleccion: '', nota_publica: '', nota_interna: '' })
const formCopia = reactive(copiaVacia())

// ---------- Tomo de la copia (obras en varios tomos, como en Horizon: «t.1 c.2») ----------
/** «Tomo 1», «T1», «t. 1» → «t.1»; «Vol 2» → «v.2» (lo mismo que App\Support\TomoEjemplar). */
function normalizarTomo(texto: string) {
  const t = texto.replace(/\s+/g, ' ').trim()
  const m = t.match(/^(t|tomo|v|vol|volumen)\.?\s*(\d+[a-z]?)$/i)
  return m ? `${m[1].charAt(0).toLowerCase()}.${m[2].toLowerCase()}` : t
}
/** Los tomos que ya tiene la obra elegida, con cuántas copias, para elegir uno o escribir otro. */
const tomosDeLaObra = computed(() => {
  const cuenta = new Map<string, number>()
  for (const e of libroParaCopia.value?.ejemplares ?? []) if (e.tomo) cuenta.set(e.tomo, (cuenta.get(e.tomo) ?? 0) + 1)
  return [...cuenta.entries()].map(([tomo, copias]) => ({ tomo, copias })).sort((a, b) => a.tomo.localeCompare(b.tomo, 'es', { numeric: true }))
})
/**
 * La «c.N» que va a tener dentro del tomo (la calcula también el servidor al guardar), como en
 * Horizon: la primera del tomo va sin «c.» (0 = «t.4» sola), la segunda es la c.1, la tercera la c.2…
 */
const copiaDelTomo = computed(() => {
  const tomo = normalizarTomo(formCopia.tomo)
  if (!tomo) return null
  const delTomo = (libroParaCopia.value?.ejemplares ?? []).filter((e) => e.tomo === tomo)
  if (!delTomo.length) return 0
  return Math.max(0, ...delTomo.map((e) => e.copia_tomo ?? 0)) + 1
})

const obligatoriosCopia = computed<Requisito[]>(() => [
  { texto: 'Obra elegida', listo: !!libroParaCopia.value, seccion: 'obra' },
  { texto: 'Código de barras', listo: !!formCopia.codigo_barras.trim(), seccion: 'copia' },
])
const obligatorios = computed(() => (modo.value === 'copia' ? obligatoriosCopia.value : obligatoriosNuevo.value))

const pasos = computed(() => {
  const iActual = secciones.value.findIndex((s) => s.clave === seccionActual.value)
  return secciones.value.map((s, i) => {
    const falta = obligatorios.value.some((o) => o.seccion === s.clave && !o.listo)
    const estado = i === iActual ? 'actual' : i < iActual ? (falta ? 'pendiente' : 'lista') : 'siguiente'
    return { ...s, numero: i + 1, estado }
  })
})

function claseNumeroPaso(estado: string, tono: TonoFicha) {
  if (estado === 'actual') return 'bg-[#b08d57] text-white'
  if (estado === 'lista') return 'bg-emerald-600 text-white'
  if (estado === 'pendiente') return 'bg-amber-500 text-white'
  return TONOS_FICHA[tono].paso
}

// ---------- Vista previa del portal ----------
// El portal solo muestra títulos con al menos una copia «En estante»; las copias nacen en «Inventario».
type EstadoVista = { texto: string; detalle: string; punto: string; color: string; nota: string }

function estadoDeCopias(copias: Ejemplar[], nota: string): EstadoVista {
  const enEstante = copias.filter((e) => e.estado_proceso === 'en_estante')
  const disponibles = enEstante.filter((e) => e.disponible).length
  if (!enEstante.length) {
    return { texto: 'Aún no aparece en el portal', detalle: 'Ninguna copia está «En estante»', punto: 'bg-gray-400', color: 'text-gray-600', nota }
  }
  if (disponibles) {
    return { texto: 'Disponible', detalle: `${disponibles} ${disponibles === 1 ? 'ejemplar' : 'ejemplares'} en estantería`, punto: 'bg-emerald-500', color: 'text-emerald-700', nota }
  }
  return { texto: 'No disponible', detalle: 'Las copias en estante están prestadas o apartadas', punto: 'bg-amber-500', color: 'text-amber-700', nota }
}

function armarVista(datos: { titulo: string; autores: string[]; categorias: string[]; tipo: string | null }, estado: EstadoVista) {
  const titulo = datos.titulo.trim()
  return {
    titulo: titulo || 'Título de la obra',
    vacio: !titulo,
    autores: datos.autores.join(' · '),
    detalle: [datos.tipo, datos.categorias.join(', ')].filter(Boolean).join(' · '),
    tipo: datos.tipo,
    claveTono: datos.categorias[0] ?? datos.tipo ?? (titulo || 'libro'),
    estado,
  }
}

const vista = computed(() => {
  if (modo.value === 'copia') {
    const libro = libroParaCopia.value
    if (!libro) return null
    return armarVista(
      {
        titulo: libro.titulo,
        autores: (libro.autores ?? []).map((a) => a.nombre),
        categorias: (libro.categorias ?? []).map((c) => c.nombre),
        tipo: libro.tipo_material?.nombre ?? null,
      },
      estadoDeCopias(libro.ejemplares ?? [], 'La copia nueva nace en «Inventario»: se suma al portal cuando pase a «En estante».'),
    )
  }
  const estado = editandoId.value
    ? estadoDeCopias(libroEditado.value?.ejemplares ?? [], '')
    : {
        texto: 'Sin ejemplares',
        detalle: 'Ficha sin copias todavía',
        punto: 'bg-gray-400',
        color: 'text-gray-600',
        nota: 'Una ficha sin ejemplares no se ve en el portal: aparece cuando una copia pase a «En estante».',
      }
  return armarVista({ titulo: form.titulo, autores: form.autores, categorias: form.categorias, tipo: tipoActual.value?.nombre ?? null }, estado)
})

const nombreTipo = computed(() => {
  const nombre = tipoActual.value?.nombre ?? 'Libro'
  // «Libro» → «libro», pero una sigla como «DVD» queda igual.
  return nombre.length > 1 && nombre.charAt(1) === nombre.charAt(1).toLowerCase() ? nombre.charAt(0).toLowerCase() + nombre.slice(1) : nombre
})

const textoAccion = computed(() => {
  if (modo.value === 'copia') return 'Agregar copia'
  return editandoId.value ? 'Guardar cambios' : 'Crear ficha'
})

const notaCatalogador = computed(() => {
  if (modo.value === 'copia') return 'La copia toma el número siguiente y nace en «Inventario».'
  const marca = etiqueta('catalogador') || '910'
  if (editandoId.value) return `Catalogado por ${catalogadorEditado.value ?? 'alguien sin registro'} (${marca})`
  return `Catalogador (${marca}): ${auth.staff?.nombre ?? 'tu cuenta'} · se registra automáticamente`
})


// ---------- Carga ----------
async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<Paginado<Libro>>('/libros', {
      params: { q: busqueda.value.trim() || undefined, page: paginaLibros.value, por_pagina: 20 },
    })
    const { data: filas, ...meta } = data
    libros.value = filas
    metaLibros.value = meta
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
    const [carrerasRes, ubicacionesRes, tiposMaterialRes, plantillasRes] = await Promise.all([
      api.get<CarreraSugerida[]>('/carreras', { params: { con_usuarios: 1 } }),
      api.get<Ubicacion[]>('/ubicaciones'),
      api.get<TipoMaterial[]>('/tipos-material'),
      api.get<PlantillaFicha[]>('/libros/plantillas'),
    ])
    api.get<{ clave: string; nombre: string }[]>('/roles-autor').then(({ data }) => (rolesAutor.value = data)).catch(() => {})
    carrerasOpciones.value = carrerasRes.data
    ubicacionesOpciones.value = ubicacionesRes.data
    tiposMaterialOpciones.value = tiposMaterialRes.data
    plantillas.value = plantillasRes.data
  } catch {
    // Los combobox simplemente no ofrecen sugerencias — no bloquea catalogar.
  }
}

async function abrirDesdeListado() {
  const editarId = Number(route.query.editar)
  const copiaId = Number(route.query.copia)
  if (!editarId && !copiaId) return
  try {
    const { data } = await api.get<Libro>(`/libros/${editarId || copiaId}`)
    if (editarId) editar(data)
    else {
      modo.value = 'copia'
      seleccionarLibroParaCopia(data)
    }
  } catch {
    toast.error('No se pudo abrir ese título')
  }
  router.replace({ query: {} })
}

onMounted(() => {
  cargar()
  cargarCatalogos()
  abrirDesdeListado()
  window.addEventListener('scroll', alDesplazar, { passive: true })
  medirSeccionActual()
})
onBeforeUnmount(() => {
  window.removeEventListener('scroll', alDesplazar)
  cancelAnimationFrame(cuadro)
})

let buscarTimer: ReturnType<typeof setTimeout> | undefined
function onBuscarInput() {
  clearTimeout(buscarTimer)
  // 1 o 2 letras recorren todo el catálogo: se busca al seguir escribiendo
  if (esBusquedaCorta(busqueda.value)) return
  buscarTimer = setTimeout(() => {
    paginaLibros.value = 1
    cargar()
  }, 250)
}

function irAPaginaLibros(pagina: number) {
  paginaLibros.value = pagina
  cargar()
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

// ---------- Libro nuevo y edición ----------
function resetForm() {
  Object.assign(form, formVacio, { autores: [], rolesAutores: {}, categorias: [], carreras: [] })
  editandoId.value = null
  libroEditado.value = null
  catalogadorEditado.value = null
  verMasTitulo.value = false
  titulosAlternativos.value = ['']
  verMasMaterias.value = false
}

function editar(libro: Libro) {
  modo.value = 'nuevo'
  editandoId.value = libro.id
  libroEditado.value = libro
  catalogadorEditado.value = libro.catalogado_por?.nombre ?? null
  Object.assign(form, {
    ...(Object.fromEntries(CAMPOS_TEXTO.map((c) => [c, libro[c] ?? ''])) as Record<CampoTexto, string>),
    codigo_barras: '',
    titulo: libro.titulo,
    autores: (libro.autores ?? []).map((a) => a.nombre),
    rolesAutores: Object.fromEntries((libro.autores ?? []).filter((a) => a.pivot?.rol).map((a) => [a.nombre, a.pivot!.rol as string])),
    categorias: (libro.categorias ?? []).map((c) => c.nombre),
    carreras: (libro.carreras ?? []).map((c) => c.nombre),
    coleccion: libro.coleccion ?? '',
    editorial: libro.editorial ?? '',
    anio_publicacion: libro.anio_publicacion ?? '',
    ubicacion_id: '',
    tipo_material_id: libro.tipo_material_id ?? '',
    volumen: '',
    precio: '',
    forma_adquisicion: '',
    copia_nota_publica: '',
    copia_nota_interna: '',
  })
  fotoEdicion.value = JSON.stringify(form)
  desdeBorrador.value = false
  abrirExtrasConDatos()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

// «Editar» en la lista pide confirmación: reemplaza lo que haya en el formulario.
const libroAEditar = ref<Libro | null>(null)
const fotoEdicion = ref('')

function confirmarEditar() {
  const libro = libroAEditar.value
  libroAEditar.value = null
  if (!libro) return
  if (modo.value === 'copia') {
    cambiarObra()
    Object.assign(formCopia, copiaVacia())
  }
  editar(libro)
}

// Cada materia agregada queda con sus subdivisiones escritas siempre igual (« -- »), y sin
// repetirse si dos se escribieron distinto («mapuches--historia» y «mapuches -- historia»).
watch(
  () => form.categorias.slice(),
  (lista) => {
    const normalizadas = [...new Map(lista.map((m) => normalizarMateria(m)).filter(Boolean).map((m) => [m.toLowerCase(), m])).values()]
    if (normalizadas.join('\n') !== lista.join('\n')) form.categorias = normalizadas
  },
)

const confirmandoGuardar = ref(false)
/** El título con que queda guardada la ficha: vacío es «Sin título», como lo guarda el servidor. */
const tituloAGuardar = computed(() => form.titulo.trim() || 'Sin título')
const ubicacionElegida = computed(() => ubicacionesOpciones.value.find((u) => u.id === form.ubicacion_id)?.nombre ?? null)

function pedirConfirmacionGuardar() {
  const falta = obligatoriosNuevo.value.filter((o) => !o.listo)
  if (falta.length) {
    toast.error(`Falta completar: ${falta.map((o) => o.texto).join(', ')}`)
    irA(falta[0].seccion)
    return
  }
  confirmandoGuardar.value = true
}

// ---------- Recién creada: ofrecer ir a cambiar su estado ----------
// Toda copia nueva nace en «Inventario»; quien puede cambiar estados tiene a mano el
// paso siguiente (Estado de ejemplares, con esa copia ya elegida).
const copiaCreada = ref<{ titulo: string; numeroCopia: string | null; codigoBarras: string; codigoLomo: string | null; estado: string } | null>(null)
const puedeCambiarEstado = computed(() => auth.puede('libros.estado'))

// ---------- Dar de baja el título ----------
// Saca todas las copias de circulación (también las prestadas, que se perdieron). Pide el
// motivo y escribir CONFIRMAR; se reincorporan copia por copia desde Administración › Eliminados.
const bajaTituloAbierta = ref(false)
const bajaMotivo = ref('')
const dandoDeBaja = ref(false)

function pedirBajaTitulo() {
  bajaMotivo.value = ''
  bajaTituloAbierta.value = true
}

async function darDeBajaTitulo() {
  const id = editandoId.value
  if (!id || !bajaMotivo.value.trim()) return
  dandoDeBaja.value = true
  try {
    const { data } = await api.post<{ copias: number; prestadas: number }>(`/libros/${id}/baja`, { motivo: bajaMotivo.value.trim(), confirmacion: 'CONFIRMAR' })
    toast.success(`Título dado de baja (${data.copias} ${data.copias === 1 ? 'copia' : 'copias'})`)
    bajaTituloAbierta.value = false
    resetForm()
    cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo dar de baja el título'))
  } finally {
    dandoDeBaja.value = false
  }
}

/** La de préstamo interbibliotecario nace «Por colocar»; las demás, en «Inventario». */
function avisarCopiaCreada(titulo: string, copia: Pick<Ejemplar, 'etiqueta_copia' | 'codigo_barras' | 'codigo_lomo'> | undefined, estado = 'Inventario') {
  if (!copia || !puedeCambiarEstado.value) return
  copiaCreada.value = { titulo, numeroCopia: numeroDeCopia(copia), codigoBarras: copia.codigo_barras, codigoLomo: copia.codigo_lomo ?? null, estado }
}

function irACambiarEstado() {
  const copia = copiaCreada.value
  copiaCreada.value = null
  if (copia) router.push({ name: 'estado-libro', query: { q: copia.codigoBarras } })
}

async function confirmarGuardar() {
  guardando.value = true
  try {
    const payload = {
      ...form,
      anio_publicacion: form.anio_publicacion === '' ? null : Number(form.anio_publicacion),
      precio: form.precio === '' ? null : Number(form.precio),
      forma_adquisicion: form.forma_adquisicion || null,
      ubicacion_id: form.ubicacion_id === '' ? null : Number(form.ubicacion_id),
      tipo_material_id: form.tipo_material_id === '' ? null : Number(form.tipo_material_id),
      autores_roles: form.autores.map((n) => form.rolesAutores[n] || null),
    }
    if (editandoId.value) {
      await api.patch(`/libros/${editandoId.value}`, payload)
      toast.success('Ficha actualizada')
    } else {
      const { data } = await api.post<Libro>('/libros', { ...payload, codigo_barras: null })
      if (desdeBorrador.value) descartarBorrador()
      toast.success(`Ficha N.º ${data.numero_ficha} creada: agregue ahora los ejemplares de «${data.titulo}»`)
      confirmandoGuardar.value = false
      resetForm()
      await Promise.all([cargar(), cargarCatalogos()])
      // La ficha ya existe: se pasa a «Nuevo ejemplar» con ella elegida.
      agregarCopiaDe(data)
      return
    }
    confirmandoGuardar.value = false
    resetForm()
    window.scrollTo({ top: 0, behavior: 'smooth' })
    await Promise.all([cargar(), cargarCatalogos()])
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo guardar la ficha')
    confirmandoGuardar.value = false
  } finally {
    guardando.value = false
  }
}

// ---------- Borrador (queda guardado en este navegador) ----------
const CLAVE_BORRADOR = 'catalogacion.borrador'
type Borrador = { guardado: string; form: Partial<typeof formVacio> }

function leerBorrador(): Borrador | null {
  try {
    const crudo = localStorage.getItem(CLAVE_BORRADOR)
    return crudo ? (JSON.parse(crudo) as Borrador) : null
  } catch {
    return null
  }
}

const borrador = ref<Borrador | null>(leerBorrador())
// Si lo que está en pantalla es el borrador, al catalogarlo se borra.
const desdeBorrador = ref(false)
const confirmandoDescartar = ref(false)
const confirmandoBorrador = ref(false)
const confirmandoRecuperar = ref(false)

const formularioVacio = computed(() => JSON.stringify(form) === JSON.stringify(formVacio))

/** Lo escrito que se perdería al cargar otra ficha o el borrador. */
const hayTrabajoSinGuardar = computed(() => {
  if (modo.value === 'copia') {
    return JSON.stringify(formCopia) !== JSON.stringify(copiaVacia()) || !!libroParaCopia.value
  }
  return editandoId.value ? JSON.stringify(form) !== fotoEdicion.value : !formularioVacio.value
})
const fechaBorrador = computed(() =>
  borrador.value ? new Date(borrador.value.guardado).toLocaleString('es-CL', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) : '',
)

function guardarBorrador() {
  const nuevo: Borrador = {
    guardado: new Date().toISOString(),
    form: { ...form, autores: [...form.autores], rolesAutores: { ...form.rolesAutores }, categorias: [...form.categorias], carreras: [...form.carreras] },
  }
  try {
    localStorage.setItem(CLAVE_BORRADOR, JSON.stringify(nuevo))
    borrador.value = nuevo
    desdeBorrador.value = true
    toast.success('Borrador guardado en este computador')
  } catch {
    toast.error('Este navegador no permite guardar el borrador')
  } finally {
    confirmandoBorrador.value = false
  }
}

function pedirRecuperarBorrador() {
  if (hayTrabajoSinGuardar.value) confirmandoRecuperar.value = true
  else recuperarBorrador()
}

function recuperarBorrador() {
  confirmandoRecuperar.value = false
  if (!borrador.value) return
  resetForm()
  Object.assign(form, borrador.value.form)
  desdeBorrador.value = true
  abrirExtrasConDatos()
}

function descartarBorrador() {
  try {
    localStorage.removeItem(CLAVE_BORRADOR)
  } catch {
    // Sin acceso al almacenamiento no hay nada que borrar.
  }
  borrador.value = null
  desdeBorrador.value = false
  confirmandoDescartar.value = false
}

// ---------- Agregar copia ----------
let buscarCopiaTimer: ReturnType<typeof setTimeout> | undefined
function onBuscarCopiaInput() {
  clearTimeout(buscarCopiaTimer)
  buscarCopiaTimer = setTimeout(async () => {
    const texto = busquedaCopia.value.trim()
    if (!texto) {
      resultadosCopia.value = []
      return
    }
    if (esBusquedaCorta(texto)) return
    try {
      const { data } = await api.get<Paginado<Libro>>('/libros', { params: { q: texto, por_pagina: 15 } })
      // Si mientras tanto se siguió escribiendo o se eligió una obra, esta respuesta ya no sirve.
      if (texto === busquedaCopia.value.trim()) resultadosCopia.value = data.data
    } catch {
      resultadosCopia.value = []
    }
  }, 250)
}

function seleccionarLibroParaCopia(libro: Libro) {
  clearTimeout(buscarCopiaTimer)
  libroParaCopia.value = libro
  resultadosCopia.value = []
  busquedaCopia.value = libro.titulo
}

/** Desde «Títulos catalogados»: salta al formulario de copia con ese título ya elegido. */
function agregarCopiaDe(libro: Libro) {
  modo.value = 'copia'
  seleccionarLibroParaCopia(libro)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function cambiarObra() {
  libroParaCopia.value = null
  busquedaCopia.value = ''
}

const confirmandoCopia = ref(false)
const ubicacionCopia = computed(() => ubicacionesOpciones.value.find((u) => u.id === formCopia.ubicacion_id)?.nombre ?? null)

function pedirConfirmacionCopia() {
  const falta = obligatoriosCopia.value.filter((o) => !o.listo)
  if (falta.length) {
    toast.error(`Falta completar: ${falta.map((o) => o.texto).join(', ')}`)
    irA(falta[0].seccion)
    return
  }
  confirmandoCopia.value = true
}

async function confirmarAgregarCopia() {
  if (!libroParaCopia.value) return
  guardando.value = true
  try {
    const titulo = libroParaCopia.value.titulo
    const { data } = await api.post<Ejemplar>('/ejemplares', {
      libro_id: libroParaCopia.value.id,
      codigo_barras: formCopia.codigo_barras,
      ubicacion_id: formCopia.ubicacion_id === '' ? null : Number(formCopia.ubicacion_id),
      volumen: formCopia.tomo.trim() ? null : formCopia.volumen || null,
      tomo: formCopia.tomo.trim() || null,
      precio: formCopia.precio === '' ? null : Number(formCopia.precio),
      forma_adquisicion: formCopia.forma_adquisicion || null,
      coleccion: formCopia.coleccion.trim() || null,
      nota_publica: formCopia.nota_publica.trim() || null,
      nota_interna: formCopia.nota_interna.trim() || null,
    })
    toast.success(data.volumen ? `Copia agregada: ${data.volumen}` : 'Copia agregada correctamente')
    confirmandoCopia.value = false
    avisarCopiaCreada(titulo, data)
    // La obra queda elegida para seguir agregando copias (otro tomo, otra copia del mismo):
    // se limpia solo el código; tomo, ubicación y colección se mantienen.
    const libroId = libroParaCopia.value.id
    Object.assign(formCopia, { codigo_barras: '', volumen: '', precio: '', nota_publica: '', nota_interna: '' })
    const [, { data: libroActualizado }] = await Promise.all([cargar(), api.get<Libro>(`/libros/${libroId}`)])
    libroParaCopia.value = libroActualizado
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo agregar la copia')
    confirmandoCopia.value = false
  } finally {
    guardando.value = false
  }
}

const MODOS = [
  { id: 'nuevo' as const, label: 'Nueva ficha', detalle: 'El registro del título, sin código', icono: 'M12 4.5v15m7.5-7.5h-15', tono: 'bg-indigo-50 text-indigo-700', activo: 'border-indigo-600 bg-indigo-600 shadow-indigo-600/30', anillo: 'hover:border-indigo-300' },
  { id: 'copia' as const, label: 'Nuevo ejemplar', icono: 'M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75M15.75 3.75H9.375c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V8.25L15.75 3.75z', tono: 'bg-emerald-50 text-emerald-700', activo: 'border-emerald-600 bg-emerald-600 shadow-emerald-600/30', anillo: 'hover:border-emerald-300', detalle: 'Una copia con su código de barras' },
  { id: 'interbibliotecario' as const, label: 'Préstamo interbibliotecario', icono: 'M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5', tono: 'bg-amber-50 text-dorado-600', activo: 'border-amber-600 bg-amber-600 shadow-amber-600/30', anillo: 'hover:border-amber-300', detalle: 'Material que llega de otra biblioteca' },
]

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}

function accionPrincipal() {
  if (modo.value === 'copia') pedirConfirmacionCopia()
  else pedirConfirmacionGuardar()
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
  devuelto_propietaria: { label: 'Devuelto a su biblioteca', cls: 'bg-slate-100 text-slate-600 line-through' },
}
</script>

<template>
  <StaffLayout>
    <div class="mx-auto max-w-[88rem]">
      <!-- La caja de título con la textura de Salas y Préstamos, las pestañas del módulo y, debajo, qué se va a catalogar. -->
      <section class="mb-5 rounded-2xl border border-gray-200 bg-white shadow-md">
        <div class="relative rounded-t-2xl" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
          <FondoEstante />
          <div class="relative min-w-0 px-6 pt-4">
            <nav class="text-xs text-white/75" aria-label="Ruta">
              <button type="button" class="font-medium text-white hover:underline" @click="abrirMenuGestion">Gestión</button>
              <span class="mx-1.5">›</span>
              <span>Catálogo</span>
            </nav>
            <router-link
              v-if="vieneDelListado"
              :to="{ name: 'listado-libros', query: { volver: '1' } }"
              class="float-right mt-1 inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3 py-1.5 text-sm font-semibold text-white ring-1 ring-inset ring-white/30 transition-colors hover:bg-white/25"
              title="Vuelve al Listado de material con la misma búsqueda, filtros y página"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
              Volver al listado
            </router-link>
            <h1 class="font-serif text-2xl font-bold tracking-tight text-white">{{ modo === 'nuevo' && editandoId ? 'Editar ficha' : 'Catalogar material' }}</h1>
            <p v-if="modo === 'nuevo' && editandoId" class="truncate text-sm text-white/75"><template v-if="libroEditado?.numero_ficha">Ficha N.º <span class="font-mono font-semibold text-white">{{ libroEditado.numero_ficha }}</span> · </template>{{ libroEditado?.titulo }}</p>
          </div>
          <PestanasLibros actual="catalogacion-libros" en-banda class="relative mt-3 px-5" />
        </div>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-b-2xl p-3">
          <!-- Botones grandes, tipo tarjeta: cada uno dice qué hace; el elegido, en su color. -->
          <div class="grid w-full grid-cols-1 gap-2.5 sm:grid-cols-3" role="tablist" aria-label="Qué catalogar">
            <button
              v-for="m in MODOS"
              :key="m.id"
              type="button"
              role="tab"
              :aria-selected="modo === m.id"
              class="group flex items-center gap-3 rounded-xl border-2 px-3.5 py-3 text-left shadow-sm transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 active:translate-y-0 active:scale-[0.99]"
              :class="modo === m.id ? `${m.activo} text-white shadow-lg` : `border-gray-200 bg-white text-gray-800 ${m.anillo}`"
              @click="modo = m.id"
            >
              <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition-transform group-hover:scale-105"
                :class="modo === m.id ? 'bg-blanco/20 text-white' : m.tono"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="m.icono" /></svg>
              </span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-bold leading-tight">{{ m.id === 'nuevo' && editandoId ? 'Editando ficha' : m.label }}</span>
                <span class="mt-0.5 block truncate text-xs" :class="modo === m.id ? 'text-white/80' : 'text-gray-500'">{{ m.detalle }}</span>
              </span>
              <svg
                class="h-4 w-4 shrink-0 transition-transform"
                :class="modo === m.id ? 'text-white' : 'text-gray-300 group-hover:translate-x-0.5 group-hover:text-gray-500'"
                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"
              >
                <path v-if="modo === m.id" stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                <path v-else stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
              </svg>
            </button>
          </div>
        </div>
      </section>

      <PrestamoInterbibliotecario
        v-if="modo === 'interbibliotecario'"
        :ubicaciones="ubicacionesOpciones"
        :tipos-material="tiposMaterialOpciones"
        @copia-creada="(titulo, copia) => avisarCopiaCreada(titulo, copia, 'Por colocar')"
      />

      <template v-else>
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-[11rem_minmax(0,1fr)_17rem] xl:grid-cols-[12.5rem_minmax(0,1fr)_19rem]">
        <!-- Índice de secciones -->
        <aside class="hidden lg:block">
          <div class="sticky top-6 space-y-3">
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
              <div class="rounded-2xl bg-indigo-50/40 p-2.5">
                <p class="mb-2 flex items-center justify-between px-1.5 text-[11px] font-bold uppercase tracking-wider text-indigo-700">
                  Secciones
                  <span class="font-semibold normal-case tracking-normal text-gray-500">{{ numeroSeccionActual }} de {{ secciones.length }}</span>
                </p>
                <ol class="space-y-1">
                  <li v-for="(p, i) in pasos" :key="p.clave" class="relative">
                    <span v-if="i < pasos.length - 1" class="absolute -bottom-3 left-[1.125rem] h-5 border-l border-dashed border-gray-300" aria-hidden="true"></span>
                    <button
                      type="button"
                      class="relative flex w-full items-center gap-2.5 rounded-xl px-1.5 py-2 text-left text-sm transition-colors"
                      :class="p.estado === 'actual' ? 'bg-white font-semibold text-gray-900 shadow-sm ring-1 ring-amber-200' : 'text-gray-700 hover:bg-white/70'"
                      :aria-current="p.estado === 'actual' ? 'step' : undefined"
                      @click="irA(p.clave)"
                    >
                      <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold" :class="claseNumeroPaso(p.estado, p.tono)">
                        <svg v-if="p.estado === 'lista'" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                        <template v-else-if="p.estado === 'pendiente'">!</template>
                        <template v-else>{{ p.numero }}</template>
                      </span>
                      <span class="min-w-0">
                        {{ p.titulo }}
                        <span v-if="p.estado === 'actual'" class="block text-[10px] font-medium leading-tight text-dorado-600">En curso</span>
                      </span>
                    </button>
                  </li>
                </ol>
                <div class="mx-1.5 mb-1 mt-3 h-1 overflow-hidden rounded-full bg-gray-200" aria-hidden="true">
                  <div class="h-full rounded-full bg-[#b08d57] transition-all" :style="{ width: `${(numeroSeccionActual / secciones.length) * 100}%` }"></div>
                </div>
              </div>
            </div>
            <div class="flex gap-2 rounded-2xl border border-amber-200 bg-gradient-to-br from-amber-50 to-white px-3 py-2.5 text-xs leading-relaxed text-dorado-700 shadow-sm">
              <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-[#b08d57] text-white" aria-hidden="true">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
              </span>
              <p>
                Los números como
                <span class="rounded bg-white px-1 py-0.5 font-mono text-[10px] font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-100">245</span>
                son las etiquetas MARC 21 de cada campo.
              </p>
            </div>
          </div>
        </aside>

        <!-- Formulario -->
        <div class="min-w-0 space-y-5">
          <ApiErrorBanner v-if="apiError" />

          <template v-if="modo === 'nuevo'">
            <div
              v-if="borrador && !desdeBorrador && !editandoId"
              class="flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
            >
              <p class="text-sm text-dorado-700">
                Tienes un borrador sin terminar<template v-if="borrador.form.titulo">: <strong>«{{ borrador.form.titulo }}»</strong></template>,
                guardado el {{ fechaBorrador }}.
              </p>
              <div class="flex shrink-0 gap-2">
                <button type="button" class="rounded-lg bg-[#b08d57] px-3 py-1.5 text-sm font-semibold text-white transition hover:brightness-110" @click="pedirRecuperarBorrador">
                  Recuperar
                </button>
                <button type="button" class="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-sm font-medium text-dorado-700 transition-colors hover:bg-amber-100" @click="confirmandoDescartar = true">
                  Descartar
                </button>
              </div>
            </div>

            <!-- 1. Identificación -->
            <SeccionFicha id="seccion-identificacion" :numero="numeroDe('identificacion')" titulo="Identificación" :detalle="seccionDe('identificacion')?.detalle ?? ''" tono="indigo">
              <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                <div :class="editandoId ? 'sm:col-span-3' : 'sm:col-span-2'">
                  <label for="cat-tipo" :class="ETIQUETA">Tipo de material</label>
                  <select id="cat-tipo" v-model="form.tipo_material_id" :class="CAMPO">
                    <option value="">Sin especificar (ficha de libro)</option>
                    <option v-for="t in tiposMaterialOpciones" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                  </select>
                  <p class="mt-1 text-[11px] text-gray-500">Define qué campos se piden<template v-if="plantillaActual"> · ficha de {{ plantillaActual.nombre.toLowerCase() }}</template></p>
                </div>
                <div v-if="tiene('isbn')" class="sm:col-span-3">
                  <label for="cat-isbn" :class="ETIQUETA"><Marc :codigo="etiqueta('isbn')" tono="indigo" /> ISBN</label>
                  <input autocomplete="off" id="cat-isbn" v-model="form.isbn" placeholder="978..." :class="[CAMPO, 'font-mono']" />
                </div>
                <div v-else-if="tiene('issn')" :class="editandoId ? 'sm:col-span-3' : 'sm:col-span-2'">
                  <label for="cat-issn" :class="ETIQUETA"><Marc :codigo="etiqueta('issn')" tono="indigo" /> ISSN</label>
                  <input autocomplete="off" id="cat-issn" v-model="form.issn" placeholder="0718-..." :class="[CAMPO, 'font-mono']" />
                </div>
                <div v-if="tiene('clasificacion')" class="sm:col-span-3">
                  <label for="cat-clasificacion" :class="ETIQUETA">
                    <Marc :codigo="etiqueta('clasificacion')" tono="indigo" />
                    {{ etiqueta('clasificacion') === '082' ? 'Clasificación Dewey' : 'Clasificación local' }}
                  </label>
                  <input autocomplete="off"
                    id="cat-clasificacion"
                    v-model="form.clasificacion"
                    :placeholder="etiqueta('clasificacion') === '082' ? 'Ej: 813.54 G216n 2020' : 'Ej: T 005.1 C764s 2023'"
                    :class="[CAMPO, 'font-mono']"
                  />
                  <p class="mt-1 text-[11px] text-gray-500">Clase · Cutter · Año</p>
                </div>
                <div v-if="tiene('idioma')" class="sm:col-span-3">
                  <label for="cat-idioma" :class="ETIQUETA"><Marc :codigo="etiqueta('idioma')" tono="indigo" /> Idioma</label>
                  <input autocomplete="off" id="cat-idioma" v-model="form.idioma" placeholder="Español" :class="CAMPO" />
                </div>
                <div v-if="tiene('otros_isbn')" class="sm:col-span-6">
                  <label for="cat-otros-isbn" :class="ETIQUETA"><Marc :codigo="etiqueta('otros_isbn')" tono="indigo" /> Otros ISBN</label>
                  <input autocomplete="off" id="cat-otros-isbn" v-model="form.otros_isbn" placeholder="978...; 978..." :class="[CAMPO, 'font-mono']" />
                  <p class="mt-1 text-[11px] text-gray-500">El de la colección o la obra completa, o el que comparte con otros tomos, separados por «;». Al buscar cualquiera de ellos aparece este título.</p>
                </div>
              </div>
            </SeccionFicha>

            <!-- 2. Título y autoría -->
            <SeccionFicha id="seccion-titulo" :numero="numeroDe('titulo')" titulo="Título y autoría" :detalle="seccionDe('titulo')?.detalle ?? ''" tono="dorado">
              <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                <div :class="tiene('edicion') ? 'sm:col-span-4' : 'sm:col-span-6'">
                  <label for="cat-titulo" :class="ETIQUETA"><Marc :codigo="etiqueta('titulo') || '245'" tono="dorado" /> Título</label>
                  <input autocomplete="off" id="cat-titulo" v-model="form.titulo" placeholder="Título de la obra (si se deja vacío, queda «Sin título»)" :class="CAMPO" />
                </div>
                <div v-if="tiene('edicion')" class="sm:col-span-2">
                  <label for="cat-edicion" :class="ETIQUETA"><Marc :codigo="etiqueta('edicion')" tono="dorado" /> Edición</label>
                  <input autocomplete="off" id="cat-edicion" v-model="form.edicion" placeholder="Ej: 6.ª ed." :class="CAMPO" />
                </div>
                <div v-if="tiene('autor_principal')" class="sm:col-span-6" :class="tiene('entidad_corporativa') ? 'rounded-xl border border-amber-200 bg-amber-50/40 p-3' : ''">
                  <p v-if="tiene('entidad_corporativa')" class="mb-2 text-xs font-semibold text-gray-800">
                    Autoría
                    <span class="font-normal text-gray-600">: <strong>los autores</strong> o <strong>la entidad institucional</strong>, si los tiene; no hace falta llenar ambos.</span>
                  </p>
                  <p :class="ETIQUETA"><Marc codigo="100 / 700" tono="dorado" /> Autores</p>
                  <MultiSelectCombobox
                    v-model="form.autores"
                    :buscar="buscarAutores"
                    placeholder="Apellido, Nombre: escribe para buscar o crear"
                    :clase-ficha="TONOS_FICHA.dorado.chip"
                  />
                  <datalist id="roles-autor-lista">
                    <option v-for="r in ROLES_AUTOR" :key="r.valor" :value="r.texto" />
                  </datalist>
                  <ul v-if="form.autores.length" class="mt-2 divide-y divide-amber-100 rounded-lg border border-amber-200 bg-white">
                    <li v-for="(nombre, i) in form.autores" :key="nombre" class="flex flex-wrap items-center gap-2 px-3 py-1.5 text-sm">
                      <span class="rounded px-1.5 py-0.5 font-mono text-[10px] font-semibold" :class="i === 0 && !form.rolesAutores[nombre] ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600'">{{ i === 0 && !form.rolesAutores[nombre] ? '100' : '700' }}</span>
                      <span class="min-w-0 flex-1 truncate text-gray-900">{{ nombre }}</span>
                      <label class="sr-only" :for="`rol-autor-${i}`">Papel de {{ nombre }}</label>
                      <!-- Se escribe para filtrar el papel («ases» → Asesor…); con la lista también se elige. -->
                      <input
                        :id="`rol-autor-${i}`"
                        :value="nombreRol(form.rolesAutores[nombre])"
                        list="roles-autor-lista"
                        autocomplete="off"
                        placeholder="Autor"
                        class="h-8 w-48 rounded-md border border-gray-300 bg-white px-2 text-xs text-gray-800 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                        @focus="(e) => (e.target as HTMLInputElement).select()"
                        @change="(e) => elegirRol(nombre, e.target as HTMLInputElement)"
                        @keydown.enter.prevent="(e) => elegirRol(nombre, e.target as HTMLInputElement)"
                      />
                    </li>
                  </ul>
                  <p class="mt-1 text-[11px] font-semibold text-gray-700">
                    El primero queda como autor principal (100); el resto, como secundarios (700). Elija el papel de cada uno (traductor, editor, compilador…): si el primero no es «Autor», la obra queda sin autor principal y todos van en 700, como una obra compilada. El profesor guía de una tesis va en su propio campo.<template v-if="tiene('entidad_corporativa')"> Si la obra es de una institución, basta la entidad (110).</template>
                  </p>
                  <template v-if="tiene('entidad_corporativa')">
                    <div class="my-3 flex items-center gap-3 text-[11px] font-bold uppercase tracking-wider text-amber-700" aria-hidden="true">
                      <span class="h-px flex-1 bg-amber-200"></span>o<span class="h-px flex-1 bg-amber-200"></span>
                    </div>
                    <label for="cat-entidad_corporativa" :class="ETIQUETA"><Marc :codigo="etiqueta('entidad_corporativa')" tono="dorado" /> Entidad institucional (autor corporativo)</label>
                    <input autocomplete="off" id="cat-entidad_corporativa" v-model="form.entidad_corporativa" :placeholder="PISTAS.entidad_corporativa" :class="CAMPO" />
                  </template>
                </div>
                <div v-if="tiene('profesor_guia')" class="sm:col-span-3">
                  <label for="cat-profesor" :class="ETIQUETA"><Marc :codigo="etiqueta('profesor_guia')" tono="dorado" /> Profesor guía</label>
                  <input autocomplete="off" id="cat-profesor" v-model="form.profesor_guia" placeholder="Apellido, Nombre" :class="CAMPO" />
                </div>
                <template v-if="extrasTitulo.length">
                  <div v-if="!verMasTitulo" class="sm:col-span-6">
                    <button
                      type="button"
                      class="inline-flex items-center gap-2 rounded-lg border border-dashed border-amber-300 px-3 py-2 text-xs font-semibold text-dorado-600 transition-colors hover:bg-amber-50"
                      @click="verMasTitulo = true"
                    >
                      <span class="text-sm leading-none">+</span> {{ textoExtras(extrasTitulo) }}
                    </button>
                  </div>
                  <template v-else>
                    <div v-if="tiene('titulo_alternativo')" class="sm:col-span-6">
                      <p :class="ETIQUETA"><Marc :codigo="etiqueta('titulo_alternativo')" tono="dorado" /> {{ NOMBRES.titulo_alternativo }} <span class="font-normal text-gray-500">(puede haber varios)</span></p>
                      <div class="space-y-2">
                        <div v-for="(_, i) in titulosAlternativos" :key="i" class="flex gap-2">
                          <input autocomplete="off"
                            :id="`cat-titulo-alt-${i}`"
                            v-model="titulosAlternativos[i]"
                            :placeholder="PISTAS.titulo_alternativo"
                            :class="CAMPO"
                            @input="guardarTitulosAlternativos"
                          />
                          <button
                            v-if="titulosAlternativos.length > 1 || titulosAlternativos[i]"
                            type="button"
                            class="shrink-0 rounded-lg border border-gray-300 px-3 text-sm text-gray-500 hover:bg-gray-50 hover:text-red-600"
                            :aria-label="`Quitar título alternativo ${i + 1}`"
                            @click="quitarTituloAlternativo(i)"
                          >✕</button>
                        </div>
                      </div>
                      <button type="button" class="mt-2 text-xs font-semibold text-dorado-600 hover:underline" @click="agregarTituloAlternativo">+ Agregar otro título alternativo</button>
                    </div>
                    <div v-for="c in extrasTitulo.filter((x) => x !== 'titulo_alternativo')" :key="c" class="sm:col-span-3">
                      <label :for="`cat-${c}`" :class="ETIQUETA"><Marc :codigo="etiqueta(c)" tono="dorado" /> {{ NOMBRES[c] }}</label>
                      <input autocomplete="off" :id="`cat-${c}`" v-model="form[c]" :placeholder="PISTAS[c]" :class="CAMPO" />
                    </div>
                  </template>
                </template>
              </div>
            </SeccionFicha>

            <!-- 3. Publicación -->
            <SeccionFicha id="seccion-publicacion" :numero="numeroDe('publicacion')" titulo="Publicación" :detalle="seccionDe('publicacion')?.detalle ?? ''" tono="teal">
              <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                <div class="sm:col-span-2">
                  <label for="cat-lugar" :class="ETIQUETA"><Marc :codigo="etiqueta('pie_imprenta') || '260'" tono="teal" /> Lugar</label>
                  <input autocomplete="off" id="cat-lugar" v-model="form.lugar_publicacion" placeholder="Ciudad" :class="CAMPO" />
                </div>
                <div class="sm:col-span-2">
                  <label for="cat-editorial" :class="ETIQUETA">
                    <Marc :codigo="etiqueta('pie_imprenta') || '260'" tono="teal" />
                    {{ etiqueta('pie_imprenta') === '264' ? 'Productor o editor' : 'Editorial' }}
                  </label>
                  <input autocomplete="off" id="cat-editorial" v-model="form.editorial" :placeholder="etiqueta('pie_imprenta') === '264' ? 'Productor o editor' : 'Editorial'" :class="CAMPO" />
                </div>
                <div class="sm:col-span-2">
                  <label for="cat-anio" :class="ETIQUETA"><Marc :codigo="etiqueta('pie_imprenta') || '260'" tono="teal" /> Año</label>
                  <input autocomplete="off" id="cat-anio" v-model="form.anio_publicacion" type="number" placeholder="2020" :class="CAMPO" />
                </div>
                <div v-if="tiene('descripcion_fisica')" class="sm:col-span-3">
                  <label for="cat-descripcion" :class="ETIQUETA"><Marc :codigo="etiqueta('descripcion_fisica')" tono="teal" /> Descripción física</label>
                  <input autocomplete="off" id="cat-descripcion" v-model="form.descripcion_fisica" placeholder="Ej: 350 p. : il. ; 24 cm.":class="CAMPO" />
                </div>
                <div v-if="tiene('serie')" class="sm:col-span-3">
                  <label for="cat-serie" :class="ETIQUETA"><Marc :codigo="etiqueta('serie')" tono="teal" /> Serie</label>
                  <textarea id="cat-serie" v-model="form.serie" rows="1" placeholder="Opcional · si son varias, una por línea" :class="CAMPO"></textarea>
                </div>
                <div v-if="tiene('designacion_volumen')" class="sm:col-span-3">
                  <label for="cat-designacion" :class="ETIQUETA"><Marc :codigo="etiqueta('designacion_volumen')" tono="teal" /> Fecha de publicación y volumen</label>
                  <input autocomplete="off" id="cat-designacion" v-model="form.designacion_volumen" :placeholder="PISTAS.designacion_volumen" :class="CAMPO" />
                </div>
                <div v-if="tiene('frecuencia')" class="sm:col-span-3">
                  <label for="cat-frecuencia" :class="ETIQUETA"><Marc :codigo="etiqueta('frecuencia')" tono="teal" /> Frecuencia</label>
                  <input autocomplete="off" id="cat-frecuencia" v-model="form.frecuencia" :placeholder="PISTAS.frecuencia" :class="CAMPO" />
                </div>
                <div v-if="tiene('nota_tesis')" class="sm:col-span-6">
                  <label for="cat-nota-tesis" :class="ETIQUETA"><Marc :codigo="etiqueta('nota_tesis')" tono="teal" /> Nota de tesis</label>
                  <textarea id="cat-nota-tesis" v-model="form.nota_tesis" rows="2" :placeholder="PISTAS.nota_tesis" :class="CAMPO"></textarea>
                </div>
              </div>
            </SeccionFicha>

            <!-- 4. Materias (o contenido, en los juegos) -->
            <SeccionFicha
              v-if="seccionDe('contenido')"
              id="seccion-contenido"
              :numero="numeroDe('contenido')"
              :titulo="seccionDe('contenido')?.titulo ?? ''"
              :detalle="seccionDe('contenido')?.detalle ?? ''"
              tono="pizarra"
            >
              <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                <div v-if="tiene('materias')" class="sm:col-span-6">
                  <p :class="ETIQUETA"><Marc :codigo="etiqueta('materias')" tono="pizarra" /> Materias</p>
                  <MultiSelectCombobox v-model="form.categorias" :buscar="buscarMaterias" placeholder="Agregar materia (ej. MAPUCHES -- VIDA SOCIAL Y COSTUMBRES)" :clase-ficha="TONOS_FICHA.pizarra.chip" />
                  <p class="mt-1 text-[11px] text-gray-500">Sugerencias según lo que ya existe en el catálogo.</p>
                  <p class="mt-1.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] text-gray-700">
                    <strong>Subdivisiones:</strong> escríbalas en la misma materia, separadas por <kbd class="rounded border border-gray-300 bg-white px-1 font-mono">--</kbd>,
                    en el orden <strong>tema -- aspecto -- lugar -- época</strong>. Ej.: <span class="font-mono">MAPUCHES -- VIDA SOCIAL Y COSTUMBRES</span>,
                    <span class="font-mono">EDUCACION -- CHILE</span>, <span class="font-mono">MAPUCHES -- HISTORIA -- 1860-1970</span>.
                    Es mejor que dejar solo el tema: el libro se encuentra con más precisión.
                  </p>
                </div>
                <div v-if="tiene('contenido')" class="sm:col-span-6">
                  <label for="cat-contenido" :class="ETIQUETA"><Marc :codigo="etiqueta('contenido')" tono="pizarra" /> Nota de contenido</label>
                  <textarea id="cat-contenido" v-model="form.contenido" rows="3" :placeholder="PISTAS.contenido" :class="CAMPO"></textarea>
                  <p class="mt-1 text-[11px] text-gray-500">Índice de capítulos o de volúmenes · se muestra en el portal</p>
                </div>
                <div v-if="tiene('resumen')" class="sm:col-span-6">
                  <label for="cat-resumen" :class="ETIQUETA"><Marc :codigo="etiqueta('resumen')" tono="pizarra" /> Resumen</label>
                  <textarea id="cat-resumen" v-model="form.resumen" rows="3" :placeholder="PISTAS.resumen" :class="CAMPO"></textarea>
                </div>
                <div v-if="tiene('audiencia')" class="sm:col-span-3">
                  <label for="cat-audiencia" :class="ETIQUETA"><Marc :codigo="etiqueta('audiencia')" tono="pizarra" /> Audiencia</label>
                  <input autocomplete="off" id="cat-audiencia" v-model="form.audiencia" :placeholder="PISTAS.audiencia" :class="CAMPO" />
                </div>
                <template v-if="extrasMaterias.length">
                  <div v-if="!verMasMaterias" class="sm:col-span-6">
                    <button
                      type="button"
                      class="inline-flex items-center gap-2 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-xs font-semibold text-pizarra-600 transition-colors hover:bg-slate-50"
                      @click="verMasMaterias = true"
                    >
                      <span class="text-sm leading-none">+</span> {{ textoExtras(extrasMaterias) }}
                    </button>
                  </div>
                  <template v-else>
                    <div v-for="c in extrasMaterias" :key="c" :class="c === 'url_acceso' || c === 'otros_enlaces' ? 'sm:col-span-6' : 'sm:col-span-3'">
                      <label :for="`cat-${c}`" :class="ETIQUETA"><Marc :codigo="etiqueta(c)" tono="pizarra" /> {{ NOMBRES[c] }}</label>
                      <input autocomplete="off" v-if="c === 'url_acceso'" :id="`cat-${c}`" v-model="form.url_acceso" type="url" :placeholder="PISTAS.url_acceso" :class="CAMPO" />
                      <textarea v-else :id="`cat-${c}`" v-model="form[c]" rows="2" :placeholder="PISTAS[c]" :class="CAMPO"></textarea>
                    </div>
                  </template>
                </template>
              </div>
            </SeccionFicha>

            <!-- 5. Ejemplar y datos internos -->
            <SeccionFicha id="seccion-ejemplar" :numero="numeroDe('ejemplar')" titulo="Colección y notas" :detalle="seccionDe('ejemplar')?.detalle ?? ''" tono="violeta">
              <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                <div v-if="false" class="sm:col-span-2">
                  <label for="cat-ubicacion" :class="ETIQUETA">Ubicación física</label>
                  <select id="cat-ubicacion" v-model="form.ubicacion_id" :class="CAMPO">
                    <option value="">Sin especificar</option>
                    <option v-for="u in ubicacionesOpciones" :key="u.id" :value="u.id">{{ u.nombre }}</option>
                  </select>
                </div>
                <div class="sm:col-span-2">
                  <label for="cat-coleccion" :class="ETIQUETA">Colección</label>
                  <input autocomplete="off" id="cat-coleccion" v-model="form.coleccion" list="colecciones" placeholder="General, Referencia…" :class="CAMPO" />
                </div>
                <div v-if="false" class="sm:col-span-2">
                  <label for="cat-volumen" :class="ETIQUETA">Volumen</label>
                  <input autocomplete="off" id="cat-volumen" v-model="form.volumen" placeholder="Tomo I" :class="CAMPO" />
                </div>
                <div class="sm:col-span-4">
                  <p :class="ETIQUETA">Carreras asociadas</p>
                  <MultiSelectCombobox v-model="form.carreras" :opciones="carrerasOpciones" placeholder="Buscar carrera" :clase-ficha="TONOS_FICHA.violeta.chip" />
                  <p class="mt-1 text-[11px] text-gray-500">A qué carreras sirve este título. Los estudiantes las ven en la ficha, en el portal. Si no está, escríbela y presiona Enter.</p>
                </div>
                <div v-if="false" class="sm:col-span-2">
                  <label for="cat-precio" :class="ETIQUETA">Precio (CLP)</label>
                  <div class="relative">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                    <input autocomplete="off" id="cat-precio" v-model="form.precio" type="number" min="0" placeholder="0" :class="[CAMPO, 'pl-7']" />
                  </div>
                </div>
                <div v-if="false" class="sm:col-span-2">
                  <label for="cat-adquisicion" :class="ETIQUETA">Forma de adquisición</label>
                  <select id="cat-adquisicion" v-model="form.forma_adquisicion" :class="CAMPO">
                    <option value="">Sin especificar</option>
                    <option v-for="f in FORMAS_ADQUISICION" :key="f.valor" :value="f.valor">{{ f.nombre }}</option>
                  </select>
                </div>
                <template v-if="false">
                  <div class="sm:col-span-6">
                    <label for="cat-copia-nota-publica" :class="ETIQUETA">Nota pública de la copia (la ve el usuario en el portal)</label>
                    <textarea id="cat-copia-nota-publica" v-model="form.copia_nota_publica" rows="2" placeholder="Ej: Incluye CD · se ve en el portal" :class="CAMPO"></textarea>
                  </div>
                  <div class="sm:col-span-6">
                    <label for="cat-copia-nota-interna" :class="ETIQUETA">Nota interna de la copia (solo la ve el personal)</label>
                    <textarea id="cat-copia-nota-interna" v-model="form.copia_nota_interna" rows="2" placeholder="Solo la ve el personal" :class="CAMPO"></textarea>
                  </div>
                </template>
                <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500 sm:col-span-6">
                  Esta es la <strong>ficha bibliográfica</strong> (el registro del título), sin código de barras. El código, la ubicación, el volumen,
                  el precio y la forma de adquisición son de cada copia: se registran en <strong>«Nuevo ejemplar»</strong>.
                  Una ficha sin ejemplares no se muestra en el portal.
                </p>
                <div v-if="notaPublica" class="sm:col-span-6">
                  <label for="cat-nota_publica" :class="ETIQUETA"><Marc :codigo="etiqueta('nota_publica')" tono="dorado" /> {{ NOMBRES.nota_publica }}</label>
                  <textarea id="cat-nota_publica" v-model="form.nota_publica" rows="2" :placeholder="PISTAS.nota_publica" :class="CAMPO"></textarea>
                </div>
                <div v-for="c in notasInternas" :key="c" class="sm:col-span-6">
                  <label :for="`cat-${c}`" :class="ETIQUETA"><Marc :codigo="etiqueta(c)" tono="violeta" /> {{ NOMBRES[c] }}</label>
                  <textarea :id="`cat-${c}`" v-model="form[c]" rows="2" :placeholder="PISTAS[c] ?? 'Opcional'" :class="CAMPO"></textarea>
                </div>
              </div>
            </SeccionFicha>
          </template>

          <template v-else>
            <!-- 1. Obra -->
            <SeccionFicha id="seccion-obra" :numero="1" titulo="Obra" detalle="Busca el título ya catalogado" tono="indigo">
              <label for="cat-buscar-obra" :class="ETIQUETA">Título, autor, ISBN, código de barras o N.º de ficha</label>
              <div class="relative">
                <input autocomplete="off"
                  id="cat-buscar-obra"
                  v-model="busquedaCopia"
                  type="text"
                  placeholder="Ej: Fundamentos de bases de datos"
                  :class="CAMPO"
                  @input="onBuscarCopiaInput"
                />
                <div v-if="resultadosCopia.length" class="absolute z-10 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                  <button
                    v-for="l in resultadosCopia"
                    :key="l.id"
                    type="button"
                    class="w-full border-b border-gray-100 px-3 py-2.5 text-left text-sm last:border-0 hover:bg-indigo-50"
                    @click="seleccionarLibroParaCopia(l)"
                  >
                    <p class="font-medium text-gray-900">{{ l.titulo }}</p>
                    <p class="text-xs text-gray-500">
                      <span class="font-mono text-gray-700">Ficha N.º {{ l.numero_ficha }}</span> ·
                      <template v-if="l.autores?.length">{{ l.autores.map((a) => a.nombre).join('; ') }} · </template>{{ l.ejemplares_count ?? l.ejemplares?.length ?? 0 }} copia(s)
                    </p>
                    <p v-if="l.ejemplares?.length" class="mt-0.5 truncate font-mono text-[11px] text-gray-400">
                      {{ l.ejemplares.map((e) => e.codigo_barras).join(' · ') }}
                    </p>
                  </button>
                </div>
              </div>
              <div v-if="libroParaCopia" class="mt-4 flex items-start justify-between gap-3 rounded-xl border border-indigo-100 bg-indigo-50/60 p-3">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-gray-900">{{ libroParaCopia.titulo }}</p>
                  <p class="mt-0.5 text-xs font-semibold text-indigo-800">Ficha N.º <span class="font-mono">{{ libroParaCopia.numero_ficha }}</span></p>
                  <p class="text-xs text-gray-500">{{ libroParaCopia.ejemplares_count ?? libroParaCopia.ejemplares?.length ?? 0 }} copia(s) ya catalogada(s)</p>
                  <div v-if="libroParaCopia.ejemplares?.length" class="mt-2 flex flex-wrap gap-1">
                    <span
                      v-for="e in libroParaCopia.ejemplares"
                      :key="e.id"
                      class="whitespace-nowrap rounded-full border border-indigo-100 bg-white px-2 py-0.5 font-mono text-[11px] text-gray-600"
                    >
                      {{ e.etiqueta_copia ? `${e.etiqueta_copia} · ` : '' }}{{ e.codigo_barras }}
                    </span>
                  </div>
                </div>
                <button type="button" class="shrink-0 text-xs font-medium text-indigo-700 hover:underline" @click="cambiarObra">Cambiar</button>
              </div>
            </SeccionFicha>

            <!-- 2. Copia nueva -->
            <SeccionFicha id="seccion-copia" :numero="2" titulo="Copia nueva" detalle="Datos de la copia física · solo uso interno" tono="violeta">
              <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                  <label for="copia-codigo" :class="ETIQUETA">Código de barras <span class="text-red-600">*</span></label>
                  <div class="flex gap-2">
                    <input autocomplete="off" id="copia-codigo" v-model="formCopia.codigo_barras" placeholder="30000003227566" :class="[CAMPO, 'font-mono']" />
                    <button
                      type="button"
                      class="shrink-0 rounded-lg border border-gray-300 bg-white px-3 text-gray-600 transition-colors hover:bg-gray-50"
                      title="Generar el siguiente código"
                      aria-label="Generar el siguiente código de barras"
                      @click="generarCodigoBarras('copia')"
                    >
                      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                      </svg>
                    </button>
                  </div>
                </div>
                <div class="sm:col-span-3">
                  <label for="copia-ubicacion" :class="ETIQUETA">Ubicación física</label>
                  <select id="copia-ubicacion" v-model="formCopia.ubicacion_id" :class="CAMPO">
                    <option value="">Sin especificar</option>
                    <option v-for="u in ubicacionesOpciones" :key="u.id" :value="u.id">{{ u.nombre }}</option>
                  </select>
                </div>
                <div class="sm:col-span-3">
                  <label for="copia-tomo" :class="ETIQUETA">Tomo <span class="font-normal text-gray-400">(si la obra tiene varios)</span></label>
                  <input autocomplete="off" id="copia-tomo" v-model="formCopia.tomo" list="tomos-de-la-obra" placeholder="t.1" :class="CAMPO" />
                  <datalist id="tomos-de-la-obra">
                    <option v-for="t in tomosDeLaObra" :key="t.tomo" :value="t.tomo">{{ t.copias }} {{ t.copias === 1 ? 'copia' : 'copias' }}</option>
                  </datalist>
                  <p v-if="copiaDelTomo === 0" class="mt-1 text-[11px] font-semibold text-indigo-700">
                    Quedará como <span class="font-mono">{{ normalizarTomo(formCopia.tomo) }}</span> (la primera de ese tomo, sin «c.»)
                  </p>
                  <p v-else-if="copiaDelTomo" class="mt-1 text-[11px] font-semibold text-indigo-700">
                    Quedará como <span class="font-mono">{{ normalizarTomo(formCopia.tomo) }} c.{{ copiaDelTomo }}</span> (copia {{ copiaDelTomo }} de ese tomo)
                  </p>
                  <p v-else-if="tomosDeLaObra.length" class="mt-1 text-[11px] text-gray-500">
                    Tomos de esta obra: {{ tomosDeLaObra.map((t) => `${t.tomo} (${t.copias})`).join(' · ') }}
                  </p>
                </div>
                <div class="sm:col-span-3">
                  <label for="copia-volumen" :class="ETIQUETA">Volumen u otra designación</label>
                  <input
                    autocomplete="off"
                    id="copia-volumen"
                    v-model="formCopia.volumen"
                    :disabled="!!formCopia.tomo.trim()"
                    :placeholder="formCopia.tomo.trim() ? 'Se arma con el tomo' : 'Ej: Atlas, Anexo'"
                    :class="[CAMPO, 'disabled:bg-gray-50 disabled:text-gray-400']"
                  />
                </div>
                <div class="sm:col-span-3">
                  <label for="copia-precio" :class="ETIQUETA">Precio (CLP)</label>
                  <div class="relative">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                    <input autocomplete="off" id="copia-precio" v-model="formCopia.precio" type="number" min="0" placeholder="0" :class="[CAMPO, 'pl-7']" />
                  </div>
                </div>
                <div class="sm:col-span-3">
                  <label for="copia-adquisicion" :class="ETIQUETA">Forma de adquisición</label>
                  <select id="copia-adquisicion" v-model="formCopia.forma_adquisicion" :class="CAMPO">
                    <option value="">Sin especificar</option>
                    <option v-for="f in FORMAS_ADQUISICION" :key="f.valor" :value="f.valor">{{ f.nombre }}</option>
                  </select>
                </div>
                <div class="sm:col-span-3">
                  <label for="copia-coleccion" :class="ETIQUETA">Colección de la copia</label>
                  <input autocomplete="off" id="copia-coleccion" v-model="formCopia.coleccion" list="colecciones" :placeholder="libroParaCopia?.coleccion ? `${libroParaCopia.coleccion} (la del libro)` : 'La del libro'" :class="CAMPO" />
                  <p class="mt-1 text-[11px] text-gray-500">Vacía, vale la colección del libro.</p>
                </div>
                <div class="sm:col-span-6">
                  <label for="copia-nota-publica" :class="ETIQUETA">Nota pública de la copia (la ve el usuario en el portal)</label>
                  <textarea id="copia-nota-publica" v-model="formCopia.nota_publica" rows="2" placeholder="Ej: Incluye CD · se ve en el portal" :class="CAMPO"></textarea>
                </div>
                <div class="sm:col-span-6">
                  <label for="copia-nota-interna" :class="ETIQUETA">Nota interna de la copia (solo la ve el personal)</label>
                  <textarea id="copia-nota-interna" v-model="formCopia.nota_interna" rows="2" placeholder="Solo la ve el personal" :class="CAMPO"></textarea>
                </div>
              </div>
            </SeccionFicha>
          </template>
        </div>

        <!-- Vista previa, obligatorios y acciones -->
        <aside class="space-y-4 lg:sticky lg:top-6 lg:self-start">
          <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <p class="flex items-center gap-2 rounded-t-2xl border-b border-teal-100 bg-teal-50 px-4 py-2.5 text-[10px] font-bold uppercase tracking-wider text-teal-800">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
              Así se verá en el portal
            </p>
            <div class="p-4">
            <template v-if="vista">
              <div class="flex items-start gap-3">
                <PortadaMaterial class="shadow-sm" :titulo="vista.titulo" :tipo="vista.tipo" :clave-tono="vista.claveTono" />
                <div class="min-w-0">
                  <p class="text-sm font-semibold leading-snug" :class="vista.vacio ? 'text-gray-400' : 'text-gray-900'">{{ vista.titulo }}</p>
                  <p v-if="vista.autores" class="mt-0.5 truncate text-xs text-gray-600">{{ vista.autores }}</p>
                  <p v-if="vista.detalle" class="mt-0.5 text-xs text-gray-500">{{ vista.detalle }}</p>
                </div>
              </div>
              <p class="mt-3 flex items-center gap-1.5 text-xs font-semibold" :class="vista.estado.color">
                <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="vista.estado.punto"></span>
                {{ vista.estado.texto }}
              </p>
              <p class="mt-0.5 text-xs text-gray-500">{{ vista.estado.detalle }}</p>
              <p v-if="vista.estado.nota" class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-[11px] leading-relaxed text-gray-500">{{ vista.estado.nota }}</p>
            </template>
            <p v-else class="text-sm text-gray-400">Elige la obra para ver cómo aparece.</p>
            </div>
          </div>

          <div v-if="obligatorios.length" class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <p class="flex items-center justify-between gap-2 rounded-t-2xl border-b border-amber-100 bg-amber-50 px-4 py-2.5 text-[10px] font-bold uppercase tracking-wider text-dorado-700">
              Obligatorios
              <span class="rounded-full border border-amber-200 bg-white px-2 py-0.5 text-[10px] tabular-nums">{{ obligatorios.filter((o) => o.listo).length }} de {{ obligatorios.length }}</span>
            </p>
            <ul class="space-y-2 p-4">
              <li v-for="o in obligatorios" :key="o.texto">
                <button
                  type="button"
                  class="flex items-center gap-2 text-left text-sm transition-colors"
                  :class="o.listo ? 'text-gray-800' : 'text-gray-500 hover:text-gray-800'"
                  @click="irA(o.seccion)"
                >
                  <svg v-if="o.listo" class="h-4 w-4 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                  </svg>
                  <span v-else class="h-4 w-4 shrink-0 rounded-full border-2 border-gray-300"></span>
                  {{ o.texto }}
                </button>
              </li>
            </ul>
          </div>

          <div class="space-y-2">
            <button
              type="button"
              class="w-full rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-md transition hover:brightness-110 disabled:opacity-60"
              style="background: linear-gradient(135deg, #3B28A3 0%, #4F46E5 100%);"
              :disabled="guardando"
              @click="accionPrincipal"
            >
              {{ textoAccion }}
            </button>
            <button
              v-if="modo === 'nuevo' && !editandoId"
              type="button"
              class="w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-800 transition-colors hover:bg-gray-50 disabled:opacity-50"
              :disabled="formularioVacio"
              @click="confirmandoBorrador = true"
            >
              Guardar borrador
            </button>
            <button
              v-if="modo === 'nuevo' && editandoId"
              type="button"
              class="w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-800 transition-colors hover:bg-gray-50"
              @click="resetForm"
            >
              Cancelar edición
            </button>
            <button
              v-if="modo === 'nuevo' && editandoId && puedeCambiarEstado"
              type="button"
              class="w-full rounded-xl border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 transition-colors hover:bg-red-50"
              @click="pedirBajaTitulo"
            >
              Dar de baja el título
            </button>
            <p class="px-2 text-center text-[11px] leading-relaxed text-gray-500">{{ notaCatalogador }}</p>
            <p v-if="modo === 'nuevo' && !editandoId && borrador && desdeBorrador" class="px-2 text-center text-[11px] text-gray-400">
              Borrador guardado en este computador el {{ fechaBorrador }}.
            </p>
          </div>
        </aside>
      </div>

      <!-- Títulos ya catalogados -->
      <section class="mt-8 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="text-[15px] font-semibold text-gray-900">Títulos catalogados</h2>
            <p class="text-xs text-gray-500">«Editar» carga la ficha arriba para corregirla.</p>
          </div>
          <div class="relative w-full sm:max-w-xs">
            <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
            <input autocomplete="off"
              v-model="busqueda"
              type="text"
              placeholder="Título, autor, código o N.º de ficha"
              :class="[CAMPO, 'pl-10']"
              aria-label="Buscar títulos catalogados"
              @input="onBuscarInput"
            />
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="border-b border-gray-200 bg-gray-50">
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">N.º ficha</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Título</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Clasificación</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Copias</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="l in libros" :key="l.id" class="align-top transition-colors hover:bg-indigo-50/40" :class="editandoId === l.id ? 'bg-amber-50/60' : ''">
                <td class="px-5 py-3 font-mono text-sm font-semibold text-gray-700">{{ l.numero_ficha }}</td>
                <td class="px-5 py-3 text-sm text-gray-900">
                  {{ l.titulo }}
                  <p v-if="l.autores?.length" class="text-xs text-gray-500">{{ l.autores.map((a) => a.nombre).join(' · ') }}</p>
                </td>
                <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ l.clasificacion ?? '—' }}</td>
                <td class="px-5 py-3">
                  <div class="flex flex-wrap gap-1">
                    <span
                      v-for="e in l.ejemplares"
                      :key="e.id"
                      class="whitespace-nowrap rounded-full px-2 py-0.5 font-mono text-xs font-medium"
                      :class="estadoBadges[e.estado_proceso]?.cls"
                      :title="`${nombreDeCopia(e) ?? e.codigo_barras} · ${estadoBadges[e.estado_proceso]?.label ?? e.estado_proceso}`"
                    >
                      {{ e.etiqueta_copia ? `${e.etiqueta_copia} · ` : '' }}{{ e.codigo_barras }}
                    </span>
                  </div>
                </td>
                <td class="px-5 py-3">
                  <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <button type="button" class="text-sm font-medium text-indigo-700 hover:text-indigo-800" @click="libroAEditar = l">Editar</button>
                    <button type="button" class="text-sm font-medium text-emerald-700 hover:text-emerald-800" @click="agregarCopiaDe(l)">Agregar copia</button>
                  </div>
                </td>
              </tr>
              <tr v-if="!cargando && !libros.length">
                <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-400">Sin títulos que coincidan con la búsqueda.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <Paginador
          class="border-t border-gray-100 px-5 py-3"
          :pagina="metaLibros.current_page"
          :paginas="metaLibros.last_page"
          :total="metaLibros.total"
          :desde="metaLibros.from"
          :hasta="metaLibros.to"
          unidad="títulos"
          :cargando="cargando"
          @update:pagina="irAPaginaLibros"
        />
      </section>
      </template>

      <datalist id="colecciones">
        <option value="General" />
        <option value="Referencia" />
        <option value="Hemeroteca" />
      </datalist>
    </div>

    <DialogoConfirmacionEscrita
      :abierto="bajaTituloAbierta"
      titulo="¿Dar de baja este título?"
      confirmar="Dar de baja el título"
      :cargando="dandoDeBaja"
      :deshabilitado="!bajaMotivo.trim()"
      @confirmar="darDeBajaTitulo"
      @cancelar="bajaTituloAbierta = false"
    >
      <p>
        <strong class="text-gray-900">{{ libroEditado?.titulo }}</strong> y <strong class="text-gray-900">todas sus copias</strong> saldrán de circulación y dejarán de
        verse en el catálogo y el portal. Si alguna está prestada porque se perdió, su préstamo queda cerrado (sin multa automática) y la lista de espera se cancela.
        Nada se borra: se puede reincorporar copia por copia desde Administración › Eliminados.
      </p>
      <div>
        <label for="baja-motivo" class="mb-1.5 block text-xs font-semibold text-gray-700">Motivo de la baja (obligatorio)</label>
        <input autocomplete="off"
          id="baja-motivo"
          v-model="bajaMotivo"
          type="text"
          maxlength="500"
          placeholder="Ej: edición obsoleta, o todas las copias perdidas"
          class="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
        />
      </div>
    </DialogoConfirmacionEscrita>

    <DialogoConfirmacion
      :abierto="confirmandoGuardar"
      :titulo="editandoId ? '¿Guardar los cambios de la ficha?' : '¿Catalogar este material?'"
      :confirmar="editandoId ? 'Sí, guardar' : 'Sí, crear ficha'"
      :cargando="guardando"
      destacado
      ancho="md"
      @confirmar="confirmarGuardar"
      @cancelar="confirmandoGuardar = false"
    >
      <template v-if="!editandoId" #antesDelTitulo><PasosCatalogacion :actual="1" /></template>
      <p v-if="editandoId" class="text-[15px] text-gray-600">
        Se guardarán los cambios de <strong class="text-gray-900">{{ tituloAGuardar }}</strong>. Se ven de inmediato en el listado y, si tiene
        copias en estante, en el portal.
      </p>
      <template v-else>
        <p class="text-[15px] text-gray-600">Se creará la ficha bibliográfica:</p>
        <div class="mt-3 flex items-start gap-3.5 rounded-xl border border-gray-200 bg-gray-50/60 p-4">
          <PortadaMaterial class="shadow-sm" :titulo="tituloAGuardar" :tipo="tipoActual?.nombre ?? null" :clave-tono="vista?.claveTono ?? null" />
          <div class="min-w-0">
            <p class="text-base font-bold leading-snug text-gray-900">{{ tituloAGuardar }}</p>
            <p v-if="form.autores.length" class="mt-0.5 truncate text-[15px] text-gray-600">{{ form.autores.join(' · ') }}</p>
            <p class="mt-2 flex flex-wrap gap-1.5 text-[13px] font-semibold">
              <span class="rounded-md bg-indigo-50 px-2 py-0.5 text-indigo-700">{{ tipoActual?.nombre ?? 'Libro' }}</span>
              <span class="rounded-md bg-amber-50 px-2 py-0.5 text-amber-800">Sin ejemplares</span>
            </p>
          </div>
        </div>
        <p class="mt-3 rounded-lg bg-gray-50 px-4 py-3 text-[15px] leading-relaxed text-gray-600">
          Después se pasa a <strong class="text-gray-900">«Nuevo ejemplar»</strong> para agregarle sus copias con código de barras.
        </p>
      </template>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="confirmandoCopia && !!libroParaCopia"
      titulo="¿Agregar esta copia?"
      confirmar="Sí, agregar"
      :cargando="guardando"
      destacado
      ancho="md"
      @confirmar="confirmarAgregarCopia"
      @cancelar="confirmandoCopia = false"
    >
      <template #antesDelTitulo><PasosCatalogacion :actual="2" /></template>
      <p class="text-[15px] text-gray-600">Se agregará una copia nueva de <strong class="text-gray-900">{{ libroParaCopia?.titulo }}</strong>:</p>
      <div class="mt-3 rounded-xl border border-l-4 border-gray-200 border-l-indigo-600 bg-gray-50/60 px-4 py-3.5">
        <p class="font-mono text-2xl font-semibold tracking-wide text-gray-900">{{ formCopia.codigo_barras.trim() }}</p>
        <p class="mt-2 flex flex-wrap items-center gap-2 text-[13px]">
          <template v-if="ubicacionCopia">
            en <span class="rounded-md border border-gray-200 bg-white px-2 py-0.5 font-semibold text-gray-900">{{ ubicacionCopia }}</span>
          </template>
          nace en <span class="rounded-md bg-indigo-50 px-2 py-0.5 font-semibold text-indigo-700">Inventario</span>
        </p>
      </div>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="!!copiaCreada"
      titulo="¿Cambiar el estado de la copia?"
      confirmar="Ir a cambiar el estado"
      cancelar="Seguir catalogando"
      tono="exito"
      destacado
      ancho="md"
      @confirmar="irACambiarEstado"
      @cancelar="copiaCreada = null"
    >
      <template #antesDelTitulo><PasosCatalogacion :actual="3" /></template>
      <div class="flex items-start gap-3.5 rounded-xl border border-emerald-200 bg-emerald-50/70 p-4">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white" aria-hidden="true">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
        </span>
        <div class="min-w-0">
          <p class="text-[15px] text-emerald-900">
            Se creó <template v-if="copiaCreada?.numeroCopia">la <strong>copia {{ copiaCreada.numeroCopia }}</strong></template><template v-else>una copia</template> de <strong>{{ copiaCreada?.titulo }}</strong>
          </p>
          <p class="mt-2 flex flex-wrap items-center gap-2 text-[13px] text-emerald-900/80">
            código <span class="rounded-md border border-emerald-200 bg-white px-2 py-0.5 font-mono text-sm font-semibold text-gray-900">{{ copiaCreada?.codigoBarras }}</span>
            <template v-if="copiaCreada?.codigoLomo">
              lomo <span class="rounded-md border border-emerald-200 bg-white px-2 py-0.5 font-mono text-sm font-semibold text-gray-900">{{ copiaCreada.codigoLomo }}</span>
            </template>
          </p>
        </div>
      </div>
      <p class="mt-3 flex flex-wrap items-center gap-2 text-[13px] text-gray-600">
        <span class="rounded-md bg-indigo-50 px-2 py-0.5 font-semibold text-indigo-700">{{ copiaCreada?.estado }}</span>
        <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
        <span class="rounded-md border border-dashed border-emerald-400 bg-emerald-50/50 px-2 py-0.5 font-semibold text-emerald-700">En estante</span>
        <span>no se presta hasta pasar a «En estante»</span>
      </p>
      <p class="mt-3 rounded-lg bg-gray-50 px-4 py-3 text-[15px] text-gray-600">Puedes ir ahora a Estado de ejemplares con esta copia ya elegida.</p>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="confirmandoBorrador"
      titulo="¿Guardar el borrador?"
      confirmar="Sí, guardar borrador"
      @confirmar="guardarBorrador"
      @cancelar="confirmandoBorrador = false"
    >
      <p>
        Lo escrito<template v-if="form.titulo.trim()"> de <strong class="text-gray-900">«{{ form.titulo.trim() }}»</strong></template> queda guardado en
        este computador para terminarlo después. Todavía no se agrega al catálogo.
      </p>
      <p v-if="borrador" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
        Reemplaza el borrador guardado el {{ fechaBorrador }}<template v-if="borrador.form.titulo && borrador.form.titulo !== form.titulo.trim()"> («{{ borrador.form.titulo }}»)</template>.
      </p>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="confirmandoRecuperar"
      titulo="¿Recuperar el borrador?"
      confirmar="Sí, recuperar"
      @confirmar="recuperarBorrador"
      @cancelar="confirmandoRecuperar = false"
    >
      <p>
        Lo que tienes escrito ahora se reemplazará por el borrador<template v-if="borrador?.form.titulo"> «{{ borrador.form.titulo }}»</template>,
        guardado el {{ fechaBorrador }}.
      </p>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="!!libroAEditar"
      titulo="¿Editar esta ficha?"
      confirmar="Sí, editar"
      @confirmar="confirmarEditar"
      @cancelar="libroAEditar = null"
    >
      <p>
        Se cargará <strong class="text-gray-900">{{ libroAEditar?.titulo }}</strong> en el formulario para corregirla. Los cambios se guardan
        recién al pulsar «Guardar cambios».
      </p>
      <p v-if="hayTrabajoSinGuardar" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
        Lo que tienes escrito sin guardar se perderá.
      </p>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="confirmandoDescartar"
      titulo="¿Descartar el borrador?"
      confirmar="Sí, descartar"
      tono="peligro"
      @confirmar="descartarBorrador"
      @cancelar="confirmandoDescartar = false"
    >
      <p>Se borrará el borrador guardado en este computador<template v-if="borrador?.form.titulo"> («{{ borrador.form.titulo }}»)</template>. No se puede recuperar.</p>
    </DialogoConfirmacion>
  </StaffLayout>
</template>
