import type { HorarioBiblioteca } from '@/types'

/** Índice ISO: 1 = lunes ... 7 = domingo. */
export const DIAS_SEMANA = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo']

export function diaIso(fecha: Date) {
  return fecha.getDay() === 0 ? 7 : fecha.getDay()
}

/** «domingo» → «domingos»; «lunes» queda igual. */
export function diaEnPlural(fecha: Date) {
  const dia = DIAS_SEMANA[diaIso(fecha)]
  return dia.endsWith('s') ? dia : `${dia}s`
}

/** «Lunes a viernes 08:45–20:30 · sábado 09:00–14:00 · domingo cerrado», agrupando días iguales. */
export function textoHorarioSemana(horario: HorarioBiblioteca) {
  const tramos: { desde: number; hasta: number; valor: string }[] = []
  for (let dia = 1; dia <= 7; dia++) {
    const h = horario[dia]
    const valor = h ? `${h[0]}–${h[1]}` : 'cerrado'
    const ultimo = tramos[tramos.length - 1]
    if (ultimo && ultimo.valor === valor && ultimo.hasta === dia - 1) ultimo.hasta = dia
    else tramos.push({ desde: dia, hasta: dia, valor })
  }
  const texto = tramos
    .map((t) => `${t.desde === t.hasta ? DIAS_SEMANA[t.desde] : `${DIAS_SEMANA[t.desde]} a ${DIAS_SEMANA[t.hasta]}`} ${t.valor}`)
    .join(' · ')
  return texto.charAt(0).toUpperCase() + texto.slice(1)
}
