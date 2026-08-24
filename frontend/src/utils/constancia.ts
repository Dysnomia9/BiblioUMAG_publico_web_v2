import { jsPDF } from 'jspdf'
import selloBiblioteca from '@/assets/sello-biblioteca.png'
import type { ConfiguracionInstitucional, Usuario } from '@/types'

type Segmento = { texto: string; negrita?: boolean; subrayado?: boolean }

/**
 * Dibuja un párrafo con estilos mixtos (negrita/subrayado por tramo) y salto de línea
 * automático — jsPDF no soporta texto enriquecido dentro de un mismo doc.text() ni
 * tiene un estilo de fuente "underline", así que se posiciona token por token a mano
 * (preservando los espacios de cada segmento.texto) y el subrayado de un segmento se
 * dibuja como una sola línea continua de su inicio a su fin, no una por palabra.
 */
function dibujarParrafoConEstilos(doc: jsPDF, segmentos: Segmento[], x: number, y: number, anchoMax: number, interlineado = 6.5): number {
  const xInicial = x
  let cursorX = x
  let cursorY = y

  for (const segmento of segmentos) {
    doc.setFont('helvetica', segmento.negrita ? 'bold' : 'normal')
    const tokens = segmento.texto.split(/(\s+)/).filter((t) => t.length > 0)
    const inicioX = cursorX
    const inicioY = cursorY

    for (const token of tokens) {
      const esEspacio = /^\s+$/.test(token)
      const ancho = doc.getTextWidth(token)

      if (!esEspacio && cursorX + ancho > xInicial + anchoMax) {
        cursorX = xInicial
        cursorY += interlineado
      }

      if (!esEspacio) doc.text(token, cursorX, cursorY)
      cursorX += ancho
    }

    // Si el segmento saltó de línea, no se dibuja el subrayado (caso borde poco
    // probable con nombres/RUT cortos) — evita una línea diagonal sin sentido.
    if (segmento.subrayado && inicioY === cursorY) {
      doc.setDrawColor(0, 0, 0)
      doc.setLineWidth(0.25)
      doc.line(inicioX, cursorY + 0.8, cursorX, cursorY + 0.8)
    }
  }

  return cursorY
}

/** Ancho total de una serie de segmentos con estilos mixtos, respetando la fuente de cada uno. */
function medirAnchoSegmentos(doc: jsPDF, segmentos: Segmento[]): number {
  return segmentos.reduce((total, segmento) => {
    doc.setFont('helvetica', segmento.negrita ? 'bold' : 'normal')
    return total + doc.getTextWidth(segmento.texto)
  }, 0)
}

/** Trae un asset importado por Vite (URL) y lo convierte a data URI base64 — lo que jsPDF necesita para addImage(). */
function cargarImagenComoBase64(url: string): Promise<string> {
  return fetch(url)
    .then((respuesta) => respuesta.blob())
    .then(
      (blob) =>
        new Promise<string>((resolve, reject) => {
          const lector = new FileReader()
          lector.onload = () => resolve(lector.result as string)
          lector.onerror = reject
          lector.readAsDataURL(blob)
        }),
    )
}

/**
 * Constancia de No Multa — mismo patrón que el QR (librería de terceros dibujando
 * sobre un canvas/documento) y el Excel (descarga directa), pero para PDF. El
 * encabezado sigue siendo de texto (no hay una imagen del logo UMAG completo en el
 * repo), pero el sello de la Biblioteca Central sí es una imagen real
 * (`assets/sello-biblioteca.png`, recortada al círculo visible) — no es una réplica
 * pixel-perfect del documento con firma manuscrita, pero ya lleva el sello real.
 */
export async function generarConstanciaNoMulta(usuario: Usuario, configuracion: ConfiguracionInstitucional): Promise<void> {
  const doc = new jsPDF({ unit: 'mm', format: 'letter' })
  const anchoPagina = doc.internal.pageSize.getWidth()
  const margen = 25

  // Encabezado tipo membrete: bloque UMAG a la izquierda, línea vertical divisoria,
  // y "Unidad de Gestión de Recursos Educativos" a la derecha de esa línea.
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(20)
  doc.setTextColor(20, 20, 20)
  doc.text('UMAG', margen, 22)

  doc.setFont('helvetica', 'normal')
  doc.setFontSize(10)
  doc.text('Universidad de Magallanes', margen, 29)

  const xLinea = 108
  doc.setDrawColor(120, 120, 120)
  doc.setLineWidth(0.4)
  doc.line(xLinea, 13, xLinea, 33)

  doc.setFont('helvetica', 'bold')
  doc.setFontSize(10)
  const anchoColumnaDerecha = anchoPagina - margen - (xLinea + 8)
  const lineasUnidad = doc.splitTextToSize('Unidad de Gestión de Recursos Educativos', anchoColumnaDerecha)
  doc.text(lineasUnidad, xLinea + 8, 21)

  doc.setDrawColor(180, 180, 180)
  doc.setLineWidth(0.2)
  doc.line(margen, 40, anchoPagina - margen, 40)

  doc.setFont('helvetica', 'bold')
  doc.setFontSize(14)
  doc.setTextColor(0, 0, 0)
  doc.text('CONSTANCIA', anchoPagina / 2, 58, { align: 'center' })

  const nombreCompleto = `${usuario.nombre} ${usuario.apellido}`.toUpperCase()
  const detalleLabel = usuario.carrera ? 'del programa de ' : 'de tipo '
  const detalleValor = usuario.carrera ?? usuario.tipo

  const anchoParrafo = anchoPagina - margen * 2
  doc.setFontSize(11)
  const yFinParrafo = dibujarParrafoConEstilos(
    doc,
    [
      { texto: `${configuracion.jefe_unidad_nombre}, ${configuracion.jefe_unidad_cargo}, deja constancia que Don(a) ` },
      { texto: nombreCompleto, negrita: true },
      { texto: ', RUT ' },
      { texto: usuario.rut, negrita: true },
      { texto: `, ${detalleLabel}` },
      { texto: detalleValor, negrita: true },
      { texto: ', no registra deuda pendiente con la Biblioteca.' },
    ],
    margen,
    78,
    anchoParrafo,
  )

  const notaFinal = 'Se extiende el presente documento a solicitud del interesado, para los fines que estime pertinentes.'
  doc.setFont('helvetica', 'normal')
  const lineasNota = doc.splitTextToSize(notaFinal, anchoParrafo)
  doc.text(lineasNota, margen, yFinParrafo + 12)

  const fecha = new Date().toLocaleDateString('es-CL', { day: 'numeric', month: 'long', year: 'numeric' })
  const segmentosFecha: Segmento[] = [{ texto: 'Punta Arenas, ' }, { texto: fecha, negrita: true }, { texto: '.' }]
  const xFecha = anchoPagina - margen - medirAnchoSegmentos(doc, segmentosFecha)
  dibujarParrafoConEstilos(doc, segmentosFecha, xFecha, 150, anchoParrafo)

  // Sello institucional, centrado justo encima de la línea de firma — el alto se
  // deriva de las proporciones reales de la imagen (getImageProperties), no de un
  // valor fijo, para no depender de las dimensiones exactas del PNG.
  const yLineaFirma = 190
  const selloBase64 = await cargarImagenComoBase64(selloBiblioteca)
  const propiedadesSello = doc.getImageProperties(selloBase64)
  const selloAncho = 26
  const selloAlto = selloAncho * (propiedadesSello.height / propiedadesSello.width)
  doc.addImage(selloBase64, 'PNG', anchoPagina / 2 - selloAncho / 2, yLineaFirma - selloAlto - 4, selloAncho, selloAlto)

  doc.line(margen + 40, yLineaFirma, anchoPagina - margen - 40, yLineaFirma)
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(10)
  doc.text(configuracion.jefe_unidad_nombre, anchoPagina / 2, 196, { align: 'center' })
  doc.setFont('helvetica', 'normal')
  doc.text(configuracion.jefe_unidad_cargo, anchoPagina / 2, 201, { align: 'center' })

  const nombreArchivo = `constancia-no-multa-${usuario.rut.replace(/\./g, '')}.pdf`
  doc.save(nombreArchivo)
}
