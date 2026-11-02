/**
 * Plano de las salas de estudio, como las tarjetas del mesón: cada piso con sus salas a los
 * dos lados del pasillo y el lado de la ventana. Cada lugar se enlaza con la sala del sistema
 * por su código (salas.codigo). El que todavía no tiene sala en el sistema se dibuja igual, en
 * gris: está en la biblioteca pero no se reserva en línea. Si se crea con ese código, se
 * puede reservar desde el mapa sin tocar nada más.
 */
export type LugarMapa = {
  codigo: string
  /** Lo que se lee en el plano: el número de la logia o el nombre de la sala. */
  etiqueta: string
  /** No se presta a estudiantes (los seminarios, Cooperación): en gris aunque exista. */
  soloAdministracion?: boolean
}

export type LadoMapa = {
  /** Ventana o pared, como en la tarjeta. */
  borde: 'ventana' | 'pared'
  /** De arriba hacia abajo, en tramos de pared: entre un tramo y otro queda un espacio. */
  tramos: LugarMapa[][]
}

export type PisoMapa = {
  nombre: string
  izquierda: LadoMapa
  derecha: LadoMapa
}

const logia = (numero: number): LugarMapa => ({ codigo: `L${String(numero).padStart(2, '0')}`, etiqueta: String(numero) })

export const PISOS_MAPA: PisoMapa[] = [
  {
    nombre: '1er piso',
    izquierda: {
      borde: 'pared',
      tramos: [
        [logia(10), logia(11), logia(12), logia(13), { codigo: 'AGA', etiqueta: 'AGACI' }, { codigo: 'MOR', etiqueta: 'Moraga' }],
        [{ codigo: 'SEM', etiqueta: 'Seminario 1', soloAdministracion: true }],
      ],
    },
    derecha: {
      borde: 'ventana',
      tramos: [[logia(9), { codigo: 'POS', etiqueta: 'Postgrado' }, ...[1, 2, 3, 4, 5, 6, 7, 8].map(logia)]],
    },
  },
  {
    nombre: '2do piso',
    izquierda: {
      borde: 'ventana',
      tramos: [[...[26, 25, 24, 23, 22].map(logia), { codigo: 'COO', etiqueta: 'Cooperación', soloAdministracion: true }]],
    },
    derecha: {
      borde: 'pared',
      tramos: [[...[27, 28, 29, 30].map(logia), { codigo: 'SEM2', etiqueta: 'Seminario 2', soloAdministracion: true }]],
    },
  },
]

/** Los códigos que tienen lugar en el plano: las demás salas van aparte, bajo el mapa. */
export const CODIGOS_EN_EL_MAPA = new Set(
  PISOS_MAPA.flatMap((piso) => [piso.izquierda, piso.derecha]).flatMap((lado) => lado.tramos.flat()).map((lugar) => lugar.codigo),
)
