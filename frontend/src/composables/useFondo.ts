import { readonly, ref } from 'vue'
import { esFondo, FONDO_PORTAL, FONDO_PREDETERMINADO } from '@/utils/fondos'

/**
 * Fondo del modo día (utils/fondos.ts): data-fondo en <html>. El del personal es el de su
 * cuenta (staff.fondo) y el del usuario del portal, el de la suya (usuarios.fondo). Cada uno
 * se recuerda en este navegador con su propia clave, para que public/tema.js lo ponga antes
 * de dibujar la página.
 */
const CLAVE_PERSONAL = 'fondo'
const CLAVE_PORTAL = 'fondo-portal'

const actual = ref(document.documentElement.dataset.fondo ?? FONDO_PREDETERMINADO)

function mostrar(clave: string) {
  actual.value = clave
  document.documentElement.dataset.fondo = clave
}

function recordado(claveLocal: string): string | null {
  try {
    return localStorage.getItem(claveLocal)
  } catch {
    return null
  }
}

/**
 * El fondo de la cuenta (null: no ha elegido, va el predeterminado). Con undefined (la
 * sesión no se pudo cargar), el último que se usó en este navegador.
 */
function poner(clave: string | null | undefined, claveLocal: string, predeterminado: string) {
  const elegido = clave === undefined ? recordado(claveLocal) : clave
  const valido = esFondo(elegido) ? elegido : predeterminado
  mostrar(valido)
  try {
    localStorage.setItem(claveLocal, valido)
  } catch {
    // Sin localStorage, tema.js pone el predeterminado hasta que cargue la sesión.
  }
}

export function useFondo() {
  return {
    actual: readonly(actual),
    ponerDelPersonal: (clave: string | null | undefined) => poner(clave, CLAVE_PERSONAL, FONDO_PREDETERMINADO),
    ponerDelPortal: (clave: string | null | undefined) => poner(clave, CLAVE_PORTAL, FONDO_PORTAL),
  }
}
