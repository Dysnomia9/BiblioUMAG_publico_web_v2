<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import logoUmag from '@/assets/logo-umag.png'

const auth = useUsuarioAuthStore()
const router = useRouter()
const route = useRoute()

const links = [
  { name: 'portal-home', label: 'Inicio', icon: 'M3 12l9-9 9 9M5 10v10a1 1 0 001 1h4a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1h4a1 1 0 001-1V10' },
  { name: 'portal-entrada', label: 'Entrada', icon: 'M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 3h3m3 0h-3m0 0v3m0-3v-3' },
  { name: 'portal-catalogo', label: 'Catálogo', icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253' },
  { name: 'portal-salas', label: 'Salas', icon: 'M4 6h16M4 12h16M4 18h7' },
]

async function onLogout() {
  await auth.logout()
  router.push({ name: 'portal-login' })
}
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <header
      class="shrink-0 flex items-center px-4 sm:px-8 gap-3 pb-3"
      style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%); border-bottom: 1px solid rgba(255,255,255,0.1); padding-top: max(1rem, calc(env(safe-area-inset-top) + 0.5rem)); min-height: 4.5rem;"
    >
      <router-link :to="{ name: 'portal-home' }" class="flex items-center gap-2.5 shrink-0">
        <div class="flex h-10 w-10 items-center justify-center rounded-xl border border-white/10 bg-white/5 overflow-hidden">
          <img :src="logoUmag" alt="UMAG" class="h-full w-full object-cover" />
        </div>
        <div class="leading-tight hidden sm:block">
          <p class="text-[13px] font-semibold text-slate-100">Biblioteca UMAG</p>
          <p class="text-[10px] uppercase tracking-wider text-slate-400">Portal del usuario</p>
        </div>
      </router-link>

      <div class="h-6 w-px bg-white/10 shrink-0 hidden md:block" />

      <nav class="flex-1 min-w-0 overflow-x-auto hidden md:block">
        <div class="flex items-center gap-1.5">
          <router-link
            v-for="link in links"
            :key="link.name"
            :to="{ name: link.name }"
            class="flex items-center gap-1.5 h-9 px-3 rounded-lg text-[13px] font-medium shrink-0 transition-colors"
            :class="route.name === link.name ? 'bg-[#b08d57] text-[#1a2430]' : 'text-slate-200 hover:bg-white/5'"
            :style="route.name === link.name ? 'box-shadow: inset 0 1px 0 rgba(255,255,255,0.28);' : ''"
          >
            <svg class="h-3.5 w-3.5 shrink-0" :class="route.name === link.name ? 'text-[#1a2430]' : 'text-slate-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="link.icon" />
            </svg>
            {{ link.label }}
          </router-link>
        </div>
      </nav>

      <div class="flex-1 md:hidden"></div>

      <div class="flex items-center gap-3 shrink-0">
        <div class="hidden sm:block text-right leading-tight">
          <p class="text-[13px] font-medium text-slate-100">{{ auth.usuario?.nombre }} {{ auth.usuario?.apellido }}</p>
          <p class="text-[11px] text-slate-400 capitalize">{{ auth.usuario?.tipo }}</p>
        </div>
        <div class="h-10 w-10 rounded-full bg-white/10 border border-white/10 flex items-center justify-center text-slate-100 font-medium text-sm">
          {{ (auth.usuario?.nombre ?? 'U').charAt(0) }}
        </div>
        <button
          @click="onLogout"
          class="text-slate-400 hover:text-slate-100 p-2 rounded-md hover:bg-white/5"
          aria-label="Cerrar sesión"
          title="Cerrar sesión"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
          </svg>
        </button>
      </div>
    </header>
    <main class="flex-1 p-6" style="background: linear-gradient(180deg, #F7F8FC 0%, #ffffff 60%);">
      <slot />
    </main>
  </div>
</template>
