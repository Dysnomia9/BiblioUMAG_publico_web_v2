<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import loginFoto from '@/assets/user-umag.webp'
import logoUmag from '@/assets/logo-umag.png'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import { useToast } from '@/composables/useToast'
import InterruptorTema from '@/components/ui/InterruptorTema.vue'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const usuarioAuth = useUsuarioAuthStore()
const toast = useToast()
const anio = new Date().getFullYear()

// Un solo formulario para el personal y los usuarios del portal, solo con correo: el
// servidor busca de quién es (POST /auth/v2/login) y responde a qué sesión corresponde.
const correo = ref('')
const password = ref('')
const mostrarPassword = ref(false)
const enviando = ref(false)
const error = ref('')

function onOlvidoPassword() {
  toast.info('Para recuperar tu contraseña, contacta a un administrador o acércate al mesón de la biblioteca.')
}

async function ingresar() {
  error.value = ''
  const correoLimpio = correo.value.trim().toLowerCase()
  if (!correoLimpio || !password.value) {
    error.value = 'Ingresa tu correo institucional y tu contraseña.'
    return
  }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correoLimpio)) {
    error.value = 'Ingresa tu correo institucional (por ejemplo, igcontre@umag.cl).'
    return
  }

  enviando.value = true
  try {
    const { data } = await api.post('/auth/v2/login', { correo: correoLimpio, password: password.value })
    aplicarSesionExterna(data.tipo, data.token)
  } catch (e: any) {
    error.value = e?.response?.data?.errors?.correo?.[0] ?? e?.response?.data?.message ?? 'No se pudo iniciar sesión'
  } finally {
    enviando.value = false
  }
}

// Redirige al backend, que a su vez redirige a Google — GoogleAuthController
// responde con un ?error= de vuelta acá mismo si GOOGLE_CLIENT_ID no está
// configurado todavía, en vez de intentar contactar a Google (ver .env.example).
function continuarConGoogle() {
  const base = (api.defaults.baseURL ?? '').replace(/\/$/, '')
  window.location.href = `${base}/auth/google/redirect`
}

// LDAP institucional: a diferencia de Google (redirect) es un bind clásico de
// usuario/contraseña, así que necesita su propio mini-formulario — se abre
// plegado por defecto para no competir visualmente con el login de arriba.
const ldapAbierto = ref(false)
const ldapUsuario = ref('')
const ldapPassword = ref('')
const enviandoLdap = ref(false)

async function ingresarConLdap() {
  if (!ldapUsuario.value.trim() || !ldapPassword.value) {
    toast.error('Ingresa tu usuario y contraseña institucional')
    return
  }

  enviandoLdap.value = true
  try {
    const { data } = await api.post('/auth/ldap/login', {
      usuario: ldapUsuario.value.trim(),
      password: ldapPassword.value,
    })
    aplicarSesionExterna(data.tipo, data.token)
  } catch (e: any) {
    const mensaje =
      e?.response?.data?.errors?.usuario?.[0] ?? e?.response?.data?.message ?? 'No se pudo iniciar sesión con LDAP'
    toast.error(mensaje)
  } finally {
    enviandoLdap.value = false
  }
}

/** Común al correo, a Google (código canjeado tras el redirect) y a LDAP. */
function aplicarSesionExterna(tipo: string, token: string) {
  if (tipo === 'staff') {
    auth.establecerToken(token)
    router.push({ name: 'dashboard' })
  } else {
    usuarioAuth.establecerToken(token)
    router.push({ name: 'portal-home' })
  }
}

const ERRORES_GOOGLE: Record<string, string> = {
  google_no_configurado: 'El login con Google todavía no está configurado en este sistema.',
  google_fallo: 'No se pudo completar el login con Google. Intenta de nuevo.',
  google_sin_cuenta: 'Tu cuenta de Google es válida, pero no está habilitada en Biblioteca UMAG. Contacta a un administrador.',
  google_no_permitido: 'Esa cuenta de Google no tiene el correo verificado o no es del dominio institucional.',
}

// Google vuelve del backend con ?codigo= (éxito) o ?error= (rechazo). El código sirve
// una sola vez y por un minuto: se canjea por el token con un POST, así el token
// nunca queda en una URL. Se limpia la URL antes, para no reprocesar en un refresh.
onMounted(async () => {
  const { codigo, error: errorGoogle } = route.query

  if (typeof codigo === 'string') {
    router.replace({ query: {} })
    try {
      const { data } = await api.post('/auth/google/canjear', { codigo })
      aplicarSesionExterna(data.tipo, data.token)
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'No se pudo completar el login con Google. Intenta de nuevo.')
    }
  } else if (typeof errorGoogle === 'string') {
    toast.error(ERRORES_GOOGLE[errorGoogle] ?? 'No se pudo iniciar sesión')
    router.replace({ query: {} })
  }
})

const destacados = [
  { label: 'Préstamos', icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253' },
  { label: 'Salas de estudio', icon: 'M4 6h16M4 12h16M4 18h7' },
  { label: 'Recursos', icon: 'M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z' },
]
</script>

<template>
  <div class="relative min-h-screen flex flex-col overflow-hidden">
    <img :src="loginFoto" alt="" class="absolute inset-0 h-full w-full object-cover" />
    <div
      class="absolute inset-0"
      style="background: linear-gradient(90deg, rgba(28,16,66,0.93) 0%, rgba(59,40,163,0.85) 28%, rgba(67,56,202,0.55) 52%, rgba(224,221,250,0.55) 75%, rgba(248,247,255,0.85) 100%);"
    ></div>

    <div class="absolute top-4 right-4 z-10 sm:top-6 sm:right-6">
      <InterruptorTema en-pagina />
    </div>

    <div class="relative flex-1 flex flex-col">
      <div class="flex-1 flex flex-col lg:flex-row items-center justify-center gap-12 px-6 sm:px-10 py-6">
        <div class="hidden lg:block max-w-md">
          <div class="rounded-3xl bg-blanco/10 backdrop-blur-md border border-white/15 px-10 py-10 text-center text-white shadow-xl">
            <img :src="logoUmag" alt="Universidad de Magallanes" class="mx-auto h-14 w-14 object-contain mb-5" />
            <h1 class="text-4xl font-serif font-bold mb-4">Biblioteca UMAG</h1>
            <div class="h-px w-32 bg-blanco/30 mb-6 mx-auto"></div>

            <div class="flex justify-center gap-5">
              <div v-for="d in destacados" :key="d.label" class="text-center">
                <div class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-blanco/10 border border-white/15">
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="d.icon" />
                  </svg>
                </div>
                <p class="text-xs font-medium text-white/85">{{ d.label }}</p>
              </div>
            </div>
          </div>
        </div>

        <div class="w-full max-w-sm">
          <div class="rounded-3xl bg-white shadow-2xl border-2 border-gray-900 p-7 sm:p-8">
            <div class="text-center mb-6">
              <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-indigo-50 text-indigo-600">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                </svg>
              </div>
              <h2 class="text-xl font-serif font-bold text-gray-900">Inicio de Sesión</h2>
              <p class="mt-1 text-sm text-gray-500">Personal de biblioteca, estudiantes, docentes y funcionarios</p>
            </div>

            <form @submit.prevent="ingresar" class="space-y-4" novalidate>
              <div>
                <label for="correo" class="block text-sm font-medium text-gray-700 mb-1.5">Correo institucional</label>
                <div class="relative">
                  <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zm0 0c0 1.657 1.007 3 2.25 3S21 13.657 21 12a9 9 0 10-2.636 6.364M16.5 12V8.25" />
                  </svg>
                  <input
                    id="correo"
                    v-model="correo"
                    type="email"
                    inputmode="email"
                    autocomplete="username"
                    placeholder="igcontre@umag.cl"
                    class="w-full rounded-lg border bg-gray-50/50 pl-10 pr-3.5 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:border-transparent"
                    :class="error ? 'border-red-300 focus:ring-red-400' : 'border-gray-300 focus:ring-indigo-500'"
                    :aria-invalid="error ? 'true' : 'false'"
                    @input="error = ''"
                  />
                </div>
              </div>

              <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">Contraseña</label>
                <div class="relative">
                  <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                  </svg>
                  <input
                    id="password"
                    v-model="password"
                    :type="mostrarPassword ? 'text' : 'password'"
                    autocomplete="current-password"
                    placeholder="Ingresa tu contraseña"
                    class="w-full rounded-lg border border-gray-300 bg-gray-50/50 pl-10 pr-10 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                    @input="error = ''"
                  />
                  <button
                    type="button"
                    @click="mostrarPassword = !mostrarPassword"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                    :aria-label="mostrarPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                    tabindex="-1"
                  >
                    <svg v-if="mostrarPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                    </svg>
                    <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.644C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                  </button>
                </div>
              </div>

              <p v-if="error" class="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2" role="alert">
                {{ error }}
              </p>

              <button
                type="submit"
                :disabled="enviando"
                class="w-full flex items-center justify-center gap-2 rounded-lg text-white text-sm font-medium py-2.5 transition-opacity disabled:opacity-60"
                style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
              >
                {{ enviando ? 'Ingresando…' : 'Ingresar' }}
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                </svg>
              </button>
            </form>

            <button type="button" @click="onOlvidoPassword" class="mt-4 w-full text-center text-sm text-indigo-600 hover:text-indigo-700 font-medium hover:underline">
              ¿Olvidaste tu contraseña?
            </button>

            <div class="my-5 flex items-center gap-3">
              <div class="flex-1 h-px bg-gray-200" />
              <span class="text-[11px] text-gray-400 uppercase tracking-wide">o continúa con</span>
              <div class="flex-1 h-px bg-gray-200" />
            </div>

            <div class="space-y-2">
              <button
                type="button"
                @click="continuarConGoogle"
                class="w-full flex items-center justify-center gap-3 rounded-lg border border-gray-300 bg-white py-2.5 text-sm font-medium text-gray-800 shadow-sm hover:shadow-md transition-all"
              >
                <svg width="18" height="18" viewBox="0 0 48 48">
                  <path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12s5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24s8.955,20,20,20s20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z" />
                  <path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z" />
                  <path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z" />
                  <path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C36.971,39.205,44,34,44,24C44,22.659,43.862,21.35,43.611,20.083z" />
                </svg>
                Continuar con Google
              </button>

              <button
                type="button"
                @click="ldapAbierto = !ldapAbierto"
                :aria-expanded="ldapAbierto"
                class="w-full rounded-lg border border-indigo-200 bg-indigo-50 py-2.5 text-sm font-semibold text-indigo-700 transition-colors hover:bg-indigo-100"
              >
                Cuenta institucional (LDAP)
              </button>

              <form v-if="ldapAbierto" @submit.prevent="ingresarConLdap" class="space-y-2 pt-2">
                <input
                  v-model="ldapUsuario"
                  type="text"
                  placeholder="Usuario institucional"
                  aria-label="Usuario institucional"
                  class="w-full rounded-lg border border-gray-300 bg-gray-50/50 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                  autocomplete="username"
                />
                <input
                  v-model="ldapPassword"
                  type="password"
                  placeholder="Contraseña institucional"
                  aria-label="Contraseña institucional"
                  class="w-full rounded-lg border border-gray-300 bg-gray-50/50 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                  autocomplete="current-password"
                />
                <button
                  type="submit"
                  :disabled="enviandoLdap"
                  class="w-full rounded-lg py-2.5 text-sm font-medium border border-gray-300 text-gray-800 hover:bg-gray-50 transition-all disabled:opacity-60"
                >
                  {{ enviandoLdap ? 'Ingresando…' : 'Ingresar con LDAP' }}
                </button>
              </form>
            </div>

            <div class="mt-5 pt-5 border-t border-gray-100 text-center">
              <router-link :to="{ name: 'login' }" class="text-sm text-gray-500 hover:text-indigo-600 font-medium hover:underline">
                ← Volver al inicio de sesión
              </router-link>
              <p class="mt-3 text-[11px] leading-relaxed text-gray-400">© {{ anio }} Universidad de Magallanes</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
