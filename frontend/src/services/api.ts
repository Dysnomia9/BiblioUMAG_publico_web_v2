import axios from 'axios'

// Si no se define VITE_API_URL, arma la URL del backend usando el mismo host
// desde el que se accedió al frontend (localhost, IP de red local, etc.) para
// que funcione igual desde el navegador del PC que desde un celular en la
// misma WiFi.
const defaultApiUrl = `${window.location.protocol}//${window.location.hostname}:8000/api`

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? defaultApiUrl,
  withCredentials: true,
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Un token vencido o revocado hace que el backend responda 401 en cualquier
// endpoint protegido. Antes esto no se manejaba: la sesión quedaba "colgada"
// en el frontend (el token seguía en localStorage) aunque ya no fuera válida.
// Se limpia acá y se fuerza a login con un reload completo (en vez de
// router.push) para evitar un import circular con el router/las stores y
// para que el estado de Pinia también quede limpio.
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && !window.location.pathname.startsWith('/login')) {
      localStorage.removeItem('token')
      window.location.href = '/login'
    }
    return Promise.reject(error)
  },
)

export default api
