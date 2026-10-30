import JsBarcode from 'jsbarcode'

/**
 * Etiqueta de lomo: la que se pega por fuera, donde se afirman las hojas. Es
 * horizontal; a la izquierda va la clasificación apilada (998 / E56 / 2026) y a la
 * derecha el código de lomo de la copia (8 dígitos), parado.
 */

export type EtiquetaLomo = {
  id: number
  titulo: string | null
  codigo_barras: string
  codigo_lomo: string
  numero_copia: number
  volumen: string | null
  clasificacion: string | null
}

export type OpcionesLomo = {
  anchoMm: number
  altoMm: number
  /** Agrega «v.2» y «c.2» debajo de la clasificación cuando corresponde. */
  conCopia: boolean
  /** El código se lee de abajo hacia arriba (lo habitual en los lomos) o de arriba hacia abajo. */
  lectura: 'abajo-arriba' | 'arriba-abajo'
  bordeDeCorte: boolean
}

export const OPCIONES_LOMO: OpcionesLomo = { anchoMm: 50, altoMm: 25, conCopia: true, lectura: 'abajo-arriba', bordeDeCorte: true }

/** «998 E56 2026» → ['998', 'E56', '2026'], más «v.2» y «c.2» si se pide. */
export function lineasDeLomo(e: Pick<EtiquetaLomo, 'clasificacion' | 'numero_copia' | 'volumen'>, conCopia: boolean): string[] {
  const lineas = (e.clasificacion ?? '').trim().split(/\s+/).filter(Boolean)
  const volumen = e.volumen?.trim() ?? ''
  if (conCopia && volumen) lineas.push(...lineasDeVolumen(volumen))
  // «v.2-c.1» (como venía de Horizon) ya dice la copia
  if (conCopia && e.numero_copia > 1 && !/(^|[\s-])c\.?\s*\d/i.test(volumen)) lineas.push(`c.${e.numero_copia}`)
  return lineas
}

/**
 * El tomo o el número de la revista: «4» → «v.4»; «v.2» o «t.1-c.3», tal cual; uno largo
 * («Año IV No. 4 (Jul.-Aug., 1985)») en renglones cortos y sin paréntesis («Año IV»,
 * «No. 4», «Jul.-Aug.», «1985»), para que la letra no se achique en todo el lomo.
 */
function lineasDeVolumen(volumen: string): string[] {
  if (/^\d+$/.test(volumen)) return [`v.${volumen}`]
  const lineas: string[] = []
  for (const palabra of volumen.replace(/[()]/g, ' ').split(/\s+/).map((p) => p.replace(/,$/, '')).filter(Boolean)) {
    const ultima = lineas.length - 1
    if (ultima >= 0 && `${lineas[ultima]} ${palabra}`.length <= 8) lineas[ultima] += ` ${palabra}`
    else lineas.push(palabra)
  }
  return lineas
}

/** El código de barras dibujado de lado y vuelto a dibujar parado (girado 90°), como imagen. */
export function codigoParado(codigo: string, lectura: OpcionesLomo['lectura']): string {
  const horizontal = document.createElement('canvas')
  JsBarcode(horizontal, codigo, {
    // Más píxeles de los necesarios: al achicarla al alto de la etiqueta queda nítida.
    format: 'CODE128',
    width: 3,
    height: 90,
    displayValue: true,
    fontSize: 26,
    fontOptions: 'bold',
    textMargin: 2,
    margin: 4,
  })

  const parado = document.createElement('canvas')
  parado.width = horizontal.height
  parado.height = horizontal.width
  const ctx = parado.getContext('2d')!
  if (lectura === 'abajo-arriba') {
    ctx.translate(0, parado.height)
    ctx.rotate(-Math.PI / 2)
  } else {
    ctx.translate(parado.width, 0)
    ctx.rotate(Math.PI / 2)
  }
  ctx.drawImage(horizontal, 0, 0)
  return parado.toDataURL('image/png')
}

function escapar(texto: string): string {
  return texto.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

/** Letra lo más grande posible sin que la línea más larga ni el total de líneas se salgan de la columna. */
function tamanoLetraMm(lineas: string[], columnaAnchoMm: number, altoMm: number): number {
  const masLarga = Math.max(1, ...lineas.map((l) => l.length))
  const porAncho = (columnaAnchoMm * 0.9) / (masLarga * 0.62)
  const porAlto = ((altoMm - 2) / Math.max(1, lineas.length)) * 0.82
  return Math.max(1.6, Math.min(4.6, porAncho, porAlto))
}

/** Abre una pestaña con las etiquetas listas para imprimir (no escribe nada en el sistema). */
export function abrirImpresionLomo(etiquetas: EtiquetaLomo[], opciones: OpcionesLomo): boolean {
  const ventana = window.open('', '_blank')
  if (!ventana) return false
  ventana.document.write(paginaEtiquetasLomo(etiquetas, opciones))
  ventana.document.close()
  // El botón se conecta desde acá: la política de seguridad del sitio no deja correr
  // código escrito dentro de la página (un onclick="...").
  ventana.document.querySelector('.toolbar button')?.addEventListener('click', () => ventana.print())
  return true
}

/** La página completa de impresión: una etiqueta por copia, con sus medidas en milímetros. */
export function paginaEtiquetasLomo(etiquetas: EtiquetaLomo[], opciones: OpcionesLomo): string {
  const { anchoMm, altoMm } = opciones
  const columnaMm = anchoMm * 0.45

  const html = etiquetas
    .map((e) => {
      const lineas = lineasDeLomo(e, opciones.conCopia)
      const letra = tamanoLetraMm(lineas, columnaMm, altoMm)
      let barra: string
      try {
        barra = `<img src="${codigoParado(e.codigo_lomo, opciones.lectura)}" alt="${escapar(e.codigo_lomo)}" />`
      } catch {
        barra = `<span class="error">Código inválido: ${escapar(e.codigo_lomo)}</span>`
      }
      return `<div class="lomo">
        <div class="clasificacion" style="font-size:${letra.toFixed(2)}mm">${lineas.map((l) => `<span>${escapar(l)}</span>`).join('')}</div>
        <div class="codigo">${barra}</div>
      </div>`
    })
    .join('')

  return `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<title>Etiquetas de lomo — Biblioteca UMAG</title>
<style>
  * { box-sizing: border-box; }
  @page { margin: 8mm; }
  body { font-family: system-ui, -apple-system, sans-serif; margin: 0; padding: 24px; background: #f8fafc; }
  .toolbar { margin-bottom: 20px; display: flex; gap: 12px; align-items: center; font-size: 13px; color: #475569; }
  .toolbar button { padding: 10px 20px; border-radius: 8px; border: none; background: #4338ca; color: white; font-weight: 600; cursor: pointer; font-size: 14px; }
  .toolbar button:hover { background: #3730a3; }
  .hoja { display: flex; flex-wrap: wrap; gap: 3mm; }
  .lomo {
    width: ${anchoMm}mm; height: ${altoMm}mm; background: white; display: flex; align-items: stretch;
    padding: 1mm; overflow: hidden; break-inside: avoid; page-break-inside: avoid;
    ${opciones.bordeDeCorte ? 'outline: 0.2mm dashed #94a3b8;' : ''}
  }
  .clasificacion {
    flex: 0 0 ${columnaMm.toFixed(2)}mm; display: flex; flex-direction: column; align-items: center; justify-content: center;
    font-family: Arial, "Helvetica Neue", sans-serif; font-weight: 700; line-height: 1.12; color: #000;
    border-right: 0.3mm solid #000; padding-right: 1mm; white-space: nowrap;
  }
  .codigo { flex: 1; display: flex; align-items: center; justify-content: center; padding-left: 1mm; }
  .codigo img { height: ${(altoMm - 2).toFixed(2)}mm; max-width: 100%; object-fit: contain; image-rendering: pixelated; }
  .error { color: #b91c1c; font-size: 2.5mm; }
  @media print {
    .toolbar { display: none; }
    body { background: white; padding: 0; }
  }
</style>
</head>
<body>
  <div class="toolbar"><button type="button">Imprimir</button><span>${etiquetas.length} ${etiquetas.length === 1 ? 'etiqueta' : 'etiquetas'} de ${anchoMm} × ${altoMm} mm. Imprime al 100 % (sin «ajustar a la página»).</span></div>
  <div class="hoja">${html}</div>
</body>
</html>`
}
