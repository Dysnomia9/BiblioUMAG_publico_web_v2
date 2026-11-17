<script setup lang="ts">
import { leerTomoCopia, nombreDeCopia, numeroDeCopia, partesCopia, separarTomoYDesignacion, textoCopia } from '@/utils/tomo'
import { computed, h, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch, type FunctionalComponent } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import Modal from '@/components/ui/Modal.vue'
import CampoPrecio from '@/components/ui/CampoPrecio.vue'
import EditorClasificacion from '@/components/libros/EditorClasificacion.vue'
import DialogoConfirmacionEscrita from '@/components/ui/DialogoConfirmacionEscrita.vue'
import Paginador from '@/components/ui/Paginador.vue'
import MultiSelectCombobox from '@/components/libros/MultiSelectCombobox.vue'
import CampoEntidad from '@/components/libros/CampoEntidad.vue'
import RevisionMaterias from '@/components/libros/RevisionMaterias.vue'
import PestanasLibros from '@/components/libros/PestanasLibros.vue'
import FondoEstante from '@/components/libros/FondoEstante.vue'
import SeccionFicha from '@/components/libros/SeccionFicha.vue'
import PasosCatalogacion from '@/components/libros/PasosCatalogacion.vue'
import EditorNotaContenido from '@/components/libros/EditorNotaContenido.vue'
import NotaContenido from '@/components/libros/NotaContenido.vue'
import { enPuntaArenas } from '@/utils/horario'
import { fechaCorta } from '@/utils/prestamos'
import { normalizarMateria } from '@/utils/materia'
import { TONOS_FICHA, type TonoFicha } from '@/components/libros/tonosFicha'
import api from '@/services/api'
import { esBusquedaCorta } from '@/utils/busqueda'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import PortadaMaterial from '@/components/libros/PortadaMaterial.vue'
import EstadoInterbibliotecario from '@/components/libros/EstadoInterbibliotecario.vue'
import type { FichaInterbibliotecaria, Autor, CarreraSugerida, Categoria, Ejemplar, Libro, Paginado, PlantillaFicha, TipoMaterial, Ubicacion, PrestamoInterbibliotecario as RegistroInterbibliotecario } from '@/types'

const toast = useToast()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const libros = ref<Libro[]>([])
// Títulos catalogados: de a 10 para mantener el listado compacto.
const paginaLibros = ref(1)
const copiasDesplegadas = ref(new Set<number>())
const metaLibros = ref<Omit<Paginado<Libro>, 'data'>>({ current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null })
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

type ModoCatalogacion = 'nuevo' | 'editar' | 'copia' | 'interbibliotecario' | 'editar_copia'
const modo = ref<ModoCatalogacion>('nuevo')
type TareaInterbibliotecaria = 'nuevo' | 'editar' | 'ejemplares' | 'editar_ejemplar'
const tareaInterbibliotecaria = ref<TareaInterbibliotecaria>('nuevo')
const modoPrincipal = computed(() => modo.value !== 'interbibliotecario' ? modo.value : tareaInterbibliotecaria.value === 'editar_ejemplar' ? 'editar_copia' : tareaInterbibliotecaria.value === 'ejemplares' ? 'copia' : tareaInterbibliotecaria.value === 'editar' ? 'editar' : 'nuevo')
const seccionInterbibliotecaria = computed(() => ['ejemplares', 'editar_ejemplar'].includes(tareaInterbibliotecaria.value) ? 'ejemplares' : 'ficha')
const registroEditandoInterbibliotecario = ref<RegistroInterbibliotecario | null>(null)
const prestamoParaEstado = ref<RegistroInterbibliotecario | null>(null)
const tareaInterbibliotecariaPendiente = ref<TareaInterbibliotecaria | null>(null)
const fichaInterbibliotecaria = ref<FichaInterbibliotecaria | null>(null)
const seleccionInterbibliotecariaPendiente = ref<{ ficha: FichaInterbibliotecaria; tarea: 'editar' | 'ejemplares' } | null>(null)
const origenIngreso = ref<'nuevo' | 'catalogo'>('nuevo')
const esIngresoEjemplares = computed(() => modo.value === 'interbibliotecario' && tareaInterbibliotecaria.value === 'ejemplares')
const esEdicionEjemplar = computed(() => modo.value === 'editar_copia' || (modo.value === 'interbibliotecario' && tareaInterbibliotecaria.value === 'editar_ejemplar'))
const esFicha = computed(() => modo.value === 'nuevo' || modo.value === 'editar' || (modo.value === 'interbibliotecario' && (tareaInterbibliotecaria.value === 'editar' || (tareaInterbibliotecaria.value === 'nuevo' && origenIngreso.value === 'nuevo'))))
const esObraExistente = computed(() => modo.value === 'copia' || (modo.value === 'interbibliotecario' && tareaInterbibliotecaria.value === 'nuevo' && origenIngreso.value === 'catalogo'))
const mostrarFormulario = computed(() => modo.value === 'editar' ? !!editandoId.value : modo.value !== 'interbibliotecario' || tareaInterbibliotecaria.value === 'nuevo' || tareaInterbibliotecaria.value === 'editar_ejemplar' || !!fichaInterbibliotecaria.value)
const modoPendiente = ref<ModoCatalogacion | null>(null)
const cargandoFicha = ref(false)

const editandoId = ref<number | null>(null)
/** Se llegó desde el Listado de material (Editar o Agregar copia): se ofrece volver con la misma búsqueda. */
const vieneDelListado = ['listado', 'interbibliotecarios'].includes(String(route.query.desde))
const listadoDeOrigen = 'listado-libros'
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

/**
 * 490 también es repetible: cada serie en su casilla; se guardan una por línea («Serie Harry
 * Potter ; 1»). Los paréntesis van fijos a los lados de la casilla y la ficha los pone al
 * mostrarla, «(Serie Harry Potter ; 1)», como el punto final del 300: si se escriben, el
 * servidor los quita (PuntuacionIsbd::serie) para que no queden dobles.
 */
const series = ref<string[]>([''])
const sinParentesis = (t: string) => t.trim().replace(/^\((.*)\)$/, '$1').trim()
/** Quien cataloga escribió los paréntesis: los fijos de la casilla se ocultan. */
const parentesisAMano = (t: string) => /^\s*\(/.test(t) || /\)\s*$/.test(t)
/** Abrió más paréntesis de los que cerró: «(Serie Harry Potter ; 1». */
const faltaCerrar = (t: string) => (t.match(/\(/g)?.length ?? 0) > (t.match(/\)/g)?.length ?? 0)
function cargarSeries() {
  const lista = (form.serie ?? '').split(/\r?\n/).map(sinParentesis).filter(Boolean)
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
    detalle: modo.value === 'interbibliotecario' ? 'Datos y notas de la ficha del material recibido' : 'Datos internos del título · las copias se agregan en «Nuevo ejemplar»',
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
const secciones = computed<Seccion[]>(() => {
  if (modo.value === 'interbibliotecario') {
    if (esEdicionEjemplar.value) return [...SECCIONES_EDITAR_COPIA, { clave: 'prestamo-interbibliotecario', titulo: 'Biblioteca y plazos', detalle: 'Condiciones del material prestado', tono: 'dorado' }]
    if (esIngresoEjemplares.value) return [
      { clave: 'copia', titulo: 'Ejemplares recibidos', detalle: 'Ejemplares de la ficha elegida', tono: 'violeta' },
    ]
    return [
      ...(esFicha.value ? seccionesNuevo.value : [SECCIONES_COPIA[0]]),
      { clave: 'prestamo-interbibliotecario', titulo: 'Biblioteca y devolución', detalle: 'Biblioteca propietaria y fecha de regreso', tono: 'dorado' },
    ]
  }
  return modo.value === 'copia' ? SECCIONES_COPIA : esEdicionEjemplar.value ? SECCIONES_EDITAR_COPIA : seccionesNuevo.value
})
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
  const seccion = document.getElementById(`seccion-${clave}`)
  if (seccion) window.scrollTo({ top: window.scrollY + seccion.getBoundingClientRect().top - (modo.value === 'editar' ? 144 : 24), behavior: 'smooth' })
}

// ---------- Obligatorios ----------
type Requisito = { texto: string; listo: boolean; seccion: string }

// Una ficha nueva no tiene campos obligatorios: sin título queda «Sin título» (lo pone
// el servidor) y el autor principal (100) o la entidad (110) se pueden dejar vacíos.
const obligatoriosNuevo = computed<Requisito[]>(() => [])

const libroParaCopia = ref<Libro | null>(null)
const obraDelEjemplar = computed(() => modo.value === 'interbibliotecario' && tareaInterbibliotecaria.value === 'nuevo' && origenIngreso.value === 'nuevo' ? null : libroParaCopia.value)
const busquedaCopia = ref('')
const resultadosCopia = ref<Libro[]>([])
const copiaVacia = () => ({ codigo_barras: '', ubicacion_id: '' as number | '', volumen: '', tomo: '', parte: '', copia: '', precio: '' as number | '', forma_adquisicion: '', coleccion: '', numero_registro: '', nota_publica: '', nota_interna: '' })
const formCopia = reactive(copiaVacia())

// ---------- Tomo de la copia (obras en varios tomos, como en Horizon: «t.1 c.2») ----------
/** «Tomo 1», «T1», «t. 1» → «t.1»; «Vol 2» → «v.2» (lo mismo que App\Support\TomoEjemplar). */
/**
 * Tomo y volumen son independientes: una copia puede tener solo tomo («t.1»), solo volumen
 * («v.2») o los dos («t.1-v.II», como en Horizon). Cada casilla lee su número: «1», «t.1»,
 * «Tomo 1» → «t.1»; «II», «2», «v.2», «Volumen 2» → «v.II» / «v.2». Vacía → ''; si no se
 * entiende → null (se avisa y no deja guardar; el servidor tampoco lo acepta).
 */
function leerParte(texto: string, letra: 't' | 'v'): string | null {
  const t = texto.replace(/\s+/g, ' ').trim()
  if (!t) return ''
  const prefijo = letra === 't' ? '(?:t|tomo)' : '(?:v|vol|volumen)'
  // Con su parte, si la tiene: «XXXIII p.1», «2-parte 2» → «t.XXXIII-p.1», «t.2-p.2»
  const m = t.match(new RegExp(`^(?:${prefijo}\\.?\\s*)?(\\d+[a-z]?|[ivxlc]+)(?:[\\s,;/-]*(?:parte|part|pt|p)\\.?[\\s-]*(\\d+|[ivxlc]+))?$`, 'i'))
  if (!m) return null
  const numero = (n: string) => (/^\d/.test(n) ? n.toLowerCase() : n.toUpperCase())
  return `${letra}.${numero(m[1])}${m[2] ? `-p.${numero(m[2])}` : ''}`
}
const PARTE_DEL_TOMO = /-p\.[^-]+$/
/** El tomo de la copia que se guarda: «t.1», «v.2», «t.1-v.II» («v.1-t.2» si el volumen va primero, como algunas de Horizon); la parte, al final («t.1-v.II-p.2»). */
function claveDe(tomo: string, volumen: string, volumenPrimero = false): string {
  const parte = tomo.match(PARTE_DEL_TOMO)?.[0] ?? volumen.match(PARTE_DEL_TOMO)?.[0] ?? ''
  const [t, v] = [tomo.replace(PARTE_DEL_TOMO, ''), volumen.replace(PARTE_DEL_TOMO, '')]
  const base = t && v ? (volumenPrimero ? `${v}-${t}` : `${t}-${v}`) : t || v
  return base ? `${base}${parte}` : ''
}
/**
 * La clave del tomo («t.1-v.II», «t.XXXIII-p.1») en las casillas Tomo y Volumen: la parte va con
 * la que la precede («XXXIII p.1»), para que no se pierda al guardar.
 */
function casillasDeClave(clave: string) {
  const partes = clave.split('-').filter(Boolean)
  const parte = partes.find((p) => p.startsWith('p'))
  const sinParte = partes.filter((p) => !p.startsWith('p'))
  let tomo = sinParte.find((p) => p.startsWith('t'))?.slice(2) ?? ''
  let volumen = sinParte.find((p) => p.startsWith('v'))?.slice(2) ?? ''
  if (parte) {
    if (sinParte.at(-1)?.startsWith('v')) volumen += ` ${parte}`
    else tomo += ` ${parte}`
  }
  return { tomo, volumen, volumenPrimero: sinParte.length === 2 && sinParte[0].startsWith('v') }
}
/**
 * Lo pegado o elegido en «Tomo» con más de una parte («t.1-v.II», «v.3», «t.1 c.2»): se reparte
 * en Tomo, Volumen y Copia. Al salir de la casilla, no mientras se escribe.
 */
function repartirTomo(form: { tomo: string; parte: string; copia: string }): boolean | null {
  const leido = leerTomoCopia(form.tomo.replace(/\bcopia\s*/gi, 'c.'))
  if (!leido) return null
  const partes = leido.clave.split('-')
  if (partes.length === 1 && partes[0].startsWith('t') && !leido.copia) return null
  const casillas = casillasDeClave(leido.clave)
  form.tomo = casillas.tomo
  form.parte = casillas.volumen
  if (leido.copia) form.copia = leido.copia
  return casillas.volumenPrimero
}

// «Nuevo ejemplar»
const tomoParte = computed(() => leerParte(formCopia.tomo, 't'))
const volumenParte = computed(() => leerParte(formCopia.parte, 'v'))
const volumenPrimeroCopia = ref(false)
function alSalirDelTomoCopia() {
  const volumenPrimero = repartirTomo(formCopia)
  if (volumenPrimero !== null) volumenPrimeroCopia.value = volumenPrimero
}
/** Lo escrito en «Tomo» y «Volumen» se puede guardar. */
const tomoValido = computed(() => tomoParte.value !== null && volumenParte.value !== null)
/** El tomo de la copia que se guarda («t.1», «v.2», «t.1-v.II»; vacío si no tiene). */
const claveTomo = computed(() => (tomoValido.value ? claveDe(tomoParte.value as string, volumenParte.value as string, volumenPrimeroCopia.value) : ''))
/** Los tomos que ya tiene la obra elegida, con cuántas copias, para elegir uno o escribir otro. */
const tomosDeLaObra = computed(() => {
  const cuenta = new Map<string, number>()
  for (const e of obraDelEjemplar.value?.ejemplares ?? []) if (e.tomo) cuenta.set(e.tomo, (cuenta.get(e.tomo) ?? 0) + 1)
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
const fotoCondicionesEjemplar = ref('')
const editarConCambios = computed(() => !!copiaEditando.value && (JSON.stringify(formEditar) !== fotoEditar.value || (!!registroEditandoInterbibliotecario.value && !registroEditandoInterbibliotecario.value.devuelto_el && JSON.stringify(datosCondicionesEjemplar()) !== fotoCondicionesEjemplar.value)))

/** Lo escrito en Tomo, Volumen y Copia, leído; y el texto de volumen que queda («t.1-v.II c.2», «Anexo c.1»). */
const tomoParteEditar = computed(() => leerParte(formEditar.tomo, 't'))
const volumenParteEditar = computed(() => leerParte(formEditar.parte, 'v'))
const volumenPrimeroEditar = ref(false)
/** «Anexo v.1»: la designación va antes del volumen, como se escribió en Horizon. */
const otraPrimeroEditar = ref(false)
function alSalirDelTomoEditar() {
  const volumenPrimero = repartirTomo(formEditar)
  if (volumenPrimero !== null) volumenPrimeroEditar.value = volumenPrimero
}
/** «2», «c.2», «c 2», «copia 2», «Copia 2» → «2»: el número de copia, como se escriba. */
const numeroDeCopiaEscrito = (texto: string) => texto.trim().replace(/^(?:copia|c)\.?\s*/i, '')
const copiaEditarValida = computed(() => /^\d{0,4}$/.test(numeroDeCopiaEscrito(formEditar.copia)) && numeroDeCopiaEscrito(formEditar.copia) !== '0')
const volumenEditar = computed(() => {
  if (tomoParteEditar.value === null || volumenParteEditar.value === null) return ''
  const clave = claveDe(tomoParteEditar.value, volumenParteEditar.value, volumenPrimeroEditar.value)
  const otra = formEditar.otra.trim()
  const base = (otraPrimeroEditar.value ? [otra, clave] : [clave, otra]).filter(Boolean).join(' ')
  const copia = numeroDeCopiaEscrito(formEditar.copia)
  return copia ? `${base} c.${copia}`.trim() : base
})
const edicionValida = computed(() => tomoParteEditar.value !== null && volumenParteEditar.value !== null && copiaEditarValida.value)

/** Abre la copia en la pestaña «Editar ejemplar», con sus datos en el formulario. */
function editarCopia(copia: Ejemplar, libro: Libro) {
  if (copia.prestamo_interbibliotecario && !(modo.value === 'interbibliotecario' && tareaInterbibliotecaria.value === 'editar_ejemplar')) {
    void abrirEjemplarDeRecepcion(copia.codigo_barras)
    return
  }
  if (!(modo.value === 'interbibliotecario' && tareaInterbibliotecaria.value === 'editar_ejemplar')) modo.value = 'editar_copia'
  copiaEditando.value = { copia, libro }
  codigoAEditar.value = copia.codigo_barras
  // El texto se lee en sus partes (tomo, volumen, otra designación y copia), sin cambiarlo:
  // «t.1-v.II c.2», «t.5 Apéndice», «Anexo v.1», «Cuadernillo-c.1», como los escribe Horizon.
  const leido = leerTomoCopia(copia.volumen)
  const junto = leido ? null : separarTomoYDesignacion(copia.volumen)
  const casillas = casillasDeClave(leido?.clave ?? junto?.clave ?? '')
  volumenPrimeroEditar.value = casillas.volumenPrimero
  otraPrimeroEditar.value = !!junto?.otraPrimero
  const soloOtra = leido || junto ? null : (copia.volumen ?? '').match(/^(.*?)[\s\-,;/]*c\.?\s*(\d{1,4})\.?$/i)
  Object.assign(formEditar, {
    ubicacion_id: copia.ubicacion_id ?? '',
    tomo: casillas.tomo,
    parte: casillas.volumen,
    copia: leido?.copia ?? junto?.copia ?? soloOtra?.[2] ?? numeroDeCopia(copia) ?? '',
    otra: junto?.otra ?? (leido ? '' : soloOtra ? soloOtra[1] : copia.volumen ?? ''),
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
    if (data.prestamo_interbibliotecario) {
      buscandoCopiaAEditar.value = false
      await abrirEjemplarDeRecepcion(data.codigo_barras)
    } else {
      registroEditandoInterbibliotecario.value = null
      modo.value = 'editar_copia'
      editarCopia(data, data.libro)
    }
  } catch {
    toast.error(`No hay ninguna copia con el código ${texto}`)
  } finally {
    buscandoCopiaAEditar.value = false
  }
}

/** Suelta la copia (sin guardar) para escanear otra. */
function soltarCopiaEditando() {
  copiaEditando.value = null
  registroEditandoInterbibliotecario.value = null
  codigoAEditar.value = ''
  Object.assign(formEditar, formEditarVacio())
  fotoEditar.value = ''
  nextTick(() => document.getElementById('editar-copia-codigo')?.focus())
}

// ---------- Confirmar antes de guardar o de descartar los cambios de una copia ----------
const confirmandoGuardarCopia = ref(false)
const confirmandoDescartarCopia = ref(false)
/** Lo que cambió (campo, antes y después), para verlo antes de guardar o de descartar. */
const cambiosEdicion = computed(() => {
  const actual = copiaEditando.value
  if (!actual || !fotoEditar.value) return []
  const antes = JSON.parse(fotoEditar.value) as ReturnType<typeof formEditarVacio>
  const lista: { campo: string; antes: string; despues: string }[] = []
  const agregar = (campo: string, a: string, d: string) => {
    if (a !== d) lista.push({ campo, antes: a, despues: d })
  }
  const texto = (v: unknown) => String(v ?? '').trim() || '—'
  const ubicacion = (id: number | '') => ubicacionesOpciones.value.find((u) => u.id === id)?.nombre ?? 'Sin especificar'
  const forma = (v: string) => FORMAS_ADQUISICION.find((f) => f.valor === v)?.nombre ?? 'Sin especificar'
  const precio = (v: number | string) => (v === '' || v === null ? 'Sin precio' : `$${Number(v).toLocaleString('es-CL', { maximumFractionDigits: 2 })}`)
  const coleccion = (v: string) => v.trim() || `${actual.libro.coleccion ?? 'Sin colección'} (la del libro)`
  if ([antes.tomo, antes.parte, antes.copia, antes.otra].join('|') !== [formEditar.tomo, formEditar.parte, formEditar.copia, formEditar.otra].join('|')) {
    lista.push({
      campo: 'Tomo, volumen y copia',
      antes: textoCopia(actual.copia.volumen, actual.copia.etiqueta_copia) ?? 'Sin tomo ni copia',
      despues: textoCopia(volumenEditar.value, null) ?? 'Sin tomo ni copia',
    })
  }
  agregar('Ubicación física', ubicacion(antes.ubicacion_id), ubicacion(formEditar.ubicacion_id))
  agregar('Colección', coleccion(antes.coleccion), coleccion(formEditar.coleccion))
  agregar('Precio', precio(antes.precio), precio(formEditar.precio))
  agregar('Forma de adquisición', forma(antes.forma_adquisicion), forma(formEditar.forma_adquisicion))
  agregar('N.º de registro', texto(antes.numero_registro), texto(formEditar.numero_registro))
  agregar('Nota pública', texto(antes.nota_publica), texto(formEditar.nota_publica))
  agregar('Nota interna', texto(antes.nota_interna), texto(formEditar.nota_interna))
  if (registroEditandoInterbibliotecario.value && fotoCondicionesEjemplar.value && !registroEditandoInterbibliotecario.value.devuelto_el) {
    const condicionesAntes = JSON.parse(fotoCondicionesEjemplar.value) as ReturnType<typeof datosCondicionesEjemplar>
    agregar('Biblioteca propietaria', texto(condicionesAntes.biblioteca), texto(formIngreso.biblioteca))
    agregar('Fecha de ingreso', texto(condicionesAntes.fecha_ingreso), texto(formIngreso.fecha_ingreso))
    agregar('Devolver a su biblioteca el', texto(condicionesAntes.fecha_limite), texto(formIngreso.fecha_limite))
    agregar('Nota interbibliotecaria', texto(condicionesAntes.nota), texto(formIngreso.nota))
  }
  return lista
})

/** «Guardar cambios»: se revisa lo escrito y se muestra qué cambia antes de guardar. */
function pedirGuardarEdicionCopia() {
  if (!copiaEditando.value) {
    toast.error('Primero escanea o escribe el código de la copia')
    irA('ejemplar')
    return
  }
  if (!edicionValida.value) {
    toast.error('Revisa el tomo, el volumen y la copia: mira los ejemplos bajo cada casilla')
    irA('datos')
    return
  }
  if (registroEditandoInterbibliotecario.value && !registroEditandoInterbibliotecario.value.devuelto_el
    && (!formIngreso.biblioteca.trim() || !formIngreso.fecha_ingreso || formIngreso.fecha_ingreso > hoyIngreso || formIngreso.fecha_limite <= formIngreso.fecha_ingreso)) {
    toast.error('Completa la biblioteca propietaria y una fecha de devolución posterior al ingreso.')
    irA('prestamo-interbibliotecario')
    return
  }
  if (!editarConCambios.value) {
    toast.error('No hay cambios por guardar')
    return
  }
  confirmandoGuardarCopia.value = true
}

/** «Descartar y editar otra»: con cambios sin guardar, se avisa qué se pierde. */
function pedirDescartarCopia() {
  if (editarConCambios.value) confirmandoDescartarCopia.value = true
  else soltarCopiaEditando()
}

/** Confirmado el descarte: la copia queda como estaba y se puede escanear otra. */
function descartarCambiosCopia() {
  confirmandoDescartarCopia.value = false
  soltarCopiaEditando()
}

async function guardarEdicionCopia() {
  const actual = copiaEditando.value
  if (!actual) return
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
      ...(registroEditandoInterbibliotecario.value && !registroEditandoInterbibliotecario.value.devuelto_el ? { interbibliotecario: datosCondicionesEjemplar() } : {}),
    })
    toast.success(`Copia ${data.codigo_barras} actualizada`)
    confirmandoGuardarCopia.value = false
    editarCopia({ ...actual.copia, ...data, libro: undefined }, actual.libro)
    if (registroEditandoInterbibliotecario.value) {
      registroEditandoInterbibliotecario.value = await buscarRegistroInterbibliotecario(data.codigo_barras) ?? registroEditandoInterbibliotecario.value
      fotoCondicionesEjemplar.value = JSON.stringify(datosCondicionesEjemplar())
      fotoRecepcion.value = estadoRecepcion()
    }
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo guardar la copia'))
  } finally {
    guardando.value = false
  }
}

/**
 * La «c.N» que seguiría (la de mayor número + 1 entre las copias del mismo tomo; sin tomo,
 * entre las que tienen la misma designación), o null si no tiene ninguna: es solo una
 * sugerencia, la copia lleva el número que se escriba (o ninguno).
 */
const copiaSugerida = computed<number | null>(() => {
  if (!tomoValido.value || !obraDelEjemplar.value) return null
  const tomo = claveTomo.value || null
  const otra = formCopia.volumen.trim()
  const delGrupo = (obraDelEjemplar.value.ejemplares ?? []).filter((e) => (tomo ? e.tomo === tomo : !e.tomo && (e.volumen ?? '').trim() === otra))
  if (!delGrupo.length) return null
  return Math.max(0, ...delGrupo.map((e) => Number((e.etiqueta_copia ?? '').replace(/^c\./, '')) || 0)) + 1
})
/** El número escrito en «Copia» («2», «c.2» → «2»); vacío = sin número. */
const copiaEscrita = computed(() => numeroDeCopiaEscrito(formCopia.copia))
const copiaEscritaValida = computed(() => /^\d{0,4}$/.test(copiaEscrita.value) && copiaEscrita.value !== '0')
/** Cómo queda el texto de la copia: «t.1-v.II c.2», «Anexo» (con «c.2» aparte) o nada. */
const volumenCopiaNueva = computed(() => {
  const copia = copiaEscrita.value ? ` c.${copiaEscrita.value}` : ''
  const clave = tomoValido.value ? claveTomo.value : ''
  return `${[clave, formCopia.volumen.trim()].filter(Boolean).join(' ')}${copia}`.trim()
})

// ---------- Recepción interbibliotecaria con ficha y ejemplares completos ----------
const hoyIngreso = enPuntaArenas().fecha
const formIngreso = reactive({ biblioteca: '', fecha_ingreso: hoyIngreso, fecha_limite: '', nota: '' })
const ejemplaresPendientes = ref<ReturnType<typeof datosCopiaNueva>[]>([])
const ejemplaresARecibir = computed(() => [...ejemplaresPendientes.value, ...(formCopia.codigo_barras.trim() ? [datosCopiaNueva()] : [])])
type RecepcionRegistrada = RegistroInterbibliotecario & { numero_ficha: number; cantidad: number; registros: RegistroInterbibliotecario[] }
const recepcionGuardada = ref<RecepcionRegistrada | null>(null)
const confirmandoIngreso = ref(false)
const fichaInterbibliotecariaGuardada = ref<FichaInterbibliotecaria | null>(null)
const topeIngreso = ref<string | null>(null)
let solicitudTope = 0
watch([() => modo.value, () => formIngreso.fecha_limite, () => esEdicionEjemplar.value ? formEditar.ubicacion_id : formCopia.ubicacion_id], async () => {
  const solicitud = ++solicitudTope
  topeIngreso.value = null
  if (modo.value !== 'interbibliotecario' || !formIngreso.fecha_limite) return
  try {
    const { data } = await api.get<{ fecha_tope: string }>('/prestamos-interbibliotecarios/fecha-tope', {
      params: { fecha_limite: formIngreso.fecha_limite, ubicacion_id: (esEdicionEjemplar.value ? formEditar.ubicacion_id : formCopia.ubicacion_id) || undefined },
    })
    if (solicitud === solicitudTope) topeIngreso.value = data.fecha_tope
  } catch { /* El servidor volverá a calcular el plazo al registrar. */ }
})
function datosCondicionesEjemplar() {
  return { biblioteca: formIngreso.biblioteca.trim(), fecha_ingreso: formIngreso.fecha_ingreso,
    fecha_limite: formIngreso.fecha_limite, nota: formIngreso.nota.trim() || null }
}

function estadoRecepcion() {
  if (esEdicionEjemplar.value) return JSON.stringify(formEditar)
  if (esIngresoEjemplares.value) return JSON.stringify({ copia: formCopia, pendientes: ejemplaresPendientes.value })
  return JSON.stringify({ ficha: esFicha.value ? form : null, obra: libroParaCopia.value?.id ?? null, prestamo: formIngreso })
}
const fotoRecepcion = ref('')
const requisitosIngreso = computed<Requisito[]>(() => esIngresoEjemplares.value ? [
  { texto: 'Ficha interbibliotecaria elegida', listo: !!fichaInterbibliotecaria.value, seccion: 'copia' },
  { texto: 'Al menos un ejemplar con código de barras', listo: ejemplaresARecibir.value.length > 0, seccion: 'copia' },
] : [
  { texto: esFicha.value ? 'Título de la ficha' : 'Ficha existente elegida', listo: esFicha.value ? !!form.titulo.trim() : !!libroParaCopia.value, seccion: esFicha.value ? 'titulo' : 'obra' },
  { texto: 'Biblioteca propietaria', listo: !!formIngreso.biblioteca.trim(), seccion: 'prestamo-interbibliotecario' },
  { texto: 'Fecha de ingreso válida', listo: !!formIngreso.fecha_ingreso && formIngreso.fecha_ingreso <= hoyIngreso, seccion: 'prestamo-interbibliotecario' },
  { texto: 'Fecha de devolución posterior al ingreso', listo: !!formIngreso.fecha_limite && formIngreso.fecha_limite > formIngreso.fecha_ingreso, seccion: 'prestamo-interbibliotecario' },
])
function cambiarTareaInterbibliotecaria(tarea: TareaInterbibliotecaria) {
  resetForm()
  cambiarObra()
  soltarCopiaEditando()
  registroEditandoInterbibliotecario.value = null
  Object.assign(formCopia, copiaVacia())
  Object.assign(formIngreso, { biblioteca: '', fecha_ingreso: hoyIngreso, fecha_limite: '', nota: '' })
  ejemplaresPendientes.value = []
  fichaInterbibliotecaria.value = null
  fichaInterbibliotecariaGuardada.value = null
  recepcionGuardada.value = null
  tareaInterbibliotecaria.value = tarea
  tareaInterbibliotecariaPendiente.value = null
  seleccionInterbibliotecariaPendiente.value = null
  origenIngreso.value = 'nuevo'
  fotoRecepcion.value = estadoRecepcion()
}
async function confirmarCambioInterbibliotecario() {
  const seleccion = seleccionInterbibliotecariaPendiente.value
  const tarea = tareaInterbibliotecariaPendiente.value
  seleccionInterbibliotecariaPendiente.value = null
  tareaInterbibliotecariaPendiente.value = null
  if (seleccion) await cargarFichaInterbibliotecaria(seleccion.ficha.id, seleccion.tarea)
  else if (tarea) cambiarTareaInterbibliotecaria(tarea)
}
async function elegirFichaInterbibliotecaria(ficha: FichaInterbibliotecaria, tarea: 'editar' | 'ejemplares') {
  if (guardando.value || cargandoFicha.value || buscandoCopiaAEditar.value) return
  if (hayTrabajoSinGuardar.value) { seleccionInterbibliotecariaPendiente.value = { ficha, tarea }; return }
  await cargarFichaInterbibliotecaria(ficha.id, tarea)
}
async function cargarFichaInterbibliotecaria(id: number, tarea: 'editar' | 'ejemplares') {
  cargandoFicha.value = true
  try {
    const { data } = await api.get<FichaInterbibliotecaria>(`/prestamos-interbibliotecarios/fichas/${id}`)
    cambiarTareaInterbibliotecaria(tarea)
    if (tarea === 'editar') editar(data.libro, true)
    modo.value = 'interbibliotecario'
    fichaInterbibliotecaria.value = data
    libroParaCopia.value = data.libro
    Object.assign(formIngreso, { biblioteca: data.biblioteca, fecha_ingreso: data.fecha_ingreso, fecha_limite: data.fecha_limite, nota: data.nota ?? '' })
    fotoRecepcion.value = estadoRecepcion()
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (e) {
    // Nunca deja habilitada la ficha previamente elegida tras fallar la carga de otra.
    cambiarTareaInterbibliotecaria(tarea)
    toast.error(mensajeDeError(e, 'No se pudo abrir la ficha interbibliotecaria'))
  } finally { cargandoFicha.value = false }
}
async function guardarFichaInterbibliotecaria() {
  if (guardando.value) return
  guardando.value = true
  try {
    const datos = {
      ...(tareaInterbibliotecaria.value === 'editar' || origenIngreso.value === 'nuevo' ? { ficha: datosFicha() } : { libro_id: libroParaCopia.value?.id }),
      biblioteca: formIngreso.biblioteca.trim(), fecha_ingreso: formIngreso.fecha_ingreso,
      fecha_limite: formIngreso.fecha_limite, nota: formIngreso.nota.trim() || null,
    }
    const { data } = tareaInterbibliotecaria.value === 'editar' && fichaInterbibliotecaria.value
      ? await api.patch<FichaInterbibliotecaria>(`/prestamos-interbibliotecarios/fichas/${fichaInterbibliotecaria.value.id}`, datos)
      : await api.post<FichaInterbibliotecaria>('/prestamos-interbibliotecarios/fichas', datos)
    fichaInterbibliotecariaGuardada.value = data
    // Guardar una ficha no abre ni exige el ingreso de ejemplares.
    editar(data.libro, true)
    modo.value = 'interbibliotecario'
    tareaInterbibliotecaria.value = 'editar'
    fichaInterbibliotecaria.value = data
    libroParaCopia.value = data.libro
    Object.assign(formIngreso, { biblioteca: data.biblioteca, fecha_ingreso: data.fecha_ingreso, fecha_limite: data.fecha_limite, nota: data.nota ?? '' })
    fotoRecepcion.value = estadoRecepcion()
    confirmandoIngreso.value = false
    toast.success(`Ficha interbibliotecaria N.º ${data.libro.numero_ficha} guardada`)
    await cargar()
  } catch (e) {
    confirmandoIngreso.value = false
    toast.error(mensajeDeError(e, 'No se pudo guardar la ficha interbibliotecaria'))
  } finally { guardando.value = false }
}
function validarCopiaDeIngreso() {
  if (!formCopia.codigo_barras.trim()) { toast.error('Falta el código de barras del ejemplar'); return false }
  if (!tomoValido.value || !copiaEscritaValida.value) { toast.error('Revisa el tomo, volumen y número de copia'); return false }
  if (ejemplaresPendientes.value.some((e) => e.codigo_barras === formCopia.codigo_barras.trim())) { toast.error('Ese código ya está en los ejemplares preparados'); return false }
  return true
}
function agregarEjemplarAlLote() {
  if (!validarCopiaDeIngreso()) return
  if (ejemplaresPendientes.value.length >= 49) { toast.info('Registra esta recepción antes de agregar más ejemplares (máximo 50)'); return }
  ejemplaresPendientes.value.push(datosCopiaNueva())
  Object.assign(formCopia, { codigo_barras: '', copia: '', numero_registro: '', nota_publica: '', nota_interna: '' })
  nextTick(() => document.getElementById('copia-codigo')?.focus())
}
function pedirRegistroInterbibliotecario() {
  if (guardando.value) return
  const faltantes = requisitosIngreso.value.filter((r) => !r.listo)
  if (faltantes.length) { toast.error(`Falta completar: ${faltantes.map((r) => r.texto).join(', ')}`); irA(faltantes[0].seccion); return }
  if (esIngresoEjemplares.value && formCopia.codigo_barras.trim() && !validarCopiaDeIngreso()) return
  confirmandoIngreso.value = true
}
async function registrarRecepcion() {
  if (!esIngresoEjemplares.value) return guardarFichaInterbibliotecaria()
  if (guardando.value || !fichaInterbibliotecaria.value) return
  guardando.value = true
  try {
    const { data } = await api.post<RecepcionRegistrada>('/prestamos-interbibliotecarios', {
      ficha_interbibliotecaria_id: fichaInterbibliotecaria.value.id,
      ejemplares: ejemplaresARecibir.value,
    })
    recepcionGuardada.value = data
    confirmandoIngreso.value = false
    const ubicacion = formCopia.ubicacion_id
    const coleccion = formCopia.coleccion
    libroParaCopia.value = data.ejemplar.libro
    ejemplaresPendientes.value = []
    Object.assign(formCopia, copiaVacia(), { ubicacion_id: ubicacion, coleccion })
    fotoRecepcion.value = estadoRecepcion()
    toast.success(`Ficha N.º ${data.numero_ficha}: ${data.cantidad} ejemplar(es) recibidos`)
    // El ingreso ya está confirmado. Una falla al refrescar no debe presentarlo como rechazado.
    try {
      const actualizada = await api.get<Libro>(`/libros/${data.ejemplar.libro_id}`)
      libroParaCopia.value = actualizada.data
    } catch { /* La siguiente búsqueda traerá los ejemplares actualizados. */ }
    await Promise.all([cargar(), cargarCatalogos()])
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (e) {
    confirmandoIngreso.value = false
    toast.error(mensajeDeError(e, 'No se pudo registrar la recepción. Revisa los datos de la ficha y ejemplares.'))
  } finally { guardando.value = false }
}
// El título puede compartir copias propias y varias recepciones de otras bibliotecas.
// El origen se elige antes de crear una copia para conservar las condiciones correctas.
const seleccionOrigenInterbibliotecario = ref<{ libro: Libro; tarea: 'editar' | 'ejemplares' } | null>(null)
const ejemplarPropioConfirmado = ref(false)
function abrirContextoInterbibliotecario(libro: Libro, tarea: 'editar' | 'ejemplares') {
  const fichas = libro.fichas_interbibliotecarias ?? []
  if (tarea === 'editar' && fichas.length === 1) { void cargarFichaInterbibliotecaria(fichas[0].id, tarea); return }
  cambiarTareaInterbibliotecaria(tarea)
  modo.value = 'interbibliotecario'
  fotoRecepcion.value = estadoRecepcion()
  seleccionOrigenInterbibliotecario.value = { libro, tarea }
}
function elegirOrigenInterbibliotecario(id: number) {
  const tarea = seleccionOrigenInterbibliotecario.value?.tarea
  seleccionOrigenInterbibliotecario.value = null
  if (tarea) void cargarFichaInterbibliotecaria(id, tarea)
}
function elegirEjemplarPropio() {
  const libro = seleccionOrigenInterbibliotecario.value?.libro
  seleccionOrigenInterbibliotecario.value = null
  if (libro) agregarCopiaDe(libro, true)
}

function puedeAbrirEditorDeRecepcion() {
  if (hayTrabajoSinGuardar.value) { toast.info('Hay cambios sin guardar: guárdalos o usa «Descartar cambios» antes de abrir otro editor.'); return false }
  return true
}
function abrirFichaDeRecepcion(id: number) { if (!buscandoCopiaAEditar.value && !guardando.value && !cargandoFicha.value && puedeAbrirEditorDeRecepcion()) return cargarFichaInterbibliotecaria(id, 'editar') }
async function abrirEjemplarDeRecepcion(codigo: string) {
  if (guardando.value || buscandoCopiaAEditar.value || !codigo.trim() || !puedeAbrirEditorDeRecepcion()) return
  buscandoCopiaAEditar.value = true
  try {
    const registro = await buscarRegistroInterbibliotecario(codigo)
    if (!registro) { toast.error('Ese ejemplar no pertenece a un préstamo interbibliotecario'); return }
    const { data: copia } = await api.get<Ejemplar & { libro: Libro }>(`/ejemplares/${encodeURIComponent(registro.ejemplar.codigo_barras)}`)
    cambiarTareaInterbibliotecaria('editar_ejemplar')
    modo.value = 'interbibliotecario'
    registroEditandoInterbibliotecario.value = registro
    Object.assign(formIngreso, { biblioteca: registro.biblioteca, fecha_ingreso: registro.fecha_ingreso, fecha_limite: registro.fecha_limite, nota: registro.nota ?? '' })
    fotoCondicionesEjemplar.value = JSON.stringify(datosCondicionesEjemplar())
    editarCopia(copia, copia.libro)
    fotoRecepcion.value = estadoRecepcion()
  } catch (e) { toast.error(mensajeDeError(e, 'No se pudo abrir el ejemplar interbibliotecario')) }
  finally { buscandoCopiaAEditar.value = false }
}
async function buscarRegistroInterbibliotecario(codigo: string) {
  const { data } = await api.get<Paginado<RegistroInterbibliotecario>>('/prestamos-interbibliotecarios', { params: { codigo: codigo.trim(), vista: 'todos', por_pagina: 1 } })
  return data.data.find((p) => p.ejemplar.codigo_barras === codigo.trim() || p.ejemplar.codigo_lomo === codigo.trim())
}
async function abrirEstadoDeRecepcion(codigo: string) {
  if (!puedeCambiarEstado.value || !puedeAbrirEditorDeRecepcion() || buscandoCopiaAEditar.value || cargandoFicha.value || guardando.value) return
  buscandoCopiaAEditar.value = true
  try {
    const registro = await buscarRegistroInterbibliotecario(codigo)
    if (!registro) { toast.error('Ese ejemplar no pertenece a un préstamo interbibliotecario'); return }
    if (registro.devuelto_el) { toast.info('Este ejemplar ya se devolvió a su biblioteca propietaria'); return }
    prestamoParaEstado.value = registro
  } catch (e) { toast.error(mensajeDeError(e, 'No se pudo abrir el estado del ejemplar')) }
  finally { buscandoCopiaAEditar.value = false }
}
async function actualizarEstadoInterbibliotecario(ejemplar: Ejemplar) {
  if (copiaEditando.value?.copia.id === ejemplar.id) Object.assign(copiaEditando.value.copia, ejemplar)
  if (registroEditandoInterbibliotecario.value?.ejemplar_id === ejemplar.id) Object.assign(registroEditandoInterbibliotecario.value.ejemplar, ejemplar)
  for (const r of recepcionGuardada.value?.registros ?? []) if (r.ejemplar_id === ejemplar.id) Object.assign(r.ejemplar, ejemplar)
  await cargar()
}

const obligatoriosCopia = computed<Requisito[]>(() => [
  { texto: 'Obra elegida', listo: !!libroParaCopia.value, seccion: 'obra' },
  { texto: 'Código de barras', listo: !!formCopia.codigo_barras.trim(), seccion: 'copia' },
])
const obligatoriosEditarCopia = computed<Requisito[]>(() => [
  { texto: 'Ejemplar elegido', listo: !!copiaEditando.value, seccion: 'ejemplar' },
  ...(registroEditandoInterbibliotecario.value && !registroEditandoInterbibliotecario.value.devuelto_el ? [
    { texto: 'Biblioteca propietaria', listo: !!formIngreso.biblioteca.trim(), seccion: 'prestamo-interbibliotecario' },
    { texto: 'Fecha de ingreso válida', listo: !!formIngreso.fecha_ingreso && formIngreso.fecha_ingreso <= hoyIngreso, seccion: 'prestamo-interbibliotecario' },
    { texto: 'Devolución posterior al ingreso', listo: !!formIngreso.fecha_limite && formIngreso.fecha_limite > formIngreso.fecha_ingreso, seccion: 'prestamo-interbibliotecario' },
  ] : []),
])
const obligatorios = computed(() =>
  esEdicionEjemplar.value ? obligatoriosEditarCopia.value : modo.value === 'interbibliotecario' ? requisitosIngreso.value : modo.value === 'copia' ? obligatoriosCopia.value : obligatoriosNuevo.value,
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
// El portal solo muestra títulos con al menos una copia «En estante».
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
  if (esEdicionEjemplar.value) {
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
  if (esObraExistente.value || esIngresoEjemplares.value) {
    const libro = libroParaCopia.value
    if (!libro) return null
    return armarVista(
      {
        titulo: libro.titulo,
        autores: (libro.autores ?? []).map((a) => a.nombre),
        categorias: (libro.categorias ?? []).map((c) => c.nombre),
        tipo: libro.tipo_material?.nombre ?? null,
      },
      estadoDeCopias(libro.ejemplares ?? [], modo.value === 'interbibliotecario'
        ? 'Los ejemplares recibidos ingresan «Por colocar»: se suman al portal cuando pasen a «En estante».'
        : 'La copia nueva nace en «Inventario»: se suma al portal cuando pase a «En estante».'),
    )
  }
  const estado = editandoId.value
    ? estadoDeCopias(libroEditado.value?.ejemplares ?? [], '')
    : modo.value === 'interbibliotecario' ? {
        texto: 'Ficha sin ejemplares',
        detalle: 'Los ejemplares se ingresan después',
        punto: 'bg-sky-500',
        color: 'text-sky-700',
        nota: 'Guardar esta ficha no crea ejemplares ni la publica en el portal.',
      } : {
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
  if (esEdicionEjemplar.value) return 'Guardar cambios del ejemplar'
  if (modo.value === 'interbibliotecario') return esIngresoEjemplares.value ? 'Registrar ejemplares recibidos' : tareaInterbibliotecaria.value === 'editar' ? `Guardar ficha N.º ${fichaInterbibliotecaria.value?.libro.numero_ficha ?? ''}` : 'Crear ficha interbibliotecaria'
  if (modo.value === 'copia') return 'Agregar copia'
  return editandoId.value ? `Guardar ficha N.º ${libroEditado.value?.numero_ficha}` : 'Crear ficha'
})

const notaCatalogador = computed(() => {
  if (modo.value === 'interbibliotecario' && esEdicionEjemplar.value) return 'Editar el ejemplar conserva su biblioteca propietaria, fecha de devolución y vínculo con el préstamo.'
  if (modo.value === 'interbibliotecario') return esIngresoEjemplares.value
    ? 'Los ejemplares conservan la biblioteca propietaria y la fecha de devolución de la ficha elegida.'
    : 'Guardar la ficha no crea ejemplares. Puedes ingresarlos después en «Agregar ejemplares».'
  if (modo.value === 'copia') return 'La copia lleva la «c.» que escribas (vacía, sin número) y nace en «Inventario».'
  if (esEdicionEjemplar.value) return copiaEditando.value && !editarConCambios.value ? 'Sin cambios por guardar.' : 'El código de barras y el estado no se cambian aquí.'
  const marca = etiqueta('catalogador') || '910'
  if (editandoId.value) return `Catalogado por ${catalogadorEditado.value ?? 'alguien sin registro'} (${marca})`
  return `Catalogador (${marca}): ${auth.staff?.nombre ?? 'tu cuenta'} · se registra automáticamente`
})


// ---------- Carga ----------
async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<Paginado<Libro>>('/libros', {
      params: { q: busqueda.value.trim() || undefined, page: paginaLibros.value, por_pagina: 10 },
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
      const sinCambiosInterbibliotecarios = modo.value === 'interbibliotecario' && !hayTrabajoSinGuardar.value
      form.tipo_material_id = tipoPorDefecto.value
      if (sinCambiosInterbibliotecarios) fotoRecepcion.value = estadoRecepcion()
    }
  } catch {
    // Los combobox simplemente no ofrecen sugerencias — no bloquea catalogar.
  }
}

async function abrirDesdeListado() {
  const fichaId = Number(route.query.ficha_interbibliotecaria)
  if (fichaId) {
    await cargarFichaInterbibliotecaria(fichaId, route.query.tarea === 'ejemplares' ? 'ejemplares' : 'editar')
    await router.replace({ query: {} })
    return
  }
  if (route.query.interbibliotecario === '1') {
    cambiarTareaInterbibliotecaria('nuevo')
    modo.value = 'interbibliotecario'
    await router.replace({ query: {} })
    return
  }
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
    let codigo = data.codigo_barras
    if (modo.value === 'interbibliotecario' && destino === 'copia' && /^\d+$/.test(codigo)) {
      const preparados = new Set(ejemplaresPendientes.value.map((e) => e.codigo_barras))
      while (preparados.has(codigo)) codigo = (BigInt(codigo) + 1n).toString().padStart(data.codigo_barras.length, '0')
    }
    if (destino === 'nuevo') form.codigo_barras = codigo
    else formCopia.codigo_barras = codigo
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

function editar(libro: Libro, editorComun = false) {
  if (!editorComun && libro.fichas_interbibliotecarias?.length) { abrirContextoInterbibliotecario(libro, 'editar'); return }
  modo.value = 'editar'
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

async function confirmarEditar() {
  const libro = libroAEditar.value
  libroAEditar.value = null
  if (!libro) return
  await cargarFichaParaEditar(libro.id)
}

async function cargarFichaParaEditar(id: number) {
  if (cargandoFicha.value || guardando.value) return
  cargandoFicha.value = true
  try {
    const { data } = await api.get<Libro>(`/libros/${id}`)
    editar(data)
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo abrir la ficha para editar'))
  } finally {
    cargandoFicha.value = false
  }
}

function pedirEditarFicha(libro: Libro) {
  if (cargandoFicha.value || guardando.value) return
  if (hayTrabajoSinGuardar.value) libroAEditar.value = libro
  else return cargarFichaParaEditar(libro.id)
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

function datosFicha() {
  return {
    ...form,
    anio_publicacion: form.anio_publicacion === '' ? null : Number(form.anio_publicacion),
    precio: form.precio === '' ? null : Number(form.precio),
    forma_adquisicion: form.forma_adquisicion || null,
    ubicacion_id: form.ubicacion_id === '' ? null : Number(form.ubicacion_id),
    tipo_material_id: form.tipo_material_id === '' ? null : Number(form.tipo_material_id),
    autores_roles: form.autores.map((n) => form.rolesAutores[n] || null),
  }
}

async function confirmarGuardar() {
  guardando.value = true
  try {
    const payload = datosFicha()
    if (editandoId.value) {
      const { data } = await api.patch<Libro>(`/libros/${editandoId.value}`, payload)
      editar(data)
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
  if (esEdicionEjemplar.value) return editarConCambios.value
  if (modo.value === 'interbibliotecario') return estadoRecepcion() !== fotoRecepcion.value
  if (modo.value === 'copia') {
    return JSON.stringify(formCopia) !== JSON.stringify(copiaVacia()) || !!libroParaCopia.value
  }
  return editandoId.value ? JSON.stringify(form) !== fotoEdicion.value : !formularioVacio.value
})

function cambiarModo(destino: ModoCatalogacion) {
  modoPendiente.value = null
  resetForm()
  cambiarObra()
  Object.assign(formCopia, copiaVacia())
  soltarCopiaEditando()
  desdeBorrador.value = false
  modo.value = destino
  tareaInterbibliotecaria.value = 'nuevo'
  fichaInterbibliotecaria.value = null
  fichaInterbibliotecariaGuardada.value = null
  origenIngreso.value = 'nuevo'
  ejemplaresPendientes.value = []
  Object.assign(formIngreso, { biblioteca: '', fecha_ingreso: hoyIngreso, fecha_limite: '', nota: '' })
  recepcionGuardada.value = null
  fotoRecepcion.value = estadoRecepcion()
  window.scrollTo({ top: 0, behavior: 'smooth' })
  nextTick(() => {
    const id = destino === 'editar' ? 'cat-buscar-ficha' : destino === 'nuevo' ? 'cat-titulo' : undefined
    if (id) document.getElementById(id)?.focus({ preventScroll: true })
  })
}
function pedirCambiarModo(destino: ModoCatalogacion) {
  if (destino === modoPrincipal.value || guardando.value || cargandoFicha.value || buscandoCopiaAEditar.value) return
  if (hayTrabajoSinGuardar.value) modoPendiente.value = destino
  else cambiarModo(destino)
}
function pedirOtraFicha() {
  if (hayTrabajoSinGuardar.value) modoPendiente.value = 'editar'
  else cambiarModo('editar')
}
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

function seleccionarLibroParaCopia(libro: Libro, propio = false) {
  if (modo.value === 'copia' && !propio && libro.fichas_interbibliotecarias?.length) { abrirContextoInterbibliotecario(libro, 'ejemplares'); return }
  ejemplarPropioConfirmado.value = propio
  clearTimeout(buscarCopiaTimer)
  libroParaCopia.value = libro
  resultadosCopia.value = []
  busquedaCopia.value = libro.titulo
}

/** Desde «Títulos catalogados»: salta al formulario de copia con ese título ya elegido. */
function agregarCopiaDe(libro: Libro, propio = false) {
  if (!propio && libro.fichas_interbibliotecarias?.length) { abrirContextoInterbibliotecario(libro, 'ejemplares'); return }
  modo.value = 'copia'
  seleccionarLibroParaCopia(libro, propio)
  // En la casilla queda el nombre: si se borra o se sigue escribiendo, se busca por nombre (no por N.º de ficha)
  buscarObraEn.value = 'resto'
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function cambiarObra() {
  ejemplarPropioConfirmado.value = false
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
  if (!copiaEscritaValida.value) {
    toast.error('En «Copia» va el número, por ejemplo «2», «c.2» o «copia 2» (o vacía, sin número)')
    document.getElementById('copia-numero')?.focus()
    return
  }
  if (!tomoValido.value) {
    toast.error('Revisa el tomo y el volumen: por ejemplo Tomo «1» y Volumen «II»')
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

function datosCopiaNueva() {
  return {
    codigo_barras: formCopia.codigo_barras.trim(),
    ubicacion_id: formCopia.ubicacion_id === '' ? null : Number(formCopia.ubicacion_id),
    // Tomo y otra designación juntos («t.5 Apéndice»): va como texto, igual que en Horizon
    volumen: claveTomo.value ? (formCopia.volumen.trim() ? `${claveTomo.value} ${formCopia.volumen.trim()}` : null) : formCopia.volumen || null,
    tomo: claveTomo.value && !formCopia.volumen.trim() ? claveTomo.value : null,
    // La «c.N» escrita, o null: sin número (no se inventa)
    copia: copiaEscrita.value ? Number(copiaEscrita.value) : null,
    precio: formCopia.precio === '' ? null : Number(formCopia.precio),
    forma_adquisicion: formCopia.forma_adquisicion || null,
    coleccion: formCopia.coleccion.trim() || null,
    nota_publica: formCopia.nota_publica.trim() || null,
    nota_interna: formCopia.nota_interna.trim() || null,
    numero_registro: formCopia.numero_registro.trim() || null,
  }
}

async function confirmarAgregarCopia() {
  if (!libroParaCopia.value) return
  guardando.value = true
  try {
    const titulo = libroParaCopia.value.titulo
    const { data } = await api.post<Ejemplar>('/ejemplares', {
      libro_id: libroParaCopia.value.id,
      ...(ejemplarPropioConfirmado.value ? { origen_ejemplar: 'propio' } : {}),
      ...datosCopiaNueva(),
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
  { id: 'editar' as const, label: 'Editar ficha', detalle: 'Busca y corrige un título existente', icono: 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.862 4.487zM16.862 4.487l2.651 2.651M19.5 13.5v5.25A2.25 2.25 0 0117.25 21H5.25A2.25 2.25 0 013 18.75V6.75A2.25 2.25 0 015.25 4.5h5.25', tono: 'bg-amber-50 text-dorado-700', activo: 'border-amber-700 bg-amber-700 shadow-amber-700/30', anillo: 'hover:border-amber-300' },
  { id: 'copia' as const, label: 'Nuevo ejemplar', icono: 'M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75M15.75 3.75H9.375c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V8.25L15.75 3.75z', tono: 'bg-emerald-50 text-emerald-700', activo: 'border-emerald-600 bg-emerald-600 shadow-emerald-600/30', anillo: 'hover:border-emerald-300', detalle: 'Una copia con su código de barras' },
  { id: 'editar_copia' as const, label: 'Editar ejemplar', icono: 'M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM16.5 13.5v6m3-3h-6', tono: 'bg-violet-50 text-violet-700', activo: 'border-violet-600 bg-violet-600 shadow-violet-600/30', anillo: 'hover:border-violet-300', detalle: 'Escanea su código y corrige sus datos' },
]

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}

function elegirTipoFicha(interbibliotecario: boolean) {
  if (modoPrincipal.value !== 'nuevo' || guardando.value) return
  modo.value = interbibliotecario ? 'interbibliotecario' : 'nuevo'
  tareaInterbibliotecaria.value = 'nuevo'
  origenIngreso.value = 'nuevo'
  // La elección del origen es un cambio de la ficha; los campos escritos se conservan.
  fotoRecepcion.value = ''
}

function accionPrincipal() {
  if (esEdicionEjemplar.value) return pedirGuardarEdicionCopia()
  if (modo.value === 'interbibliotecario') return pedirRegistroInterbibliotecario()
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
              :to="{ name: listadoDeOrigen, query: { volver: '1' } }"
              class="float-right mt-1 inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3 py-1.5 text-sm font-semibold text-white ring-1 ring-inset ring-white/30 transition-colors hover:bg-white/25"
              title="Vuelve al Listado de material con la misma búsqueda, filtros y página"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
              Volver al listado
            </router-link>
            <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Catalogar material</h1>
            <p v-if="modoPrincipal === 'editar'" class="mt-1 text-sm text-white/80">{{ editandoId ? 'Edición de una ficha existente' : 'Elige la ficha que quieres editar' }}</p>
          </div>
          <PestanasLibros actual="catalogacion-libros" en-banda class="relative mt-3 px-5" />
        </div>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-b-2xl p-3">
          <!-- Botones grandes, tipo tarjeta: cada uno dice qué hace; el elegido, en su color. -->
          <div class="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" role="tablist" aria-label="Qué catalogar">
            <button
              v-for="m in MODOS"
              :key="m.id"
              type="button"
              role="tab"
              :aria-selected="modoPrincipal === m.id"
              :disabled="guardando || cargandoFicha || buscandoCopiaAEditar"
              class="group flex min-h-20 items-center gap-3 rounded-xl border-2 px-3.5 py-3 text-left shadow-sm transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 active:translate-y-0 active:scale-[0.99] disabled:opacity-60"
              :class="modoPrincipal === m.id ? `${m.activo} text-white shadow-lg` : `border-gray-200 bg-white text-gray-800 ${m.anillo}`"
              @click="pedirCambiarModo(m.id)"
            >
              <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition-transform group-hover:scale-105"
                :class="modoPrincipal === m.id ? 'bg-blanco/20 text-white' : m.tono"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="m.icono" /></svg>
              </span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-bold leading-tight">{{ m.label }}</span>
                <span class="mt-1 block text-xs leading-snug" :class="modoPrincipal === m.id ? 'text-white/90' : 'text-gray-600'">{{ m.detalle }}</span>
              </span>
              <svg
                class="h-4 w-4 shrink-0 transition-transform"
                :class="modoPrincipal === m.id ? 'text-white' : 'text-gray-300 group-hover:translate-x-0.5 group-hover:text-gray-500'"
                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"
              >
                <path v-if="modoPrincipal === m.id" stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                <path v-else stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
              </svg>
            </button>
          </div>
        </div>
      </section>

      <section v-if="modoPrincipal === 'editar' && libroEditado" class="sticky top-2 z-20 mb-5 flex items-center gap-3 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 shadow-md" aria-label="Ficha en edición">
        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#b08d57] text-white sm:flex" aria-hidden="true">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.862 4.487z" /></svg>
        </span>
        <div class="min-w-0 flex-1">
          <p class="flex flex-wrap items-center gap-x-2 text-xs font-bold uppercase tracking-wide text-dorado-700">Editando ficha <span class="rounded-md border border-amber-200 bg-white px-2 py-0.5 font-mono text-sm text-gray-900">N.º {{ libroEditado.numero_ficha }}</span><span v-if="hayTrabajoSinGuardar" class="normal-case font-medium tracking-normal">Cambios sin guardar</span></p>
          <p class="mt-1 line-clamp-2 text-sm font-semibold leading-snug text-gray-900">{{ libroEditado.titulo }}</p>
          <p v-if="libroEditado.autores?.length" class="mt-0.5 truncate text-xs text-gray-600">{{ libroEditado.autores.map((a) => a.nombre).join(' · ') }}<template v-if="libroEditado.anio_publicacion"> · {{ libroEditado.anio_publicacion }}</template></p>
        </div>
        <button type="button" class="min-h-11 shrink-0 rounded-full border-2 border-amber-300 bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-950 transition hover:bg-amber-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-700 focus-visible:ring-offset-2 disabled:opacity-60" :disabled="guardando || cargandoFicha" @click="pedirOtraFicha">Elegir otra ficha</button>
      </section>

      <div v-if="modoPrincipal === 'editar' && !editandoId" class="mb-5 rounded-2xl border border-amber-200 bg-amber-50/70 px-5 py-4">
        <h2 class="font-serif text-xl font-semibold text-gray-900">Buscar una ficha para editar</h2>
        <p class="mt-1 text-sm text-gray-600">Busca por título, autor, ISBN, código de barras o número de ficha. Luego elige «Editar ficha».</p>
        <p v-if="cargandoFicha" class="mt-2 text-sm font-semibold text-dorado-700" role="status">Cargando la ficha…</p>
      </div>

      <section v-if="modoPrincipal === 'nuevo'" class="mb-5 overflow-hidden rounded-2xl border border-indigo-200 bg-gradient-to-r from-[#f6f7ff] to-[#f8f9ff] shadow-sm" aria-labelledby="origen-material-titulo">
        <div class="flex flex-col gap-4 px-5 py-3.5 lg:flex-row lg:items-center lg:justify-between lg:px-6">
          <div class="flex shrink-0 items-center gap-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600" aria-hidden="true">
              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m12 3-9 4.5L12 12l9-4.5L12 3Zm-9 9L12 16.5l9-4.5M3 16.5 12 21l9-4.5" /></svg>
            </span>
            <div>
              <h2 id="origen-material-titulo" class="text-sm font-bold text-slate-950">Origen del material</h2>
              <p class="mt-0.5 text-xs text-slate-700">Procedencia del material</p>
            </div>
          </div>
          <div class="flex w-full flex-col gap-1 rounded-2xl border border-indigo-200 bg-white/30 p-0.5 sm:flex-row sm:rounded-full lg:w-[34rem]" role="radiogroup" aria-labelledby="origen-material-titulo">
            <label class="relative flex-1 cursor-pointer" :class="guardando && 'pointer-events-none opacity-60'">
              <input class="peer sr-only" type="radio" name="origen-ficha" :checked="modo !== 'interbibliotecario'" :disabled="guardando" @change="elegirTipoFicha(false)" />
              <span class="flex min-h-10 items-center justify-center gap-2.5 rounded-full border px-4 py-2 text-sm sm:whitespace-nowrap font-semibold transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-500 peer-focus-visible:ring-offset-2" :class="modo !== 'interbibliotecario' ? 'border-transparent bg-gradient-to-r from-indigo-600 to-[#4b2ff4] text-white shadow-sm' : 'border-transparent text-slate-700 hover:bg-indigo-100/60 hover:text-indigo-950'">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.25v13.5m0-13.5C9.4 4.7 6.1 4.7 3 6.25v13.5c3.1-1.55 6.4-1.55 9 0m0-13.5c2.6-1.55 5.9-1.55 9 0v13.5c-3.1-1.55-6.4-1.55-9 0" /></svg>
                <span>Propios de la biblioteca</span>
                <svg class="h-4 w-4 shrink-0 text-white" :class="modo !== 'interbibliotecario' ? 'opacity-100' : 'opacity-0'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6" /></svg>
              </span>
            </label>
            <label class="relative flex-1 cursor-pointer" :class="guardando && 'pointer-events-none opacity-60'">
              <input class="peer sr-only" type="radio" name="origen-ficha" :checked="modo === 'interbibliotecario'" :disabled="guardando" @change="elegirTipoFicha(true)" />
              <span class="flex min-h-10 items-center justify-center gap-2.5 rounded-full border px-4 py-2 text-sm sm:whitespace-nowrap font-semibold transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-500 peer-focus-visible:ring-offset-2" :class="modo === 'interbibliotecario' ? 'border-transparent bg-gradient-to-r from-indigo-600 to-[#4b2ff4] text-white shadow-sm' : 'border-transparent text-slate-700 hover:bg-indigo-100/60 hover:text-indigo-950'">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8h16m0 0-4-4m4 4-4 4M20 16H4m0 0 4-4m-4 4 4 4" /></svg>
                <span>Préstamo interbibliotecario</span>
                <svg class="h-4 w-4 shrink-0 text-white" :class="modo === 'interbibliotecario' ? 'opacity-100' : 'opacity-0'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6" /></svg>
              </span>
            </label>
          </div>
        </div>
        <div v-if="modo === 'interbibliotecario'" class="border-t border-amber-100 bg-amber-50/60 px-4 py-3 sm:px-5">
          <p class="text-xs leading-relaxed text-amber-900">Completa la ficha y la biblioteca propietaria con su fecha de devolución. Los ejemplares conservarán ese plazo al ingresarlos.</p>
          <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs text-gray-700">
            <label class="flex items-center gap-2"><input v-model="origenIngreso" type="radio" value="nuevo" :disabled="guardando" class="accent-indigo-600" /> Crear los datos bibliográficos</label>
            <label class="flex items-center gap-2"><input v-model="origenIngreso" type="radio" value="catalogo" :disabled="guardando" class="accent-indigo-600" /> Usar un título ya catalogado</label>
          </div>
        </div>
      </section>
      <p v-if="modo === 'copia' && ejemplarPropioConfirmado" class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-950">Ejemplar propio de nuestra biblioteca. Este título también tiene préstamos interbibliotecarios, cuyos ejemplares se ingresan desde su ficha de préstamo.</p>
      <template v-if="modo === 'interbibliotecario'">
        <div v-if="fichaInterbibliotecariaGuardada" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3" role="status">
          <p class="text-sm font-semibold text-emerald-950">Ficha interbibliotecaria N.º {{ fichaInterbibliotecariaGuardada.libro.numero_ficha }} guardada · {{ fichaInterbibliotecariaGuardada.libro.titulo }}</p>
          <p class="mt-1 text-xs text-emerald-900">Biblioteca propietaria: {{ fichaInterbibliotecariaGuardada.biblioteca }} · Devolver el {{ fechaCorta(fichaInterbibliotecariaGuardada.fecha_limite) }}. Guardar la ficha no crea ejemplares.</p>
          <button type="button" class="mt-3 min-h-8 rounded-lg border border-emerald-300 bg-white px-3 py-1 text-xs font-semibold text-emerald-950" @click="elegirFichaInterbibliotecaria(fichaInterbibliotecariaGuardada, 'ejemplares')">Agregar ejemplares cuando corresponda</button>
        </div>
        <section v-if="fichaInterbibliotecaria" class="mb-5 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3" aria-label="Ficha interbibliotecaria seleccionada">
          <p class="text-xs font-bold uppercase tracking-wide text-amber-900">{{ esIngresoEjemplares ? 'Ingresando ejemplares para' : 'Editando ficha interbibliotecaria' }} · N.º {{ fichaInterbibliotecaria.libro.numero_ficha }}</p>
          <p class="mt-1 text-sm font-semibold text-gray-900">{{ fichaInterbibliotecaria.libro.titulo }}</p>
          <p class="mt-1 text-sm text-gray-700">Propietaria: <strong>{{ fichaInterbibliotecaria.biblioteca }}</strong> · Devolver a su biblioteca el <strong>{{ fechaCorta(fichaInterbibliotecaria.fecha_limite) }}</strong>.</p>
          <p v-if="esIngresoEjemplares" class="mt-1 text-xs text-amber-950">Cada ejemplar queda vinculado a este préstamo. Para cambiar la biblioteca o el plazo, usa «Editar ficha».</p>
          <p v-else class="mt-1 text-xs text-gray-600">Los datos bibliográficos del título se comparten con el catálogo. La biblioteca propietaria y la fecha de devolución pertenecen a este préstamo interbibliotecario.</p>
        </section>
        <section v-if="esEdicionEjemplar && registroEditandoInterbibliotecario" class="mb-5 rounded-xl border border-violet-200 bg-violet-50 px-4 py-3" aria-label="Préstamo del ejemplar en edición">
          <p class="text-xs font-bold uppercase tracking-wide text-violet-900">Ejemplar del préstamo interbibliotecario · {{ registroEditandoInterbibliotecario.ejemplar.codigo_barras }}</p>
          <p class="mt-1 text-sm font-semibold text-gray-900">{{ registroEditandoInterbibliotecario.ejemplar.libro.titulo }} · Ficha N.º {{ registroEditandoInterbibliotecario.ejemplar.libro.numero_ficha }}</p>
          <p class="mt-1 text-sm text-gray-700">Propietaria: <strong>{{ registroEditandoInterbibliotecario.biblioteca }}</strong> · Devolver a su biblioteca el <strong>{{ fechaCorta(registroEditandoInterbibliotecario.fecha_limite) }}</strong>.</p>
        </section>
        <div v-if="recepcionGuardada" class="mb-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3" role="status">
          <p class="font-semibold text-emerald-950">Ficha N.º {{ recepcionGuardada.numero_ficha }} · {{ recepcionGuardada.ejemplar.libro.titulo }}</p>
          <p class="mt-1 text-sm text-emerald-900">{{ recepcionGuardada.cantidad }} ejemplar(es) registrados. La ficha queda elegida para recibir más ejemplares.</p>
          <ul class="mt-3 space-y-2">
            <li v-for="r in recepcionGuardada.registros" :key="r.id" class="flex flex-wrap items-center gap-2 text-sm text-emerald-950">
              <span class="rounded-full border border-emerald-200 bg-white px-3 py-1 font-mono">{{ r.ejemplar.codigo_barras }}</span>
              <span v-if="nombreDeCopia(r.ejemplar)">{{ nombreDeCopia(r.ejemplar) }}</span>
              <span class="text-xs">Usuarios: devolución hasta {{ fechaCorta(r.fecha_tope) }}.</span>
              <button type="button" class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-1 text-xs font-semibold text-violet-950" @click="abrirEjemplarDeRecepcion(r.ejemplar.codigo_barras)">Editar ejemplar</button>
              <button v-if="puedeCambiarEstado" type="button" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-950" @click="abrirEstadoDeRecepcion(r.ejemplar.codigo_barras)">Cambiar estado</button>
            </li>
          </ul>
          <button type="button" class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-950" @click="recepcionGuardada.ficha_interbibliotecaria_id && abrirFichaDeRecepcion(recepcionGuardada.ficha_interbibliotecaria_id)">Editar ficha N.º {{ recepcionGuardada.numero_ficha }}</button>
        </div>
      </template>

      <div v-if="mostrarFormulario" class="grid grid-cols-1 gap-6 lg:grid-cols-[11rem_minmax(0,1fr)_17rem] xl:grid-cols-[12.5rem_minmax(0,1fr)_19rem]">
        <!-- Índice de secciones -->
        <aside class="hidden lg:block">
          <div class="sticky space-y-3" :class="modoPrincipal === 'editar' ? 'top-36' : 'top-6'">
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

          <template v-if="esFicha">
            <div
              v-if="modo === 'nuevo' && borrador && !desdeBorrador && !editandoId"
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
                  <EditorClasificacion id="cat-clasificacion" v-model="form.clasificacion" :placeholder="`Ej: ${guiaClasificacion.ejemplo}`" :clase-campo="CAMPO" guia-id="cat-clasificacion-guia" :plantilla="tipoActual?.plantilla" />
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
                    <CampoEntidad id-campo="cat-entidad_corporativa" v-model="form.entidad_corporativa" :placeholder="PISTAS.entidad_corporativa" :clase-campo="CAMPO" />
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
                      <CampoEntidad v-if="c === 'entidad_secundaria'" :id-campo="`cat-${c}`" v-model="form[c]" :placeholder="PISTAS[c]" :clase-campo="CAMPO" varias />
                      <input v-else autocomplete="off" :id="`cat-${c}`" v-model="form[c]" :placeholder="PISTAS[c]" :class="CAMPO" />
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
                  <input autocomplete="off" id="cat-descripcion" v-model="form.descripcion_fisica" aria-describedby="cat-descripcion-ejemplo" placeholder="Ej: 350 p. : il. ; 24 cm." :class="CAMPO" />
                  <p id="cat-descripcion-ejemplo" class="mt-1 text-xs text-gray-500">Ejemplo: 248 p. : il. col. ; 22 cm.</p>
                </div>
                <div v-if="tiene('serie')" class="sm:col-span-3">
                  <p :class="ETIQUETA"><Marc :codigo="etiqueta('serie')" tono="teal" /> Serie <span class="font-normal text-gray-500">(opcional; puede haber varias)</span></p>
                  <div class="space-y-2">
                    <div v-for="(_, i) in series" :key="i" class="flex gap-2">
                      <div class="min-w-0 flex-1">
                        <div class="relative">
                          <!-- Paréntesis fijos: el «)» va justo tras lo escrito (o el ejemplo). Si se escriben a mano, se ocultan. -->
                          <template v-if="!parentesisAMano(series[i])">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm font-bold text-gray-700" aria-hidden="true">(</span>
                            <span class="pointer-events-none absolute inset-y-0 left-0 right-3 flex items-center overflow-hidden whitespace-pre pl-[calc(1.1rem+1px)] text-sm" aria-hidden="true">
                              <span class="invisible">{{ series[i] || 'Ej: Serie Harry Potter ; 1' }}</span><span class="font-bold text-gray-700">)</span>
                            </span>
                          </template>
                          <input autocomplete="off"
                            :id="`cat-serie-${i}`"
                            v-model="series[i]"
                            placeholder="Ej: Serie Harry Potter ; 1"
                            :aria-label="`Serie ${i + 1}`"
                            :aria-invalid="faltaCerrar(series[i])"
                            :aria-describedby="faltaCerrar(series[i]) ? `cat-serie-aviso-${i}` : undefined"
                            :class="[CAMPO, parentesisAMano(series[i]) ? '' : '!pl-[1.1rem] !pr-6', faltaCerrar(series[i]) ? '!border-amber-400 ring-1 ring-amber-300' : '']"
                            @input="guardarSeries"
                          />
                        </div>
                        <p v-if="faltaCerrar(series[i])" :id="`cat-serie-aviso-${i}`" class="mt-1 text-[11px] font-semibold text-amber-700">Falta cerrar el paréntesis «)».</p>
                      </div>
                      <button
                        v-if="series.length > 1 || series[i]"
                        type="button"
                        class="shrink-0 rounded-lg border border-gray-300 px-3 text-sm text-gray-500 hover:bg-gray-50 hover:text-red-600"
                        :aria-label="`Quitar serie ${i + 1}`"
                        @click="quitarSerie(i)"
                      >✕</button>
                    </div>
                  </div>
                  <p class="mt-1 text-[11px] text-gray-500">Los paréntesis se ponen solos en la ficha, pero también se pueden escribir a mano.</p>
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
                  <RevisionMaterias v-model="form.categorias" />
                  <p class="mt-1.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] text-gray-700">
                    <strong>Subdivisiones:</strong> escríbalas en la misma materia, separadas por <kbd class="rounded border border-gray-300 bg-white px-1 font-mono">--</kbd>,
                    en el orden <strong>tema -- aspecto -- lugar -- época</strong>. Ej.: <span class="font-mono">MAPUCHES -- VIDA SOCIAL Y COSTUMBRES</span>,
                    <span class="font-mono">EDUCACION -- CHILE</span>, <span class="font-mono">MAPUCHES -- HISTORIA -- 1860-1970</span>.
                    Es mejor que dejar solo el tema: el libro se encuentra con más precisión.
                  </p>
                </div>
                <div v-if="tiene('contenido')" class="sm:col-span-6">
                  <p id="cat-contenido-etiqueta" :class="ETIQUETA"><Marc :codigo="etiqueta('contenido')" tono="pizarra" /> Nota de contenido</p>
                  <EditorNotaContenido id="cat-contenido" v-model="form.contenido" />
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

          <template v-else-if="esObraExistente">
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

          </template>

          <!-- Editar ejemplar: la copia por su código y todos sus datos -->
          <template v-else-if="esEdicionEjemplar">
            <!-- Qué es esta pestaña: solo copias, por su código de barras -->
            <div class="flex items-start gap-3 rounded-2xl border border-violet-200 bg-violet-50/70 px-5 py-4 shadow-sm">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-600 text-white" aria-hidden="true">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5zM16.5 13.5v6m3-3h-6" /></svg>
              </span>
              <div>
                <p class="font-serif text-lg font-bold leading-tight text-violet-950">Editor de ejemplares</p>
                <p class="mt-1 text-sm leading-relaxed text-violet-900/80">
                  Corrige los datos de <strong>una copia</strong> ya catalogada: escanea su código de barras. Lo que es del título (autor, materias,
                  clasificación, editorial…) se cambia desde «Editar ficha»{{ modo === 'interbibliotecario' ? ' en «Ficha del préstamo».' : ' en el catálogo.' }}
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
                {{ modo === 'interbibliotecario' ? 'También puedes elegir «Editar ejemplar» en el listado de ejemplares de estos préstamos, aquí abajo.' : 'También se llega con «Editar» de una copia en el Listado de material o en «Títulos catalogados».' }}
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
                  <label for="editar-tomo" :class="ETIQUETA"><Marc codigo="863 $a" tono="violeta" /> Tomo</label>
                  <input id="editar-tomo" v-model="formEditar.tomo" autocomplete="off" placeholder="1" :aria-invalid="tomoParteEditar === null" :class="[CAMPO, tomoParteEditar === null ? 'border-red-400 ring-1 ring-red-300' : '']" @change="alSalirDelTomoEditar" />
                  <p class="mt-1 text-[11px] text-gray-500">Ej: <span class="font-mono">1</span>, <span class="font-mono">I</span>, <span class="font-mono">t.1</span> o <span class="font-mono">t.I</span> · vacío si no tiene</p>
                </div>
                <div class="sm:col-span-2">
                  <label for="editar-parte" :class="ETIQUETA"><Marc codigo="863 $b" tono="violeta" /> Volumen</label>
                  <input id="editar-parte" v-model="formEditar.parte" autocomplete="off" placeholder="2" :aria-invalid="volumenParteEditar === null" :class="[CAMPO, volumenParteEditar === null ? 'border-red-400 ring-1 ring-red-300' : '']" />
                  <p class="mt-1 text-[11px] text-gray-500">Ej: <span class="font-mono">2</span>, <span class="font-mono">II</span>, <span class="font-mono">v.2</span> o <span class="font-mono">v.II</span> · con o sin tomo</p>
                </div>
                <div class="sm:col-span-2">
                  <label for="editar-copia-num" :class="ETIQUETA"><Marc codigo="852 $t" tono="violeta" /> Copia</label>
                  <input id="editar-copia-num" v-model="formEditar.copia" autocomplete="off" inputmode="numeric" placeholder="2" :aria-invalid="!copiaEditarValida" :class="[CAMPO, !copiaEditarValida ? 'border-red-400 ring-1 ring-red-300' : '']" />
                  <p class="mt-1 text-[11px] text-gray-500">Ej: <span class="font-mono">2</span>, <span class="font-mono">c.2</span> o <span class="font-mono">copia 2</span> · vacía = sin número</p>
                </div>
                <div class="sm:col-span-6">
                  <label for="editar-otra" :class="ETIQUETA"><Marc codigo="852 $3" tono="violeta" /> Otra designación <span class="font-normal text-gray-400">(sola o junto al tomo)</span></label>
                  <input id="editar-otra" v-model="formEditar.otra" autocomplete="off" placeholder="Ej: Ficha · Cuadernillo · Anexo · Año IV No. 4 (Jul.-Aug., 1985)" :class="[CAMPO, 'disabled:bg-gray-50']" />
                  <p class="mt-1 text-[11px] text-gray-500">Lo que no es tomo, volumen ni copia: Ficha, Cuadernillo, Apéndice, Anexo o el número de una revista. Puede ir junto al tomo («t.5 Apéndice»).</p>
                </div>
                <p v-if="!edicionValida" class="text-[11px] font-semibold text-red-700 sm:col-span-6">
                  Hay algo que no se reconoce: el tomo va como «1», el volumen como «2» o «II» y la copia como «2» o «c.2».
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
                  <label for="editar-nota-publica" :class="ETIQUETA"><Marc codigo="852 $z" tono="violeta" /> Nota pública de la copia (la ve el usuario en el portal)</label>
                  <textarea id="editar-nota-publica" v-model="formEditar.nota_publica" rows="2" placeholder="Ej: Incluye CD · se ve en el portal" :class="CAMPO"></textarea>
                </div>
                <div class="sm:col-span-6">
                  <label for="editar-nota-interna" :class="ETIQUETA"><Marc codigo="852 $x" tono="violeta" /> Nota interna de la copia (solo la ve el personal)</label>
                  <textarea id="editar-nota-interna" v-model="formEditar.nota_interna" rows="2" placeholder="Solo la ve el personal" :class="CAMPO"></textarea>
                </div>
                <p class="text-[11px] text-gray-500 sm:col-span-6">
                  El código de barras y el de lomo no se cambian: identifican a la copia. El estado se cambia en «Estado de ejemplares».
                </p>
              </div>
            </SeccionFicha>
          </template>
            <!-- 2. Copia nueva -->
            <SeccionFicha v-if="modo === 'copia' || esIngresoEjemplares" id="seccion-copia" :numero="numeroDe('copia')" titulo="Copia nueva" detalle="Datos de la copia física · solo uso interno" tono="violeta">
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
                    <template v-if="volumenCopiaNueva && tomoValido">
                      <strong class="text-violet-900">{{ textoCopia(volumenCopiaNueva, null) ?? volumenCopiaNueva }}</strong>
                      <span class="font-mono text-xs text-violet-500">({{ volumenCopiaNueva }})</span>
                    </template>
                    <span v-else class="text-gray-400">Sin tomo ni número de copia</span>
                  </p>
                </div>
                <!-- Tomo, volumen y copia por separado (el texto se arma solo, como en Horizon) -->
                <div class="sm:col-span-2">
                  <label for="copia-tomo" :class="ETIQUETA"><Marc codigo="863 $a" tono="violeta" /> Tomo</label>
                  <input autocomplete="off" id="copia-tomo" v-model="formCopia.tomo" list="tomos-de-la-obra" placeholder="1" :aria-invalid="tomoParte === null" :class="[CAMPO, tomoParte === null ? 'border-red-400 ring-1 ring-red-300' : '']" @change="alSalirDelTomoCopia" />
                  <p class="mt-1 text-[11px] text-gray-500">Ej: <span class="font-mono">1</span>, <span class="font-mono">I</span>, <span class="font-mono">t.1</span> o <span class="font-mono">t.I</span> · vacío si no tiene</p>
                  <datalist id="tomos-de-la-obra">
                    <option v-for="t in tomosDeLaObra" :key="t.tomo" :value="t.tomo">{{ partesCopia(t.tomo, null).tomo ?? t.tomo }} · {{ t.copias }} {{ t.copias === 1 ? 'copia' : 'copias' }}</option>
                  </datalist>
                </div>
                <div class="sm:col-span-2">
                  <label for="copia-parte" :class="ETIQUETA"><Marc codigo="863 $b" tono="violeta" /> Volumen</label>
                  <input
                    autocomplete="off"
                    id="copia-parte"
                    v-model="formCopia.parte"
                    placeholder="2"
                    :aria-invalid="volumenParte === null"
                    :class="[CAMPO, volumenParte === null ? 'border-red-400 ring-1 ring-red-300' : '']"
                  />
                  <p class="mt-1 text-[11px] text-gray-500">Ej: <span class="font-mono">2</span>, <span class="font-mono">II</span>, <span class="font-mono">v.2</span> o <span class="font-mono">v.II</span> · con o sin tomo</p>
                </div>
                <div class="sm:col-span-2">
                  <label for="copia-numero" :class="ETIQUETA"><Marc codigo="852 $t" tono="violeta" /> Copia</label>
                  <div class="flex gap-2">
                    <input id="copia-numero" v-model="formCopia.copia" autocomplete="off" inputmode="numeric" placeholder="2" :aria-invalid="!copiaEscritaValida" :class="[CAMPO, !copiaEscritaValida ? 'border-red-400 ring-1 ring-red-300' : '']" />
                    <button
                      v-if="copiaSugerida && copiaEscrita !== String(copiaSugerida)"
                      type="button"
                      class="shrink-0 rounded-lg border border-violet-200 bg-white px-2 text-xs font-semibold text-violet-700 hover:bg-violet-50"
                      :title="`La siguiente de ${claveTomo ? 'ese tomo' : 'la obra'}`"
                      @click="formCopia.copia = String(copiaSugerida)"
                    >
                      Usar c.{{ copiaSugerida }}
                    </button>
                  </div>
                  <p class="mt-1 text-[11px] text-gray-500">Ej: <span class="font-mono">2</span>, <span class="font-mono">c.2</span> o <span class="font-mono">copia 2</span> · vacía = sin número</p>
                </div>
                <div class="sm:col-span-6">
                  <p v-if="tomoParte === null" class="text-[11px] font-semibold text-red-700">
                    En «Tomo» va solo su número, por ejemplo «1» o «t.1».
                  </p>
                  <p v-else-if="volumenParte === null" class="text-[11px] font-semibold text-red-700">
                    En «Volumen» va solo su número, por ejemplo «2» o «II».
                  </p>
                  <p v-else-if="tomosDeLaObra.length" class="text-[11px] text-gray-500">
                    Tomos de esta obra: {{ tomosDeLaObra.map((t) => `${partesCopia(t.tomo, null).tomo ?? t.tomo} (${t.copias} ${t.copias === 1 ? 'copia' : 'copias'})`).join(' · ') }}
                  </p>
                </div>
                <div class="sm:col-span-6">
                  <label for="copia-volumen" :class="ETIQUETA"><Marc codigo="852 $3" tono="violeta" /> Otra designación <span class="font-normal text-gray-400">(sola o junto al tomo)</span></label>
                  <input
                    autocomplete="off"
                    id="copia-volumen"
                    v-model="formCopia.volumen"
                    placeholder="Ej: Anexo · Atlas · Año IV No. 4 (Jul.-Aug., 1985)"
                    :class="[CAMPO, 'disabled:bg-gray-50 disabled:text-gray-400']"
                  />
                  <p class="mt-1 text-[11px] text-gray-500">Lo que no es tomo, volumen ni copia: Ficha, Cuadernillo, Apéndice, Anexo o el número de una revista. Puede ir junto al tomo («t.5 Apéndice»).</p>
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
                  <input autocomplete="off" id="copia-coleccion" v-model="formCopia.coleccion" list="colecciones" :placeholder="obraDelEjemplar?.coleccion ? `${obraDelEjemplar.coleccion} (la del libro)` : 'La del libro'" :class="CAMPO" />
                  <p class="mt-1 text-[11px] text-gray-500">Vacía, vale la colección del libro.</p>
                </div>
                <div class="sm:col-span-3">
                  <label for="copia-registro" :class="ETIQUETA">N.º de registro</label>
                  <input id="copia-registro" v-model="formCopia.numero_registro" maxlength="50" placeholder="Registro de este ejemplar" :class="CAMPO" />
                </div>
                <div class="sm:col-span-6">
                  <label for="copia-nota-publica" :class="ETIQUETA"><Marc codigo="852 $z" tono="violeta" /> Nota pública de la copia (la ve el usuario en el portal)</label>
                  <textarea id="copia-nota-publica" v-model="formCopia.nota_publica" rows="2" placeholder="Ej: Incluye CD · se ve en el portal" :class="CAMPO"></textarea>
                </div>
                <div class="sm:col-span-6">
                  <label for="copia-nota-interna" :class="ETIQUETA"><Marc codigo="852 $x" tono="violeta" /> Nota interna de la copia (solo la ve el personal)</label>
                  <textarea id="copia-nota-interna" v-model="formCopia.nota_interna" rows="2" placeholder="Solo la ve el personal" :class="CAMPO"></textarea>
                </div>
              </div>
              <div v-if="modo === 'interbibliotecario'" class="mt-4 border-t border-violet-100 pt-3">
                <button type="button" class="rounded-full border border-violet-300 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-950 disabled:opacity-50" :disabled="guardando" @click="agregarEjemplarAlLote">+ Añadir este ejemplar y preparar otro</button>
                <p class="mt-2 text-xs text-gray-600">Puedes registrar uno directamente o preparar varios ejemplares de esta misma ficha y biblioteca.</p>
                <ul v-if="ejemplaresPendientes.length" class="mt-3 divide-y divide-violet-100 rounded-xl border border-violet-200 bg-violet-50/50">
                  <li v-for="(e, i) in ejemplaresPendientes" :key="e.codigo_barras" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                    <span><strong class="font-mono">{{ e.codigo_barras }}</strong><span v-if="e.volumen || e.tomo" class="ml-2 text-gray-600">{{ e.volumen || e.tomo }}</span></span>
                    <button type="button" class="rounded-lg border border-red-200 bg-red-50 px-3 py-1 text-xs font-semibold text-red-800" :disabled="guardando" @click="ejemplaresPendientes.splice(i, 1)">Quitar</button>
                  </li>
                </ul>
              </div>
            </SeccionFicha>

          <!-- Los datos del préstamo se suman a la ficha y ejemplares compartidos. -->
          <SeccionFicha v-if="modo === 'interbibliotecario' && (seccionInterbibliotecaria === 'ficha' || (esEdicionEjemplar && registroEditandoInterbibliotecario))" id="seccion-prestamo-interbibliotecario" :numero="numeroDe('prestamo-interbibliotecario')" titulo="Biblioteca y plazos" detalle="De quién es el material y cuándo debe regresar" tono="dorado">
            <div class="grid gap-4 sm:grid-cols-2">
              <div class="sm:col-span-2"><label for="ingreso-biblioteca" :class="ETIQUETA">Biblioteca propietaria *</label><input id="ingreso-biblioteca" v-model="formIngreso.biblioteca" :disabled="!!registroEditandoInterbibliotecario?.devuelto_el" maxlength="255" :class="CAMPO" /></div>
              <div><label for="ingreso-fecha" :class="ETIQUETA">Fecha de ingreso *</label><input id="ingreso-fecha" v-model="formIngreso.fecha_ingreso" :disabled="!!registroEditandoInterbibliotecario?.devuelto_el" type="date" :max="hoyIngreso" :class="CAMPO" /></div>
              <div><label for="ingreso-limite" :class="ETIQUETA">Vuelve a su biblioteca el *</label><input id="ingreso-limite" v-model="formIngreso.fecha_limite" :disabled="!!registroEditandoInterbibliotecario?.devuelto_el" type="date" :min="formIngreso.fecha_ingreso" :class="CAMPO" /></div>
              <div class="sm:col-span-2"><label for="ingreso-nota" :class="ETIQUETA">Nota del préstamo interbibliotecario</label><textarea id="ingreso-nota" v-model="formIngreso.nota" :disabled="!!registroEditandoInterbibliotecario?.devuelto_el" maxlength="2000" rows="2" :class="CAMPO"></textarea></div>
            </div>
            <p v-if="esEdicionEjemplar" class="mt-3 rounded-lg bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-950">Tope de devolución para usuarios: {{ topeIngreso ? fechaCorta(topeIngreso) : '—' }}. Un día hábil antes de regresar a su biblioteca.</p>
            <p v-if="esEdicionEjemplar && !registroEditandoInterbibliotecario?.devuelto_el" class="mt-2 text-xs text-gray-600">Al guardar se actualizan también la ficha y sus ejemplares pendientes de devolver. Los préstamos ya realizados conservan su vencimiento.</p>
            <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-950">Esta es la fecha en que debemos devolver el material a su biblioteca propietaria. Los ejemplares se pueden ingresar después; sus préstamos a usuarios respetarán este plazo.</p>
            <p v-if="tareaInterbibliotecaria === 'editar' && fichaInterbibliotecaria?.ingresos_count" class="mt-2 text-xs text-gray-600">Los cambios de biblioteca y fechas se aplican a los ejemplares pendientes de devolver de esta ficha. Los ya devueltos conservan su historial; los préstamos a usuarios mantienen su vencimiento.</p>
          </SeccionFicha>
        </div>

        <!-- Vista previa, obligatorios y acciones -->
        <aside class="space-y-4 lg:sticky lg:self-start" :class="modoPrincipal === 'editar' ? 'lg:top-36' : 'lg:top-6'">
          <div v-if="modo === 'interbibliotecario' && !esEdicionEjemplar" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            <p class="font-semibold">Préstamo interbibliotecario</p>
            <p class="mt-1">{{ esIngresoEjemplares ? `${ejemplaresARecibir.length} ejemplar(es) por registrar` : 'Ficha independiente · ejemplares opcionales después' }}</p>
            <p class="mt-2">Regresa el {{ formIngreso.fecha_limite ? fechaCorta(formIngreso.fecha_limite) : '—' }}.</p>
            <p v-if="formIngreso.fecha_limite" class="mt-1">Préstamo a usuarios: devolución hasta {{ topeIngreso ? fechaCorta(topeIngreso) : '—' }}.</p>
          </div>
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
            <p v-else class="text-sm text-gray-400">{{ esEdicionEjemplar ? 'Escanea una copia para ver su obra.' : 'Elige la obra para ver cómo aparece.' }}</p>
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

          <div class="space-y-3">
            <button
              type="button"
              class="min-h-11 w-full px-4 py-2.5 text-sm font-semibold text-white shadow-md transition hover:brightness-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-700 focus-visible:ring-offset-2 disabled:opacity-60"
              :class="modo === 'interbibliotecario' ? 'rounded-xl' : 'rounded-full'"
              style="background: linear-gradient(135deg, #3B28A3 0%, #4F46E5 100%);"
              :disabled="guardando || (esEdicionEjemplar && (!copiaEditando || !editarConCambios))"
              @click="accionPrincipal"
            >
              {{ textoAccion }}
            </button>
            <button v-if="modo === 'interbibliotecario' && !esEdicionEjemplar && hayTrabajoSinGuardar" type="button" class="min-h-10 w-full rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-800" :disabled="guardando" @click="tareaInterbibliotecariaPendiente = tareaInterbibliotecaria">Descartar cambios</button>
            <button
              v-if="esEdicionEjemplar && copiaEditando"
              type="button"
              class="min-h-11 w-full border-2 border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-950 transition-colors hover:bg-violet-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-700 focus-visible:ring-offset-2"
              :class="modo === 'interbibliotecario' ? 'rounded-lg' : 'rounded-full'"
              @click="pedirDescartarCopia"
            >
              {{ editarConCambios ? 'Descartar y editar otra' : 'Editar otra copia' }}
            </button>
            <button
              v-if="modo === 'nuevo' && !editandoId"
              type="button"
              class="min-h-11 w-full rounded-full border-2 border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-semibold text-indigo-950 transition-colors hover:bg-indigo-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-700 focus-visible:ring-offset-2 disabled:opacity-50"
              :disabled="formularioVacio"
              @click="confirmandoBorrador = true"
            >
              Guardar borrador
            </button>
            <button
              v-if="modoPrincipal === 'editar' && editandoId"
              type="button"
              class="min-h-11 w-full rounded-full border-2 border-amber-300 bg-amber-100 px-4 py-2.5 text-sm font-semibold text-amber-950 transition-colors hover:bg-amber-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-700 focus-visible:ring-offset-2"
              @click="pedirOtraFicha"
            >
              Cerrar edición
            </button>
            <button
              v-if="modoPrincipal === 'editar' && editandoId && puedeCambiarEstado"
              type="button"
              class="min-h-11 w-full rounded-full border-2 border-red-300 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-800 transition-colors hover:bg-red-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2"
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
      <section v-if="modoPrincipal !== 'nuevo' && !esEdicionEjemplar" class="mt-5 overflow-hidden rounded-xl border border-indigo-200 bg-indigo-50/60 shadow-md" aria-label="Buscar fichas existentes">
        <div class="flex flex-col gap-3 border-b border-indigo-200 bg-gradient-to-r from-indigo-100 via-violet-50 to-amber-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="text-sm font-bold text-indigo-950">{{ modoPrincipal === 'editar' ? 'Fichas disponibles para editar' : 'Títulos catalogados' }}</h2>
            <p class="mt-0.5 text-xs leading-normal text-gray-700">{{ modoPrincipal === 'editar' ? 'Selecciona la ficha del título. Las copias físicas tienen su propia pestaña «Editar ejemplar».' : 'Elige «Editar ficha» para corregir el título o «Editar ejemplar» para una copia física.' }}</p>
          </div>
          <div class="relative w-full sm:max-w-xs">
            <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
            <input autocomplete="off"
              id="cat-buscar-ficha"
              v-model="busqueda"
              type="text"
              placeholder="Título, autor, código o N.º de ficha"
              :class="[CAMPO, '!h-9 pl-10 !text-sm']"
              aria-label="Buscar títulos catalogados"
              @input="onBuscarInput"
            />
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="border-b border-indigo-200 bg-indigo-100">
                <th scope="col" class="px-3 py-2 text-left text-xs font-bold text-indigo-950">N.º ficha</th>
                <th scope="col" class="px-3 py-2 text-left text-xs font-bold text-indigo-950">Título</th>
                <th scope="col" class="px-3 py-2 text-left text-xs font-bold text-indigo-950">Clasificación</th>
                <th scope="col" class="px-3 py-2 text-left text-xs font-bold text-indigo-950">Copias</th>
                <th scope="col" class="px-3 py-2 text-left text-xs font-bold text-indigo-950">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-indigo-200/70">
              <tr v-for="(l, indice) in libros" :key="l.id" class="align-middle transition-colors" :class="editandoId === l.id ? 'bg-amber-100 shadow-[inset_4px_0_0_#b08d57]' : indice % 2 === 0 ? 'bg-indigo-50/70 hover:bg-indigo-100/80' : 'bg-slate-100/80 hover:bg-indigo-100/80'">
                <td class="px-3 py-2"><span class="inline-flex rounded-full border border-indigo-200 bg-indigo-100 px-2 py-0.5 font-mono text-xs font-bold text-indigo-950">{{ l.numero_ficha }}</span></td>
                <td class="min-w-48 max-w-lg px-3 py-2 text-sm font-semibold leading-normal text-gray-900">
                  <span class="line-clamp-2" :title="l.titulo">{{ l.titulo }}</span>
                  <p v-if="l.autores?.length" class="mt-0.5 max-w-sm truncate text-xs font-normal text-gray-700">{{ l.autores.map((a) => a.nombre).join(' · ') }}</p>
                </td>
                <td class="px-3 py-2 font-mono text-xs text-gray-700">{{ l.clasificacion ?? '—' }}</td>
                <td class="px-3 py-2">
                  <div class="flex flex-wrap items-center gap-1.5">
                    <!-- Cada copia, con su botón «Editar»: abre la pestaña «Editar ejemplar» con ella -->
                    <span
                      v-for="e in copiasDesplegadas.has(l.id) ? l.ejemplares : l.ejemplares?.slice(0, 2)"
                      :key="e.id"
                      class="inline-flex items-center overflow-hidden rounded-full border border-black/10 text-xs font-semibold"
                      :class="estadoBadges[e.estado_proceso]?.cls"
                      :title="`${nombreDeCopia(e) ?? e.codigo_barras} · ${estadoBadges[e.estado_proceso]?.label ?? e.estado_proceso}`"
                    >
                      <span class="max-w-40 truncate px-2 py-1">{{ rotuloCopia(e) }}</span>
                      <button
                        v-if="modo !== 'editar'"
                        type="button"
                        class="min-h-8 whitespace-nowrap border-l border-violet-200 bg-violet-100 px-2 py-1 text-xs font-bold text-violet-950 transition-colors hover:bg-violet-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-violet-700"
                        :aria-label="`Editar la copia ${e.codigo_barras}`"
                        @click="editarCopia(e, l)"
                      >
                        Editar ejemplar
                      </button>
                    </span>
                    <button v-if="(l.ejemplares?.length ?? 0) > 2" type="button" class="min-h-8 whitespace-nowrap rounded-full border border-indigo-200 bg-indigo-100 px-2.5 py-1 text-xs font-semibold text-indigo-950 hover:bg-indigo-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-700" :aria-expanded="copiasDesplegadas.has(l.id)" @click="copiasDesplegadas.has(l.id) ? copiasDesplegadas.delete(l.id) : copiasDesplegadas.add(l.id)">
                      {{ copiasDesplegadas.has(l.id) ? 'Ver menos' : `Ver ${(l.ejemplares?.length ?? 0) - 2} más` }}
                    </button>
                  </div>
                </td>
                <td class="px-3 py-2">
                  <div class="flex flex-nowrap items-center gap-2">
                    <!-- En «Nuevo ejemplar» se editan las copias (en la columna Copias), no la ficha -->
                    <button v-if="modo !== 'copia'" type="button" class="inline-flex min-h-8 items-center justify-center gap-2 whitespace-nowrap rounded-full border border-amber-300 bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-950 shadow-sm transition-colors hover:bg-amber-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-700 focus-visible:ring-offset-2 disabled:opacity-60" :disabled="cargandoFicha || guardando" @click="pedirEditarFicha(l)">
                      <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.862 4.487z" /></svg>
                      {{ modoPrincipal === 'editar' && editandoId === l.id ? 'Editando esta ficha' : 'Editar ficha' }}
                    </button>
                    <button type="button" class="inline-flex min-h-8 items-center justify-center gap-2 whitespace-nowrap rounded-full border border-emerald-300 bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-950 shadow-sm transition-colors hover:bg-emerald-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2" @click="agregarCopiaDe(l)">
                      <span class="text-base leading-none" aria-hidden="true">+</span> Agregar ejemplar
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!cargando && !libros.length">
                <td colspan="5" class="bg-indigo-50 px-4 py-5 text-center text-sm text-gray-600">Sin títulos que coincidan con la búsqueda.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <Paginador
          class="border-t border-indigo-200 bg-indigo-100/60 px-3 py-2"
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

      <!-- Las colecciones del catálogo (las de Horizon), para escribirlas igual y no crear otra parecida -->
      <datalist id="colecciones">
        <option v-for="c in colecciones" :key="c.nombre" :value="c.nombre">{{ c.copias === 1 ? '1 copia' : `${c.copias.toLocaleString('es-CL')} copias` }}</option>
      </datalist>
    </div>

    <EstadoInterbibliotecario :prestamo="prestamoParaEstado" @cerrar="prestamoParaEstado = null" @cambiado="actualizarEstadoInterbibliotecario" />

    <DialogoConfirmacion :abierto="confirmandoIngreso" :titulo="esIngresoEjemplares ? '¿Registrar estos ejemplares recibidos?' : tareaInterbibliotecaria === 'editar' ? '¿Guardar los cambios de esta ficha interbibliotecaria?' : '¿Crear esta ficha interbibliotecaria?'" :confirmar="esIngresoEjemplares ? 'Sí, registrar ejemplares' : 'Sí, guardar ficha'" :cargando="guardando" destacado ancho="lg" @confirmar="registrarRecepcion" @cancelar="confirmandoIngreso = false">
      <p class="font-semibold text-gray-900">{{ esFicha ? tituloAGuardar : libroParaCopia?.titulo }}</p>
      <p class="mt-3 text-sm text-gray-700">Biblioteca propietaria: <strong>{{ formIngreso.biblioteca }}</strong>. Debemos devolver el material el <strong>{{ fechaCorta(formIngreso.fecha_limite) }}</strong>.</p>
      <template v-if="esIngresoEjemplares">
        <ul class="mt-3 divide-y divide-violet-100 rounded-xl border border-violet-200 bg-violet-50">
          <li v-for="e in ejemplaresARecibir" :key="e.codigo_barras" class="px-3 py-2 text-sm"><strong class="font-mono">{{ e.codigo_barras }}</strong><span v-if="e.tomo || e.volumen"> · {{ e.tomo || e.volumen }}</span><span v-if="e.copia"> · c.{{ e.copia }}</span></li>
        </ul>
        <p class="mt-3 text-xs text-gray-600">Los ejemplares se registran juntos y quedan vinculados a la ficha elegida y a su fecha de devolución. Ingresan «Por colocar».</p>
      </template>
      <p v-else class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-950">Se guarda la ficha, sin crear ejemplares. Puedes ingresarlos después desde «Agregar ejemplares».</p>
    </DialogoConfirmacion>

    <DialogoConfirmacion :abierto="tareaInterbibliotecariaPendiente !== null || seleccionInterbibliotecariaPendiente !== null" titulo="¿Descartar los cambios sin guardar?" confirmar="Descartar y continuar" cancelar="Seguir aquí" tono="peligro" :cargando="guardando" @confirmar="confirmarCambioInterbibliotecario" @cancelar="tareaInterbibliotecariaPendiente = null; seleccionInterbibliotecariaPendiente = null">
      <p class="text-sm text-gray-700">Se descartará lo escrito en esta tarea. Las fichas y ejemplares ya guardados se conservan.</p>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="modoPendiente !== null"
      titulo="¿Cambiar de tarea?"
      confirmar="Descartar cambios y continuar"
      cancelar="Seguir aquí"
      tono="peligro"
      destacado
      @confirmar="modoPendiente && cambiarModo(modoPendiente)"
      @cancelar="modoPendiente = null"
    >
      <p class="text-sm text-gray-600">Hay cambios sin guardar<template v-if="modoPrincipal === 'editar' && libroEditado"> en la ficha <strong class="font-mono text-gray-900">N.º {{ libroEditado.numero_ficha }}</strong>, «{{ libroEditado.titulo }}»</template>. Se descartarán al continuar; la información guardada en el catálogo se conserva.</p>
    </DialogoConfirmacion>

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
        Se guardarán los cambios de la ficha <strong class="font-mono text-gray-900">N.º {{ libroEditado?.numero_ficha }}</strong>, <strong class="text-gray-900">{{ tituloAGuardar }}</strong>. Se ven de inmediato en el listado y, si tiene
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
              <dd class="whitespace-pre-line break-words text-gray-900">
                <NotaContenido v-if="f.etiqueta === '505'" :valor="f.valor" />
                <template v-else>{{ f.valor }}</template>
              </dd>
            </div>
          </dl>
        </div>
        <p class="mt-3 rounded-lg bg-gray-50 px-4 py-3 text-[13px] leading-relaxed text-gray-600">
          Si falta o sobra algo, aprieta <strong class="text-gray-900">Cancelar</strong> y corrígelo. Al crearla se le asigna su N.º de ficha y
          podrás elegir entre catalogar otra o agregarle ejemplares.
        </p>
      </template>
    </DialogoConfirmacion>

    <!-- Editar ejemplar: confirmar los cambios antes de guardarlos -->
    <DialogoConfirmacion
      :abierto="confirmandoGuardarCopia && !!copiaEditando"
      titulo="¿Guardar los cambios de la copia?"
      confirmar="Sí, guardar cambios"
      :cargando="guardando"
      destacado
      ancho="md"
      @confirmar="guardarEdicionCopia"
      @cancelar="confirmandoGuardarCopia = false"
    >
      <p class="text-[15px] text-gray-600">
        Copia <strong class="font-mono text-gray-900">{{ copiaEditando?.copia.codigo_barras }}</strong> de
        <strong class="text-gray-900">«{{ copiaEditando?.libro.titulo }}»</strong>. Esto es lo que cambia:
      </p>
      <ul class="mt-3 divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200">
        <li v-for="c in cambiosEdicion" :key="c.campo" class="px-4 py-2.5 text-sm">
          <p class="text-xs font-semibold text-gray-500">{{ c.campo }}</p>
          <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
            <span class="text-gray-400 line-through">{{ c.antes }}</span>
            <span class="text-gray-400" aria-hidden="true">→</span>
            <strong class="text-gray-900">{{ c.despues }}</strong>
          </p>
        </li>
      </ul>
    </DialogoConfirmacion>

    <!-- Editar ejemplar: descartar los cambios sin guardar -->
    <DialogoConfirmacion
      :abierto="confirmandoDescartarCopia && !!copiaEditando"
      titulo="¿Descartar los cambios?"
      confirmar="Sí, descartar y editar otra"
      cancelar="Seguir editando"
      tono="peligro"
      destacado
      ancho="md"
      @confirmar="descartarCambiosCopia"
      @cancelar="confirmandoDescartarCopia = false"
    >
      <p class="text-[15px] text-gray-600">
        Estos cambios de la copia <strong class="font-mono text-gray-900">{{ copiaEditando?.copia.codigo_barras }}</strong>
        <strong class="text-gray-900">no se van a guardar</strong>:
      </p>
      <ul class="mt-3 divide-y divide-red-100 overflow-hidden rounded-xl border border-red-100 bg-red-50/40">
        <li v-for="c in cambiosEdicion" :key="c.campo" class="space-y-1 px-4 py-3 text-sm">
          <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ c.campo }}</p>
          <p class="flex flex-wrap items-baseline gap-x-2">
            <span class="w-24 shrink-0 text-xs text-red-700">Se descarta:</span>
            <span class="text-gray-500 line-through">{{ c.despues }}</span>
          </p>
          <p class="flex flex-wrap items-baseline gap-x-2">
            <span class="w-24 shrink-0 text-xs text-emerald-700">Queda como:</span>
            <strong class="text-gray-900">{{ c.antes }}</strong>
          </p>
        </li>
      </ul>
      <p class="mt-3 text-xs text-gray-500">La copia queda como estaba y se puede escanear otra.</p>
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
        recién al pulsar el botón «Guardar ficha» con su número.
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
    <Modal :abierto="!!seleccionOrigenInterbibliotecario" titulo="Elegir ficha y origen del material" ancho="lg" @cerrar="seleccionOrigenInterbibliotecario = null">
      <p class="mb-4 text-sm text-gray-700">{{ seleccionOrigenInterbibliotecario?.libro.titulo }} tiene préstamos interbibliotecarios. Elige la biblioteca y el plazo que corresponden.</p>
      <div class="space-y-3">
        <button v-for="f in seleccionOrigenInterbibliotecario?.libro.fichas_interbibliotecarias ?? []" :key="f.id" type="button" class="block w-full rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-left text-sm text-amber-950 hover:bg-amber-100" @click="elegirOrigenInterbibliotecario(f.id)">
          <span class="block font-semibold">{{ f.biblioteca }}</span>
          <span class="block text-xs">Devolver el {{ fechaCorta(f.fecha_limite) }} · {{ seleccionOrigenInterbibliotecario?.tarea === 'editar' ? 'Editar ficha y plazo' : 'Agregar ejemplares a este préstamo' }}</span>
        </button>
      </div>
      <button v-if="seleccionOrigenInterbibliotecario?.tarea === 'ejemplares'" type="button" class="mt-5 min-h-10 rounded-lg border border-indigo-200 px-4 py-2 text-sm font-semibold text-indigo-900 hover:bg-indigo-50" @click="elegirEjemplarPropio">Es un ejemplar propio de nuestra biblioteca</button>
    </Modal>
  </StaffLayout>
</template>
