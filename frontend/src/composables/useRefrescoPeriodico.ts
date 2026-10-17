import { onMounted, onUnmounted } from 'vue'

/**
 * Vuelve a llamar `cargar` cada `cadaMs` mientras la pestaña está a la vista, y de
 * inmediato al volver a ella, para que lo hecho desde otro equipo (por ejemplo, una
 * reserva desde el portal) aparezca sin recargar la página. Con la pestaña oculta
 * no consulta al servidor.
 */
export function useRefrescoPeriodico(cargar: () => unknown, cadaMs: number) {
  let timer: ReturnType<typeof setInterval> | undefined
  let ultimaCarga = 0
  let enCurso = false

  async function refrescar() {
    // Con la red lenta, no se encima otra consulta mientras la anterior no vuelve.
    if (enCurso) return
    enCurso = true
    ultimaCarga = Date.now()
    try {
      await cargar()
    } finally {
      enCurso = false
    }
  }

  function detener() {
    clearInterval(timer)
    timer = undefined
  }

  function programar() {
    detener()
    timer = setInterval(refrescar, cadaMs)
  }

  function alVolver() {
    if (document.visibilityState !== 'visible') {
      detener()
      return
    }
    if (Date.now() - ultimaCarga > 5000) refrescar()
    programar()
  }

  onMounted(() => {
    ultimaCarga = Date.now()
    programar()
    document.addEventListener('visibilitychange', alVolver)
    window.addEventListener('focus', alVolver)
  })

  onUnmounted(() => {
    detener()
    document.removeEventListener('visibilitychange', alVolver)
    window.removeEventListener('focus', alVolver)
  })
}
