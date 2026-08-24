import { reactive } from 'vue'

export type ToastTipo = 'success' | 'error' | 'info'

export type ToastItem = {
  id: number
  tipo: ToastTipo
  mensaje: string
}

const toasts = reactive<ToastItem[]>([])
let nextId = 1

function push(tipo: ToastTipo, mensaje: string) {
  const id = nextId++
  toasts.push({ id, tipo, mensaje })
  setTimeout(() => {
    const idx = toasts.findIndex((t) => t.id === id)
    if (idx !== -1) toasts.splice(idx, 1)
  }, 3500)
}

export function useToast() {
  return {
    toasts,
    success: (mensaje: string) => push('success', mensaje),
    error: (mensaje: string) => push('error', mensaje),
    info: (mensaje: string) => push('info', mensaje),
  }
}
