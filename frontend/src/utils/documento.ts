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
 * Lo que se marca en el selector de un campo de identificación cuando no se pide el tipo
 * exacto: RUT chileno, o documento extranjero (pasaporte, DNI, cédula). Con RUT se exige
 * el dígito verificador; con documento, letras y números sin formatear.
 */
export type ModoDocumento = 'rut' | 'extranjero'

/** El modo que corresponde a un tipo de documento ya conocido (el de la propia cuenta, por ejemplo). */
export const modoDe = (tipo?: TipoDocumento | null): ModoDocumento => (!tipo || tipo === 'rut' ? 'rut' : 'extranjero')

/** Para una persona ya conocida solo por su identificador (un enlace con ?rut=): RUT si lo es, si no, documento. */
export const modoSegunValor = (valor: string): ModoDocumento => (pareceRut(valor) && validarRut(valor) ? 'rut' : 'extranjero')

export function formatearSegunModo(valor: string, modo: ModoDocumento): string {
  return formatearIdentificador(valor, modo === 'rut' ? 'rut' : 'pasaporte')
}

/** Al cambiar de modo con algo ya escrito: un documento con letras no se puede pasar a RUT, así que se borra. */
export function convertirAlModo(valor: string, modo: ModoDocumento): string {
  if (modo === 'rut' && !pareceRut(valor)) return ''
  return formatearSegunModo(valor, modo)
}

/**
 * Para el @input. Con RUT marcado, escribir una letra (que no sea la K del final) pasa
 * solo a pasaporte/DNI y la conserva, en vez de borrarla sin aviso.
 */
export function escribirIdentificador(event: Event, modo: ModoDocumento): { valor: string; modo: ModoDocumento } {
  const input = event.target as HTMLInputElement
  const nuevoModo: ModoDocumento = modo === 'rut' && !pareceRut(input.value) ? 'extranjero' : modo
  const valor = formatearSegunModo(input.value, nuevoModo)
  input.value = valor
  return { valor, modo: nuevoModo }
}

/** El error de lo escrito según el modo marcado, o '' si está bien. `tuteo`: con «tú», como habla el portal. */
export function errorSegunModo(valor: string, modo: ModoDocumento, tuteo = false): string {
  const n = normalizarDocumento(valor)
  if (!n) return `${tuteo ? 'Escribe tu' : 'Ingrese el'} ${modo === 'rut' ? 'RUT' : 'número de pasaporte o DNI'}.`
  if (modo === 'rut') {
    if (validarRut(valor)) return ''
    return tuteo
      ? 'Ese RUT no es válido: revisa el dígito verificador. Si tienes pasaporte o DNI extranjero, marca «Pasaporte / DNI».'
      : 'Ese RUT no es válido: revise el dígito verificador. Si es un pasaporte o DNI extranjero, marque «Pasaporte / DNI».'
  }
  if (n.length < 5 || n.length > 20 || !/\d/.test(n)) return 'El pasaporte o DNI debe tener entre 5 y 20 letras o números.'
  return ''
}

export const placeholderSegunModo = (modo: ModoDocumento) => (modo === 'rut' ? '12.345.678-5' : 'N.º de pasaporte o DNI')
export const largoSegunModo = (modo: ModoDocumento) => (modo === 'rut' ? 12 : 20)

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
