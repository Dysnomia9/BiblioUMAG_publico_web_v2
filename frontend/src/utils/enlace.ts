/**
 * Los enlaces del catálogo (856) que van a un href: solo direcciones web, http o https.
 * El backend ya los valida al guardar y al responder; esto es la segunda barrera, por si
 * llega un dato viejo o una respuesta de otra versión: un «javascript:…» o «data:…» en un
 * href ejecutaría código en la sesión de quien hace clic. Lo que no es web queda sin enlace.
 */
export function enlaceWeb(url: string | null | undefined): string | undefined {
  if (!url) return undefined
  try {
    const { protocol, hostname } = new URL(url.trim())
    return (protocol === 'http:' || protocol === 'https:') && hostname ? url.trim() : undefined
  } catch {
    return undefined
  }
}
