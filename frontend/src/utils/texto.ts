/** Para comparar textos sin distinguir tildes, ñ ni mayúsculas: «Cálculo» → «calculo», «Muñoz» → «munoz». */
export function sinTildes(texto: string) {
  return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

/**
 * Cada palabra buscada aparece en el texto, en cualquier orden y sin importar tildes:
 * «ignacio contreras norambuena» coincide con «Ignacio Alexis Andrés Contreras Norambuena».
 */
export function contieneCadaPalabra(texto: string, busqueda: string) {
  const enTexto = sinTildes(texto)
  return sinTildes(busqueda).split(/\s+/).filter(Boolean).every((palabra) => enTexto.includes(palabra))
}
