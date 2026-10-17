import { formatRut, validarRut } from '@/composables/useRut'

/**
 * Identificación de una persona: RUT o, para extranjeros, pasaporte, DNI (argentino u
 * otro país) u otro documento. El servidor compara siempre el identificador sin
 * puntos, guiones ni espacios (App\Support\Documento), así que da lo mismo cómo se
 * escriba.
 */
export type TipoDocumento = 'rut' | 'pasaporte' | 'dni' | 'otro'

export const TIPOS_DOCUMENTO: Record<TipoDocumento, string> = {
  rut: 'RUT',
  pasaporte: 'Pasaporte',
  dni: 'DNI / cédula extranjera',
  otro: 'Otro documento',
}

/** Solo letras y números, en mayúscula: así lo compara el servidor. */
export function normalizarDocumento(valor: string): string {
  return valor.replace(/[^0-9a-zA-Z]/g, '').toUpperCase()
}

/** Solo números, puntos, guion y espacios, con una K solo al final: se escribe como RUT. */
export function pareceRut(valor: string): boolean {
  return /^[\d.\-\s]*[\dkK]?$/.test(valor.trim())
}

/** Mientras se escribe: un RUT con puntos y guion; un pasaporte (con letras) en mayúsculas y sin espacios. */
export function formatearIdentificador(valor: string, tipo?: TipoDocumento): string {
  if (tipo ? tipo === 'rut' : pareceRut(valor)) return formatRut(valor)
  return normalizarDocumento(valor).slice(0, 20)
}

/**
 * Para el @input de un campo de RUT o documento: igual que formatearInputRut(), deja el
 * campo con el valor formateado aunque Vue no lo repinte.
 */
export function formatearInputIdentificador(event: Event, tipo?: TipoDocumento): string {
  const input = event.target as HTMLInputElement
  const valor = formatearIdentificador(input.value, tipo)
  input.value = valor
  return valor
}

/**
 * Para buscar a alguien ya registrado: un RUT válido o un documento extranjero
 * plausible (5 a 20 letras o números). Un RUT mal escrito pasa igual y el servidor
 * responde que no encontró a nadie, porque un DNI se escribe solo con números.
 */
export function identificadorValido(valor: string): boolean {
  if (validarRut(valor)) return true
  const n = normalizarDocumento(valor)
  return n.length >= 5 && n.length <= 20 && /\d/.test(n)
}

/** Para un documento declarado con su tipo (alta de un estudiante, entrada de un externo). Devuelve el error o ''. */
export function errorDocumento(valor: string, tipo: TipoDocumento): string {
  const n = normalizarDocumento(valor)
  if (!n) return tipo === 'rut' ? 'Ingrese el RUT.' : 'Ingrese el número de documento.'
  if (tipo === 'rut') return validarRut(valor) ? '' : 'Ese RUT no es válido: revise los números y el dígito verificador.'
  if (n.length < 5 || n.length > 20 || !/\d/.test(n)) return 'El documento debe tener entre 5 y 20 letras o números.'
  return ''
}

export function mismoDocumento(a: string | null | undefined, b: string | null | undefined): boolean {
  return !!a && !!b && normalizarDocumento(a) === normalizarDocumento(b)
}

/** «Pasaporte FT482913 · Brasil», o el RUT tal cual. */
export function describirDocumento(identificador: string | null | undefined, tipo?: TipoDocumento | null, pais?: string | null): string {
  if (!identificador) return '—'
  if (!tipo || tipo === 'rut') return identificador
  const nombre = tipo === 'dni' ? 'DNI' : TIPOS_DOCUMENTO[tipo]
  return `${nombre} ${identificador}${pais ? ` · ${pais}` : ''}`
}
