import type { ReservaListado, SituacionReserva } from '@/types'

/** Cómo se muestra cada situación de una reserva de sala en el Listado de reservas. */
export const SITUACIONES: Record<SituacionReserva, { texto: string; clase: string }> = {
  pendiente: { texto: 'Pendiente de llegada', clase: 'bg-amber-50 text-amber-800' },
  en_uso: { texto: 'En uso', clase: 'bg-indigo-50 text-indigo-700' },
  sin_devolver: { texto: 'Llave sin devolver', clase: 'bg-red-50 text-red-700' },
  finalizada: { texto: 'Finalizada', clase: 'bg-emerald-50 text-emerald-700' },
  no_show: { texto: 'No se presentaron', clase: 'bg-orange-50 text-orange-700' },
  cancelada: { texto: 'Cancelada', clase: 'bg-gray-100 text-gray-600' },
}

/** «vie 14/08/2026» (la fecha viene como «2026-08-14», sin hora: se lee como día local). */
export function fechaReserva(fecha: string) {
  const dia = new Date(`${fecha}T12:00:00`)
  return `${dia.toLocaleDateString('es-CL', { weekday: 'short' }).replace('.', '')} ${dia.toLocaleDateString('es-CL')}`
}

export function fechaLargaReserva(fecha: string) {
  return new Date(`${fecha}T12:00:00`).toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
}

export function duracion(minutos: number) {
  const h = Math.floor(minutos / 60)
  const m = minutos % 60
  if (h === 0) return `${m} min`
  return m === 0 ? `${h} h` : `${h} h ${m} min`
}

/** «14:02» de una marca de tiempo completa. */
export const horaDe = (iso: string) => new Date(iso).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })

/** Se puede cambiar mientras sigue activa (pendiente de llegada, en uso o con la llave sin devolver). */
export const reservaEditable = (r: ReservaListado) => r.estado === 'activa' && !r.hora_devolucion_real
