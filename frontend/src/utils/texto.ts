/** Para comparar textos sin distinguir tildes, ñ ni mayúsculas: «Cálculo» → «calculo», «Muñoz» → «munoz». */
export function sinTildes(texto: string) {
  return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}
