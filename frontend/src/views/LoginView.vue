<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import LoginBrandPanel from '@/components/auth/LoginBrandPanel.vue'
import logoUmag from '@/assets/logo-umag.png'
import { useToast } from '@/composables/useToast'

const email = ref('admin@umag.cl')
const password = ref('')
const mostrarPassword = ref(false)
const recordarme = ref(true)
const auth = useAuthStore()
const router = useRouter()
const toast = useToast()

async function onSubmit() {
  const ok = await auth.login(email.value, password.value)
  if (ok) router.push({ name: 'dashboard' })
}

function onOlvidoPassword() {
  toast.info('Para recuperar tu contraseña, contacta a un administrador del sistema.')
}
</script>

<template>
  <div class="relative min-h-screen flex items-center justify-center bg-gradient-to-br from-indigo-50 via-slate-50 to-purple-50/60 px-4 py-12">
    <router-link
      :to="{ name: 'login-v2' }"
      class="absolute top-4 right-4 sm:top-6 sm:right-6 text-xs font-medium text-gray-500 hover:text-indigo-600 border border-gray-200 hover:border-indigo-300 rounded-full px-3 py-1.5 bg-white/70 backdrop-blur-sm transition-colors"
    >
      Versión 2
    </router-link>

    <div class="w-full max-w-4xl rounded-3xl shadow-2xl overflow-hidden grid md:grid-cols-2 bg-white">
      <LoginBrandPanel />

      <div class="flex flex-col justify-center px-6 py-10 sm:px-10 sm:py-12">
        <div class="text-center mb-7">
          <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl border border-gray-200 bg-white shadow-md overflow-hidden">
            <img :src="logoUmag" alt="Universidad de Magallanes" class="h-full w-full object-cover" />
          </div>
          <h2 class="text-2xl font-serif font-bold text-gray-900">Administración</h2>
          <p class="mt-1 text-sm text-gray-500">Panel para personal de biblioteca</p>
        </div>

        <form @submit.prevent="onSubmit" class="space-y-5">
          <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">Correo institucional</label>
            <div class="relative">
              <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
              </svg>
              <input
                id="email"
                v-model="email"
                type="email"
                required
                autocomplete="username"
                class="w-full rounded-lg border border-gray-300 bg-gray-50/50 pl-10 pr-3.5 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                placeholder="nombre@umag.cl"
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
                required
                autocomplete="current-password"
                class="w-full rounded-lg border border-gray-300 bg-gray-50/50 pl-10 pr-10 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                placeholder="••••••••"
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

          <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
            <input v-model="recordarme" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
            Recordarme
          </label>

          <p v-if="auth.error" class="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">
            {{ auth.error }}
          </p>

          <button
            type="submit"
            :disabled="auth.loading"
            class="w-full flex items-center justify-center gap-2 rounded-lg text-white text-sm font-medium py-2.5 transition-opacity disabled:opacity-60"
            style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l4-4m0 0l-4-4m4 4H3m7 4v1a3 3 0 003 3h4a3 3 0 003-3V7a3 3 0 00-3-3h-4a3 3 0 00-3 3v1" />
            </svg>
            {{ auth.loading ? 'Ingresando…' : 'Iniciar sesión' }}
          </button>
        </form>

        <button type="button" @click="onOlvidoPassword" class="mt-4 w-full text-center text-sm text-indigo-600 hover:text-indigo-700 font-medium hover:underline">
          ¿Olvidaste tu contraseña?
        </button>

        <div class="my-6 flex items-center gap-3">
          <div class="h-px flex-1 bg-gray-200"></div>
          <span class="text-xs text-gray-400 uppercase tracking-wide">Sistema interno</span>
          <div class="h-px flex-1 bg-gray-200"></div>
        </div>

        <router-link
          :to="{ name: 'portal-login' }"
          class="flex items-center justify-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-700 font-medium hover:underline"
        >
          <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 017.231-4.41 60.53 60.53 0 00-.491-6.347M4.26 10.147a48.474 48.474 0 017.44-5.16.75.75 0 01.6 0 48.55 48.55 0 017.44 5.16M4.26 10.147a49.99 49.99 0 00-2.658.813.75.75 0 00-.211 1.319 47.71 47.71 0 019.6 6.36.75.75 0 00.918 0 47.7 47.7 0 019.6-6.36.75.75 0 00-.212-1.318 49.9 49.9 0 00-2.658-.812m-15.482 0A50.717 50.717 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5" />
          </svg>
          ¿Eres estudiante, docente o funcionario? Ingresa aquí
        </router-link>

        <p class="mt-6 text-center text-xs text-gray-400">
          Sistema interno · Universidad de Magallanes
        </p>
      </div>
    </div>
  </div>
</template>
