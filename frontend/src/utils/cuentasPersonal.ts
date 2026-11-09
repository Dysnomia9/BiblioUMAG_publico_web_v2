/** Contraseña inicial al azar, sin los caracteres que se confunden al leerla (0 y O, 1, l e I). */
export function generarClave() {
  const alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'
  const valores = crypto.getRandomValues(new Uint32Array(10))
  return Array.from(valores, (v) => alfabeto[v % alfabeto.length]).join('')
}

/**
 * Las direcciones de un texto pegado, sin repetir y en el orden en que aparecen: sirve la
 * lista sola o el correo completo con el saludo y la firma.
 */
export function extraerCorreos(texto: string): string[] {
  const encontrados = texto.match(/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)+/gu) ?? []
  return [...new Set(encontrados.map((correo) => correo.toLowerCase()))]
}

/** «adriana.navarro@umag.cl» → «Adriana Navarro». Es una propuesta: el correo no trae tildes ni segundos nombres. */
export function nombreDesdeCorreo(correo: string): string {
  return correo
    .split('@')[0]
    .split(/[._%+-]+/)
    .map((parte) => parte.replace(/\p{N}+/gu, ''))
    .filter(Boolean)
    .map((parte) => parte.charAt(0).toUpperCase() + parte.slice(1))
    .join(' ')
}

type Credenciales = { nombre: string; email: string; password: string }

const ASUNTO = 'Tu cuenta en el panel de la Biblioteca UMAG'

/** El mensaje para entregarle a cada persona su cuenta. */
export function mensajeDeCuenta({ nombre, email, password }: Credenciales, direccion: string): string {
  return [
    `Hola, ${nombre.split(' ')[0]}:`,
    '',
    'Ya tienes cuenta en el panel del personal de la Biblioteca UMAG.',
    '',
    `Dirección: ${direccion}`,
    `Correo: ${email}`,
    `Contraseña inicial: ${password}`,
    '',
    'Al entrar, cámbiala desde el menú con tu nombre, arriba a la derecha, en «Cambiar mi contraseña».',
  ].join('\r\n')
}

/** Abre el programa de correo de quien la crea, con el mensaje listo para enviar. */
export function enlaceDeCorreo(cuenta: Credenciales, direccion: string): string {
  return `mailto:${cuenta.email}?subject=${encodeURIComponent(ASUNTO)}&body=${encodeURIComponent(mensajeDeCuenta(cuenta, direccion))}`
}
