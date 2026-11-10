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

/**
 * La biblioteca está en Punta Arenas y las reservas valen con la hora de allá, como el
 * servidor (APP_TIMEZONE=America/Punta_Arenas), no con la del reloj del computador: un
 * equipo con la zona de Santiago va una hora atrasado en invierno (Santiago cambia de hora y
 * Punta Arenas no).
 */
export const ZONA_BIBLIOTECA = 'America/Punta_Arenas'

const formatoPuntaArenas = (() => {
  const opciones: Intl.DateTimeFormatOptions = {
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  }
  try {
    return new Intl.DateTimeFormat('en-CA', { ...opciones, timeZone: ZONA_BIBLIOTECA })
  } catch {
    // Un navegador sin esa zona: la del computador, como antes.
    return new Intl.DateTimeFormat('en-CA', opciones)
  }
})()

/** `momento` en Punta Arenas: { fecha: '2026-11-10', minutos: 987 (desde la medianoche), hora: '16:27', conSegundos: '16:27:45' }. */
export function enPuntaArenas(momento: Date = new Date()) {
  const p = Object.fromEntries(formatoPuntaArenas.formatToParts(momento).map((parte) => [parte.type, parte.value]))
  const hora = `${p.hour}:${p.minute}`
  return { fecha: `${p.year}-${p.month}-${p.day}`, minutos: Number(p.hour) * 60 + Number(p.minute), hora, conSegundos: `${hora}:${p.second}` }
}
