// Igual que App\Support\Materia: las subdivisiones de una materia escritas siempre igual.
const ABREVIATURAS = ['etc', 'ed', 'eds', 'ca', 'aprox', 'sr', 'sra', 'dr', 'dra', 's.a', 'ltda', 'inc', 'cía', 'cia']

/**
 * «mapuches--historia» → «mapuches -- historia»; «MAPUCHES — HISTORIA.» → «MAPUCHES -- HISTORIA».
 * Respeta las mayúsculas y no toca el guion simple («ACTIVO - PASIVO» es parte del nombre).
 */
export function normalizarMateria(nombre: string): string {
  let t = nombre.trim().replace(/\s+/g, ' ')
  t = t.replace(/\s*-{2,}\s*/g, ' -- ').replace(/\s+[—–]\s+/g, ' -- ').trim()
  t = t.replace(/^(?:--\s*)+|(?:\s*--)+$/g, '').trim()
  if (t.endsWith('.') && !t.endsWith('...')) {
    const ultima = t.slice(0, -1).replace(/^.*[\s(]/, '').toLowerCase()
    if (!ABREVIATURAS.includes(ultima) && ultima.length > 1) t = t.slice(0, -1).trimEnd()
  }
  return t
}
