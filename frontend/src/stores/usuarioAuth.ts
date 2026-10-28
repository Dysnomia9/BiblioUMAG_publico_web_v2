import { defineStore } from 'pinia'
import { ref } from 'vue'
import apiUsuario from '@/services/apiUsuario'
import type { Usuario } from '@/types'

export const useUsuarioAuthStore = defineStore('usuarioAuth', () => {
  const usuario = ref<Usuario | null>(null)
  const token = ref<string | null>(localStorage.getItem('usuario_token'))
  const validated = ref(false)

  /** Ver auth.ts (staff): la sesión que emitió el login (LoginView.vue). */
  function establecerToken(nuevoToken: string) {
    token.value = nuevoToken
    validated.value = false
    localStorage.setItem('usuario_token', nuevoToken)
  }

  async function logout() {
    try {
      await apiUsuario.post('/auth/usuario/logout')
    } finally {
      token.value = null
      usuario.value = null
      validated.value = false
      localStorage.removeItem('usuario_token')
    }
  }

  /** Ver auth.ts (staff) — misma lógica para la sesión del portal. */
  async function validar(): Promise<boolean> {
    if (!token.value) return false
    if (validated.value) return true

    try {
      const { data } = await apiUsuario.get('/auth/usuario/me')
      usuario.value = data
      validated.value = true
      return true
    } catch (e: any) {
      if (e?.response?.status === 401) {
        // Ver auth.ts (staff) — sin esto, el guard del router hace ping-pong
        // infinito entre el login y portal-home porque el token stale nunca
        // se borra, disparando un GET /auth/usuario/me en cada vuelta.
        token.value = null
        usuario.value = null
        validated.value = false
        localStorage.removeItem('usuario_token')
        return false
      }
      return true
    }
  }

  return { usuario, token, establecerToken, logout, validar }
})
