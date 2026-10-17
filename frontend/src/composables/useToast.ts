import { reactive } from 'vue'

export type ToastTipo = 'success' | 'error' | 'info'

export type ToastItem = {
  id: number
  tipo: ToastTipo
  mensaje: string
}

// Los errores suelen explicar qué corregir, así que se quedan más tiempo en pantalla.
const DURACION: Record<ToastTipo, number> = { success: 3500, info: 5000, error: 7000 }

const toasts = reactive<ToastItem[]>([])
let nextId = 1

function cerrar(id: number) {
  const idx = toasts.findIndex((t) => t.id === id)
  if (idx !== -1) toasts.splice(idx, 1)
}

function push(tipo: ToastTipo, mensaje: string) {
  const id = nextId++
  toasts.push({ id, tipo, mensaje })
  if (toasts.length > 4) toasts.splice(0, toasts.length - 4)
  setTimeout(() => cerrar(id), DURACION[tipo])
}

export function useToast() {
  return {
    toasts,
    cerrar,
    success: (mensaje: string) => push('success', mensaje),
    error: (mensaje: string) => push('error', mensaje),
    info: (mensaje: string) => push('info', mensaje),
  }
}

/** Primer mensaje útil de un error de axios (422 con errores por campo, o message). */
export function mensajeDeError(e: any, porDefecto: string): string {
  const errores = e?.response?.data?.errors
  if (errores && typeof errores === 'object') {
    const primero = Object.values(errores).flat()[0]
    if (typeof primero === 'string') return primero
  }
  return e?.response?.data?.message ?? porDefecto
}
