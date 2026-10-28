<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import GraficoColumnas from '@/components/reportes/GraficoColumnas.vue'
import BarrasRanking from '@/components/reportes/BarrasRanking.vue'
import BarraApilada from '@/components/reportes/BarraApilada.vue'
import TablaReporte from '@/components/reportes/TablaReporte.vue'
import Heatmap from '@/components/reportes/Heatmap.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import { useTema } from '@/composables/useTema'
import { descargarExcel } from '@/utils/excel'
import { construirCsv, descargarCsv } from '@/utils/csv'
import { diaIso, textoHorarioSemana } from '@/utils/horario'
import type { Periodo, ReporteOpciones, ReporteResumen, ReporteTab, SerieItem } from '@/types'

const toast = useToast()
const apiError = ref(false)

const tab = ref<ReporteTab>('prestamos')
const periodo = ref<Periodo>('mes')
const vista = ref<'grafico' | 'tabla'>('grafico')
const hoy = new Date().toLocaleDateString('sv-SE')
// Fecha de referencia: el reporte muestra el día, semana, mes, semestre o año que la contiene.
const fecha = ref(hoy)

const filtros = reactive({
  carrera: '',
  anio_ingreso: '',
  sexo: '',
  tipo_usuario: '',
  // Solo en la pestaña Préstamos: bibliografico | recursos_apoyo.
  tipo_prestamo: '',
  // Préstamos y material más prestado: id del tipo de material (libro, tesis, juego…).
  tipo_material: '',
})

const opciones = ref<ReporteOpciones>({ carreras: [], aniosIngreso: [], sexos: [], tiposUsuario: ['estudiante', 'docente', 'funcionario'], recursos: { libro: 'Material de la colección' }, tiposMaterial: [] })

const resumenVacio: ReporteResumen = {
  rango: { desde: hoy, hasta: hoy },
  total: 0,
  totalAnterior: 0,
  promedioPorPeriodo: 0,
  categoriaMasFrecuente: '—',
  horarioPico: null,
  horario: {},
  serie: [],
  porCarrera: [],
  porSexo: [],
  porAnioIngreso: [],
  porTipoUsuario: [],
  porHora: [],
  porTipoPrestamo: [],
  porRecurso: [],
  porTipoMaterial: [],
  porSala: [],
  porLibro: [],
  porHoraPorSala: [],
}

const resumen = ref<ReporteResumen>({ ...resumenVacio })
const cargando = ref(true)

const TABS: { id: ReporteTab; label: string }[] = [
  { id: 'prestamos', label: 'Préstamos' },
  { id: 'ingresos', label: 'Ingresos' },
  { id: 'logias', label: 'Uso de salas' },
  { id: 'libros', label: 'Material más prestado' },
]

const PERIODOS: { id: Periodo; label: string }[] = [
  { id: 'dia', label: 'Día' },
  { id: 'semana', label: 'Semana' },
  { id: 'mes', label: 'Mes' },
  { id: 'semestre', label: 'Semestre' },
  { id: 'anio', label: 'Año' },
]

const { oscuro } = useTema()

const INDIGO = '#4F46E5'
const DORADO = '#B08D57'
const VERDE_AZULADO = '#0D9488'
const PIZARRA = '#3F5F8A'
// La primera barra del ranking resalta más oscura en modo claro y más clara en oscuro;
// las columnas suaves (fines de semana, horas que no son pico) quedan apagadas en oscuro.
const INDIGO_DESTACADO = computed(() => (oscuro.value ? '#818CF8' : '#3730A3'))
const INDIGO_SUAVE = computed(() => (oscuro.value ? '#34378a' : '#C7D2FE'))
const DORADO_DESTACADO = computed(() => (oscuro.value ? '#D6B67C' : '#8A6A3B'))
const DORADO_SUAVE = computed(() => (oscuro.value ? '#5c4f3b' : '#EADFC9'))

const UNIDAD: Record<ReporteTab, { plural: string; titulo: string; quien: string }> = {
  prestamos: { plural: 'préstamos', titulo: 'Préstamos', quien: 'pidió el préstamo' },
  ingresos: { plural: 'ingresos', titulo: 'Ingresos', quien: 'ingresó' },
  logias: { plural: 'reservas', titulo: 'Reservas', quien: 'reservó la sala' },
  libros: { plural: 'préstamos', titulo: 'Préstamos de material', quien: 'pidió el material' },
}
const unidad = computed(() => UNIDAD[tab.value])

const DEL_PERIODO: Record<Periodo, string> = { dia: 'del día', semana: 'de la semana', mes: 'del mes', semestre: 'del semestre', anio: 'del año' }
const SUBUNIDAD: Record<Periodo, string> = { dia: 'hora', semana: 'día', mes: 'semana', semestre: 'mes', anio: 'mes' }
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
const NOMBRES_TIPO: Record<string, string> = { estudiante: 'Estudiantes', docente: 'Docentes', funcionario: 'Funcionarios' }
const COLORES_TIPO: Record<string, string> = { estudiante: INDIGO, funcionario: DORADO, docente: VERDE_AZULADO }
const COLORES_SEXO: Record<string, string> = { Femenino: INDIGO, Masculino: DORADO }
const NOMBRES_TIPO_PRESTAMO: Record<string, string> = { bibliografico: 'Colección (catálogo)', recursos_apoyo: 'Recursos de apoyo' }
const COLORES_TIPO_PRESTAMO: Record<string, string> = { bibliografico: INDIGO, recursos_apoyo: DORADO }
// Cada categoría de recurso de apoyo (bicicletas…), con el nombre del catálogo; el material
// de la colección ya llega con el nombre de su tipo (Libro, Tesis, Juego educativo…).
const NOMBRES_RECURSO = computed(() => opciones.value.recursos)
const conNombres = (datos: SerieItem[], nombres: Record<string, string>) => datos.map((d) => ({ ...d, label: nombres[d.label] ?? d.label }))

const capital = (texto: string) => texto.charAt(0).toUpperCase() + texto.slice(1)
const fechaLocal = (iso: string) => new Date(`${iso}T12:00:00`)
const porcentaje = (valor: number, total: number) => (total ? Math.round((valor / total) * 100) : 0)

const VISTAS = [
  { id: 'grafico' as const, label: 'Gráfico', d: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z' },
  { id: 'tabla' as const, label: 'Tabla', d: 'M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m0 0h7.5' },
]

const filtrosPildora = computed(() => [
  ...(tab.value === 'prestamos'
    ? [{ campo: 'tipo_prestamo' as const, nombre: 'Préstamo', todos: 'Todos', valores: Object.entries(NOMBRES_TIPO_PRESTAMO).map(([valor, texto]) => ({ valor, texto })) }]
    : []),
  { campo: 'carrera' as const, nombre: 'Carrera', todos: 'Todas', valores: opciones.value.carreras.map((c) => ({ valor: c, texto: c })) },
  { campo: 'anio_ingreso' as const, nombre: 'Ingreso', todos: 'Todos', valores: opciones.value.aniosIngreso.map((a) => ({ valor: String(a), texto: String(a) })) },
  { campo: 'sexo' as const, nombre: 'Sexo', todos: 'Todos', valores: opciones.value.sexos.map((x) => ({ valor: x, texto: x })) },
].filter((f) => !soloSinCarrera.value || (f.campo !== 'carrera' && f.campo !== 'anio_ingreso')))

// «Quiénes»: para acreditación se piden los números de estudiantes, docentes y funcionarios por
// separado. Los externos y visitas solo están en Ingresos (para prestar o reservar hay que estar registrado).
const QUIENES: Record<string, string> = {
  '': 'Todos',
  estudiante: 'Estudiantes',
  docente: 'Docentes',
  funcionario: 'Funcionarios',
  docente_funcionario: 'Docentes y funcionarios',
  externo: 'Externos y visitas',
}
const opcionesQuienes = computed(() =>
  Object.entries(QUIENES)
    .filter(([valor]) => valor !== 'externo' || tab.value === 'ingresos')
    .map(([valor, texto]) => ({ valor, texto })),
)
// Docentes, funcionarios y externos no tienen carrera ni año de ingreso: esos filtros no aplican.
const soloSinCarrera = computed(() => ['docente', 'funcionario', 'docente_funcionario', 'externo'].includes(filtros.tipo_usuario))
watch(soloSinCarrera, (sinCarrera) => {
  if (!sinCarrera) return
  filtros.carrera = ''
  filtros.anio_ingreso = ''
})
// «Externos y visitas» no existe fuera de Ingresos: al cambiar de pestaña vuelve a «Todos».
watch(tab, (t) => {
  if (t !== 'ingresos' && filtros.tipo_usuario === 'externo') filtros.tipo_usuario = ''
})

// Botones de tipo de material: «Todo» y cada tipo, con Libro y Juego educativo primero (los más consultados).
const mostrarMaterial = computed(() => tab.value === 'prestamos' || tab.value === 'libros')
const opcionesMaterial = computed(() => {
  const primeros = ['Libro', 'Juego educativo']
  const peso = (nombre: string) => (primeros.includes(nombre) ? primeros.indexOf(nombre) : primeros.length)
  const tipos = [...opciones.value.tiposMaterial].sort((a, b) => peso(a.nombre) - peso(b.nombre) || a.nombre.localeCompare(b.nombre, 'es'))
  return [{ valor: '', texto: 'Todo' }, ...tipos.map((t) => ({ valor: String(t.id), texto: t.nombre }))]
})

const etiquetaPeriodo = computed(() => {
  const desde = fechaLocal(resumen.value.rango.desde)
  const hasta = fechaLocal(resumen.value.rango.hasta)
  switch (periodo.value) {
    case 'dia':
      return capital(desde.toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))
    case 'semana':
      return `Semana del ${desde.getDate()} ${MESES[desde.getMonth()].slice(0, 3)} al ${hasta.getDate()} ${MESES[hasta.getMonth()].slice(0, 3)} ${hasta.getFullYear()}`
    case 'semestre':
      return `${desde.getMonth() < 6 ? '1er' : '2º'} semestre ${desde.getFullYear()}`
    case 'anio':
      return String(desde.getFullYear())
    default:
      return `${capital(MESES[desde.getMonth()])} ${desde.getFullYear()}`
  }
})

const etiquetaAnterior = computed(() => {
  const desde = fechaLocal(resumen.value.rango.desde)
  switch (periodo.value) {
    case 'dia':
      return 'el día anterior'
    case 'semana':
      return 'la semana anterior'
    case 'semestre':
      return 'el semestre anterior'
    case 'anio':
      return String(desde.getFullYear() - 1)
    default:
      return MESES[(desde.getMonth() + 11) % 12]
  }
})

function moverPeriodo(paso: -1 | 1) {
  const f = fechaLocal(fecha.value)
  if (periodo.value === 'dia') f.setDate(f.getDate() + paso)
  else if (periodo.value === 'semana') f.setDate(f.getDate() + 7 * paso)
  else {
    f.setDate(1)
    f.setMonth(f.getMonth() + paso * (periodo.value === 'semestre' ? 6 : periodo.value === 'anio' ? 12 : 1))
  }
  fecha.value = f.toLocaleDateString('sv-SE')
}
const hayPeriodoSiguiente = computed(() => resumen.value.rango.hasta < hoy)

async function cargarOpciones() {
  try {
    const { data } = await api.get<ReporteOpciones>('/reportes/opciones')
    opciones.value = data
  } catch {
    apiError.value = true
  }
}

async function cargarResumen() {
  cargando.value = true
  try {
    const { data } = await api.get<ReporteResumen>('/reportes/resumen', {
      params: {
        tab: tab.value,
        periodo: periodo.value,
        fecha: fecha.value,
        carrera: filtros.carrera || undefined,
        anio_ingreso: filtros.anio_ingreso || undefined,
        sexo: filtros.sexo || undefined,
        tipo_usuario: filtros.tipo_usuario || undefined,
        tipo_prestamo: (tab.value === 'prestamos' && filtros.tipo_prestamo) || undefined,
        tipo_material: ((tab.value === 'prestamos' || tab.value === 'libros') && filtros.tipo_material) || undefined,
      },
    })
    resumen.value = data
    apiError.value = false
  } catch {
    apiError.value = true
    resumen.value = { ...resumenVacio }
  } finally {
    cargando.value = false
  }
}

onMounted(async () => {
  await cargarOpciones()
  await cargarResumen()
})

watch([tab, periodo, fecha, filtros], cargarResumen, { deep: true })

// --- Indicadores ---
const diferencia = computed(() => resumen.value.total - resumen.value.totalAnterior)

const destacado = computed(() => {
  const r = resumen.value
  if (tab.value === 'logias') return { titulo: 'Sala más usada', item: r.porSala[0] ?? null }
  if (tab.value === 'libros') return { titulo: 'Material más prestado', item: r.porLibro[0] ?? null }
  return { titulo: `Carrera con más ${unidad.value.plural}`, item: r.porCarrera[0] ?? null }
})

/** Acorta nombres largos de carrera para la tarjeta (el nombre completo queda en el título). */
function abreviar(texto: string) {
  if (texto.length <= 18) return texto
  return texto
    .replace(/^Medicina /, 'Med. ')
    .replace(/^Ingeniería /, 'Ing. ')
    .replace(/^Pedagogía /, 'Ped. ')
    .replace(/ en Educación /, ' en Ed. ')
}

// --- Gráficos ---
const etiquetaHora = (label: string) => label.replace('h', '')
const horasPico = computed(() => {
  const pico = resumen.value.horarioPico
  if (!pico) return []
  const hora = parseInt(pico.desde)
  return [hora, hora + 1].map((h) => `${String(h).padStart(2, '0')}h`)
})
// En el reporte de un día, el horario de ese día; en los demás, el de la semana.
const horarioDelDia = computed(() => resumen.value.horario[diaIso(fechaLocal(resumen.value.rango.desde))] ?? null)
const diaSinAtencion = computed(() => periodo.value === 'dia' && Object.keys(resumen.value.horario).length > 0 && !horarioDelDia.value)
const textoHorario = computed(() => {
  if (!Object.keys(resumen.value.horario).length) return ''
  if (periodo.value !== 'dia') return textoHorarioSemana(resumen.value.horario)
  return horarioDelDia.value ? `Atención de ${horarioDelDia.value[0]} a ${horarioDelDia.value[1]}` : 'Sin atención este día'
})
const serieTieneFinesDeSemana = computed(() => resumen.value.serie.some((d) => d.finDeSemana))
const serieTieneDiasCerrados = computed(() => resumen.value.serie.some((d) => d.cerrado))

// --- Vista tabla ---
type Agrupacion = { id: string; label: string; color: string; datos: SerieItem[]; columna: string; ordenPropio?: boolean }

function etiquetaTramo(label: string) {
  if (periodo.value === 'mes') return `${label} ${MESES[fechaLocal(resumen.value.rango.desde).getMonth()].slice(0, 3)}`
  return capital(label)
}

const agrupaciones = computed<Agrupacion[]>(() => {
  const r = resumen.value
  const lista: Agrupacion[] = [
    { id: 'carrera', label: 'Carrera', color: INDIGO, datos: r.porCarrera, columna: 'Carrera' },
    {
      id: 'tipo',
      label: 'Tipo de usuario',
      color: PIZARRA,
      datos: r.porTipoUsuario.map((d) => ({ ...d, label: NOMBRES_TIPO[d.label] ?? d.label })),
      columna: 'Tipo de usuario',
    },
    { id: 'sexo', label: 'Sexo', color: DORADO, datos: r.porSexo, columna: 'Sexo' },
    { id: 'anio', label: 'Año de ingreso', color: VERDE_AZULADO, datos: r.porAnioIngreso, columna: 'Año de ingreso', ordenPropio: true },
    {
      id: 'hora',
      label: 'Hora del día',
      color: DORADO,
      datos: r.porHora.map((d) => ({ ...d, label: `${etiquetaHora(d.label)}:00` })),
      columna: 'Hora',
      ordenPropio: true,
    },
  ]
  if (periodo.value !== 'dia') {
    const nombre = capital(SUBUNIDAD[periodo.value])
    lista.push({ id: 'serie', label: nombre, color: INDIGO, datos: r.serie.map((d) => ({ label: etiquetaTramo(d.label), value: d.value })), columna: nombre, ordenPropio: true })
  }
  if (tab.value === 'prestamos') {
    lista.unshift(
      { id: 'tipoPrestamo', label: 'Tipo de préstamo', color: PIZARRA, datos: conNombres(r.porTipoPrestamo, NOMBRES_TIPO_PRESTAMO), columna: 'Tipo de préstamo' },
      { id: 'tipoMaterial', label: 'Tipo de material', color: INDIGO, datos: r.porTipoMaterial, columna: 'Tipo de material' },
      { id: 'recurso', label: 'Recurso', color: DORADO, datos: conNombres(r.porRecurso, NOMBRES_RECURSO.value), columna: 'Recurso' },
    )
  }
  if (tab.value === 'logias') lista.push({ id: 'sala', label: 'Sala', color: DORADO, datos: r.porSala, columna: 'Sala' })
  if (tab.value === 'libros') {
    lista.push(
      { id: 'tipoMaterial', label: 'Tipo de material', color: DORADO, datos: r.porTipoMaterial, columna: 'Tipo de material' },
      { id: 'libro', label: 'Título', color: INDIGO, datos: r.porLibro, columna: 'Título' },
    )
  }
  return lista
})

const agrupacionId = ref('carrera')
const agrupacion = computed(() => agrupaciones.value.find((a) => a.id === agrupacionId.value) ?? agrupaciones.value[0])
const tituloTabla = computed(() => `${unidad.value.titulo} por ${agrupacion.value.label.toLowerCase()}`)
const subtituloTabla = computed(() => {
  const filas = agrupacion.value.datos.length
  return `${etiquetaPeriodo.value} · ${filas} ${filas === 1 ? 'fila' : 'filas'} · haz clic en un encabezado para ordenar`
})

const confirmandoTabla = ref(false)
const exportandoTabla = ref(false)

async function exportarTabla() {
  const a = agrupacion.value
  exportandoTabla.value = true
  try {
    await descargarExcel(`reporte-${tab.value}-${a.id}-${resumen.value.rango.desde}.xlsx`, [
      {
        titulo: tituloTabla.value,
        columnas: [a.columna, capital(unidad.value.plural), '% del total'],
        filas: a.datos.map((d) => [d.label, d.value, porcentaje(d.value, resumen.value.total)]),
      },
    ])
    toast.success('Tabla exportada')
  } catch {
    toast.error('No se pudo generar el archivo Excel')
  } finally {
    exportandoTabla.value = false
    confirmandoTabla.value = false
  }
}

// --- Exportar el reporte completo ---
const menuExportar = ref(false)
const formatoExportar = ref<'excel' | 'csv' | null>(null)
const exportando = ref(false)

const descripcionFiltros = computed(() => {
  const partes: string[] = []
  if (tab.value === 'prestamos' && filtros.tipo_prestamo) partes.push(`préstamo ${NOMBRES_TIPO_PRESTAMO[filtros.tipo_prestamo].toLowerCase()}`)
  if ((tab.value === 'prestamos' || tab.value === 'libros') && filtros.tipo_material) {
    const tipo = opciones.value.tiposMaterial.find((t) => String(t.id) === filtros.tipo_material)
    if (tipo) partes.push(`material "${tipo.nombre}"`)
  }
  if (filtros.carrera) partes.push(`carrera "${filtros.carrera}"`)
  if (filtros.anio_ingreso) partes.push(`año de ingreso ${filtros.anio_ingreso}`)
  if (filtros.sexo) partes.push(`sexo ${filtros.sexo}`)
  if (filtros.tipo_usuario) partes.push(`solo ${(QUIENES[filtros.tipo_usuario] ?? filtros.tipo_usuario).toLowerCase()}`)
  return partes.length ? partes.join(', ') : 'sin filtros'
})

function pedirExportar(formato: 'excel' | 'csv') {
  menuExportar.value = false
  formatoExportar.value = formato
}

async function confirmarExportar() {
  const formato = formatoExportar.value
  if (!formato) return
  const r = resumen.value
  const tabLabel = TABS.find((t) => t.id === tab.value)?.label ?? tab.value
  const tabla = (titulo: string, columna: string, datos: SerieItem[]) => ({
    titulo,
    columnas: [columna, 'Cantidad', '%'],
    filas: datos.map((d) => [d.label, d.value, porcentaje(d.value, r.total)]),
  })

  const secciones = [
    {
      titulo: 'Resumen del reporte',
      columnas: ['Campo', 'Valor'],
      filas: [
        ['Reporte', tabLabel],
        ['Período', etiquetaPeriodo.value],
        ['Filtros aplicados', descripcionFiltros.value],
        ['Total', r.total],
        [`Total ${etiquetaAnterior.value}`, r.totalAnterior],
        ['Horario de mayor uso', r.horarioPico ? `${r.horarioPico.desde}–${r.horarioPico.hasta} (${r.horarioPico.value})` : '—'],
        ['Horario de atención', textoHorario.value || '—'],
      ] as (string | number)[][],
    },
    ...agrupaciones.value.map((a) => tabla(`Por ${a.label.toLowerCase()}`, a.columna, a.datos)),
  ]
  if (tab.value === 'logias') {
    secciones.push({
      titulo: 'Horas por sala',
      columnas: ['Sala y hora', 'Cantidad', '%'],
      filas: r.porHoraPorSala
        .flatMap((fila) => fila.horas.map((h) => ({ label: `${fila.sala} · ${etiquetaHora(h.label)}:00`, value: h.value })))
        .filter((d) => d.value > 0)
        .sort((a, b) => b.value - a.value)
        .map((d) => [d.label, d.value, porcentaje(d.value, r.total)]),
    })
  }

  const nombre = `reporte-${tab.value}-${periodo.value}-${r.rango.desde}`
  exportando.value = true
  try {
    if (formato === 'excel') await descargarExcel(`${nombre}.xlsx`, secciones)
    else descargarCsv(`${nombre}.csv`, construirCsv(secciones))
    toast.success('Reporte exportado')
    formatoExportar.value = null
  } catch {
    toast.error(formato === 'excel' ? 'No se pudo generar el archivo Excel' : 'No se pudo generar el archivo CSV')
  } finally {
    exportando.value = false
  }
}
</script>

<template>
  <StaffLayout>
    <div class="max-w-6xl mx-auto">
      <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 class="text-3xl font-serif font-bold tracking-tight text-gray-900">Reportes</h1>
          <p class="mt-1 text-sm text-gray-500">Estadísticas de uso de la biblioteca</p>
        </div>
        <div class="flex items-center gap-3">
          <div class="flex items-center gap-1 rounded-lg bg-gray-100 p-1">
            <button
              v-for="v in VISTAS"
              :key="v.id"
              type="button"
              class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
              :class="vista === v.id ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
              @click="vista = v.id"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" :d="v.d" />
              </svg>
              {{ v.label }}
            </button>
          </div>
          <div class="relative">
            <button
              type="button"
              class="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition-colors"
              :aria-expanded="menuExportar"
              @click="menuExportar = !menuExportar"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
              </svg>
              Exportar
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <template v-if="menuExportar">
              <div class="fixed inset-0 z-10" @click="menuExportar = false"></div>
              <div class="absolute right-0 z-20 mt-2 w-52 rounded-xl border border-gray-200 bg-white p-1.5 shadow-lg">
                <button type="button" class="w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50" @click="pedirExportar('excel')">
                  Excel (.xlsx)
                </button>
                <button type="button" class="w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50" @click="pedirExportar('csv')">
                  CSV
                </button>
              </div>
            </template>
          </div>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="mb-5 flex gap-6 border-b border-gray-200">
        <button
          v-for="t in TABS"
          :key="t.id"
          type="button"
          class="-mb-px border-b-2 pb-2.5 text-sm font-semibold transition-colors"
          :class="tab === t.id ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800'"
          @click="tab = t.id"
        >
          {{ t.label }}
        </button>
      </div>

      <div class="mb-4 flex flex-wrap items-center gap-2" role="group" aria-label="Quiénes">
        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">Quiénes</span>
        <button
          v-for="q in opcionesQuienes"
          :key="q.valor"
          type="button"
          class="rounded-full border px-3.5 py-1.5 text-sm font-semibold transition-colors"
          :class="filtros.tipo_usuario === q.valor ? 'border-indigo-600 bg-indigo-600 text-white shadow-sm' : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400'"
          :aria-pressed="filtros.tipo_usuario === q.valor"
          @click="filtros.tipo_usuario = q.valor"
        >
          {{ q.texto }}
        </button>
      </div>

      <div class="mb-5 flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-1 rounded-lg bg-gray-100 p-1">
          <button
            v-for="p in PERIODOS"
            :key="p.id"
            type="button"
            class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
            :class="periodo === p.id ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
            @click="periodo = p.id"
          >
            {{ p.label }}
          </button>
        </div>

        <div class="flex items-center gap-1">
          <button type="button" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-800" aria-label="Período anterior" @click="moverPeriodo(-1)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
          </button>
          <span class="min-w-[9rem] text-center text-sm font-semibold text-gray-900">{{ etiquetaPeriodo }}</span>
          <button
            type="button"
            class="rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-800 disabled:opacity-30 disabled:hover:bg-transparent"
            aria-label="Período siguiente"
            :disabled="!hayPeriodoSiguiente"
            @click="moverPeriodo(1)"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
          </button>
        </div>

        <span class="hidden h-6 w-px bg-gray-200 lg:block" aria-hidden="true"></span>

        <div v-if="mostrarMaterial" class="flex flex-wrap items-center gap-1" role="group" aria-label="Tipo de material">
          <span class="mr-1 text-xs text-gray-500">Material:</span>
          <button
            v-for="m in opcionesMaterial"
            :key="m.valor"
            type="button"
            class="rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors"
            :class="filtros.tipo_material === m.valor ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400'"
            :aria-pressed="filtros.tipo_material === m.valor"
            @click="filtros.tipo_material = m.valor"
          >
            {{ m.texto }}
          </button>
        </div>

        <span v-if="mostrarMaterial" class="hidden h-6 w-px bg-gray-200 lg:block" aria-hidden="true"></span>

        <label
          v-for="f in filtrosPildora"
          :key="f.campo"
          class="relative inline-flex cursor-pointer items-center gap-1 rounded-lg border border-gray-300 bg-white py-1.5 pl-3 pr-7 text-xs text-gray-500 hover:border-gray-400 focus-within:ring-2 focus-within:ring-indigo-500"
        >
          {{ f.nombre }}:
          <span class="max-w-[9rem] truncate font-semibold text-gray-900">
            {{ f.valores.find((o) => o.valor === filtros[f.campo])?.texto ?? f.todos }}
          </span>
          <svg class="pointer-events-none absolute right-2 w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
          </svg>
          <select v-model="filtros[f.campo]" class="absolute inset-0 cursor-pointer opacity-0" :aria-label="`Filtrar por ${f.nombre.toLowerCase()}`">
            <option value="">{{ f.todos }}</option>
            <option v-for="o in f.valores" :key="o.valor" :value="o.valor">{{ o.texto }}</option>
          </select>
        </label>
      </div>

      <div class="transition-opacity" :class="cargando ? 'opacity-60' : ''">
        <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div class="rounded-xl border border-gray-200 border-t-4 bg-white p-4 shadow-sm" :style="{ borderTopColor: INDIGO }">
            <div class="flex items-start justify-between gap-3">
              <p class="text-xs font-medium text-gray-600">{{ unidad.titulo }} {{ DEL_PERIODO[periodo] }}</p>
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                </svg>
              </span>
            </div>
            <p class="mt-1 text-3xl font-serif font-bold tabular-nums text-gray-900">{{ resumen.total }}</p>
            <p class="mt-1 text-xs text-gray-500">
              <span class="font-semibold" :class="diferencia > 0 ? 'text-emerald-600' : diferencia < 0 ? 'text-red-600' : 'text-gray-500'">
                {{ diferencia > 0 ? `+${diferencia}` : diferencia < 0 ? `−${-diferencia}` : '=' }}
              </span>
              vs. {{ etiquetaAnterior }} ({{ resumen.totalAnterior }})
            </p>
          </div>

          <div class="rounded-xl border border-gray-200 border-t-4 bg-white p-4 shadow-sm" :style="{ borderTopColor: DORADO }">
            <div class="flex items-start justify-between gap-3">
              <p class="text-xs font-medium text-gray-600">{{ destacado.titulo }}</p>
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-dorado-600">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5" />
                </svg>
              </span>
            </div>
            <p class="mt-1 truncate text-2xl font-serif font-bold text-gray-900" :title="destacado.item?.label">
              {{ destacado.item ? abreviar(destacado.item.label) : '—' }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
              <template v-if="destacado.item">{{ destacado.item.value }} {{ unidad.plural }} · {{ porcentaje(destacado.item.value, resumen.total) }}%</template>
              <template v-else>Sin {{ unidad.plural }} en este período</template>
            </p>
          </div>

          <div class="rounded-xl border border-gray-200 border-t-4 bg-white p-4 shadow-sm" :style="{ borderTopColor: VERDE_AZULADO }">
            <div class="flex items-start justify-between gap-3">
              <p class="text-xs font-medium text-gray-600">Horario de mayor uso</p>
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-600">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </span>
            </div>
            <p class="mt-1 text-2xl font-serif font-bold tabular-nums text-gray-900">
              {{ resumen.horarioPico ? `${resumen.horarioPico.desde}–${resumen.horarioPico.hasta}` : '—' }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
              <template v-if="resumen.horarioPico">{{ resumen.horarioPico.value }} de {{ resumen.total }} {{ unidad.plural }}</template>
              <template v-else>Sin {{ unidad.plural }} en este período</template>
            </p>
          </div>
        </div>

        <template v-if="vista === 'grafico'">
          <section class="mb-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="font-sans text-base font-bold text-gray-900">{{ unidad.titulo }} por {{ SUBUNIDAD[periodo] }}</h3>
            <p class="mt-0.5 text-xs text-gray-500">
              {{ etiquetaPeriodo }}<template v-if="periodo === 'dia' && textoHorario"> · {{ textoHorario.toLowerCase() }}</template>
              <template v-if="serieTieneFinesDeSemana"> · en tono claro, los fines de semana</template>
              <template v-if="serieTieneDiasCerrados"> · rayados, los días sin atención</template>
            </p>
            <div class="mt-4 h-56">
              <GraficoColumnas
                :datos="resumen.serie"
                :color="INDIGO"
                :color-suave="INDIGO_SUAVE"
                :unidad="unidad.plural"
                :mensaje-vacio="diaSinAtencion ? 'La biblioteca no atiende este día.' : undefined"
              />
            </div>
          </section>

          <section v-if="tab === 'prestamos'" class="mb-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="font-sans text-base font-bold text-gray-900">Por tipo de préstamo</h3>
            <p class="mt-0.5 mb-4 text-xs text-gray-500">Material de la colección (libros, tesis, revistas, juegos…) o recursos de apoyo (notebooks, audífonos, bicicletas…)</p>
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <BarraApilada :datos="resumen.porTipoPrestamo" :colores="COLORES_TIPO_PRESTAMO" :nombres="NOMBRES_TIPO_PRESTAMO" />
              <BarrasRanking :datos="conNombres(resumen.porRecurso, NOMBRES_RECURSO)" :total="resumen.total" :color="DORADO" :color-primero="DORADO_DESTACADO" />
            </div>
          </section>

          <div class="mb-5 grid grid-cols-1 gap-5 lg:grid-cols-5">
            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm lg:col-span-3">
              <h3 class="font-sans text-base font-bold text-gray-900">Por carrera</h3>
              <p class="mt-0.5 mb-4 text-xs text-gray-500">
                Ordenado de mayor a menor<template v-if="resumen.porCarrera.length > 8"> · las 8 con más {{ unidad.plural }} (todas en la vista Tabla)</template>
              </p>
              <BarrasRanking :datos="resumen.porCarrera" :total="resumen.total" :color="INDIGO" :color-primero="INDIGO_DESTACADO" />
            </section>

            <div class="space-y-5 lg:col-span-2">
              <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="mb-4 font-sans text-base font-bold text-gray-900">Por tipo de usuario</h3>
                <BarraApilada :datos="resumen.porTipoUsuario" :colores="COLORES_TIPO" :nombres="NOMBRES_TIPO" />
              </section>
              <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="mb-4 font-sans text-base font-bold text-gray-900">Por sexo</h3>
                <BarraApilada :datos="resumen.porSexo" :colores="COLORES_SEXO" />
              </section>
            </div>
          </div>

          <div class="mb-5 grid grid-cols-1 gap-5 lg:grid-cols-5">
            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm lg:col-span-2">
              <h3 class="font-sans text-base font-bold text-gray-900">Por año de ingreso</h3>
              <p class="mt-0.5 text-xs text-gray-500">Cohorte de quien {{ unidad.quien }}</p>
              <div class="mt-4 h-48">
                <GraficoColumnas :datos="resumen.porAnioIngreso" :color="VERDE_AZULADO" :color-suave="VERDE_AZULADO" mostrar-valores :unidad="unidad.plural" />
              </div>
            </section>
            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm lg:col-span-3">
              <h3 class="font-sans text-base font-bold text-gray-900">Por hora del día</h3>
              <p class="mt-0.5 text-xs text-gray-500">
                {{ textoHorario }}<template v-if="horasPico.length"> · en color fuerte, las horas de mayor uso</template>
              </p>
              <div class="mt-4 h-48">
                <GraficoColumnas
                  :datos="resumen.porHora"
                  :color="DORADO"
                  :color-suave="DORADO_SUAVE"
                  :destacados="horasPico"
                  mostrar-valores
                  :etiqueta="etiquetaHora"
                  :unidad="unidad.plural"
                  :mensaje-vacio="diaSinAtencion ? 'La biblioteca no atiende este día.' : undefined"
                />
              </div>
            </section>
          </div>

          <section v-if="tab === 'logias'" class="mb-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="font-sans text-base font-bold text-gray-900">Salas más usadas</h3>
            <p class="mt-0.5 mb-4 text-xs text-gray-500">Ordenado de mayor a menor</p>
            <BarrasRanking :datos="resumen.porSala" :total="resumen.total" :color="DORADO" :color-primero="DORADO_DESTACADO" :limite="10" />
          </section>
          <section v-if="tab === 'logias'" class="mb-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="font-sans text-base font-bold text-gray-900">Horas más solicitadas por sala</h3>
            <p class="mt-0.5 mb-4 text-xs text-gray-500">Reservas por bloque horario en cada sala</p>
            <Heatmap :data="resumen.porHoraPorSala" />
          </section>
          <section v-if="tab === 'libros'" class="mb-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="font-sans text-base font-bold text-gray-900">Material más prestado</h3>
            <p class="mt-0.5 mb-4 text-xs text-gray-500">Libros, tesis, revistas, juegos y demás: ranking por cantidad de préstamos (cada copia cuenta por separado)</p>
            <BarrasRanking :datos="resumen.porLibro" :total="resumen.total" :color="INDIGO" :color-primero="INDIGO_DESTACADO" :limite="10" />
            <h3 class="mt-6 font-sans text-base font-bold text-gray-900">Por tipo de material</h3>
            <p class="mt-0.5 mb-4 text-xs text-gray-500">Cuántos préstamos fueron de cada tipo de material</p>
            <BarrasRanking :datos="resumen.porTipoMaterial" :total="resumen.total" :color="DORADO" :color-primero="DORADO_DESTACADO" />
          </section>
        </template>

        <div v-else class="mb-5 grid grid-cols-1 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm md:grid-cols-[13rem_minmax(0,1fr)]">
          <nav class="border-b border-gray-200 bg-gray-50/70 p-3 md:border-b-0 md:border-r" aria-label="Agrupar por">
            <p class="px-2 pb-2 pt-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Agrupar por</p>
            <button
              v-for="a in agrupaciones"
              :key="a.id"
              type="button"
              class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm transition-colors"
              :class="agrupacion.id === a.id ? 'bg-white font-semibold text-gray-900 shadow-sm ring-1 ring-gray-200' : 'text-gray-600 hover:bg-white/70'"
              @click="agrupacionId = a.id"
            >
              <span class="h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: a.color }"></span>
              <span class="flex-1 truncate">{{ a.label }}</span>
              <span class="text-xs tabular-nums text-gray-400">{{ a.datos.length }}</span>
            </button>
          </nav>
          <TablaReporte
            :titulo="tituloTabla"
            :subtitulo="subtituloTabla"
            :columna="agrupacion.columna"
            :unidad="capital(unidad.plural)"
            :datos="agrupacion.datos"
            :total="resumen.total"
            :color="agrupacion.color"
            :orden-propio="agrupacion.ordenPropio"
            @exportar="confirmandoTabla = true"
          />
        </div>
      </div>
    </div>

    <DialogoConfirmacion
      :abierto="formatoExportar !== null"
      titulo="Confirmar exportación"
      confirmar="Confirmar y exportar"
      tono="exito"
      :cargando="exportando"
      @confirmar="confirmarExportar"
      @cancelar="formatoExportar = null"
    >
      <p>
        Se va a exportar en formato {{ formatoExportar === 'excel' ? 'Excel (.xlsx)' : 'CSV' }} el reporte de
        <strong>{{ TABS.find((t) => t.id === tab)?.label }}</strong> de <strong>{{ etiquetaPeriodo }}</strong>, {{ descripcionFiltros }}:
        resumen, todas las agrupaciones y, en salas, las horas por sala.
      </p>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="confirmandoTabla"
      titulo="¿Exportar esta tabla?"
      confirmar="Confirmar y exportar"
      tono="exito"
      :cargando="exportandoTabla"
      @confirmar="exportarTabla"
      @cancelar="confirmandoTabla = false"
    >
      <p>
        Se descargará en Excel (.xlsx) la tabla <strong>{{ tituloTabla }}</strong> de <strong>{{ etiquetaPeriodo }}</strong>
        ({{ agrupacion.datos.length }} {{ agrupacion.datos.length === 1 ? 'fila' : 'filas' }}, {{ descripcionFiltros }}).
      </p>
    </DialogoConfirmacion>
  </StaffLayout>
</template>
