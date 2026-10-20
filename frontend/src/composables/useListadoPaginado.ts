import { ref, watch, type Ref } from 'vue'
import type { Paginado } from '@/types'

/**
 * Un listado que el servidor entrega de a una página. `pedir` trae la página indicada con
 * los filtros de la pantalla; al cambiar un filtro se llama `reiniciar()` (vuelve a la
 * primera). Si llegan dos respuestas desordenadas (escribiendo rápido en el buscador),
 * solo cuenta la del último pedido.
 */
export function useListadoPaginado<T>(pedir: (pagina: number) => Promise<Paginado<T>>) {
  const filas = ref([]) as Ref<T[]>
  const total = ref(0)
  const porPagina = ref(25)
  const pagina = ref(1)
  const cargando = ref(true)
  const error = ref(false)
  let ultimoPedido = 0

  async function cargar() {
    const pedido = ++ultimoPedido
    cargando.value = true
    try {
      const datos = await pedir(pagina.value)
      if (pedido !== ultimoPedido) return
      // Se pidió una página que ya no existe (por ejemplo, tras pagar la última multa de ella).
      if (!datos.data.length && datos.total > 0 && pagina.value > datos.last_page) {
        pagina.value = datos.last_page
        return
      }
      filas.value = datos.data
      total.value = datos.total
      porPagina.value = datos.per_page
      error.value = false
    } catch {
      if (pedido !== ultimoPedido) return
      error.value = true
      filas.value = []
      total.value = 0
    } finally {
      if (pedido === ultimoPedido) cargando.value = false
    }
  }

  watch(pagina, cargar)

  function reiniciar() {
    if (pagina.value === 1) cargar()
    else pagina.value = 1
  }

  return { filas, total, porPagina, pagina, cargando, error, cargar, reiniciar }
}
