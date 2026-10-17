/** Portadas del catálogo: las iniciales del título en serif, con un color pastel por área. */

const PALABRAS_MENORES = new Set(['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'a', 'en', 'para', 'por', 'con', 'un', 'una', '-'])

export function inicialesPortada(titulo: string) {
  return titulo
    .split(/\s+/)
    .filter((p) => p && !PALABRAS_MENORES.has(p.toLowerCase()))
    .slice(0, 2)
    .map((p) => p.charAt(0).toUpperCase())
    .join('')
}

const TONOS_PORTADA = [
  'bg-rose-50 text-rose-800 border-rose-300',
  'bg-indigo-50 text-indigo-800 border-indigo-300',
  'bg-emerald-50 text-emerald-800 border-emerald-300',
  'bg-amber-50 text-amber-800 border-amber-300',
  'bg-sky-50 text-sky-800 border-sky-300',
  'bg-violet-50 text-violet-800 border-violet-300',
  'bg-teal-50 text-teal-800 border-teal-300',
  'bg-slate-100 text-slate-700 border-slate-300',
]

/** El color sale del área (sin área, del tipo de material o del título): los títulos de una misma área se reconocen de un vistazo. */
export function tonoPortada(clave: string) {
  let hash = 0
  for (const letra of clave) hash = (hash * 31 + letra.charCodeAt(0)) >>> 0
  return TONOS_PORTADA[hash % TONOS_PORTADA.length]
}
