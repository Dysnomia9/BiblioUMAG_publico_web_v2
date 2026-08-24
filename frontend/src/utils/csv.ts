export type SeccionCsv = {
  titulo: string
  columnas: string[]
  filas: (string | number)[][]
}

function escaparValorCsv(valor: string | number): string {
  const str = String(valor)
  if (/[";\n]/.test(str)) {
    return `"${str.replace(/"/g, '""')}"`
  }
  return str
}

/** Construye un CSV (delimitado por ";", como espera Excel en configuración regional es-CL) con varias secciones tituladas. */
export function construirCsv(secciones: SeccionCsv[]): string {
  const lineas: string[] = []
  for (const seccion of secciones) {
    lineas.push(escaparValorCsv(seccion.titulo))
    lineas.push(seccion.columnas.map(escaparValorCsv).join(';'))
    for (const fila of seccion.filas) {
      lineas.push(fila.map(escaparValorCsv).join(';'))
    }
    lineas.push('')
  }
  return '﻿' + lineas.join('\r\n')
}

export function descargarCsv(nombreArchivo: string, contenido: string) {
  const blob = new Blob([contenido], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = nombreArchivo
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}
