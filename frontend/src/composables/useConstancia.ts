import { ref } from 'vue'
import apiUsuario from '@/services/apiUsuario'
import { useToast } from '@/composables/useToast'
import { generarConstanciaNoMulta } from '@/utils/constancia'
import type { ConfiguracionInstitucional, Usuario } from '@/types'

/** Constancia de No Multa desde el portal: se genera solo si la persona no tiene multas pendientes. */
export function useConstancia() {
  const toast = useToast()
  const confirmando = ref(false)
  const generando = ref(false)

  async function generar() {
    confirmando.value = false
    generando.value = true
    try {
      const [{ data: usuario }, { data: configuracion }] = await Promise.all([
        apiUsuario.get<Usuario & { multas_pendientes: { cantidad: number; monto_total: number } }>('/mi/multas'),
        apiUsuario.get<ConfiguracionInstitucional>('/mi/configuracion'),
      ])
      const { cantidad } = usuario.multas_pendientes
      if (cantidad > 0) {
        toast.error(`Tienes ${cantidad === 1 ? '1 multa pendiente' : `${cantidad} multas pendientes`}: no se puede emitir la constancia hasta pagar en la biblioteca`)
        return
      }
      await generarConstanciaNoMulta(usuario, configuracion)
      toast.success('Constancia generada')
    } catch {
      toast.error('No se pudo generar la constancia')
    } finally {
      generando.value = false
    }
  }

  return { confirmando, generando, generar }
}
