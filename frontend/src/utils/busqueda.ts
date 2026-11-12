/**
 * 1 o 2 letras sin dígitos («h», «hi», «de»): los índices de trigramas no sirven con menos de 3
 * y la búsqueda de libros recorre todo el catálogo (~1,8 s con 46 mil títulos, y en desarrollo
 * el servidor atiende de a una solicitud). Mientras se escribe no se busca; con Enter, sí.
 */
export function esBusquedaCorta(texto: string): boolean {
  const t = texto.trim()
  return t.length > 0 && t.length < 3 && !/\d/.test(t)
}
