import { readonly, ref } from 'vue'

/**
 * Modo claro u oscuro de toda la app (panel del personal, portal y logins). La clase
 * .dark en <html> cambia los colores (ver tailwind.config.js); public/tema.js la pone
 * antes de dibujar la página. La elección queda guardada en este navegador.
 */
const CLAVE = 'tema'

const oscuro = ref(document.documentElement.classList.contains('dark'))

function mostrar(valor: boolean) {
  oscuro.value = valor
  document.documentElement.classList.toggle('dark', valor)
}

function aplicar(valor: boolean) {
  mostrar(valor)
  try {
    localStorage.setItem(CLAVE, valor ? 'oscuro' : 'claro')
  } catch {
    // Sin localStorage, vale hasta recargar la página.
  }
}

// Si se cambia en otra pestaña del mismo navegador, esta también cambia.
window.addEventListener('storage', (e) => {
  if (e.key === CLAVE) mostrar(e.newValue === 'oscuro')
})

export function useTema() {
  return {
    oscuro: readonly(oscuro),
    alternar: () => aplicar(!oscuro.value),
  }
}
