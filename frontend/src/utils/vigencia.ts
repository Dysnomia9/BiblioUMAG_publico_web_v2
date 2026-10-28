import { ref } from 'vue'
import api from '@/services/api'
import type { PoliticaVigencia } from '@/types'

/**
 * Vigencia de las cuentas. Cuánto dura la cuenta de cada tipo al renovarla lo define el
 * Administrador/a en Administración › Vigencia de cuentas; acá solo se lee, para anunciar
 * la fecha antes de renovar. La que vale es la que calcula y guarda el servidor.
 */
const politicas = ref<PoliticaVigencia[]>([])
let cargando: Promise<void> | null = null

/** Las políticas por tipo; se piden una vez y se reusan (se vuelven a pedir con `recargar`). */
export function useVigencia() {
  async function cargar(recargar = false) {
    if (recargar) cargando = null
    cargando ??= api
      .get<PoliticaVigencia[]>('/vigencia')
      .then(({ data }) => {
        politicas.value = data
      })
      .catch(() => {
        cargando = null
      })
    await cargando
  }

  /** Fecha (AAAA-MM-DD) que recibiría hoy una cuenta de este tipo al renovarse; null si ese tipo no vence. */
  function proximoPara(tipo: string | null | undefined): string | null {
    return politicas.value.find((p) => p.tipo === (tipo ?? 'estudiante'))?.proximo ?? null
  }

  /** Frase para anunciar el resultado: «hasta el 31/03/2027» o «sin vencimiento». */
  function hastaPara(tipo: string | null | undefined): string {
    const fecha = proximoPara(tipo)
    return fecha ? `hasta el ${fechaVigencia(fecha)}` : 'sin vencimiento'
  }

  return { politicas, cargar, proximoPara, hastaPara }
}

/** «31/03/2027» a partir de una fecha ISO («2027-03-31») o de un Date. */
export function fechaVigencia(valor: string | Date | null | undefined): string {
  if (!valor) return ''
  const fecha = typeof valor === 'string' ? new Date(`${valor.slice(0, 10)}T12:00:00`) : valor
  return fecha.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

/** Por qué una cuenta no sirve, en una frase corta para etiquetas. */
export function textoInactividad(u: { inactividad?: 'vencida' | 'desactivada' | null; vigente_hasta?: string | null }): string {
  return u.inactividad === 'vencida' ? `Venció el ${fechaVigencia(u.vigente_hasta)}` : 'Desactivada'
}
