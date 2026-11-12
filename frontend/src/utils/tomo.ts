// El tomo y la copia como los escribe Horizon («t.72», «v.1 c.2»), igual que App\Support\TomoEjemplar.
const TOMO = /^(t|tomo|v|vol|volumen)\.?\s*(\d+[a-z]?)\s*(?:[-,;]\s*|\s)?(?:c\.?\s*(\d+))?\.?$/i

/**
 * El tomo y la copia por separado, para las píldoras del listado: «t.72» → tomo «Tomo 72»;
 * «v.1 c.2» → «Volumen 1» y copia «2». Lo que no es un tomo (el número de una revista,
 * «No 180 (oct. 2006)») va en `otro`. Sin «c.» no hay número de copia (null): no se inventa.
 */
export function partesCopia(volumen?: string | null, etiquetaCopia?: string | null) {
  const texto = (volumen ?? '').trim().replace(/\s+/g, ' ')
  const m = TOMO.exec(texto)
  return {
    tomo: m ? `${m[1].toLowerCase().startsWith('t') ? 'Tomo' : 'Volumen'} ${m[2]}` : null,
    otro: m || !texto ? null : texto,
    copia: m?.[3] ?? etiquetaCopia?.replace(/^c\.\s*/i, '') ?? null,
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
