import type { EstadoMulta, Multa, TipoMulta } from '@/types'

export const TIPOS_MULTA: Record<TipoMulta, string> = {
  atraso: 'Atraso',
  deterioro: 'Deterioro',
  perdida: 'Pérdida',
  otro: 'Otro motivo',
}

export const ESTADOS_MULTA: Record<EstadoMulta, { label: string; cls: string }> = {
  pendiente: { label: 'Pendiente', cls: 'bg-red-100 text-red-700' },
  pagada: { label: 'Pagada', cls: 'bg-emerald-100 text-emerald-700' },
  anulada: { label: 'Anulada', cls: 'bg-gray-100 text-gray-600' },
}

export const formatMonto = (monto: number) => new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP' }).format(monto)

// Una fecha sin hora («2026-10-08») se lee a mediodía: a medianoche UTC, en Chile caería el día anterior.
export const fechaMulta = (iso: string | null) =>
  iso ? new Date(/^\d{4}-\d{2}-\d{2}$/.test(iso) ? `${iso}T12:00:00` : iso).toLocaleDateString('es-CL') : '—'

/** De dónde salió la multa: la devolución atrasada, el personal o la importación desde Horizon. */
export function origenMulta(m: Multa) {
  if (m.origen === 'importada') return `Importada de Horizon${m.referencia_externa ? ` (ref. ${m.referencia_externa})` : ''}`
  if (m.origen === 'manual') return `Cursada por ${m.registrada_por?.nombre ?? 'el personal'}`
  return m.registrada_por ? `Generada al devolver · la cursó ${m.registrada_por.nombre}` : 'Generada sola al devolver con atraso'
}
