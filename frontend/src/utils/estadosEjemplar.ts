import type { Ejemplar, EstadoProcesoEjemplar } from '@/types'

/** Estados de proceso de una copia: nombre, para qué sirve y sus colores (punto y etiqueta). */
export const ESTADOS_EJEMPLAR: Record<EstadoProcesoEjemplar, { label: string; descripcion: string; punto: string; badge: string }> = {
  inventario: { label: 'Inventario', descripcion: 'Recién ingresado, aún sin procesar', punto: 'bg-gray-500', badge: 'bg-gray-100 text-gray-700' },
  procesos_tecnicos: { label: 'Procesos técnicos', descripcion: 'Plastificado o etiquetado', punto: 'bg-violet-500', badge: 'bg-violet-100 text-violet-700' },
  por_colocar: { label: 'Por colocar', descripcion: 'Listo, falta ubicarlo en estante', punto: 'bg-amber-500', badge: 'bg-amber-100 text-amber-800' },
  en_estante: { label: 'En estante', descripcion: 'Disponible para préstamo', punto: 'bg-emerald-600', badge: 'bg-emerald-100 text-emerald-700' },
  estanteria_auxiliar: { label: 'Estantería auxiliar', descripcion: 'Ubicación secundaria', punto: 'bg-teal-600', badge: 'bg-teal-100 text-teal-700' },
  coleccion_movil: { label: 'Colección móvil', descripcion: 'Préstamo itinerante fuera de la biblioteca', punto: 'bg-blue-700', badge: 'bg-blue-100 text-blue-800' },
  personalizado: { label: 'Personalizado', descripcion: 'Estado definido en Administración', punto: 'bg-pink-600', badge: 'bg-pink-100 text-pink-700' },
  de_baja: { label: 'De baja', descripcion: 'Retirada de la colección', punto: 'bg-red-600', badge: 'bg-red-100 text-red-700' },
}

/** El camino normal de una copia nueva hasta quedar disponible. */
export const FLUJO_PROCESO: EstadoProcesoEjemplar[] = ['inventario', 'procesos_tecnicos', 'por_colocar', 'en_estante']

export function nombreEstado(e: Pick<Ejemplar, 'estado_proceso' | 'estado_personalizado'>) {
  if (e.estado_proceso === 'personalizado') return e.estado_personalizado?.nombre ?? 'Personalizado'
  return ESTADOS_EJEMPLAR[e.estado_proceso]?.label ?? e.estado_proceso
}

/** Situación de una copia para el préstamo, igual que en el portal: solo «En estante» y libre cuenta como disponible. */
export function situacionCopia(e: Ejemplar) {
  if (e.prestado) return { texto: 'Prestado', badge: 'bg-amber-100 text-amber-800', barra: 'bg-amber-500', disponible: false }
  if (e.reservado) return { texto: 'Reservado', badge: 'bg-violet-100 text-violet-700', barra: 'bg-violet-500', disponible: false }
  if (e.estado_proceso === 'en_estante' && e.disponible) {
    return { texto: 'Disponible', badge: 'bg-emerald-100 text-emerald-700', barra: 'bg-emerald-600', disponible: true }
  }
  return { texto: nombreEstado(e), badge: ESTADOS_EJEMPLAR[e.estado_proceso]?.badge ?? 'bg-gray-100 text-gray-700', barra: 'bg-gray-300', disponible: false }
}
