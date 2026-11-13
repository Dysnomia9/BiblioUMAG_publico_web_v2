// El tomo y la copia como los escribe Horizon («t.72», «v.1 c.2»), igual que App\Support\TomoEjemplar.
// La letra tras el número del tomo («t.2a») no es la «c» de la copia pegada («t.1c.1»); también «t.2/c.1».
// Horizon también junta tomo y volumen, en el orden en que se escribió: «t.1-v.II», «t.2-v.I-c.1», «v.1-t.2».
const TOMO = /^(t|tomo|v|vol|volumen)\.?\s*(\d+(?:[a-z](?![.\s]*\d))?|[ivxlc]+(?![a-z]))(?:\s*[-,;/]?\s*(t|tomo|v|vol|volumen)\.?\s*(\d+|[ivxlc]+)(?![a-z]))?\s*(?:[-,;/]\s*|\s)?(?:c\.?\s*(\d+))?\.?$/i

const nombreParte = (tipo: string, numero: string) =>
  `${tipo.toLowerCase().startsWith('t') ? 'Tomo' : 'Volumen'} ${/^\d/.test(numero) ? numero : numero.toUpperCase()}`

/**
 * El tomo (y el volumen, si van juntos) leído del texto: «t.1-v.II c.2» → { tomo: «Tomo 1 ·
 * Volumen II», copia: «2», clave: «t.1-v.II» }. null si no es un tomo (el número de una revista).
 */
export function leerTomoCopia(texto?: string | null): { tomo: string; copia: string | null; clave: string } | null {
  const m = TOMO.exec((texto ?? '').trim().replace(/\s+/g, ' '))
  if (!m) return null
  // «t.1 t.2» no es tomo y volumen
  if (m[3] && m[3][0].toLowerCase() === m[1][0].toLowerCase()) return null
  const parte = (tipo: string, numero: string) => `${tipo[0].toLowerCase()}.${/^\d/.test(numero) ? numero.toLowerCase() : numero.toUpperCase()}`
  return {
    tomo: [nombreParte(m[1], m[2]), m[3] ? nombreParte(m[3], m[4]) : null].filter(Boolean).join(' · '),
    copia: m[5] ?? null,
    clave: [parte(m[1], m[2]), m[3] ? parte(m[3], m[4]) : null].filter(Boolean).join('-'),
  }
}

/**
 * El tomo y la copia por separado, para las píldoras del listado: «t.72» → tomo «Tomo 72»;
 * «v.1 c.2» → «Volumen 1» y copia «2»; «t.2-v.I-c.1» → «Tomo 2 · Volumen I» y copia «1». Lo que no es un tomo (el número de una revista,
 * «No 180 (oct. 2006)») va en `otro`. Sin «c.» no hay número de copia (null): no se inventa.
 */
export function partesCopia(volumen?: string | null, etiquetaCopia?: string | null) {
  const texto = (volumen ?? '').trim().replace(/\s+/g, ' ')
  const leido = leerTomoCopia(texto)
  return {
    tomo: leido?.tomo ?? null,
    otro: leido || !texto ? null : texto,
    copia: leido?.copia ?? etiquetaCopia?.replace(/^c\.\s*/i, '') ?? null,
  }
}

/**
 * La copia en una línea, para el portal: «t.72» → «Tomo 72», «v.1 c.2» → «Volumen 1 ·
 * Copia 2», una copia sin tomo con «c.3» → «Copia 3». null si no hay ni tomo ni copia.
 */
export function textoCopia(volumen?: string | null, etiquetaCopia?: string | null): string | null {
  const { tomo, otro, copia } = partesCopia(volumen, etiquetaCopia)
  const partes = [tomo ?? otro, copia ? `Copia ${copia}` : null].filter(Boolean)
  return partes.length ? partes.join(' · ') : null
}

/**
 * El número de copia que se ve en el lomo y en Horizon («c.2» → «2»), o null si la copia no
 * tiene. No es numero_copia: ese es interno de BiblioUMAG y no coincide con el de Horizon.
 */
export const numeroDeCopia = (e?: { etiqueta_copia?: string | null } | null) => e?.etiqueta_copia?.replace(/^c\.\s*/i, '') || null

/** «Copia 2», o null si la copia no tiene número. */
export const nombreDeCopia = (e?: { etiqueta_copia?: string | null } | null) => (numeroDeCopia(e) ? `Copia ${numeroDeCopia(e)}` : null)
