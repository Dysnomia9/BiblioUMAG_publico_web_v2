/**
 * Copia un texto al portapapeles y devuelve si se pudo.
 *
 * `navigator.clipboard` solo existe con conexión segura (HTTPS, localhost o 127.0.0.1): con la
 * aplicación abierta por http con la IP o el nombre del servidor no está, y los botones
 * «Copiar» fallaban. En ese caso (o si el navegador rechaza la escritura) se copia como antes
 * de esa API: seleccionando el texto en un campo oculto y pidiendo «copiar» al documento.
 */
export async function copiarTexto(texto: string): Promise<boolean> {
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(texto)
      return true
    } catch {
      // Sin permiso o sin foco en la página: se intenta con el campo oculto.
    }
  }
  return copiarConCampoOculto(texto)
}

function copiarConCampoOculto(texto: string): boolean {
  const campo = document.createElement('textarea')
  campo.value = texto
  campo.setAttribute('readonly', '')
  campo.setAttribute('aria-hidden', 'true')
  // En la página pero invisible: con display:none no se podría seleccionar.
  Object.assign(campo.style, { position: 'fixed', top: '0', left: '0', opacity: '0', pointerEvents: 'none' })

  const foco = document.activeElement instanceof HTMLElement ? document.activeElement : null
  document.body.appendChild(campo)
  campo.select()
  campo.setSelectionRange(0, texto.length)

  let copiado = false
  try {
    copiado = document.execCommand('copy')
  } catch {
    copiado = false
  }

  campo.remove()
  foco?.focus()
  return copiado
}
