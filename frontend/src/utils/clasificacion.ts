export type PartesClasificacion = { clase: string; cutter: string; anio: string; complemento: string }

export function separarClasificacion(texto: string): PartesClasificacion | null {
  const partes = texto.trim().split(/\s+/).filter(Boolean)
  if (!partes.length) return { clase: '', cutter: '', anio: '', complemento: '' }
  const clase = partes.shift()!
  // Los formatos locales no reconocidos se conservan en el editor de texto completo.
  if (!/^(?:\d\S*|T|P|I|TE|D|JE\d+)$/i.test(clase)) return null
  const cutter = /^\p{L}+\d[\p{L}\p{N}.,/-]*$/u.test(partes[0] ?? '') ? partes.shift()! : ''
  const anio = /^\d{4}$/.test(partes[0] ?? '') ? partes.shift()! : ''
  return { clase, cutter, anio, complemento: partes.join(' ') }
}

export function unirClasificacion(partes: PartesClasificacion): string {
  return [partes.clase, partes.cutter, partes.anio, partes.complemento].map((p) => p.trim()).filter(Boolean).join(' ')
}
