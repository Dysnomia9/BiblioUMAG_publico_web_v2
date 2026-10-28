/** Portadas del catálogo: las iniciales del título en serif, con un color pastel por área. */

import { sinTildes } from '@/utils/texto'

const PALABRAS_MENORES = new Set(['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'a', 'en', 'para', 'por', 'con', 'un', 'una', '-'])

export function inicialesPortada(titulo: string) {
  return titulo
    .split(/\s+/)
    // Sin los signos del comienzo: «¡Hagamos títeres!» es HT, no «¡H».
    .map((p) => p.replace(/^[^\p{L}\p{N}]+/u, ''))
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

/**
 * Qué se dibuja en la portada según el tipo de material: el libro va con sus iniciales y el
 * lomo; lo demás (tesis, revistas, juegos, DVD…) con un ícono propio, para que no todo
 * parezca un libro. Los tipos se crean en Administración, así que se reconocen por el nombre
 * y, si no calza, por la ficha (plantilla) que usan.
 */
export type FormaPortada = 'libro' | 'tesis' | 'revista' | 'juego' | 'video' | 'otro'

export function formaPortada(tipo?: string | null, plantilla?: string | null): FormaPortada {
  const nombre = sinTildes(tipo ?? '')
  if (nombre.includes('juego') || plantilla === 'juego_educativo') return 'juego'
  if (nombre.includes('tesis') || nombre.includes('titulacion') || plantilla === 'trabajo_titulacion') return 'tesis'
  if (nombre.includes('revista') || nombre.includes('seriada') || nombre.includes('periodic') || plantilla === 'publicacion_seriada') return 'revista'
  if (/\b(dvd|cd|video|audiovisual|pelicula)\b/.test(nombre)) return 'video'
  if (nombre === 'otro' || nombre === 'otros') return 'otro'
  return 'libro'
}

export const ICONOS_PORTADA: Record<Exclude<FormaPortada, 'libro'>, string> = {
  tesis: 'M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 017.231-4.41 60.53 60.53 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5',
  revista: 'M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 01-2.25 2.25M16.5 7.5V18a2.25 2.25 0 002.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 002.25 2.25h13.5M6 7.5h3v3H6v-3z',
  juego: 'M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 01-.657.643 48.39 48.39 0 01-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 01-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 00-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 01-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 00.657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 01-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.4.604-.4.959v0c0 .333.277.599.61.58a48.1 48.1 0 005.427-.63 48.05 48.05 0 00.582-4.717.532.532 0 00-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.035 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.401.96.401v0a.656.656 0 00.658-.663 48.422 48.422 0 00-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 01-.61-.58v0z',
  video: 'M12 21a9 9 0 100-18 9 9 0 000 18zm0-6.75a2.25 2.25 0 100-4.5 2.25 2.25 0 000 4.5z',
  otro: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
}
