import { ref, watch, type Ref } from 'vue'
import { convertirAlModo, errorSegunModo, escribirIdentificador, type ModoDocumento } from '@/utils/documento'

function leer(clave?: string): ModoDocumento | null {
  if (!clave) return null
  try {
    const guardado = localStorage.getItem(clave)
    return guardado === 'rut' || guardado === 'extranjero' ? guardado : null
  } catch {
    return null
  }
}

function guardar(clave: string | undefined, modo: ModoDocumento) {
  if (!clave) return
  try {
    localStorage.setItem(clave, modo)
  } catch {
    // Sin almacenamiento (navegación privada): la próxima vez parte en RUT.
  }
}

/**
 * El modo (RUT o pasaporte/DNI) de un campo de identificación. Al cambiarlo, lo ya
 * escrito se adapta; con `recordarEn`, el navegador recuerda la última elección (para
 * que un estudiante extranjero no la cambie cada vez que entra al portal).
 */
export function useModoDocumento(valor: Ref<string>, opciones: { inicial?: ModoDocumento; recordarEn?: string; tuteo?: boolean } = {}) {
  const modo = ref<ModoDocumento>(leer(opciones.recordarEn) ?? opciones.inicial ?? 'rut')

  watch(modo, (nuevo) => {
    valor.value = convertirAlModo(valor.value, nuevo)
    guardar(opciones.recordarEn, nuevo)
  })

  function escribir(event: Event) {
    const resultado = escribirIdentificador(event, modo.value)
    valor.value = resultado.valor
    modo.value = resultado.modo
  }

  return { modo, escribir, error: () => errorSegunModo(valor.value, modo.value, opciones.tuteo) }
}

/**
 * Lo mismo para una lista de campos (los integrantes de una reserva de sala): cada fila
 * con su propio modo, porque en un grupo puede venir un estudiante extranjero.
 */
export function useModosPorFila(valores: Ref<string[]>, opciones: { tuteo?: boolean } = {}) {
  const modos = ref<ModoDocumento[]>(valores.value.map(() => 'rut'))

  // Al subir o bajar la cantidad de personas, las filas nuevas parten en RUT.
  watch(
    () => valores.value.length,
    (largo) => {
      while (modos.value.length < largo) modos.value.push('rut')
      modos.value.splice(largo)
    },
  )

  function escribir(indice: number, event: Event) {
    const resultado = escribirIdentificador(event, modos.value[indice] ?? 'rut')
    valores.value[indice] = resultado.valor
    modos.value[indice] = resultado.modo
  }

  function cambiar(indice: number, modo: ModoDocumento) {
    modos.value[indice] = modo
    valores.value[indice] = convertirAlModo(valores.value[indice] ?? '', modo)
  }

  /** Para cuando se vuelven a armar las filas desde cero (al abrir el formulario). */
  function reiniciar(iniciales: ModoDocumento[] = []) {
    modos.value = valores.value.map((_, i) => iniciales[i] ?? 'rut')
  }

  /** Los errores por fila (solo las que tienen algo mal), para marcarlas antes de enviar. */
  function errores(): Record<number, string> {
    return Object.fromEntries(
      valores.value
        .map((valor, i) => [i, errorSegunModo(valor, modos.value[i] ?? 'rut', opciones.tuteo)] as const)
        .filter(([, error]) => error),
    )
  }

  return { modos, escribir, cambiar, reiniciar, errores }
}
