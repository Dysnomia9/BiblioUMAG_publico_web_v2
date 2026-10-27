<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import InterruptorTema from '@/components/ui/InterruptorTema.vue'
import logoUmag from '@/assets/logo-umag.png'

const auth = useUsuarioAuthStore()
const router = useRouter()
const route = useRoute()

const iniciales = computed(() => {
  const letras = `${auth.usuario?.nombre?.charAt(0) ?? ''}${auth.usuario?.apellido?.charAt(0) ?? ''}`
  return letras.toUpperCase() || 'U'
})

const links = [
  { name: 'portal-home', label: 'Inicio' },
  { name: 'portal-entrada', label: 'Entrada' },
  { name: 'portal-catalogo', label: 'Catálogo' },
  { name: 'portal-salas', label: 'Salas' },
]

const menuCuenta = [
  { name: 'portal-perfil', label: 'Mi perfil', icon: 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z' },
  { name: 'portal-ingresos', label: 'Mis ingresos', icon: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z' },
]

const barraInferior = [
  { name: 'portal-home', label: 'Inicio', icon: 'M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25' },
  { name: 'portal-catalogo', label: 'Catálogo', icon: 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25' },
  { name: 'portal-entrada', label: 'Entrada', icon: 'M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M9 9h6v6H9V9z', central: true },
  { name: 'portal-salas', label: 'Salas', icon: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5' },
  { name: 'portal-perfil', label: 'Perfil', icon: 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z' },
]

// Menú de la persona (nombre y tipo arriba a la derecha): se cierra al hacer clic
// afuera, con Esc o al cambiar de pantalla.
const menuAbierto = ref(false)
const menu = ref<HTMLElement | null>(null)

function alHacerClic(e: MouseEvent) {
  if (menuAbierto.value && menu.value && !menu.value.contains(e.target as Node)) menuAbierto.value = false
}
function alPresionarTecla(e: KeyboardEvent) {
  if (e.key === 'Escape') menuAbierto.value = false
}
// En el teléfono, la barra inferior se esconde mientras se escribe: con el teclado
// abierto quedaba encima del campo (por ejemplo, el RUT en Entrada).
const escribiendo = ref(false)
const SIN_TECLADO = ['checkbox', 'radio', 'button', 'submit', 'reset', 'file', 'range', 'color', 'date', 'time', 'datetime-local', 'month', 'week']
function hayCampoDeTextoActivo() {
  const el = document.activeElement
  return el instanceof HTMLTextAreaElement || (el instanceof HTMLInputElement && !SIN_TECLADO.includes(el.type))
}
// Se revisa después del evento: al pasar de un campo a otro, o si el campo desaparece
// (un formulario que se cierra), el foco ya quedó donde corresponde. Al cerrar el
// teclado cambia el alto visible de la página, y también se revisa.
function revisarTeclado() {
  setTimeout(() => (escribiendo.value = hayCampoDeTextoActivo()))
}

onMounted(() => {
  document.addEventListener('click', alHacerClic)
  document.addEventListener('keydown', alPresionarTecla)
  document.addEventListener('focusin', revisarTeclado)
  document.addEventListener('focusout', revisarTeclado)
  window.visualViewport?.addEventListener('resize', revisarTeclado)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', alHacerClic)
  document.removeEventListener('keydown', alPresionarTecla)
  document.removeEventListener('focusin', revisarTeclado)
  document.removeEventListener('focusout', revisarTeclado)
  window.visualViewport?.removeEventListener('resize', revisarTeclado)
})
watch(() => route.fullPath, () => (menuAbierto.value = false))

async function onLogout() {
  menuAbierto.value = false
  await auth.logout()
  router.push({ name: 'portal-login' })
}
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <header
      class="shrink-0"
      style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%); padding-top: env(safe-area-inset-top);"
    >
      <div class="flex h-16 items-center gap-3 px-4 sm:px-8">
        <router-link :to="{ name: 'portal-home' }" class="flex items-center gap-2.5 shrink-0">
          <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-blanco/10 p-1">
            <img :src="logoUmag" alt="UMAG" class="h-full w-full rounded-lg object-cover" />
          </span>
          <span class="font-serif text-[15px] font-bold text-white">Biblioteca UMAG</span>
        </router-link>

        <nav class="hidden md:flex flex-1 justify-center" aria-label="Portal del usuario">
          <div class="flex items-center gap-1 rounded-xl bg-blanco/10 p-1">
            <router-link
              v-for="link in links"
              :key="link.name"
              :to="{ name: link.name }"
              class="rounded-lg px-4 py-1.5 text-[13px] font-semibold transition-colors"
              :class="route.name === link.name ? 'tema-claro bg-white text-indigo-900 shadow-sm' : 'text-indigo-100 hover:bg-blanco/10 hover:text-white'"
            >
              {{ link.label }}
            </router-link>
          </div>
        </nav>

        <div class="flex-1 md:hidden"></div>

        <InterruptorTema />

        <div ref="menu" class="relative shrink-0">
          <button
            type="button"
            class="flex items-center gap-2.5 rounded-full sm:border sm:border-white/20 sm:bg-blanco/10 sm:py-1 sm:pl-1 sm:pr-3 sm:hover:bg-blanco/15 transition-colors"
            aria-haspopup="menu"
            :aria-expanded="menuAbierto"
            @click="menuAbierto = !menuAbierto"
          >
            <span class="flex h-9 w-9 sm:h-8 sm:w-8 items-center justify-center rounded-full bg-[#b08d57] text-xs font-bold text-[#1a2430]">
              {{ iniciales }}
            </span>
            <span class="hidden sm:block text-left leading-tight">
              <span class="block text-[13px] font-semibold text-white">{{ auth.usuario?.nombre }} {{ auth.usuario?.apellido }}</span>
              <span class="block text-[11px] text-indigo-200 capitalize">{{ auth.usuario?.tipo }}</span>
            </span>
            <svg class="hidden sm:block h-4 w-4 text-indigo-200 transition-transform" :class="menuAbierto ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
          </button>

          <div
            v-if="menuAbierto"
            role="menu"
            class="absolute right-0 z-50 mt-2 w-60 rounded-xl border border-gray-200 bg-white p-1.5 shadow-lg"
          >
            <div class="px-3 py-2">
              <p class="text-sm font-semibold text-gray-900">{{ auth.usuario?.nombre }} {{ auth.usuario?.apellido }}</p>
              <p class="text-xs text-gray-500"><span class="capitalize">{{ auth.usuario?.tipo }}</span> · {{ auth.usuario?.rut }}</p>
            </div>
            <div class="my-1 border-t border-gray-100"></div>
            <router-link
              v-for="item in menuCuenta"
              :key="item.name"
              :to="{ name: item.name }"
              role="menuitem"
              class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
            >
              <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
              </svg>
              {{ item.label }}
            </router-link>
            <div class="my-1 border-t border-gray-100"></div>
            <button
              type="button"
              role="menuitem"
              class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 transition-colors"
              @click="onLogout"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
              Cerrar sesión
            </button>
          </div>
        </div>
      </div>
      <slot name="cabecera" />
    </header>
    <main class="flex-1 p-6 pb-28 md:pb-6 bg-gradient-to-b from-[#F7F8FC] to-[#ffffff] to-60% dark:from-[#12152b] dark:to-[#0e1124]">
      <slot />
    </main>

    <nav
      v-show="!escribiendo"
      class="md:hidden fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white/95 backdrop-blur"
      style="padding-bottom: env(safe-area-inset-bottom);"
      aria-label="Navegación del portal"
    >
      <div class="grid grid-cols-5 items-end px-1 pt-1.5 pb-2">
        <router-link
          v-for="item in barraInferior"
          :key="item.name"
          :to="{ name: item.name }"
          class="flex flex-col items-center gap-1 text-[11px] font-semibold transition-colors"
          :class="route.name === item.name ? 'text-indigo-700' : 'text-gray-500 hover:text-gray-800'"
        >
          <span
            v-if="item.central"
            class="-mt-7 flex h-14 w-14 items-center justify-center rounded-2xl text-white shadow-lg shadow-indigo-900/30 ring-4 ring-white dark:ring-[#181c30]"
            style="background: linear-gradient(135deg, #3B28A3 0%, #4F46E5 100%);"
          >
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
            </svg>
          </span>
          <svg v-else class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
            <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
          </svg>
          {{ item.label }}
        </router-link>
      </div>
    </nav>
  </div>
</template>
