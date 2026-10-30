const DIA_MES_ANIO = { day: '2-digit', month: '2-digit', year: 'numeric' } as const

/**
 * Cuándo entró un título o una copia: a Horizon (fecha sin hora, «1997-04-08», que se lee a
 * mediodía para que en Chile no caiga el día anterior) y a BiblioUMAG (created_at, con hora).
 * Vacío lo que no tiene: lo catalogado en BiblioUMAG nunca estuvo en Horizon.
 */
export function fechasDeIngreso(x: { fecha_ingreso_horizon?: string | null; created_at?: string }) {
  return {
    horizon: x.fecha_ingreso_horizon ? new Date(`${x.fecha_ingreso_horizon.slice(0, 10)}T12:00:00`).toLocaleDateString('es-CL', DIA_MES_ANIO) : '',
    biblioumag: x.created_at ? new Date(x.created_at).toLocaleDateString('es-CL', DIA_MES_ANIO) : '',
  }
}

/** «Ingreso: Horizon 08-04-1997 · BiblioUMAG 29-10-2026», o solo la de BiblioUMAG. */
export function textoIngreso(x: { fecha_ingreso_horizon?: string | null; created_at?: string }) {
  const { horizon, biblioumag } = fechasDeIngreso(x)
  const partes = [horizon && `Horizon ${horizon}`, biblioumag && `BiblioUMAG ${biblioumag}`].filter(Boolean)
  return partes.length ? `Ingreso: ${partes.join(' · ')}` : ''
}
