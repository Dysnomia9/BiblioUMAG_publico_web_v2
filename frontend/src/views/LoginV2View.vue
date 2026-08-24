<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import logoUmag from '@/assets/logo-umag.png'
import LoginBrandPanel from '@/components/auth/LoginBrandPanel.vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import { useToast } from '@/composables/useToast'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const usuarioAuth = useUsuarioAuthStore()
const toast = useToast()
const anio = new Date().getFullYear()

const identificador = ref('')
const password = ref('')
const enviando = ref(false)

// Un solo formulario para las dos capas de autenticación del sistema (staff y
// usuario del portal), que hoy son guards de Sanctum independientes con sus
// propios stores/tokens — acá se detecta cuál de los dos corresponde por el
// formato del identificador (un email siempre tiene "@", un RUT chileno nunca
// lo tiene) y se delega al store correspondiente, sin duplicar la lógica de
// login que ya vive en AuthController/UsuarioAuthController.
function onOlvidoPassword() {
  toast.info('Para recuperar tu contraseña, contacta a un administrador o acércate al mesón de la biblioteca.')
}

async function ingresar() {
  if (!identificador.value.trim() || !password.value) {
    toast.error('Ingresa tu email o RUT, y tu contraseña')
    return
  }

  enviando.value = true
  try {
    const esEmail = identificador.value.includes('@')

    if (esEmail) {
      const ok = await auth.login(identificador.value.trim(), password.value)
      if (ok) {
        router.push({ name: 'dashboard' })
        return
      }
      toast.error(auth.error ?? 'No se pudo iniciar sesión')
    } else {
      const ok = await usuarioAuth.login(identificador.value.trim(), password.value)
      if (ok) {
        router.push({ name: 'portal-home' })
        return
      }
      toast.error(usuarioAuth.error ?? 'No se pudo iniciar sesión')
    }
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
// plegado por defecto para no competir visualmente con el login local de arriba.
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

/** Común a Google (query params tras el redirect) y LDAP (respuesta directa del POST). */
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
}

// Google vuelve del backend con ?token=&tipo= (éxito) o ?error= (rechazo) —
// se procesa una sola vez al montar y se limpia la URL para no reprocesar en un refresh.
onMounted(() => {
  const { token, tipo, error } = route.query

  if (typeof token === 'string' && typeof tipo === 'string') {
    aplicarSesionExterna(tipo, token)
    router.replace({ query: {} })
  } else if (typeof error === 'string') {
    toast.error(ERRORES_GOOGLE[error] ?? 'No se pudo iniciar sesión')
    router.replace({ query: {} })
  }
})
</script>

<template>
  <div class="relative min-h-screen flex items-center justify-center bg-gradient-to-br from-indigo-50 via-slate-50 to-purple-50/60 px-4 py-12">
    <router-link
      :to="{ name: 'login' }"
      class="absolute top-4 right-4 sm:top-6 sm:right-6 text-xs font-medium text-gray-500 hover:text-indigo-600 border border-gray-200 hover:border-indigo-300 rounded-full px-3 py-1.5 bg-white/70 backdrop-blur-sm transition-colors"
    >
      ← Volver al inicio de sesión
    </router-link>

    <div class="w-full max-w-4xl rounded-3xl shadow-2xl overflow-hidden grid md:grid-cols-2 bg-white">
      <LoginBrandPanel subtitulo="Sistema Digital Institucional" />

      <div class="flex flex-col justify-center px-6 py-10 sm:px-10 sm:py-12">
        <div class="text-center mb-7">
          <img :src="logoUmag" alt="Universidad de Magallanes" class="mx-auto h-14 w-14 object-contain mb-3" />
          <h1 class="text-2xl font-serif font-semibold text-biblioteca-900">Biblioteca UMAG</h1>
          <p class="mt-1 text-sm text-biblioteca-500">Sistema Digital Institucional</p>
        </div>

        <form @submit.prevent="ingresar" class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-biblioteca-700 mb-1">Email institucional o RUT</label>
            <input
              v-model="identificador"
              type="text"
              placeholder="usuario@umag.cl o 12.345.678-5"
              class="w-full px-4 py-2.5 border border-biblioteca-200 rounded-xl focus:ring-2 focus:ring-acento-500 focus:border-transparent outline-none text-sm"
              autocomplete="username"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-biblioteca-700 mb-1">Contraseña</label>
            <input
              v-model="password"
              type="password"
              placeholder="••••••••"
              class="w-full px-4 py-2.5 border border-biblioteca-200 rounded-xl focus:ring-2 focus:ring-acento-500 focus:border-transparent outline-none text-sm"
              autocomplete="current-password"
            />
          </div>

          <button
            type="submit"
            :disabled="enviando"
            class="w-full rounded-xl py-3 text-sm font-semibold text-white shadow-sm hover:shadow-md transition-all disabled:opacity-60"
            style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
          >
            {{ enviando ? 'Ingresando…' : 'Ingresar' }}
          </button>
        </form>

        <button type="button" @click="onOlvidoPassword" class="mt-3 w-full text-center text-sm text-acento-600 hover:text-acento-500 font-medium hover:underline">
          ¿Olvidaste tu contraseña?
        </button>

        <p class="mt-3 text-center text-xs text-biblioteca-400">
          Un solo acceso para personal de biblioteca y usuarios institucionales — se detecta
          automáticamente según lo que ingreses.
        </p>

        <div class="my-5 flex items-center gap-3">
          <div class="flex-1 h-px bg-biblioteca-100" />
          <span class="text-[11px] text-biblioteca-400 uppercase tracking-wide">o continúa con</span>
          <div class="flex-1 h-px bg-biblioteca-100" />
        </div>

        <div class="space-y-2">
          <button
            type="button"
            @click="continuarConGoogle"
            class="w-full flex items-center justify-center gap-3 rounded-xl border border-biblioteca-200 bg-white py-2.5 text-sm font-medium text-biblioteca-800 shadow-sm hover:shadow-md hover:border-biblioteca-300 transition-all"
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
            class="w-full rounded-xl py-2.5 text-sm font-semibold text-white shadow-sm hover:shadow-md transition-all"
            style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
          >
            Cuenta institucional (LDAP)
          </button>

          <form v-if="ldapAbierto" @submit.prevent="ingresarConLdap" class="space-y-2 pt-2">
            <input
              v-model="ldapUsuario"
              type="text"
              placeholder="Usuario institucional"
              class="w-full px-4 py-2.5 border border-biblioteca-200 rounded-xl focus:ring-2 focus:ring-acento-500 focus:border-transparent outline-none text-sm"
              autocomplete="username"
            />
            <input
              v-model="ldapPassword"
              type="password"
              placeholder="Contraseña institucional"
              class="w-full px-4 py-2.5 border border-biblioteca-200 rounded-xl focus:ring-2 focus:ring-acento-500 focus:border-transparent outline-none text-sm"
              autocomplete="current-password"
            />
            <button
              type="submit"
              :disabled="enviandoLdap"
              class="w-full rounded-xl py-2.5 text-sm font-medium border border-biblioteca-200 text-biblioteca-800 hover:bg-biblioteca-50 transition-all disabled:opacity-60"
            >
              {{ enviandoLdap ? 'Ingresando…' : 'Ingresar con LDAP' }}
            </button>
          </form>
        </div>

        <p class="mt-8 text-[11px] text-biblioteca-400 leading-relaxed text-center">
          © {{ anio }} Todos los derechos reservados.<br />
          Universidad de Magallanes
        </p>
      </div>
    </div>
  </div>
</template>
