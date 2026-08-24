import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/services/api'
import type { Staff } from '@/types'

export const useAuthStore = defineStore('auth', () => {
  const staff = ref<Staff | null>(null)
  const token = ref<string | null>(localStorage.getItem('token'))
  const error = ref<string | null>(null)
  const loading = ref(false)
  // Evita repetir la validación contra /auth/me en cada navegación dentro de
  // la misma sesión del SPA (se resetea solo con una recarga completa).
  const validated = ref(false)

  async function login(email: string, password: string) {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.post('/auth/login', { email, password })
      token.value = data.token
      staff.value = data.staff
      localStorage.setItem('token', data.token)
      return true
    } catch (e: any) {
      error.value = e?.response?.data?.message ?? 'No se pudo iniciar sesión. Verifica tus credenciales.'
      return false
    } finally {
      loading.value = false
    }
  }

  /**
   * Para logins que ya emitieron un token por fuera del flujo de auth.login()
   * (Google/LDAP en LoginV2View.vue, ver GoogleAuthController/LdapAuthController) —
   * evita repetir el POST /auth/login cuando el token ya viene emitido. staff se
   * completa recién en la próxima validar()/GET /auth/me.
   */
  function establecerToken(nuevoToken: string) {
    token.value = nuevoToken
    validated.value = false
    localStorage.setItem('token', nuevoToken)
  }

  async function logout() {
    try {
      await api.post('/auth/logout')
    } finally {
      token.value = null
      staff.value = null
      validated.value = false
      localStorage.removeItem('token')
    }
  }

  /**
   * Confirma contra el backend que el token guardado en localStorage sigue
   * siendo válido (antes el router solo comprobaba que el string existiera,
   * sin verificar si el backend seguía reconociéndolo). Devuelve false solo
   * cuando el backend responde 401 explícitamente — si la API no responde
   * (caída/desconexión) se deja pasar la navegación para que la vista
   * muestre el ApiErrorBanner en vez de expulsar al usuario.
   */
  async function validar(): Promise<boolean> {
    if (!token.value) return false
    if (validated.value) return true

    try {
      const { data } = await api.get('/auth/me')
      staff.value = data
      validated.value = true
      return true
    } catch (e: any) {
      if (e?.response?.status === 401) {
        // Limpiar el token acá, no solo devolver false: si no, el guard del router
        // rebota infinitamente entre login (ve auth.token truthy) y dashboard (ve
        // que la sesión no es válida) sin que el token stale se borre nunca — cada
        // vuelta del ping-pong dispara otro GET /auth/me.
        token.value = null
        staff.value = null
        validated.value = false
        localStorage.removeItem('token')
        return false
      }
      return true
    }
  }

  return { staff, token, error, loading, login, establecerToken, logout, validar }
})
