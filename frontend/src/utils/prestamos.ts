import type { Prestamo } from '@/types'

const DIA = 86_400_000
const inicioDeHoy = () => new Date(new Date().toDateString())

export const fechaCorta = (iso: string) => new Date(iso).toLocaleDateString('es-CL')

/** Días que faltan para la fecha de devolución (negativo si ya pasó). */
function diasParaDevolver(fechaDevolucion: string) {
  return Math.round((new Date(new Date(fechaDevolucion).toDateString()).getTime() - inicioDeHoy().getTime()) / DIA)
}

/** Sin devolver y con la fecha ya pasada; el que vence hoy todavía no (igual que Prestamo::scopeVencidos()). */
export const estaVencido = (p: Prestamo) =>
  p.estado !== 'devuelto' && !!p.fecha_devolucion && diasParaDevolver(p.fecha_devolucion) < 0

/** Columna «Devolución»: la fecha y cuánto falta, cuándo se devolvió o, en los recursos de apoyo (sin fecha fija), si sigue pendiente. */
export function devolucionDe(p: Prestamo) {
  if (p.estado === 'devuelto') {
    return { linea1: p.fecha_devolucion_real ? fechaCorta(p.fecha_devolucion_real) : '—', linea2: 'Devuelto', clase: 'text-gray-500' }
  }
  if (p.en_sala) {
    return estaVencido(p)
      ? { linea1: fechaCorta(p.fecha_devolucion!), linea2: 'Uso en sala: no se devolvió el mismo día', clase: 'text-red-700' }
      : { linea1: 'Hoy, antes del cierre', linea2: 'Uso en sala', clase: 'text-indigo-700' }
  }
  if (!p.fecha_devolucion) {
    return new Date(p.fecha_prestamo).toDateString() === new Date().toDateString()
      ? { linea1: 'Pendiente', linea2: 'Uso en sala', clase: 'text-indigo-700' }
      : { linea1: 'Pendiente', linea2: 'No se devolvió el mismo día', clase: 'text-amber-700' }
  }
  const dias = diasParaDevolver(p.fecha_devolucion)
  if (dias < 0) return { linea1: fechaCorta(p.fecha_devolucion), linea2: `Vencido hace ${-dias} ${dias === -1 ? 'día' : 'días'}`, clase: 'text-red-700' }
  if (dias === 0) return { linea1: fechaCorta(p.fecha_devolucion), linea2: 'Vence hoy', clase: 'text-amber-700' }
  return { linea1: fechaCorta(p.fecha_devolucion), linea2: `En ${dias} ${dias === 1 ? 'día' : 'días'}`, clase: 'text-emerald-700' }
}

/** La etiqueta de estado de un préstamo. */
export function estadoDe(p: Prestamo) {
  if (p.estado === 'devuelto') return { texto: 'Devuelto', clase: 'bg-gray-100 text-gray-700' }
  if (estaVencido(p)) return { texto: 'Vencido', clase: 'bg-red-50 text-red-700' }
  if (p.en_sala) return { texto: 'En sala', clase: 'bg-indigo-50 text-indigo-700' }
  if (!p.fecha_devolucion) return { texto: 'En uso', clase: 'bg-indigo-50 text-indigo-700' }
  if (diasParaDevolver(p.fecha_devolucion) === 0) return { texto: 'Vence hoy', clase: 'bg-amber-50 text-amber-700' }
  return { texto: 'Vigente', clase: 'bg-emerald-50 text-emerald-700' }
}
