/** Una sección de la nota MARC 505, sin cambiar el texto guardado. */
export type BloqueContenido = { valor: string; etiqueta: string | null; texto: string }

// «t.1:», «Tomo II.», «v. 2», «Vol. 3:», «Cap. 1.»… Solo se lee
// una designación explícita: el orden de los bloques no inventa números de tomo.
const DESIGNACION = /^((t(?:omo)?|v(?:ol(?:umen)?)?|parte|pt|p|cap(?:[íi]tulo)?)\.?\s*(\d+[a-z]?(?:\s*[-–]\s*\d+[a-z]?)?|[ivxlcdm]+))(?=$|[\s.:;)])(?:\s*[:.)]\s*|\s+|$)/i

const NOMBRES: Record<string, string> = { t: 'Tomo', v: 'Volumen', p: 'Parte', c: 'Capítulo' }

/** Admite «.--», «]--», espacios variables y un bloque por línea. */
export function separarContenido(contenido?: string | null): BloqueContenido[] {
  return (contenido ?? '').trim().replace(/^Contenido\s*:\s*/i, '')
    .split(/--+|\r\n|[\n\r]/)
    .map((valor) => valor.trim())
    .filter(Boolean)
    .map((valor) => {
      const m = DESIGNACION.exec(valor)
      return {
        valor,
        etiqueta: m ? `${NOMBRES[m[2][0].toLowerCase()]} ${/^\d/.test(m[3]) ? m[3] : m[3].toUpperCase()}` : null,
        texto: m ? valor.slice(m[0].length).trim() : valor,
      }
    })
}

/** Sigue siendo texto MARC 505: compatible con las fichas y las exportaciones existentes. */
export function unirContenido(bloques: readonly string[]): string {
  return bloques.map((valor) => valor.trim()).filter(Boolean).join(' -- ')
}
