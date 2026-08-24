<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import { formatRut } from '@/composables/useRut'
import { useToast } from '@/composables/useToast'
import loginFoto from '@/assets/user-umag.webp'
import logoUmag from '@/assets/logo-umag.png'

const rut = ref('')
const password = ref('')
const mostrarPassword = ref(false)
const recordarme = ref(true)
const auth = useUsuarioAuthStore()
const router = useRouter()
const toast = useToast()

function onRutInput(event: Event) {
  rut.value = formatRut((event.target as HTMLInputElement).value)
}

async function onSubmit() {
  const ok = await auth.login(rut.value, password.value)
  if (ok) router.push({ name: 'portal-home' })
}

function onOlvidoPassword() {
  toast.info('Para recuperar tu contraseña, acércate al mesón de la biblioteca con tu credencial.')
}

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

    <div class="relative flex-1 flex flex-col">
      <!-- Contenido central -->
      <div class="flex-1 flex flex-col lg:flex-row items-center justify-center gap-12 px-6 sm:px-10 py-6">
        <div class="hidden lg:block max-w-md">
          <div class="rounded-3xl bg-white/10 backdrop-blur-md border border-white/15 px-10 py-10 text-center text-white shadow-xl">
            <img :src="logoUmag" alt="Universidad de Magallanes" class="mx-auto h-14 w-14 object-contain mb-5" />
            <h1 class="text-4xl font-serif font-bold mb-4">Biblioteca UMAG</h1>
            <div class="h-px w-32 bg-white/30 mb-6 mx-auto"></div>

            <div class="flex justify-center gap-5">
              <div v-for="d in destacados" :key="d.label" class="text-center">
                <div class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-white/10 border border-white/15">
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
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                </svg>
              </div>
              <h2 class="text-xl font-serif font-bold text-gray-900">Inicio de Sesión Usuarios</h2>
              <p class="mt-1 text-sm text-gray-500">Portal para estudiantes, docentes y funcionarios</p>
            </div>

            <form @submit.prevent="onSubmit" class="space-y-4">
              <div>
                <label for="rut" class="block text-sm font-medium text-gray-700 mb-1.5">RUT</label>
                <div class="relative">
                  <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                  </svg>
                  <input
                    id="rut"
                    :value="rut"
                    @input="onRutInput"
                    type="text"
                    required
                    maxlength="12"
                    autocomplete="username"
                    class="w-full rounded-lg border border-gray-300 bg-gray-50/50 pl-10 pr-3.5 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                    placeholder="12.345.678-5"
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
                    placeholder="Ingresa tu contraseña"
                    class="w-full rounded-lg border border-gray-300 bg-gray-50/50 pl-10 pr-10 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
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
                {{ auth.loading ? 'Ingresando…' : 'Ingresar' }}
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                </svg>
              </button>
            </form>

            <button type="button" @click="onOlvidoPassword" class="mt-4 w-full text-center text-sm text-indigo-600 hover:text-indigo-700 font-medium hover:underline">
              ¿Olvidaste tu contraseña?
            </button>

            <div class="mt-5 pt-5 border-t border-gray-100 text-center">
              <router-link :to="{ name: 'login' }" class="text-sm text-gray-500 hover:text-indigo-600 font-medium hover:underline">
                ¿Eres personal de biblioteca? Ingresa aquí
              </router-link>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
