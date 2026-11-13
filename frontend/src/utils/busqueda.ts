import { sinTildes } from '@/utils/texto'

/**
 * 1 o 2 letras sin dígitos («h», «hi», «de»): los índices de trigramas no sirven con menos de 3
 * y la búsqueda de libros recorre todo el catálogo (~1,8 s con 46 mil títulos, y en desarrollo
 * el servidor atiende de a una solicitud). Mientras se escribe no se busca; con Enter, sí.
 */
export function esBusquedaCorta(texto: string): boolean {
  const t = texto.trim()
  return t.length > 0 && t.length < 3 && !/\d/.test(t)
}

// Las mismas que descarta el servidor al buscar (App\Support\Busqueda::palabras).
const VACIAS = new Set(['el', 'la', 'lo', 'los', 'las', 'de', 'del', 'y', 'e', 'o', 'u', 'en', 'a', 'al',
  'un', 'una', 'unos', 'unas', 'por', 'para', 'con', 'sin', 'sobre', 'que', 'se', 'su', 'sus'])

/** Las palabras que cuentan al buscar, sin tildes: sin artículos ni preposiciones, como el servidor. */
function palabrasBuscadas(busqueda: string): string[] {
  const palabras = sinTildes(busqueda).split(/\s+/)
    .map((p) => p.replace(/^[()[\]«»"',;:.¿?¡!]+|[()[\]«»"',;:.¿?¡!]+$/g, ''))
    .filter(Boolean)
  const utiles = palabras.filter((p) => !VACIAS.has(p) && !(p.length <= 2 && /^\p{L}+$/u.test(p)))
  return utiles.length ? utiles : palabras
}

/**
 * El título alternativo (246) por el que se encontró el libro, para mostrarlo en la lista: al
 * buscar «diccionario lengua rapa nui» sale «Diccionario de lenguas indígenas en Chile» (245),
 * y sin esto no se ve por qué. null si el título principal ya trae lo buscado, o si ningún
 * 246 lo trae (se encontró por el autor, el ISBN, un código…).
 */
export function tituloAlternativoEncontrado(libro: { titulo: string; titulo_alternativo?: string | null }, busqueda: string): string | null {
  const palabras = palabrasBuscadas(busqueda)
  if (!palabras.length || !libro.titulo_alternativo) return null
  const trae = (texto: string) => palabras.every((p) => sinTildes(texto).includes(p))
  if (trae(libro.titulo)) return null
  return libro.titulo_alternativo.split(/\r?\n/).map((t) => t.trim()).find((t) => t && trae(t)) ?? null
}
