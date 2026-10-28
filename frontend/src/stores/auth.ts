import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import type { Staff } from '@/types'

export const useAuthStore = defineStore('auth', () => {
  const staff = ref<Staff | null>(null)
  const token = ref<string | null>(localStorage.getItem('token'))
  // Evita repetir la validación contra /auth/me en cada navegación dentro de
  // la misma sesión del SPA (se resetea solo con una recarga completa).
  const validated = ref(false)

  const permisos = computed(() => new Set(staff.value?.permisos ?? []))

  /**
   * Solo decide qué se muestra: el backend vuelve a verificar cada permiso en
   * cada request (middleware 'permiso'), así que ocultar un botón no es la
   * protección real.
   */
  function puede(permiso: string): boolean {
    return permisos.value.has(permiso)
  }

  function puedeAlguno(requeridos: string | string[] | undefined): boolean {
    if (!requeridos || (Array.isArray(requeridos) && requeridos.length === 0)) return true
    return (Array.isArray(requeridos) ? requeridos : [requeridos]).some(puede)
  }

  /**
   * Guarda la sesión que emitió el login (LoginView.vue: correo o RUT, Google o LDAP).
   * staff se completa en la próxima validar()/GET /auth/me.
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
   * siendo válido, y de paso trae los permisos vigentes del rol. Devuelve false
   * solo cuando el backend responde 401 explícitamente — si la API no responde
   * (caída/desconexión) se deja pasar la navegación para que la vista muestre
   * el ApiErrorBanner en vez de expulsar al usuario.
   */
  async function validar(): Promise<boolean> {
    if (!token.value) return false
    if (validated.value) return true

    try {
      const { data } = await api.get<Staff>('/auth/me')
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

  return { staff, token, permisos, puede, puedeAlguno, establecerToken, logout, validar }
})
