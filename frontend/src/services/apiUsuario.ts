import axios from 'axios'

const defaultApiUrl = `${window.location.protocol}//${window.location.hostname}:8000/api`

const apiUsuario = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? defaultApiUrl,
  withCredentials: true,
})

apiUsuario.interceptors.request.use((config) => {
  const token = localStorage.getItem('usuario_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Ver el mismo interceptor en services/api.ts (staff): limpia el token vencido
// y fuerza login con reload completo en vez de router.push.
apiUsuario.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && !window.location.pathname.startsWith('/portal/login')) {
      localStorage.removeItem('usuario_token')
      window.location.href = '/portal/login'
    }
    return Promise.reject(error)
  },
)

export default apiUsuario
