import { readonly, ref } from 'vue'
import { esFondo, FONDO_PORTAL, FONDO_PREDETERMINADO } from '@/utils/fondos'

/**
 * Fondo del modo día (utils/fondos.ts): data-fondo en <html>. El del personal es el de su
 * cuenta (staff.fondo) y se recuerda en este navegador, para que public/tema.js lo ponga
 * antes de dibujar la página; el del portal es siempre Neblina.
 */
const CLAVE = 'fondo'

const actual = ref(document.documentElement.dataset.fondo ?? FONDO_PREDETERMINADO)

function mostrar(clave: string) {
  actual.value = clave
  document.documentElement.dataset.fondo = clave
}

function recordado(): string | null {
  try {
    return localStorage.getItem(CLAVE)
  } catch {
    return null
  }
}

/**
 * El fondo de la cuenta del personal (null: no ha elegido). Con undefined (la sesión no se
 * pudo cargar), el último que se usó en este navegador.
 */
function ponerDelPersonal(clave: string | null | undefined) {
  const elegido = clave === undefined ? recordado() : clave
  const valido = esFondo(elegido) ? elegido : FONDO_PREDETERMINADO
  mostrar(valido)
  try {
    localStorage.setItem(CLAVE, valido)
  } catch {
    // Sin localStorage, tema.js pone el predeterminado hasta que cargue la sesión.
  }
}

export function useFondo() {
  return {
    actual: readonly(actual),
    ponerDelPersonal,
    ponerDelPortal: () => mostrar(FONDO_PORTAL),
  }
}
