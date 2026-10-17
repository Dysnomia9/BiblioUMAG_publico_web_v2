import ExcelJS from 'exceljs'
import { sinTildes } from '@/utils/texto'

/**
 * Lectura de la planilla de la carga masiva de estudiantes (Excel o CSV) y la
 * plantilla para descargar. Acá solo se leen las filas: qué se carga y qué no lo
 * decide el servidor (POST /usuarios/importar/revisar).
 */

export type CampoEstudiante =
  | 'rut'
  | 'tipo_documento'
  | 'pais'
  | 'nombre'
  | 'apellido'
  | 'email'
  | 'carrera'
  | 'anio_ingreso'
  | 'sexo'
  | 'tipo'
  // Columnas del reporte de matrícula de la universidad: el servidor las combina
  // (apellidos en uno, especialidad junto a la carrera, nacionalidad para el documento).
  | 'apellido_paterno'
  | 'apellido_materno'
  | 'especialidad'
  | 'nacionalidad'

/** Una fila leída, con su número en el archivo para poder ubicarla. */
export type FilaPlanilla = Partial<Record<CampoEstudiante, string>> & { fila: number }

export type LecturaPlanilla = {
  filas: FilaPlanilla[]
  /** Columnas del archivo que se reconocieron, y las que se ignoran. */
  reconocidas: CampoEstudiante[]
  ignoradas: string[]
}

/** Columnas de la plantilla, en orden: encabezado, campo y una nota para la hoja de instrucciones. */
export const COLUMNAS_PLANTILLA: { encabezado: string; campo: CampoEstudiante; nota: string }[] = [
  { encabezado: 'RUT o documento', campo: 'rut', nota: 'Obligatorio. RUT con o sin puntos y guion (12.345.678-5 o 123456785), o el número de pasaporte o DNI si es extranjero.' },
  { encabezado: 'Tipo de documento', campo: 'tipo_documento', nota: 'RUT, Pasaporte o DNI. Si se deja vacío: con letras es pasaporte; solo números, RUT. Un DNI o cédula extranjera (solo números) debe decir DNI.' },
  { encabezado: 'País', campo: 'pais', nota: 'Opcional, para extranjeros (ej. Argentina).' },
  { encabezado: 'Nombre', campo: 'nombre', nota: 'Obligatorio.' },
  { encabezado: 'Apellido', campo: 'apellido', nota: 'Obligatorio.' },
  { encabezado: 'Correo', campo: 'email', nota: 'Opcional. No puede repetirse con el de otro usuario.' },
  { encabezado: 'Carrera', campo: 'carrera', nota: 'Opcional. Escríbela igual que en el sistema para que los reportes la agrupen (ej. Pedagogía en Educación Básica).' },
  { encabezado: 'Año de ingreso', campo: 'anio_ingreso', nota: 'Opcional. Cuatro dígitos (ej. 2026).' },
  { encabezado: 'Sexo', campo: 'sexo', nota: 'Opcional. Femenino, Masculino u Otro (también F o M).' },
  { encabezado: 'Tipo', campo: 'tipo', nota: 'Opcional. Estudiante (si se deja vacío), Docente o Funcionario.' },
]

/** Encabezados que se aceptan para cada campo, ya sin tildes, en minúscula y sin signos. */
const SINONIMOS: Record<CampoEstudiante, string[]> = {
  rut: ['rut', 'run', 'rut o documento', 'rut documento', 'rut o pasaporte', 'rut pasaporte', 'documento', 'n documento', 'numero documento', 'numero de documento', 'identificador', 'identificacion'],
  tipo_documento: ['tipo documento', 'tipo de documento', 'tipo doc', 'documento tipo'],
  pais: ['pais', 'pais documento', 'pais de origen'],
  nombre: ['nombre', 'nombres', 'nombre del alumno', 'nombre alumno', 'nombres del alumno', 'nombre del estudiante', 'nombre estudiante'],
  apellido: ['apellido', 'apellidos'],
  email: ['email', 'e mail', 'mail', 'correo', 'correo electronico', 'correo institucional'],
  carrera: ['carrera', 'programa', 'nombre carrera', 'nombre de la carrera', 'nombre de carrera'],
  anio_ingreso: ['anio ingreso', 'ano ingreso', 'anio de ingreso', 'ano de ingreso', 'ingreso', 'cohorte'],
  sexo: ['sexo', 'genero'],
  tipo: ['tipo', 'tipo usuario', 'tipo de usuario'],
  apellido_paterno: ['paterno', 'apellido paterno', 'primer apellido', 'ap paterno'],
  apellido_materno: ['materno', 'apellido materno', 'segundo apellido', 'ap materno'],
  especialidad: ['especialidad', 'mencion', 'especialidad o mencion'],
  nacionalidad: ['nacionalidad'],
}

function claveEncabezado(texto: string): string {
  return sinTildes(texto)
    .toLowerCase()
    .replace(/ñ/g, 'n')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

export function campoDeEncabezado(texto: string): CampoEstudiante | null {
  const clave = claveEncabezado(texto)
  for (const [campo, sinonimos] of Object.entries(SINONIMOS) as [CampoEstudiante, string[]][]) {
    if (sinonimos.includes(clave)) return campo
  }
  return null
}

export async function leerPlanilla(archivo: File): Promise<LecturaPlanilla> {
  const nombre = archivo.name.toLowerCase()
  if (nombre.endsWith('.xlsx')) return leerExcel(await archivo.arrayBuffer())
  if (nombre.endsWith('.csv') || nombre.endsWith('.txt')) return leerCsv(decodificarTexto(await archivo.arrayBuffer()))
  throw new Error('Formato no soportado: usa un archivo Excel (.xlsx) o CSV (.csv). Si tienes un .xls antiguo, guárdalo como .xlsx.')
}

/**
 * El texto del CSV en la codificación en que vino: UTF-8 (con o sin BOM) o, si no lo
 * es, la que usa Excel al guardar «CSV»: Windows-1252 en Windows y Mac Roman en Mac
 * (así llega el reporte de matrícula). Entre esas dos gana la que deja más letras del
 * español (á, é, í, ó, ú, ñ, ü) en vez de símbolos raros como «„» o «ê».
 */
export function decodificarTexto(buffer: ArrayBuffer): string {
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(buffer)
  } catch {
    const letras = (texto: string) => (texto.match(/[áéíóúñüÁÉÍÓÚÑÜ]/g) ?? []).length
    const [windows, mac] = ['windows-1252', 'macintosh'].map((codificacion) => new TextDecoder(codificacion).decode(buffer))
    return letras(mac) > letras(windows) ? mac : windows
  }
}

/** Arma las filas a partir de una tabla de textos: busca la fila de encabezados entre las primeras 10. */
function desdeTabla(tabla: { numero: number; celdas: string[] }[]): LecturaPlanilla {
  const indiceEncabezado = tabla.slice(0, 10).findIndex((f) => f.celdas.some((c) => campoDeEncabezado(c) === 'rut'))
  if (indiceEncabezado === -1) {
    throw new Error('No se encontró la columna «RUT o documento». Usa la plantilla: la primera fila debe tener los nombres de las columnas.')
  }

  const encabezados = tabla[indiceEncabezado].celdas
  const campos = encabezados.map(campoDeEncabezado)
  const reconocidas = [...new Set(campos.filter((c): c is CampoEstudiante => !!c))]
  // Los encabezados del reporte de matrícula traen saltos de línea («FECHA↵NACIMIENTO»).
  const ignoradas = encabezados.filter((e, i) => e.trim() && !campos[i]).map((e) => e.replace(/\s+/g, ' ').trim())

  if (!reconocidas.includes('nombre')) {
    throw new Error('Falta la columna «Nombre». Usa la plantilla para ver qué columnas lleva.')
  }
  if (!reconocidas.includes('apellido') && !reconocidas.includes('apellido_paterno')) {
    throw new Error('Falta la columna «Apellido» (o «Paterno» y «Materno»). Usa la plantilla para ver qué columnas lleva.')
  }

  const filas: FilaPlanilla[] = []
  for (const { numero, celdas } of tabla.slice(indiceEncabezado + 1)) {
    if (celdas.every((c) => !c.trim())) continue
    const fila: FilaPlanilla = { fila: numero }
    campos.forEach((campo, i) => {
      if (campo && !fila[campo]) fila[campo] = (celdas[i] ?? '').trim()
    })
    filas.push(fila)
  }

  return { filas, reconocidas, ignoradas }
}

async function leerExcel(buffer: ArrayBuffer): Promise<LecturaPlanilla> {
  const libro = new ExcelJS.Workbook()
  await libro.xlsx.load(buffer)
  // La hoja «Estudiantes» de la plantilla o, si no está, la primera.
  const hoja = libro.getWorksheet('Estudiantes') ?? libro.worksheets[0]
  if (!hoja) throw new Error('El archivo Excel no tiene hojas.')

  const tabla: { numero: number; celdas: string[] }[] = []
  hoja.eachRow({ includeEmpty: false }, (row, numero) => {
    const celdas: string[] = []
    for (let c = 1; c <= hoja.columnCount; c++) celdas.push(row.getCell(c).text ?? '')
    tabla.push({ numero, celdas })
  })
  return desdeTabla(tabla)
}

/** CSV separado por «;» (Excel en español), «,» o tabulador, con comillas y saltos de línea dentro de comillas. */
export function leerCsv(texto: string): LecturaPlanilla {
  const limpio = texto.replace(/^﻿/, '')
  // Excel para Mac separa las filas solo con «\r».
  const primeraLinea = limpio.split(/\r\n|\r|\n/).find((l) => l.trim()) ?? ''
  const separador = [';', ',', '\t'].reduce((mejor, s) => (primeraLinea.split(s).length > primeraLinea.split(mejor).length ? s : mejor), ';')

  const tabla: { numero: number; celdas: string[] }[] = []
  let celdas: string[] = []
  let actual = ''
  let entreComillas = false
  let linea = 1
  let inicioFila = 1

  for (let i = 0; i < limpio.length; i++) {
    const c = limpio[i]
    if (entreComillas) {
      if (c === '"' && limpio[i + 1] === '"') {
        actual += '"'
        i++
      } else if (c === '"') {
        entreComillas = false
      } else {
        if (c === '\n') linea++
        actual += c
      }
    } else if (c === '"') {
      entreComillas = true
    } else if (c === separador) {
      celdas.push(actual)
      actual = ''
    } else if (c === '\n' || c === '\r') {
      if (c === '\r' && limpio[i + 1] === '\n') i++
      celdas.push(actual)
      tabla.push({ numero: inicioFila, celdas })
      celdas = []
      actual = ''
      linea++
      inicioFila = linea
    } else {
      actual += c
    }
  }
  if (actual || celdas.length) {
    celdas.push(actual)
    tabla.push({ numero: inicioFila, celdas })
  }

  return desdeTabla(tabla)
}

const EJEMPLOS_PLANTILLA: string[][] = [
  ['15.003.151-6', 'RUT', '', 'Sebastián', 'Sánchez Mella', 'sebastian.sanchez@umag.cl', 'Pedagogía en Educación Básica', '2026', 'Masculino', 'Estudiante'],
  ['38456123', 'DNI', 'Argentina', 'Martina', 'Gómez', 'martina.gomez@umag.cl', 'Enfermería', '2026', 'Femenino', 'Estudiante'],
  ['FT482913', 'Pasaporte', 'Brasil', 'Lucas', 'Silva', 'lucas.silva@umag.cl', 'Ingeniería Comercial', '2026', 'Masculino', 'Estudiante'],
]

function descargar(nombre: string, blob: Blob) {
  const url = URL.createObjectURL(blob)
  const enlace = document.createElement('a')
  enlace.href = url
  enlace.download = nombre
  document.body.appendChild(enlace)
  enlace.click()
  document.body.removeChild(enlace)
  URL.revokeObjectURL(url)
}

/** Plantilla con las columnas, tres filas de ejemplo (bórralas antes de cargar) y una hoja de instrucciones. */
export async function descargarPlantilla(formato: 'excel' | 'csv') {
  if (formato === 'csv') {
    const escapar = (v: string) => (/[";\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v)
    const lineas = [COLUMNAS_PLANTILLA.map((c) => c.encabezado), ...EJEMPLOS_PLANTILLA].map((f) => f.map(escapar).join(';'))
    descargar('plantilla-estudiantes.csv', new Blob(['﻿' + lineas.join('\r\n')], { type: 'text/csv;charset=utf-8;' }))
    return
  }

  const libro = new ExcelJS.Workbook()
  libro.creator = 'Biblioteca UMAG'
  const hoja = libro.addWorksheet('Estudiantes', { views: [{ state: 'frozen', ySplit: 1 }] })
  hoja.columns = COLUMNAS_PLANTILLA.map((c) => ({ header: c.encabezado, key: c.campo, width: Math.max(16, c.encabezado.length + 6) }))
  hoja.getColumn('rut').numFmt = '@'
  hoja.getColumn('email').width = 30
  hoja.getColumn('carrera').width = 32
  const encabezado = hoja.getRow(1)
  encabezado.font = { bold: true, color: { argb: 'FFFFFFFF' } }
  encabezado.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF3B28A3' } }
  EJEMPLOS_PLANTILLA.forEach((f) => hoja.addRow(f))

  for (let fila = 2; fila <= 2000; fila++) {
    hoja.getCell(fila, 2).dataValidation = { type: 'list', allowBlank: true, formulae: ['"RUT,Pasaporte,DNI,Otro"'] }
    hoja.getCell(fila, 9).dataValidation = { type: 'list', allowBlank: true, formulae: ['"Femenino,Masculino,Otro"'] }
    hoja.getCell(fila, 10).dataValidation = { type: 'list', allowBlank: true, formulae: ['"Estudiante,Docente,Funcionario"'] }
  }

  const ayuda = libro.addWorksheet('Instrucciones')
  ayuda.columns = [{ width: 22 }, { width: 100 }]
  ayuda.addRow(['Carga masiva de estudiantes']).font = { bold: true, size: 14 }
  ayuda.addRow(['Las filas de ejemplo de la hoja «Estudiantes» son solo una guía: bórralas o reemplázalas antes de cargar.'])
  ayuda.addRow(['Quien ya existe en el sistema (mismo RUT o documento) no se carga de nuevo ni se modifica: el sistema lo avisa antes de confirmar.'])
  ayuda.addRow([])
  ayuda.addRow(['Columna', 'Qué va']).font = { bold: true }
  COLUMNAS_PLANTILLA.forEach((c) => ayuda.addRow([c.encabezado, c.nota]))
  ayuda.getColumn(2).alignment = { wrapText: true, vertical: 'top' }

  const buffer = await libro.xlsx.writeBuffer()
  descargar('plantilla-estudiantes.xlsx', new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }))
}
