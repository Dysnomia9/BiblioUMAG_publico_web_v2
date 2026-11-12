<script setup lang="ts">
import { leerTomoCopia, nombreDeCopia, numeroDeCopia, partesCopia, textoCopia } from '@/utils/tomo'
import { computed, h, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch, type FunctionalComponent } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import Modal from '@/components/ui/Modal.vue'
import CampoPrecio from '@/components/ui/CampoPrecio.vue'
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
const modo = ref<'nuevo' | 'copia' | 'interbibliotecario' | 'editar_copia'>('nuevo')

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
/** Las colecciones que ya se usan, para sugerirlas tal como están escritas (GET /libros/colecciones). */
const colecciones = ref<{ nombre: string; copias: number }[]>([])
const catalogadorEditado = ref<string | null>(null)

/** Una ficha nueva parte con «Libro» elegido (se puede cambiar, también a «Sin especificar»). */
const tipoPorDefecto = computed<number | ''>(() => tiposMaterialOpciones.value.find((t) => t.nombre === 'Libro')?.id ?? '')
/** El formulario en blanco: el vacío, con el tipo por defecto. */
const formEnBlanco = () => ({ ...formVacio, tipo_material_id: tipoPorDefecto.value })
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
  otros_enlaces: 'https://...',
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
  otros_enlaces: 'Otros enlaces',
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

/**
 * La guía de la clasificación: un ejemplo de cómo están escritas las del catálogo, separado
 * en sus partes. Cada plantilla tiene la suya (sacadas de lo migrado de Horizon):
 * libros «001.42 S116c 1998», tesis «T A116i 1999» (sin clase Dewey), juegos «JE1 V986j 2020»;
 * las revistas se ubican por su nombre, sin partes.
 */
const guiaClasificacion = computed<{ ejemplo: string; partes: { valor: string; parte: string; que: string }[]; nota?: string }>(() => {
  const cutter = (valor: string) => ({ valor, parte: 'Cutter', que: 'autor + inicial del título' })
  const anio = (valor: string, que = 'de la edición') => ({ valor, parte: 'Año', que })
  switch (tipoActual.value?.plantilla) {
    case 'trabajo_titulacion':
      return {
        ejemplo: 'T A473p 2013',
        partes: [{ valor: 'T', parte: 'Prefijo', que: 'T, P, I, TE o D' }, cutter('A473p'), anio('2013', 'de la tesis')],
        nota: 'Las tesis no llevan clase Dewey: el prefijo, el Cutter del autor y el año.',
      }
    case 'juego_educativo':
      return { ejemplo: 'JE1 V986j 2020', partes: [{ valor: 'JE1', parte: 'Código', que: 'del juego' }, cutter('V986j'), anio('2020')] }
    case 'publicacion_seriada':
      return { ejemplo: 'Revista Chilena de Historia', partes: [], nota: 'Las revistas se ubican por su nombre, tal como está en el estante (ej: «Memoria anual»).' }
    default:
      return { ejemplo: '813.54 G216n 2020', partes: [{ valor: '813.54', parte: 'Clase', que: 'el tema (Dewey)' }, cutter('G216n'), anio('2020')] }
  }
})

/**
 * 856 repetible (los demás enlaces, además del de acceso): cada uno en su fila, con la
 * dirección y, si se quiere, un texto. Se guardan uno por línea: «URL texto».
 */
const enlaces = ref<{ url: string; texto: string }[]>([{ url: '', texto: '' }])
function cargarEnlaces() {
  const lista = (form.otros_enlaces ?? '').split(/\r?\n/).map((l) => l.trim()).filter(Boolean)
    .map((l) => {
      const [url, ...texto] = l.split(/\s+/)
      return { url, texto: texto.join(' ') }
    })
  enlaces.value = lista.length ? lista : [{ url: '', texto: '' }]
}
function guardarEnlaces() {
  form.otros_enlaces = enlaces.value.map((e) => `${e.url.trim()} ${e.texto.trim()}`.trim()).filter(Boolean).join('\n')
}
function agregarEnlace() {
  enlaces.value.push({ url: '', texto: '' })
  nextTick(() => document.getElementById(`cat-enlace-${enlaces.value.length - 1}`)?.focus())
}
function quitarEnlace(i: number) {
  enlaces.value.splice(i, 1)
  if (!enlaces.value.length) enlaces.value.push({ url: '', texto: '' })
  guardarEnlaces()
}

/** 490 también es repetible: cada serie en su casilla; se guardan una por línea («Serie Harry Potter ; 1»). */
const series = ref<string[]>([''])
function cargarSeries() {
  const lista = (form.serie ?? '').split(/\r?\n/).map((t) => t.trim()).filter(Boolean)
  series.value = lista.length ? lista : ['']
}
function guardarSeries() {
  form.serie = series.value.map((t) => t.trim()).filter(Boolean).join('\n')
}
function agregarSerie() {
  series.value.push('')
  nextTick(() => document.getElementById(`cat-serie-${series.value.length - 1}`)?.focus())
}
function quitarSerie(i: number) {
  series.value.splice(i, 1)
  if (!series.value.length) series.value.push('')
  guardarSeries()
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
  cargarSeries()
  cargarEnlaces()
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
const SECCIONES_EDITAR_COPIA: Seccion[] = [
  { clave: 'ejemplar', titulo: 'Ejemplar', detalle: 'Escanea el código de la copia', tono: 'indigo' },
  { clave: 'datos', titulo: 'Datos de la copia', detalle: 'Todo lo que se puede corregir · solo uso interno', tono: 'violeta' },
]
const secciones = computed(() => (modo.value === 'copia' ? SECCIONES_COPIA : modo.value === 'editar_copia' ? SECCIONES_EDITAR_COPIA : seccionesNuevo.value))
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
const copiaVacia = () => ({ codigo_barras: '', ubicacion_id: '' as number | '', volumen: '', tomo: '', parte: '', precio: '' as number | '', forma_adquisicion: '', coleccion: '', nota_publica: '', nota_interna: '' })
const formCopia = reactive(copiaVacia())

// ---------- Tomo de la copia (obras en varios tomos, como en Horizon: «t.1 c.2») ----------
/** «Tomo 1», «T1», «t. 1» → «t.1»; «Vol 2» → «v.2» (lo mismo que App\Support\TomoEjemplar). */
/**
 * Lee lo escrito en «Tomo»: solo el tomo («t.1», «Tomo 1», «v.2») o tomo y volumen juntos,
 * como en Horizon («t.1-v.II»). Si trae también la copia («t.1 c.2»), se toma el tomo y se
 * avisa: la copia la numera el sistema. Lo que no es un tomo («t.1 c..2», «primero») no vale:
 * se avisa y no deja guardar (el servidor tampoco lo acepta).
 */
function leerTomo(texto: string): { tomo: string; valido: boolean; traiaCopia: boolean; conVolumen: boolean } {
  const t = texto.replace(/\s+/g, ' ').trim().replace(/\bcopia\s*/gi, 'c.')
  if (!t) return { tomo: '', valido: true, traiaCopia: false, conVolumen: false }
  const leido = leerTomoCopia(t)
  if (!leido) return { tomo: t, valido: false, traiaCopia: false, conVolumen: false }
  return { tomo: leido.clave, valido: true, traiaCopia: !!leido.copia, conVolumen: leido.clave.includes('-') }
}
const tomoLeido = computed(() => leerTomo(formCopia.tomo))
// Sin tomo (o con tomo y volumen ya escritos juntos) no hay segunda parte que guardar
watch(() => formCopia.tomo, () => {
  if (!tomoLeido.value.tomo || tomoLeido.value.conVolumen) formCopia.parte = ''
})
/** El segundo de la pareja: si el tomo es «t.1», un volumen; si es «v.1», un tomo («v.1-t.2», como en Horizon). */
const segundaParte = computed(() => (tomoLeido.value.tomo.startsWith('v') ? { letra: 't', nombre: 'Tomo' } : { letra: 'v', nombre: 'Volumen' }))
/** «II», «2», «v.II», «Volumen 2» → «II» / «2»; vacío si no se escribió; null si no se entiende. */
const parteLeida = computed<string | null>(() => {
  const t = formCopia.parte.trim()
  if (!t) return ''
  const m = t.match(/^(?:(?:t|tomo|v|vol|volumen)\.?\s*)?(\d+|[ivxlc]+)$/i)
  return m ? (/^\d/.test(m[1]) ? m[1] : m[1].toUpperCase()) : null
})
/** El tomo de la copia que se guarda: «t.1», o con el volumen «t.1-v.II» (vacío si no tiene). */
const claveTomo = computed(() => {
  const { tomo, valido, conVolumen } = tomoLeido.value
  if (!tomo || !valido) return tomo
  return parteLeida.value && !conVolumen ? `${tomo}-${segundaParte.value.letra}.${parteLeida.value}` : tomo
})
/** Lo escrito en «Tomo» y «Volumen» se puede guardar. */
const tomoValido = computed(() => tomoLeido.value.valido && parteLeida.value !== null && !(formCopia.parte.trim() && !tomoLeido.value.tomo))
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
/** La copia como se ve en el listado: «Tomo 1 · Copia 2 · 30000…» (sin tomo ni «c.», solo el código). */
const rotuloCopia = (e: Pick<Ejemplar, 'volumen' | 'etiqueta_copia' | 'codigo_barras'>) => {
  const texto = textoCopia(e.volumen, e.etiqueta_copia)
  return texto ? `${texto} · ${e.codigo_barras}` : e.codigo_barras
}

// ---------- Editar ejemplar (pestaña): todos los datos de una copia ya catalogada ----------
// Se llega escaneando su código, con un clic en una copia («Nuevo ejemplar», «Títulos
// catalogados») o con el «Editar» de una copia en el Listado de material (?editar_copia=código).
const codigoAEditar = ref('')
const buscandoCopiaAEditar = ref(false)
const copiaEditando = ref<{ copia: Ejemplar; libro: Libro } | null>(null)
const formEditarVacio = () => ({
  ubicacion_id: '' as number | '',
  // El texto de volumen se arma con estas partes: tomo, volumen dentro del tomo y copia
  // («t.1-v.II c.2»), o es otra designación si no es un tomo (el número de una revista).
  tomo: '',
  parte: '',
  copia: '',
  otra: '',
  coleccion: '',
  precio: '' as number | string,
  forma_adquisicion: '',
  numero_registro: '',
  nota_publica: '',
  nota_interna: '',
})
const formEditar = reactive(formEditarVacio())
const fotoEditar = ref('')
const editarConCambios = computed(() => !!copiaEditando.value && JSON.stringify(formEditar) !== fotoEditar.value)

/** Lo escrito en Tomo, Volumen y Copia, leído; y el texto de volumen que queda («t.1-v.II c.2»). */
const tomoEditar = computed(() => leerTomo(formEditar.tomo))
const parteEditar = computed<string | null>(() => {
  const t = formEditar.parte.trim()
  if (!t) return ''
  const m = t.match(/^(?:(?:t|tomo|v|vol|volumen)\.?\s*)?(\d+|[ivxlc]+)$/i)
  return m ? (/^\d/.test(m[1]) ? m[1] : m[1].toUpperCase()) : null
})
const copiaEditarValida = computed(() => /^(?:c\.?\s*)?\d{0,4}$/i.test(formEditar.copia.trim()))
const volumenEditar = computed(() => {
  if (!formEditar.tomo.trim()) return formEditar.otra.trim()
  const t = tomoEditar.value
  if (!t.valido || parteEditar.value === null) return ''
  const segunda = t.tomo.startsWith('v') ? 't' : 'v'
  const clave = parteEditar.value && !t.conVolumen ? `${t.tomo}-${segunda}.${parteEditar.value}` : t.tomo
  const copia = formEditar.copia.trim().replace(/^c\.?\s*/i, '')
  return copia ? `${clave} c.${copia}` : clave
})
const edicionValida = computed(() => !formEditar.tomo.trim() || (tomoEditar.value.valido && parteEditar.value !== null && copiaEditarValida.value))

/** Abre la copia en la pestaña «Editar ejemplar», con sus datos en el formulario. */
function editarCopia(copia: Ejemplar, libro: Libro) {
  modo.value = 'editar_copia'
  copiaEditando.value = { copia, libro }
  codigoAEditar.value = copia.codigo_barras
  const leido = leerTomoCopia(copia.volumen)
  const [tomo, parte] = (leido?.clave ?? '').split('-')
  Object.assign(formEditar, {
    ubicacion_id: copia.ubicacion_id ?? '',
    tomo: tomo ?? '',
    parte: (parte ?? '').replace(/^[tv]\./, ''),
    copia: leido?.copia ?? '',
    otra: leido ? '' : copia.volumen ?? '',
    coleccion: copia.coleccion ?? '',
    precio: copia.precio ?? '',
    forma_adquisicion: copia.forma_adquisicion ?? '',
    numero_registro: copia.numero_registro ?? '',
    nota_publica: copia.nota_publica ?? '',
    nota_interna: copia.nota_interna ?? '',
  })
  fotoEditar.value = JSON.stringify(formEditar)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

/** Busca la copia por su código de barras (o de lomo) y la abre. */
async function buscarCopiaParaEditar(codigo = codigoAEditar.value) {
  const texto = codigo.trim()
  if (!texto) return
  buscandoCopiaAEditar.value = true
  try {
    const { data } = await api.get<Ejemplar & { libro: Libro }>(`/ejemplares/${encodeURIComponent(texto)}`)
    editarCopia(data, data.libro)
  } catch {
    toast.error(`No hay ninguna copia con el código ${texto}`)
  } finally {
    buscandoCopiaAEditar.value = false
  }
}

/** Suelta la copia (sin guardar) para escanear otra. */
function soltarCopiaEditando() {
  copiaEditando.value = null
  codigoAEditar.value = ''
  Object.assign(formEditar, formEditarVacio())
  fotoEditar.value = ''
  nextTick(() => document.getElementById('editar-copia-codigo')?.focus())
}

async function guardarEdicionCopia() {
  const actual = copiaEditando.value
  if (!actual) {
    toast.error('Primero escanea o escribe el código de la copia')
    irA('ejemplar')
    return
  }
  if (!edicionValida.value) {
    toast.error('Revisa el tomo, el volumen y la copia: mira los ejemplos bajo cada casilla')
    irA('datos')
    return
  }
  guardando.value = true
  try {
    const { data } = await api.patch<Ejemplar>(`/ejemplares/${actual.copia.id}`, {
      ubicacion_id: formEditar.ubicacion_id === '' ? null : formEditar.ubicacion_id,
      volumen: volumenEditar.value || null,
      coleccion: formEditar.coleccion.trim() || null,
      precio: formEditar.precio === '' ? null : Number(formEditar.precio),
      forma_adquisicion: formEditar.forma_adquisicion || null,
      numero_registro: formEditar.numero_registro.trim() || null,
      nota_publica: formEditar.nota_publica.trim() || null,
      nota_interna: formEditar.nota_interna.trim() || null,
    })
    toast.success(`Copia ${data.codigo_barras} actualizada`)
    editarCopia({ ...actual.copia, ...data, libro: undefined }, actual.libro)
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo guardar la copia'))
  } finally {
    guardando.value = false
  }
}

const copiaDelTomo = computed(() => {
  if (!tomoValido.value) return null
  const tomo = claveTomo.value
  if (!tomo) return null
  const delTomo = (libroParaCopia.value?.ejemplares ?? []).filter((e) => e.tomo === tomo)
  if (!delTomo.length) return 0
  return Math.max(0, ...delTomo.map((e) => e.copia_tomo ?? 0)) + 1
})

const obligatoriosCopia = computed<Requisito[]>(() => [
  { texto: 'Obra elegida', listo: !!libroParaCopia.value, seccion: 'obra' },
  { texto: 'Código de barras', listo: !!formCopia.codigo_barras.trim(), seccion: 'copia' },
])
const obligatoriosEditarCopia = computed<Requisito[]>(() => [{ texto: 'Ejemplar elegido', listo: !!copiaEditando.value, seccion: 'ejemplar' }])
const obligatorios = computed(() =>
  modo.value === 'copia' ? obligatoriosCopia.value : modo.value === 'editar_copia' ? obligatoriosEditarCopia.value : obligatoriosNuevo.value,
)

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
  if (modo.value === 'editar_copia') {
    const actual = copiaEditando.value
    if (!actual) return null
    return armarVista(
      {
        titulo: actual.libro.titulo,
        autores: (actual.libro.autores ?? []).map((a) => a.nombre),
        categorias: (actual.libro.categorias ?? []).map((c) => c.nombre),
        tipo: actual.libro.tipo_material?.nombre ?? null,
      },
      estadoDeCopias([actual.copia], 'La nota pública y la ubicación de la copia se ven en el portal; lo demás es solo para el personal.'),
    )
  }
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
  if (modo.value === 'editar_copia') return 'Guardar cambios de la copia'
  return editandoId.value ? 'Guardar cambios' : 'Crear ficha'
})

const notaCatalogador = computed(() => {
  if (modo.value === 'copia') return 'La copia toma el número siguiente y nace en «Inventario».'
  if (modo.value === 'editar_copia') return copiaEditando.value && !editarConCambios.value ? 'Sin cambios por guardar.' : 'El código de barras y el estado no se cambian aquí.'
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
    api.get<{ nombre: string; copias: number }[]>('/libros/colecciones').then(({ data }) => (colecciones.value = data)).catch(() => {})
    carrerasOpciones.value = carrerasRes.data
    ubicacionesOpciones.value = ubicacionesRes.data
    tiposMaterialOpciones.value = tiposMaterialRes.data
    plantillas.value = plantillasRes.data
    // Recién abierta, la ficha nueva queda con «Libro» (no si se está editando o ya se eligió otro)
    if (!editandoId.value && form.tipo_material_id === '' && JSON.stringify(form) === JSON.stringify(formVacio)) {
      form.tipo_material_id = tipoPorDefecto.value
    }
  } catch {
    // Los combobox simplemente no ofrecen sugerencias — no bloquea catalogar.
  }
}

async function abrirDesdeListado() {
  const editarId = Number(route.query.editar)
  const copiaId = Number(route.query.copia)
  // «Editar» de una copia en el Listado de material: la pestaña «Editar ejemplar» con ella
  const codigoCopia = typeof route.query.editar_copia === 'string' ? route.query.editar_copia : ''
  if (codigoCopia) {
    await buscarCopiaParaEditar(codigoCopia)
    router.replace({ query: {} })
    return
  }
  if (!editarId && !copiaId) return
  try {
    const { data } = await api.get<Libro>(`/libros/${editarId || copiaId}`)
    if (editarId) editar(data)
    else agregarCopiaDe(data) // desde «Agregar copia» del listado de material
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

/** Largo: el siguiente de 14 dígitos. Corto: el siguiente de 8, que queda también como su código de lomo. */
async function generarCodigoBarras(destino: 'nuevo' | 'copia', largo: 'largo' | 'corto' = 'largo') {
  try {
    const { data } = await api.get<{ codigo_barras: string }>('/ejemplares/siguiente-codigo-barras', { params: { largo } })
    if (destino === 'nuevo') form.codigo_barras = data.codigo_barras
    else formCopia.codigo_barras = data.codigo_barras
  } catch {
    toast.error('No se pudo generar un código de barras')
  }
}

// ---------- Libro nuevo y edición ----------
function resetForm() {
  Object.assign(form, formEnBlanco(), { autores: [], rolesAutores: {}, categorias: [], carreras: [] })
  editandoId.value = null
  libroEditado.value = null
  catalogadorEditado.value = null
  verMasTitulo.value = false
  titulosAlternativos.value = ['']
  series.value = ['']
  enlaces.value = [{ url: '', texto: '' }]
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

/**
 * La ficha como va a quedar, para revisarla antes de crearla: cada campo lleno de la
 * plantilla, en su orden y con su etiqueta MARC. Los vacíos no se muestran.
 */
const fichaAConfirmar = computed(() => {
  const filas: { etiqueta: string; nombre: string; valor: string; interno: boolean }[] = []
  const agregar = (etiquetaMarc: string, nombre: string, valor: string, interno = false) => {
    if (valor.trim()) filas.push({ etiqueta: etiquetaMarc, nombre, valor: valor.trim(), interno })
  }
  agregar('', 'Tipo de material', tipoActual.value?.nombre ?? 'Libro')
  // Si el primero tiene otro papel (traductor, editor…), no hay autor principal: todos van en 700
  const [primero, ...resto] = form.autores
  const conPrincipal = !!primero && !form.rolesAutores[primero]
  const conPapel = (n: string) => (form.rolesAutores[n] ? `${n} (${nombreRol(form.rolesAutores[n])})` : n)
  const secundarios = (conPrincipal ? resto : form.autores).map(conPapel)
  for (const c of plantillaActual.value?.campos ?? []) {
    const interno = !c.publico
    switch (c.clave) {
      case 'titulo':
        agregar(c.etiqueta, c.nombre, tituloAGuardar.value, interno)
        break
      case 'autor_principal':
        if (conPrincipal) agregar(c.etiqueta, c.nombre, primero, interno)
        break
      case 'autores_secundarios':
        agregar(c.etiqueta, c.nombre, secundarios.join('\n'), interno)
        break
      case 'pie_imprenta': {
        const lugarEditorial = [form.lugar_publicacion.trim(), form.editorial.trim()].filter(Boolean).join(' : ')
        agregar(c.etiqueta, c.nombre, [lugarEditorial, String(form.anio_publicacion ?? '').trim()].filter(Boolean).join(', '), interno)
        break
      }
      case 'materias':
        agregar(c.etiqueta, c.nombre, form.categorias.join('\n'), interno)
        break
      case 'catalogador':
      case 'creacion_registro':
        break // los pone el sistema al crearla
      default:
        if ((CAMPOS_TEXTO as readonly string[]).includes(c.clave)) agregar(c.etiqueta, c.nombre, form[c.clave as CampoTexto] ?? '', interno)
    }
  }
  agregar('', 'Carreras', form.carreras.join('\n'))
  agregar('', 'Colección', form.coleccion)
  return filas
})
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
const copiaCreada = ref<{ titulo: string; numeroCopia: string | null; rotulo: string | null; codigoBarras: string; codigoLomo: string | null; estado: string } | null>(null)
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
function avisarCopiaCreada(titulo: string, copia: Pick<Ejemplar, 'etiqueta_copia' | 'codigo_barras' | 'codigo_lomo'> & { volumen?: string | null } | undefined, estado = 'Inventario') {
  if (!copia || !puedeCambiarEstado.value) return
  copiaCreada.value = { titulo, numeroCopia: numeroDeCopia(copia), rotulo: textoCopia(copia.volumen, copia.etiqueta_copia), codigoBarras: copia.codigo_barras, codigoLomo: copia.codigo_lomo ?? null, estado }
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
      confirmandoGuardar.value = false
      resetForm()
      // No se pasa solo a «Nuevo ejemplar» (las copias las suele agregar otra persona): un menú
      // con la ficha creada y su N.º, que se queda hasta elegir: otra ficha, sus ejemplares o editarla.
      fichaCreada.value = {
        libro: data,
        numero: data.numero_ficha,
        titulo: data.titulo,
        autores: (data.autores ?? []).map((a) => a.nombre),
        tipo: data.tipo_material?.nombre ?? null,
        plantilla: data.tipo_material?.plantilla ?? null,
        claveTono: data.categorias?.[0]?.nombre ?? data.tipo_material?.nombre ?? data.titulo,
      }
      numeroCopiado.value = false
      window.scrollTo({ top: 0, behavior: 'smooth' })
      await Promise.all([cargar(), cargarCatalogos()])
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

const formularioVacio = computed(() => JSON.stringify(form) === JSON.stringify(formEnBlanco()) || JSON.stringify(form) === JSON.stringify(formVacio))

/** Lo escrito que se perdería al cargar otra ficha o el borrador. */
const hayTrabajoSinGuardar = computed(() => {
  if (modo.value === 'editar_copia') return editarConCambios.value
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
// Por qué dato se busca la obra: en todo a la vez, o solo en uno de sus números (el
// servidor busca solo en ese campo: «buscar_en» de GET /libros).
type BuscarObraEn = 'ficha' | 'resto'
const MODOS_BUSCAR_OBRA: { valor: BuscarObraEn; texto: string; escribir: string; ayuda: string; placeholder: string; numerico: boolean }[] = [
  {
    valor: 'ficha',
    texto: 'N.º de ficha',
    escribir: 'El número de la ficha (ej: «48760»). También sirve pegarlo tal como se copia de la pantalla: «Ficha N.º 48760».',
    ayuda: 'Es el número único que pone el sistema (en las fichas que vienen de Horizon, su bib#). Trae solo esa ficha, exacta: es lo más rápido si lo tienes.',
    placeholder: 'Ej: 48760',
    numerico: true,
  },
  {
    valor: 'resto',
    texto: 'Código de barras, ISBN o nombre',
    escribir: 'El código de barras de una copia que la obra ya tiene (escanéalo o escribe sus números), su ISBN con o sin guiones, o palabras del título o del autor.',
    ayuda: 'Busca en todo lo demás de la ficha y sus copias: título, autor, ISBN, clasificación, código de barras, código de lomo y N.º de registro.',
    placeholder: 'Ej: 30000000655316, 956-15-1167-4 o Desolación',
    numerico: false,
  },
]
const buscarObraEn = ref<BuscarObraEn>('ficha')
const modoBuscarObra = computed(() => MODOS_BUSCAR_OBRA.find((m) => m.valor === buscarObraEn.value) ?? MODOS_BUSCAR_OBRA[0])
function elegirBuscarObraEn(valor: BuscarObraEn) {
  buscarObraEn.value = valor
  resultadosCopia.value = []
  onBuscarCopiaInput()
  nextTick(() => document.getElementById('cat-buscar-obra')?.focus())
}

let buscarCopiaTimer: ReturnType<typeof setTimeout> | undefined
function onBuscarCopiaInput() {
  clearTimeout(buscarCopiaTimer)
  // Se borró o cambió el título de la obra elegida: se suelta y se busca de nuevo (sin apretar «Cambiar»)
  if (libroParaCopia.value && busquedaCopia.value.trim() !== libroParaCopia.value.titulo) libroParaCopia.value = null
  buscarCopiaTimer = setTimeout(async () => {
    const texto = busquedaCopia.value.trim()
    const en = buscarObraEn.value
    if (!texto) {
      resultadosCopia.value = []
      return
    }
    if (esBusquedaCorta(texto)) return
    try {
      const { data } = await api.get<Paginado<Libro>>('/libros', { params: { q: texto, buscar_en: en, por_pagina: 15 } })
      // Si mientras tanto se siguió escribiendo, se cambió por qué buscar o se eligió una obra, esta respuesta ya no sirve.
      if (texto === busquedaCopia.value.trim() && en === buscarObraEn.value) resultadosCopia.value = data.data
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
  // En la casilla queda el nombre: si se borra o se sigue escribiendo, se busca por nombre (no por N.º de ficha)
  buscarObraEn.value = 'resto'
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function cambiarObra() {
  libroParaCopia.value = null
  busquedaCopia.value = ''
}

/** La ficha recién creada, para el menú «Ficha creada»: hasta elegir editarla o catalogar otra. */
const fichaCreada = ref<{ libro: Libro; numero: number; titulo: string; autores: string[]; tipo: string | null; plantilla: string | null; claveTono: string | null } | null>(null)
const numeroCopiado = ref(false)
function editarFichaCreada() {
  const libro = fichaCreada.value?.libro
  fichaCreada.value = null
  if (libro) editar(libro)
}
/** Si quien la creó también le agrega las copias: a «Nuevo ejemplar» con ella elegida. */
function agregarEjemplaresAFichaCreada() {
  const libro = fichaCreada.value?.libro
  fichaCreada.value = null
  if (libro) agregarCopiaDe(libro)
}
/** El formulario ya quedó en blanco al crearla: solo se cierra el menú y se va al título. */
function catalogarOtraFicha() {
  fichaCreada.value = null
  window.scrollTo({ top: 0, behavior: 'smooth' })
  nextTick(() => document.getElementById('cat-titulo')?.focus())
}
async function copiarNumeroFicha() {
  if (!fichaCreada.value) return
  try {
    await navigator.clipboard.writeText(`Ficha N.º ${fichaCreada.value.numero}`)
    numeroCopiado.value = true
  } catch {
    toast.error('No se pudo copiar: anótalo a mano')
  }
}

/**
 * La obra elegida es material electrónico: un «Libro electrónico» o una tesis con enlace de
 * acceso (856). Se lee en línea, así que normalmente no lleva copias; se avisa (no se impide)
 * por si de verdad hay una copia física, como una tesis impresa o en CD.
 */
const avisoElectronico = computed(() => {
  const l = libroParaCopia.value
  if (!l) return null
  if (l.tipo_material?.nombre === 'Libro electrónico') return 'un libro electrónico'
  if (l.tipo_material?.plantilla === 'trabajo_titulacion' && l.url_acceso) return 'una tesis electrónica'
  return null
})

const confirmandoCopia = ref(false)
const ubicacionCopia = computed(() => ubicacionesOpciones.value.find((u) => u.id === formCopia.ubicacion_id)?.nombre ?? null)

function pedirConfirmacionCopia() {
  if (!tomoValido.value) {
    toast.error('Revisa el tomo y el volumen: por ejemplo «t.1» y «II». El número de copia lo pone el sistema')
    document.getElementById('copia-tomo')?.focus()
    return
  }
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
      tomo: claveTomo.value || null,
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
  { id: 'editar_copia' as const, label: 'Editar ejemplar', icono: 'M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM16.5 13.5v6m3-3h-6', tono: 'bg-violet-50 text-violet-700', activo: 'border-violet-600 bg-violet-600 shadow-violet-600/30', anillo: 'hover:border-violet-300', detalle: 'Escanea su código y corrige sus datos' },
  { id: 'interbibliotecario' as const, label: 'Préstamo interbibliotecario', icono: 'M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5', tono: 'bg-amber-50 text-dorado-600', activo: 'border-amber-600 bg-amber-600 shadow-amber-600/30', anillo: 'hover:border-amber-300', detalle: 'Material que llega de otra biblioteca' },
]

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}

function accionPrincipal() {
  if (modo.value === 'editar_copia') guardarEdicionCopia()
  else if (modo.value === 'copia') pedirConfirmacionCopia()
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
          <div class="grid w-full grid-cols-1 gap-2.5 sm:grid-cols-2 xl:grid-cols-4" role="tablist" aria-label="Qué catalogar">
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
                  <!-- El tipo cambia la ficha: cada plantilla pide sus propios campos -->
                  <p class="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-indigo-700">
                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                    </svg>
                    El tipo de material cambia los campos de la ficha.
                  </p>
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
                    :placeholder="`Ej: ${guiaClasificacion.ejemplo}`"
                    aria-describedby="cat-clasificacion-guia"
                    :class="[CAMPO, 'font-mono']"
                  />
                  <!-- Guía: el ejemplo separado en sus partes, con qué es cada una -->
                  <div id="cat-clasificacion-guia" class="mt-1.5 rounded-lg border border-indigo-100 bg-indigo-50/50 px-2.5 py-1.5">
                    <div v-if="guiaClasificacion.partes.length" class="flex flex-wrap items-start gap-x-2 gap-y-1">
                      <div v-for="p in guiaClasificacion.partes" :key="p.parte" class="text-center">
                        <span class="block rounded border border-indigo-200 bg-white px-1.5 font-mono text-xs font-semibold text-gray-900">{{ p.valor }}</span>
                        <span class="block text-[10px] leading-tight text-indigo-800"><strong>{{ p.parte }}</strong> · {{ p.que }}</span>
                      </div>
                    </div>
                    <p v-if="guiaClasificacion.nota" class="text-[11px] leading-snug text-indigo-900" :class="guiaClasificacion.partes.length ? 'mt-1.5' : ''">{{ guiaClasificacion.nota }}</p>
                  </div>
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
                  <p :class="ETIQUETA"><Marc :codigo="etiqueta('serie')" tono="teal" /> Serie <span class="font-normal text-gray-500">(opcional; puede haber varias)</span></p>
                  <div class="space-y-2">
                    <div v-for="(_, i) in series" :key="i" class="flex gap-2">
                      <input autocomplete="off"
                        :id="`cat-serie-${i}`"
                        v-model="series[i]"
                        placeholder="Ej: Serie Harry Potter ; 1"
                        :aria-label="`Serie ${i + 1}`"
                        :class="CAMPO"
                        @input="guardarSeries"
                      />
                      <button
                        v-if="series.length > 1 || series[i]"
                        type="button"
                        class="shrink-0 rounded-lg border border-gray-300 px-3 text-sm text-gray-500 hover:bg-gray-50 hover:text-red-600"
                        :aria-label="`Quitar serie ${i + 1}`"
                        @click="quitarSerie(i)"
                      >✕</button>
                    </div>
                  </div>
                  <button type="button" class="mt-2 text-xs font-semibold text-teal-700 hover:underline" @click="agregarSerie">+ Agregar otra serie</button>
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
                      <template v-if="c === 'otros_enlaces'">
                        <p :class="ETIQUETA"><Marc :codigo="etiqueta(c)" tono="pizarra" /> {{ NOMBRES[c] }} <span class="font-normal text-gray-500">(opcional; puede haber varios)</span></p>
                        <div class="space-y-2">
                          <div v-for="(e, i) in enlaces" :key="i" class="flex flex-col gap-2 sm:flex-row">
                            <input autocomplete="off"
                              :id="`cat-enlace-${i}`"
                              v-model="e.url"
                              type="url"
                              placeholder="https://..."
                              :aria-label="`Dirección del enlace ${i + 1}`"
                              :class="[CAMPO, 'sm:flex-[3]']"
                              @input="guardarEnlaces"
                            />
                            <div class="flex gap-2 sm:flex-[2]">
                              <input autocomplete="off"
                                v-model="e.texto"
                                placeholder="Texto (opcional). Ej: Versión digital, v.2"
                                :aria-label="`Texto del enlace ${i + 1}`"
                                :class="CAMPO"
                                @input="guardarEnlaces"
                              />
                              <button
                                v-if="enlaces.length > 1 || e.url || e.texto"
                                type="button"
                                class="shrink-0 rounded-lg border border-gray-300 px-3 text-sm text-gray-500 hover:bg-gray-50 hover:text-red-600"
                                :aria-label="`Quitar enlace ${i + 1}`"
                                @click="quitarEnlace(i)"
                              >✕</button>
                            </div>
                          </div>
                        </div>
                        <button type="button" class="mt-2 text-xs font-semibold text-pizarra-600 hover:underline" @click="agregarEnlace">+ Agregar otro enlace</button>
                      </template>
                      <template v-else>
                        <label :for="`cat-${c}`" :class="ETIQUETA"><Marc :codigo="etiqueta(c)" tono="pizarra" /> {{ NOMBRES[c] }}</label>
                        <input autocomplete="off" v-if="c === 'url_acceso'" :id="`cat-${c}`" v-model="form.url_acceso" type="url" :placeholder="PISTAS.url_acceso" :class="CAMPO" />
                        <textarea v-else :id="`cat-${c}`" v-model="form[c]" rows="2" :placeholder="PISTAS[c]" :class="CAMPO"></textarea>
                      </template>
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
                  <input autocomplete="off" id="cat-coleccion" v-model="form.coleccion" list="colecciones" :placeholder="colecciones[0] ? `Ej: ${colecciones[0].nombre} · escribe para ver las demás` : 'Escribe para ver las colecciones'" :class="CAMPO" />
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
                  <CampoPrecio id="cat-precio" v-model="form.precio" :clase="CAMPO" />
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

          <template v-else-if="modo === 'copia'">
            <!-- 1. Obra -->
            <SeccionFicha id="seccion-obra" :numero="1" titulo="Obra" detalle="Busca el título ya catalogado" tono="indigo">
              <p id="cat-buscar-obra-por" :class="ETIQUETA">Buscar la obra por</p>
              <div role="radiogroup" aria-labelledby="cat-buscar-obra-por" class="mb-2 inline-flex flex-wrap gap-1 rounded-xl border border-gray-200 bg-gray-50 p-1">
                <button
                  v-for="m in MODOS_BUSCAR_OBRA"
                  :key="m.valor"
                  type="button"
                  role="radio"
                  :aria-checked="buscarObraEn === m.valor"
                  class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors"
                  :class="buscarObraEn === m.valor ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:bg-white hover:text-gray-900'"
                  @click="elegirBuscarObraEn(m.valor)"
                >
                  {{ m.texto }}
                </button>
              </div>
              <!-- Para qué sirve la opción elegida -->
              <p id="cat-buscar-obra-ayuda" class="mb-2 rounded-lg border border-indigo-100 bg-indigo-50/60 px-3 py-2 text-xs leading-relaxed text-indigo-900">
                <span class="block"><strong>Qué escribir:</strong> {{ modoBuscarObra.escribir }}</span>
                <span class="mt-0.5 block text-indigo-800/80">{{ modoBuscarObra.ayuda }}</span>
              </p>
              <div class="relative">
                <input autocomplete="off"
                  id="cat-buscar-obra"
                  v-model="busquedaCopia"
                  type="text"
                  :inputmode="modoBuscarObra.numerico ? 'numeric' : 'text'"
                  :placeholder="modoBuscarObra.placeholder"
                  :aria-label="`Buscar la obra por ${modoBuscarObra.texto.toLowerCase()}`"
                  aria-describedby="cat-buscar-obra-ayuda"
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
                    <p v-if="l.ejemplares?.length" class="mt-0.5 line-clamp-2 text-[11px] text-gray-500">
                      {{ l.ejemplares.map((e) => rotuloCopia(e)).join('  |  ') }}
                    </p>
                  </button>
                </div>
              </div>
              <div v-if="libroParaCopia" class="mt-4 flex items-start justify-between gap-3 rounded-xl border border-indigo-100 bg-indigo-50/60 p-3">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-gray-900">{{ libroParaCopia.titulo }}</p>
                  <p class="mt-0.5 text-xs font-semibold text-indigo-800">Ficha N.º <span class="font-mono">{{ libroParaCopia.numero_ficha }}</span></p>
                  <p class="text-xs text-gray-500">{{ libroParaCopia.ejemplares_count ?? libroParaCopia.ejemplares?.length ?? 0 }} copia(s) ya catalogada(s)</p>
                  <p v-if="libroParaCopia.ejemplares?.length" class="mt-2 text-[11px] text-indigo-800">Haz clic en un código para editar esa copia:</p>
                  <div v-if="libroParaCopia.ejemplares?.length" class="mt-1 flex flex-wrap gap-1">
                    <button
                      v-for="e in libroParaCopia.ejemplares"
                      :key="e.id"
                      type="button"
                      class="whitespace-nowrap rounded-full border border-indigo-100 bg-white px-2 py-0.5 text-[11px] text-gray-600 transition-colors hover:border-indigo-300 hover:bg-indigo-50"
                      :title="`Editar la copia ${e.codigo_barras}`"
                      @click="editarCopia(e, libroParaCopia)"
                    >
                      {{ rotuloCopia(e) }}
                    </button>
                  </div>
                </div>
                <button type="button" class="shrink-0 text-xs font-medium text-indigo-700 hover:underline" @click="cambiarObra">Cambiar</button>
              </div>
              <!-- Aviso: a un libro o tesis electrónica normalmente no se le agregan copias -->
              <div v-if="avisoElectronico" role="alert" class="mt-3 flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-3.5 text-sm text-amber-900">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                </svg>
                <div>
                  <p class="font-semibold">Esta obra es {{ avisoElectronico }}.</p>
                  <p class="mt-0.5 text-[13px] leading-relaxed text-amber-800">
                    Se consulta en línea desde su enlace de acceso, así que normalmente no lleva copias. Agrega un ejemplar
                    <strong>solo si la biblioteca tiene una copia física</strong> (impresa, en CD o DVD) con su propio código de barras.
                  </p>
                </div>
              </div>
            </SeccionFicha>

            <!-- 2. Copia nueva -->
            <SeccionFicha id="seccion-copia" :numero="2" titulo="Copia nueva" detalle="Datos de la copia física · solo uso interno" tono="violeta">
              <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                  <label for="copia-codigo" :class="ETIQUETA">Código de barras <span class="text-red-600">*</span></label>
                  <input autocomplete="off" id="copia-codigo" v-model="formCopia.codigo_barras" placeholder="Escanea el código o genera uno" aria-describedby="copia-codigo-generar" :class="[CAMPO, 'font-mono']" />
                  <!-- Generar uno nuevo: el largo de siempre o el corto (que sirve también de código de lomo) -->
                  <div id="copia-codigo-generar" class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                    <span class="text-gray-500">Generar:</span>
                    <button
                      type="button"
                      class="rounded-lg border border-gray-300 bg-white px-2.5 py-1 font-semibold text-gray-700 transition-colors hover:bg-gray-50"
                      title="El siguiente código de 14 dígitos, como los de siempre"
                      @click="generarCodigoBarras('copia', 'largo')"
                    >
                      Largo <span class="font-mono font-normal text-gray-500">· 14 dígitos</span>
                    </button>
                    <button
                      type="button"
                      class="rounded-lg border border-gray-300 bg-white px-2.5 py-1 font-semibold text-gray-700 transition-colors hover:bg-gray-50"
                      title="El siguiente de 8 dígitos: el mismo número queda como código de lomo"
                      @click="generarCodigoBarras('copia', 'corto')"
                    >
                      Corto <span class="font-mono font-normal text-gray-500">· 8 dígitos</span>
                    </button>
                  </div>
                  <p class="mt-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[11px] leading-snug text-amber-900">
                    <strong>Generar</strong> crea un código <strong>nuevo, que no usa ninguna copia ni equipo</strong>. Úsalo solo si la copia no trae
                    código de barras pegado, y después imprime su etiqueta. Si ya trae uno, escanéalo en la casilla.
                  </p>
                  <p v-if="/^[1-9][0-9]{7}$/.test(formCopia.codigo_barras.trim())" class="mt-1 text-[11px] text-gray-500">
                    Código corto: queda también como su código de lomo (la copia tendrá un solo número).
                  </p>
                </div>
                <div class="sm:col-span-3">
                  <label for="copia-ubicacion" :class="ETIQUETA">Ubicación física</label>
                  <select id="copia-ubicacion" v-model="formCopia.ubicacion_id" :class="CAMPO">
                    <option value="">Sin especificar</option>
                    <option v-for="u in ubicacionesOpciones" :key="u.id" :value="u.id">{{ u.nombre }}</option>
                  </select>
                </div>
                <!-- Cómo queda la copia (igual que en «Editar ejemplar») -->
                <div class="sm:col-span-3">
                  <p :class="ETIQUETA">Cómo queda</p>
                  <p class="flex min-h-[2.75rem] flex-wrap items-center gap-x-2 rounded-lg border border-dashed border-violet-200 bg-violet-50/50 px-3 py-1.5 text-sm">
                    <template v-if="claveTomo && tomoValido">
                      <strong class="text-violet-900">{{ textoCopia(copiaDelTomo ? `${claveTomo} c.${copiaDelTomo}` : claveTomo, null) ?? claveTomo }}</strong>
                      <span class="font-mono text-xs text-violet-500">({{ copiaDelTomo ? `${claveTomo} c.${copiaDelTomo}` : claveTomo }})</span>
                      <span v-if="copiaDelTomo === 0" class="text-xs text-gray-500">· la primera de ese tomo</span>
                    </template>
                    <strong v-else-if="!formCopia.tomo.trim() && formCopia.volumen.trim()" class="text-violet-900">{{ formCopia.volumen.trim() }}</strong>
                    <span v-else class="text-gray-400">Sin tomo · el número de copia lo pone el sistema</span>
                  </p>
                </div>
                <!-- Tomo, volumen y copia por separado (el texto se arma solo, como en Horizon) -->
                <div class="sm:col-span-2">
                  <label for="copia-tomo" :class="ETIQUETA">Tomo <span class="font-normal text-gray-400">(si la obra tiene varios)</span></label>
                  <input autocomplete="off" id="copia-tomo" v-model="formCopia.tomo" list="tomos-de-la-obra" placeholder="t.1" :aria-invalid="!tomoLeido.valido" :class="[CAMPO, !tomoLeido.valido ? 'border-red-400 ring-1 ring-red-300' : '']" />
                  <p class="mt-1 text-[11px] text-gray-500">Ej: <span class="font-mono">t.1</span>, <span class="font-mono">Tomo 2</span> o <span class="font-mono">v.3</span> · vacío si no tiene</p>
                  <datalist id="tomos-de-la-obra">
                    <option v-for="t in tomosDeLaObra" :key="t.tomo" :value="t.tomo">{{ partesCopia(t.tomo, null).tomo ?? t.tomo }} · {{ t.copias }} {{ t.copias === 1 ? 'copia' : 'copias' }}</option>
                  </datalist>
                </div>
                <div class="sm:col-span-2">
                  <label for="copia-parte" :class="ETIQUETA">{{ segundaParte.nombre }} dentro del {{ segundaParte.nombre === 'Volumen' ? 'tomo' : 'volumen' }}</label>
                  <input
                    autocomplete="off"
                    id="copia-parte"
                    v-model="formCopia.parte"
                    :disabled="!tomoLeido.tomo || !tomoLeido.valido || tomoLeido.conVolumen"
                    placeholder="II"
                    :aria-invalid="parteLeida === null"
                    :class="[CAMPO, 'disabled:bg-gray-50 disabled:text-gray-400', parteLeida === null ? 'border-red-400 ring-1 ring-red-300' : '']"
                  />
                  <p class="mt-1 text-[11px] text-gray-500">Ej: <span class="font-mono">II</span> o <span class="font-mono">2</span> · solo si el tomo tiene varios</p>
                </div>
                <div class="sm:col-span-2">
                  <p :class="ETIQUETA">Copia</p>
                  <p class="flex min-h-[2.75rem] items-center rounded-lg border border-gray-200 bg-gray-50 px-3 text-sm text-gray-700">
                    <template v-if="claveTomo && tomoValido">{{ copiaDelTomo ? `Copia ${copiaDelTomo}` : 'Primera (sin «c.»)' }}</template>
                    <template v-else>La pone el sistema</template>
                  </p>
                  <p class="mt-1 text-[11px] text-gray-500">No se escribe: sigue la numeración del tomo</p>
                </div>
                <div class="sm:col-span-6">
                  <p v-if="!tomoLeido.valido" class="text-[11px] font-semibold text-red-700">
                    No se reconoce como tomo. Escribe solo el tomo, por ejemplo «t.1» o «Tomo 1»: el número de copia lo pone el sistema.
                  </p>
                  <p v-else-if="parteLeida === null" class="text-[11px] font-semibold text-red-700">
                    En «{{ segundaParte.nombre }}» va solo el número, por ejemplo «2» o «II».
                  </p>
                  <p v-else-if="tomoLeido.traiaCopia" class="text-[11px] text-amber-800">
                    El número de copia lo pone el sistema: se toma solo <strong>{{ textoCopia(tomoLeido.tomo, null) }}</strong>.
                  </p>
                  <p v-else-if="tomosDeLaObra.length" class="text-[11px] text-gray-500">
                    Tomos de esta obra: {{ tomosDeLaObra.map((t) => `${partesCopia(t.tomo, null).tomo ?? t.tomo} (${t.copias} ${t.copias === 1 ? 'copia' : 'copias'})`).join(' · ') }}
                  </p>
                </div>
                <div class="sm:col-span-6">
                  <label for="copia-volumen" :class="ETIQUETA">Otra designación <span class="font-normal text-gray-400">(si no es un tomo)</span></label>
                  <input
                    autocomplete="off"
                    id="copia-volumen"
                    v-model="formCopia.volumen"
                    :disabled="!!formCopia.tomo.trim()"
                    placeholder="Ej: Anexo · Atlas · Año IV No. 4 (Jul.-Aug., 1985)"
                    :class="[CAMPO, 'disabled:bg-gray-50 disabled:text-gray-400']"
                  />
                  <p class="mt-1 text-[11px] text-gray-500">Para lo que no es un tomo, como el número de una revista. Con tomo, se deja vacía.</p>
                </div>
                <div class="sm:col-span-3">
                  <label for="copia-precio" :class="ETIQUETA">Precio (CLP)</label>
                  <CampoPrecio id="copia-precio" v-model="formCopia.precio" :clase="CAMPO" />
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

          <!-- Editar ejemplar: la copia por su código y todos sus datos -->
          <template v-else>
            <!-- Qué es esta pestaña: solo copias, por su código de barras -->
            <div class="flex items-start gap-3 rounded-2xl border border-violet-200 bg-violet-50/70 px-5 py-4 shadow-sm">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-600 text-white" aria-hidden="true">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5zM16.5 13.5v6m3-3h-6" /></svg>
              </span>
              <div>
                <p class="font-serif text-lg font-bold leading-tight text-violet-950">Editor de ejemplares</p>
                <p class="mt-1 text-sm leading-relaxed text-violet-900/80">
                  Corrige los datos de <strong>una copia</strong> ya catalogada: escanea su código de barras. Lo que es del título (autor, materias,
                  clasificación, editorial…) se cambia en la ficha, con «Editar» en «Nueva ficha» o en el listado.
                </p>
              </div>
            </div>

            <!-- 1. Ejemplar -->
            <SeccionFicha id="seccion-ejemplar" :numero="1" titulo="Ejemplar" detalle="Escanea el código de la copia" tono="indigo">
              <form class="flex gap-2" @submit.prevent="buscarCopiaParaEditar()">
                <div class="min-w-0 flex-1">
                  <label for="editar-copia-codigo" :class="ETIQUETA">Código de barras o de lomo</label>
                  <input
                    id="editar-copia-codigo"
                    v-model="codigoAEditar"
                    autocomplete="off"
                    inputmode="numeric"
                    placeholder="Escanea la copia o escribe su código"
                    :class="[CAMPO, 'font-mono']"
                  />
                </div>
                <button
                  type="submit"
                  class="mt-auto shrink-0 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:opacity-60"
                  :disabled="buscandoCopiaAEditar || !codigoAEditar.trim()"
                >
                  {{ buscandoCopiaAEditar ? 'Buscando…' : 'Abrir' }}
                </button>
              </form>
              <p class="mt-1.5 text-[11px] text-gray-500">
                También se llega con el «Editar» de una copia, en el Listado de material o en «Títulos catalogados», aquí abajo.
              </p>
              <div v-if="copiaEditando" class="mt-4 flex items-start justify-between gap-3 rounded-xl border border-indigo-100 bg-indigo-50/60 p-3">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-gray-900">{{ copiaEditando.libro.titulo }}</p>
                  <p class="mt-0.5 text-xs font-semibold text-indigo-800">Ficha N.º <span class="font-mono">{{ copiaEditando.libro.numero_ficha }}</span></p>
                  <p class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                    <span
                      v-if="partesCopia(copiaEditando.copia.volumen, copiaEditando.copia.etiqueta_copia).tomo"
                      class="rounded-full bg-gradient-to-b from-indigo-500 to-indigo-600 px-2.5 py-0.5 font-bold text-white shadow-sm"
                    >{{ partesCopia(copiaEditando.copia.volumen, copiaEditando.copia.etiqueta_copia).tomo }}</span>
                    <span
                      v-if="partesCopia(copiaEditando.copia.volumen, copiaEditando.copia.etiqueta_copia).copia"
                      class="rounded-full bg-emerald-50 px-2.5 py-0.5 font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200"
                    >Copia <strong>{{ partesCopia(copiaEditando.copia.volumen, copiaEditando.copia.etiqueta_copia).copia }}</strong></span>
                    <span class="rounded-full px-2.5 py-0.5 font-semibold" :class="estadoBadges[copiaEditando.copia.estado_proceso]?.cls">
                      {{ estadoBadges[copiaEditando.copia.estado_proceso]?.label ?? copiaEditando.copia.estado_proceso }}
                    </span>
                  </p>
                  <p class="mt-1.5 text-xs text-gray-600">
                    Código <span class="font-mono font-semibold text-gray-900">{{ copiaEditando.copia.codigo_barras }}</span> · lomo <span class="font-mono">{{ copiaEditando.copia.codigo_lomo }}</span>
                  </p>
                  <router-link
                    v-if="puedeCambiarEstado"
                    :to="{ name: 'estado-libro', query: { q: copiaEditando.copia.codigo_barras } }"
                    class="mt-1 inline-block text-xs font-semibold text-indigo-700 hover:underline"
                  >
                    Cambiar su estado en «Estado de ejemplares» →
                  </router-link>
                </div>
                <button type="button" class="shrink-0 text-xs font-medium text-indigo-700 hover:underline" @click="soltarCopiaEditando">Cambiar</button>
              </div>
            </SeccionFicha>

            <!-- 2. Datos de la copia -->
            <SeccionFicha id="seccion-datos" :numero="2" titulo="Datos de la copia" detalle="Todo lo que se puede corregir · solo uso interno" tono="violeta">
              <p v-if="!copiaEditando" class="text-sm text-gray-500">Escanea una copia arriba para ver y corregir sus datos.</p>
              <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                  <label for="editar-ubicacion" :class="ETIQUETA">Ubicación física</label>
                  <select id="editar-ubicacion" v-model="formEditar.ubicacion_id" :class="CAMPO">
                    <option value="">Sin especificar</option>
                    <option v-for="u in ubicacionesOpciones" :key="u.id" :value="u.id">{{ u.nombre }}</option>
                  </select>
                </div>
                <div class="sm:col-span-3">
                  <p :class="ETIQUETA">Cómo queda</p>
                  <p class="flex min-h-[2.75rem] items-center rounded-lg border border-dashed border-violet-200 bg-violet-50/50 px-3 text-sm">
                    <strong v-if="volumenEditar" class="text-violet-900">{{ textoCopia(volumenEditar, null) }}</strong>
                    <span v-else class="text-gray-400">Sin tomo ni copia</span>
                    <span v-if="volumenEditar" class="ml-2 font-mono text-xs text-violet-500">({{ volumenEditar }})</span>
                  </p>
                </div>
                <!-- Tomo, volumen y copia por separado (el texto se arma solo, como en Horizon) -->
                <div class="sm:col-span-2">
                  <label for="editar-tomo" :class="ETIQUETA">Tomo</label>
                  <input id="editar-tomo" v-model="formEditar.tomo" autocomplete="off" placeholder="t.1" :aria-invalid="!tomoEditar.valido" :class="[CAMPO, !tomoEditar.valido ? 'border-red-400 ring-1 ring-red-300' : '']" />
                  <p class="mt-1 text-[11px] text-gray-500">Ej: <span class="font-mono">t.1</span>, <span class="font-mono">Tomo 2</span> o <span class="font-mono">v.3</span> · vacío si no tiene</p>
                </div>
                <div class="sm:col-span-2">
                  <label for="editar-parte" :class="ETIQUETA">{{ tomoEditar.tomo.startsWith('v') ? 'Tomo dentro del volumen' : 'Volumen dentro del tomo' }}</label>
                  <input id="editar-parte" v-model="formEditar.parte" autocomplete="off" placeholder="II" :disabled="!formEditar.tomo.trim()" :aria-invalid="parteEditar === null" :class="[CAMPO, 'disabled:bg-gray-50', parteEditar === null ? 'border-red-400 ring-1 ring-red-300' : '']" />
                  <p class="mt-1 text-[11px] text-gray-500">Ej: <span class="font-mono">II</span> o <span class="font-mono">2</span> · solo si el tomo tiene varios</p>
                </div>
                <div class="sm:col-span-2">
                  <label for="editar-copia-num" :class="ETIQUETA">Copia</label>
                  <input id="editar-copia-num" v-model="formEditar.copia" autocomplete="off" inputmode="numeric" placeholder="2" :disabled="!formEditar.tomo.trim()" :aria-invalid="!copiaEditarValida" :class="[CAMPO, 'disabled:bg-gray-50', !copiaEditarValida ? 'border-red-400 ring-1 ring-red-300' : '']" />
                  <p class="mt-1 text-[11px] text-gray-500">Ej: <span class="font-mono">2</span> · vacío en la primera (va sin «c.»)</p>
                </div>
                <div class="sm:col-span-6">
                  <label for="editar-otra" :class="ETIQUETA">Otra designación <span class="font-normal text-gray-400">(si no es un tomo)</span></label>
                  <input id="editar-otra" v-model="formEditar.otra" autocomplete="off" :disabled="!!formEditar.tomo.trim()" placeholder="Ej: Anexo · Atlas · Año IV No. 4 (Jul.-Aug., 1985)" :class="[CAMPO, 'disabled:bg-gray-50']" />
                  <p class="mt-1 text-[11px] text-gray-500">Para lo que no es un tomo, como el número de una revista. Con tomo, se deja vacía.</p>
                </div>
                <p v-if="!edicionValida" class="text-[11px] font-semibold text-red-700 sm:col-span-6">
                  Hay algo que no se reconoce: el tomo va como «t.1», el volumen como «II» o «2» y la copia como «2».
                </p>
                <div class="sm:col-span-6">
                  <label for="editar-coleccion" :class="ETIQUETA">Colección de la copia</label>
                  <input
                    id="editar-coleccion"
                    v-model="formEditar.coleccion"
                    autocomplete="off"
                    list="colecciones"
                    :placeholder="copiaEditando.libro.coleccion ? `${copiaEditando.libro.coleccion} (la del libro)` : 'La del libro'"
                    :class="CAMPO"
                  />
                  <p class="mt-1 text-[11px] text-gray-500">Vacía, vale la colección del libro<template v-if="copiaEditando.libro.coleccion"> («{{ copiaEditando.libro.coleccion }}»)</template>.</p>
                </div>
                <div class="sm:col-span-2">
                  <label for="editar-precio" :class="ETIQUETA">Precio (CLP)</label>
                  <CampoPrecio id="editar-precio" v-model="formEditar.precio" :clase="CAMPO" />
                </div>
                <div class="sm:col-span-2">
                  <label for="editar-adquisicion" :class="ETIQUETA">Forma de adquisición</label>
                  <select id="editar-adquisicion" v-model="formEditar.forma_adquisicion" :class="CAMPO">
                    <option value="">Sin especificar</option>
                    <option v-for="f in FORMAS_ADQUISICION" :key="f.valor" :value="f.valor">{{ f.nombre }}</option>
                  </select>
                </div>
                <div class="sm:col-span-2">
                  <label for="editar-registro" :class="ETIQUETA">N.º de registro (Horizon)</label>
                  <input id="editar-registro" v-model="formEditar.numero_registro" autocomplete="off" maxlength="50" inputmode="numeric" placeholder="Ej: 30727" :class="CAMPO" />
                </div>
                <div class="sm:col-span-6">
                  <label for="editar-nota-publica" :class="ETIQUETA">Nota pública de la copia (la ve el usuario en el portal)</label>
                  <textarea id="editar-nota-publica" v-model="formEditar.nota_publica" rows="2" placeholder="Ej: Incluye CD · se ve en el portal" :class="CAMPO"></textarea>
                </div>
                <div class="sm:col-span-6">
                  <label for="editar-nota-interna" :class="ETIQUETA">Nota interna de la copia (solo la ve el personal)</label>
                  <textarea id="editar-nota-interna" v-model="formEditar.nota_interna" rows="2" placeholder="Solo la ve el personal" :class="CAMPO"></textarea>
                </div>
                <p class="text-[11px] text-gray-500 sm:col-span-6">
                  El código de barras y el de lomo no se cambian: identifican a la copia. El estado se cambia en «Estado de ejemplares».
                </p>
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
            <p v-else class="text-sm text-gray-400">{{ modo === 'editar_copia' ? 'Escanea una copia para ver su obra.' : 'Elige la obra para ver cómo aparece.' }}</p>
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
              :disabled="guardando || (modo === 'editar_copia' && (!copiaEditando || !editarConCambios))"
              @click="accionPrincipal"
            >
              {{ textoAccion }}
            </button>
            <button
              v-if="modo === 'editar_copia' && copiaEditando"
              type="button"
              class="w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-800 transition-colors hover:bg-gray-50"
              @click="soltarCopiaEditando"
            >
              {{ editarConCambios ? 'Descartar y editar otra' : 'Editar otra copia' }}
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
                    <!-- Cada copia, con su botón «Editar»: abre la pestaña «Editar ejemplar» con ella -->
                    <span
                      v-for="e in l.ejemplares"
                      :key="e.id"
                      class="inline-flex items-center overflow-hidden rounded-full text-xs font-medium"
                      :class="estadoBadges[e.estado_proceso]?.cls"
                      :title="`${nombreDeCopia(e) ?? e.codigo_barras} · ${estadoBadges[e.estado_proceso]?.label ?? e.estado_proceso}`"
                    >
                      <span class="whitespace-nowrap py-0.5 pl-2 pr-1.5">{{ rotuloCopia(e) }}</span>
                      <button
                        type="button"
                        class="border-l border-black/10 bg-white/60 px-2 py-0.5 font-semibold text-violet-700 transition-colors hover:bg-violet-600 hover:text-white"
                        :aria-label="`Editar la copia ${e.codigo_barras}`"
                        @click="editarCopia(e, l)"
                      >
                        Editar
                      </button>
                    </span>
                  </div>
                </td>
                <td class="px-5 py-3">
                  <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <!-- En «Nuevo ejemplar» se editan las copias (en la columna Copias), no la ficha -->
                    <button v-if="modo !== 'copia'" type="button" class="text-sm font-medium text-indigo-700 hover:text-indigo-800" @click="libroAEditar = l">Editar</button>
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

      <!-- Las colecciones del catálogo (las de Horizon), para escribirlas igual y no crear otra parecida -->
      <datalist id="colecciones">
        <option v-for="c in colecciones" :key="c.nombre" :value="c.nombre">{{ c.copias === 1 ? '1 copia' : `${c.copias.toLocaleString('es-CL')} copias` }}</option>
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
      :ancho="editandoId ? 'md' : 'lg'"
      @confirmar="confirmarGuardar"
      @cancelar="confirmandoGuardar = false"
    >
      <template v-if="!editandoId" #antesDelTitulo><PasosCatalogacion :actual="1" /></template>
      <p v-if="editandoId" class="text-[15px] text-gray-600">
        Se guardarán los cambios de <strong class="text-gray-900">{{ tituloAGuardar }}</strong>. Se ven de inmediato en el listado y, si tiene
        copias en estante, en el portal.
      </p>
      <template v-else>
        <p class="text-[15px] text-gray-600">Se creará esta ficha bibliográfica. Revisa que todo esté bien:</p>
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
        <!-- La ficha completa: cada campo lleno, con su etiqueta MARC -->
        <div class="mt-3 overflow-hidden rounded-xl border border-indigo-100">
          <p class="flex items-center justify-between gap-2 border-b border-indigo-100 bg-indigo-50/60 px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-indigo-800">
            Ficha bibliográfica
            <span class="font-normal normal-case tracking-normal text-indigo-700">{{ fichaAConfirmar.length }} {{ fichaAConfirmar.length === 1 ? 'campo' : 'campos' }} con datos</span>
          </p>
          <dl class="divide-y divide-gray-100">
            <div v-for="(f, i) in fichaAConfirmar" :key="i" class="grid grid-cols-[2.75rem_minmax(0,9rem)_minmax(0,1fr)] gap-x-3 px-4 py-2 text-sm">
              <dt class="pt-px font-mono text-xs font-semibold text-indigo-700">{{ f.etiqueta }}</dt>
              <dt class="text-gray-500">
                {{ f.nombre }}
                <span v-if="f.interno" class="mt-0.5 block text-[10px] font-semibold uppercase tracking-wide text-gray-400">solo personal</span>
              </dt>
              <dd class="whitespace-pre-line break-words text-gray-900">{{ f.valor }}</dd>
            </div>
          </dl>
        </div>
        <p class="mt-3 rounded-lg bg-gray-50 px-4 py-3 text-[13px] leading-relaxed text-gray-600">
          Si falta o sobra algo, aprieta <strong class="text-gray-900">Cancelar</strong> y corrígelo. Al crearla se le asigna su N.º de ficha y
          podrás elegir entre catalogar otra o agregarle ejemplares.
        </p>
      </template>
    </DialogoConfirmacion>

    <!-- Ficha creada: su N.º a la vista y qué hacer ahora (no se va sola como una notificación) -->
    <Modal
      :abierto="!!fichaCreada"
      titulo="Ficha creada"
      destacado
      tono="exito"
      ancho="md"
      :cerrar-al-clic-afuera="false"
      @cerrar="catalogarOtraFicha"
    >
      <div v-if="fichaCreada" class="flex items-start gap-4">
        <PortadaMaterial :titulo="fichaCreada.titulo" :tipo="fichaCreada.tipo" :plantilla="fichaCreada.plantilla" :clave-tono="fichaCreada.claveTono" tamano="sm" class="shrink-0 shadow-sm" />
        <div class="min-w-0 flex-1">
          <p class="text-[15px] leading-snug text-gray-700">
            Ya creaste la ficha de <strong class="text-gray-900">«{{ fichaCreada.titulo }}»</strong>. Quedó con el
          </p>
          <p class="mt-2 flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-emerald-600 px-3 py-1 text-sm font-semibold text-white">
              N.º de ficha <span class="font-mono text-base">{{ fichaCreada.numero }}</span>
            </span>
            <button type="button" class="rounded-lg border border-emerald-300 bg-white px-2.5 py-1 text-xs font-semibold text-emerald-800 hover:bg-emerald-50" @click="copiarNumeroFicha">
              {{ numeroCopiado ? '✓ Copiado' : 'Copiar N.º' }}
            </button>
          </p>
          <p v-if="fichaCreada.autores.length" class="mt-2 truncate text-sm text-gray-600">{{ fichaCreada.autores.join(' · ') }}</p>
          <p class="mt-2 text-xs text-gray-500">Con este número se encuentra la ficha en Catalogación y en el listado.</p>
        </div>
      </div>
      <p v-if="fichaCreada" class="mt-4 text-[15px] font-semibold text-gray-900">
        ¿Ahora quieres crear una ficha nueva o agregarle ejemplares a la ficha N.º {{ fichaCreada.numero }}?
      </p>
      <p v-if="fichaCreada" class="mt-2 text-xs text-gray-500">
        ¿Te faltó algo?
        <button type="button" class="font-semibold text-indigo-700 hover:underline" @click="editarFichaCreada">Editar la ficha N.º {{ fichaCreada.numero }}</button>
      </p>
      <template #pie>
        <button
          type="button"
          class="flex-1 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-100"
          @click="agregarEjemplaresAFichaCreada"
        >
          Agregarle ejemplares
        </button>
        <button
          type="button"
          class="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700"
          @click="catalogarOtraFicha"
        >
          Catalogar una ficha nueva
        </button>
      </template>
    </Modal>

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
      <p v-if="avisoElectronico" class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-[13px] leading-relaxed text-amber-900">
        <strong>Ojo: es {{ avisoElectronico }}.</strong> Confirma solo si esta copia es física (impresa, en CD o DVD) y tiene este código de barras pegado.
      </p>
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
            Se creó <template v-if="copiaCreada?.rotulo"><strong>{{ copiaCreada.rotulo }}</strong></template><template v-else>una copia</template> de <strong>{{ copiaCreada?.titulo }}</strong>
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
