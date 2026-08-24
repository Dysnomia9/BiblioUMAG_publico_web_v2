import ExcelJS from 'exceljs'

export type SeccionExcel = {
  titulo: string
  columnas: string[]
  filas: (string | number)[][]
}

const CARACTERES_INVALIDOS_HOJA = /[\\/?*[\]:]/g

const COLOR_TITULO = 'FF3B28A3'
const COLOR_ENCABEZADO = 'FFE5E7EB'
const COLOR_FILA_PAR = 'FFF3F4F6'
const COLOR_BORDE = 'FFD1D5DB'

const BORDE_FINO: Partial<ExcelJS.Borders> = {
  top: { style: 'thin', color: { argb: COLOR_BORDE } },
  left: { style: 'thin', color: { argb: COLOR_BORDE } },
  bottom: { style: 'thin', color: { argb: COLOR_BORDE } },
  right: { style: 'thin', color: { argb: COLOR_BORDE } },
}

function nombreHojaUnico(titulo: string, usados: Set<string>): string {
  const base = titulo.replace(CARACTERES_INVALIDOS_HOJA, ' ').trim().slice(0, 31) || 'Hoja'
  let candidato = base
  let i = 2
  while (usados.has(candidato)) {
    const sufijo = ` (${i})`
    candidato = base.slice(0, 31 - sufijo.length) + sufijo
    i++
  }
  usados.add(candidato)
  return candidato
}

/** Genera un libro .xlsx con diseño (título, encabezado y filas con color) — una hoja por sección. */
export async function descargarExcel(nombreArchivo: string, secciones: SeccionExcel[]) {
  const workbook = new ExcelJS.Workbook()
  workbook.creator = 'Biblioteca UMAG'
  workbook.created = new Date()
  const nombresUsados = new Set<string>()

  for (const seccion of secciones) {
    const worksheet = workbook.addWorksheet(nombreHojaUnico(seccion.titulo, nombresUsados))
    const numColumnas = Math.max(1, seccion.columnas.length)

    // Fila 1: título de la hoja, fusionado y coloreado.
    worksheet.mergeCells(1, 1, 1, numColumnas)
    const celdaTitulo = worksheet.getCell(1, 1)
    celdaTitulo.value = seccion.titulo
    celdaTitulo.font = { bold: true, size: 13, color: { argb: 'FFFFFFFF' } }
    celdaTitulo.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: COLOR_TITULO } }
    celdaTitulo.alignment = { vertical: 'middle', horizontal: 'left' }
    worksheet.getRow(1).height = 24

    // Fila 3: encabezados de columna (fila 2 queda en blanco como separador).
    const filaEncabezado = worksheet.getRow(3)
    seccion.columnas.forEach((columna, idx) => {
      const celda = filaEncabezado.getCell(idx + 1)
      celda.value = columna
      celda.font = { bold: true, color: { argb: 'FF1F2937' } }
      celda.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: COLOR_ENCABEZADO } }
      celda.border = BORDE_FINO
      celda.alignment = { vertical: 'middle' }
    })

    // Filas de datos, alternadas, con bordes y alineación a la derecha para números.
    seccion.filas.forEach((fila, filaIdx) => {
      const filaExcel = worksheet.getRow(4 + filaIdx)
      fila.forEach((valor, colIdx) => {
        const celda = filaExcel.getCell(colIdx + 1)
        celda.value = valor
        celda.border = BORDE_FINO
        celda.alignment = { horizontal: typeof valor === 'number' ? 'right' : 'left' }
        if (filaIdx % 2 === 1) {
          celda.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: COLOR_FILA_PAR } }
        }
      })
    })

    worksheet.columns = seccion.columnas.map((columna, idx) => {
      const anchoContenido = Math.max(
        columna.length,
        ...seccion.filas.map((f) => String(f[idx] ?? '').length),
      )
      return { width: Math.min(40, Math.max(12, anchoContenido + 4)) }
    })

    worksheet.views = [{ state: 'frozen', ySplit: 3 }]
  }

  const buffer = await workbook.xlsx.writeBuffer()
  const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = nombreArchivo
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}
