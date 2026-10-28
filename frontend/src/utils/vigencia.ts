/**
 * Vigencia de las cuentas. Es la misma regla de config/usuarios.php del backend: las
 * cuentas vencen el 31 de marzo del año calendario siguiente a la renovación. Acá solo
 * sirve para anunciar la fecha antes de renovar; la que vale es la que guarda el servidor.
 */
export function proximoVencimiento(hoy: Date = new Date()): Date {
  return new Date(hoy.getFullYear() + 1, 2, 31)
}

/** «31/03/2027» a partir de una fecha ISO («2027-03-31») o de un Date. */
export function fechaVigencia(valor: string | Date | null | undefined): string {
  if (!valor) return ''
  const fecha = typeof valor === 'string' ? new Date(`${valor.slice(0, 10)}T12:00:00`) : valor
  return fecha.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

/** Por qué una cuenta no sirve, en una frase corta para etiquetas. */
export function textoInactividad(u: { inactividad?: 'vencida' | 'desactivada' | null; vigente_hasta?: string | null }): string {
  return u.inactividad === 'vencida' ? `Venció el ${fechaVigencia(u.vigente_hasta)}` : 'Desactivada'
}
