<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import logoUmag from '@/assets/logo-umag.png'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const generalLinks = [
  { name: 'dashboard', label: 'Inicio', icon: 'M3 12l9-9 9 9M5 10v10a1 1 0 001 1h4a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1h4a1 1 0 001-1V10', shortcut: '1' },
]

const moduleLinks = [
  { name: 'entrada', label: 'Entradas', icon: 'M11 16l-4-4m0 0l4-4m-4 4h14M5 5v14', shortcut: '2' },
  { name: 'prestamo', label: 'Préstamos', icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253', shortcut: '3' },
  { name: 'salas', label: 'Salas', icon: 'M4 6h16M4 12h16M4 18h7', shortcut: '5' },
  { name: 'reportes', label: 'Reportes', icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z', shortcut: '6' },
]

type AdminColor = 'indigo' | 'amber' | 'emerald' | 'violet' | 'sky' | 'rose' | 'teal' | 'red' | 'orange' | 'fuchsia' | 'slate'

const adminLinks: { name: string; label: string; icon: string; adminOnly: boolean; color: AdminColor }[] = [
  { name: 'usuarios', label: 'Usuarios', icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1a4 4 0 100-8 4 4 0 000 8zm6 3a4 4 0 00-3-3.87M9 12a4 4 0 100-8 4 4 0 000 8z', adminOnly: false, color: 'indigo' },
  { name: 'listado-prestamos', label: 'Listado Préstamos', icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', adminOnly: false, color: 'amber' },
  { name: 'listado-libros', label: 'Listado Libros', icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253', adminOnly: false, color: 'emerald' },
  { name: 'catalogacion-libros', label: 'Catalogación de Libros', icon: 'M12 4.5v15m0-15c-2.485 0-4.5.672-6 2.25v13.5c1.5-1.578 3.515-2.25 6-2.25s4.5.672 6 2.25V6.75c-1.5-1.578-3.515-2.25-6-2.25z', adminOnly: true, color: 'violet' },
  { name: 'estado-libro', label: 'Estado de Libro', icon: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', adminOnly: false, color: 'sky' },
  { name: 'cambio-masivo-estado', label: 'Cambio Masivo de Estado', icon: 'M4 7h16M4 12h10m-10 5h16', adminOnly: true, color: 'orange' },
  { name: 'historial-estado-libro', label: 'Historial de Estado', icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', adminOnly: false, color: 'slate' },
  { name: 'historial-prestamos-libro', label: 'Historial de Libros', icon: 'M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25', adminOnly: false, color: 'fuchsia' },
  { name: 'codigo-qr', label: 'Código QR', icon: 'M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 3h3m3 0h-3m0 0v3m0-3v-3', adminOnly: false, color: 'rose' },
  { name: 'equipos', label: 'Equipos', icon: 'M20.25 7.5l-8.25 4.5L3.75 7.5M3.75 7.5l8.25-4.5 8.25 4.5M3.75 7.5v9l8.25 4.5m0-9v9m0-9l8.25-4.5m-8.25 4.5l8.25 4.5m0 0v-9', adminOnly: true, color: 'teal' },
  { name: 'multas-pendientes', label: 'Multas Pendientes', icon: 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-8.99 3.75h.008v.008h-.008v-.008z', adminOnly: false, color: 'red' },
  { name: 'administracion', label: 'Administración', icon: 'M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 011.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 01-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 01-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.107-1.204l-.527-.738a1.125 1.125 0 01.12-1.45l.773-.773a1.125 1.125 0 011.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z', adminOnly: true, color: 'slate' },
  { name: 'registro-absoluto', label: 'Registro Absoluto', icon: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01', adminOnly: true, color: 'indigo' },
]

const ADMIN_COLORS: Record<AdminColor, { icon: string; iconHover: string; border: string; topBorder: string }> = {
  indigo: { icon: 'bg-indigo-50 text-indigo-600', iconHover: 'group-hover:bg-indigo-600', border: 'hover:border-indigo-300', topBorder: 'border-t-indigo-400' },
  amber: { icon: 'bg-amber-50 text-amber-600', iconHover: 'group-hover:bg-amber-600', border: 'hover:border-amber-300', topBorder: 'border-t-amber-400' },
  emerald: { icon: 'bg-emerald-50 text-emerald-600', iconHover: 'group-hover:bg-emerald-600', border: 'hover:border-emerald-300', topBorder: 'border-t-emerald-400' },
  violet: { icon: 'bg-violet-50 text-violet-600', iconHover: 'group-hover:bg-violet-600', border: 'hover:border-violet-300', topBorder: 'border-t-violet-400' },
  sky: { icon: 'bg-sky-50 text-sky-600', iconHover: 'group-hover:bg-sky-600', border: 'hover:border-sky-300', topBorder: 'border-t-sky-400' },
  rose: { icon: 'bg-rose-50 text-rose-600', iconHover: 'group-hover:bg-rose-600', border: 'hover:border-rose-300', topBorder: 'border-t-rose-400' },
  teal: { icon: 'bg-teal-50 text-teal-600', iconHover: 'group-hover:bg-teal-600', border: 'hover:border-teal-300', topBorder: 'border-t-teal-400' },
  red: { icon: 'bg-red-50 text-red-600', iconHover: 'group-hover:bg-red-600', border: 'hover:border-red-300', topBorder: 'border-t-red-400' },
  orange: { icon: 'bg-orange-50 text-orange-600', iconHover: 'group-hover:bg-orange-600', border: 'hover:border-orange-300', topBorder: 'border-t-orange-400' },
  fuchsia: { icon: 'bg-fuchsia-50 text-fuchsia-600', iconHover: 'group-hover:bg-fuchsia-600', border: 'hover:border-fuchsia-300', topBorder: 'border-t-fuchsia-400' },
  slate: { icon: 'bg-slate-100 text-slate-600', iconHover: 'group-hover:bg-slate-600', border: 'hover:border-slate-300', topBorder: 'border-t-slate-400' },
}

const adminLinksVisibles = () => adminLinks.filter((link) => !link.adminOnly || auth.staff?.rol === 'admin')

const adminActive = () => adminLinks.some((link) => link.name === route.name)

// El menú de gestión se muestra a pantalla completa (no un dropdown chico) —
// se bloquea el scroll del body mientras está abierto y se puede cerrar con Esc,
// con el botón de cerrar, o navegando a un módulo.
const menuGestionAbierto = ref(false)

function abrirMenuGestion() {
  menuGestionAbierto.value = true
  document.body.style.overflow = 'hidden'
}

function cerrarMenuGestion() {
  menuGestionAbierto.value = false
  document.body.style.overflow = ''
}

function onKeyDown(event: KeyboardEvent) {
  if (event.key === 'Escape' && menuGestionAbierto.value) cerrarMenuGestion()
}

onMounted(() => document.addEventListener('keydown', onKeyDown))
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeyDown)
  document.body.style.overflow = ''
})

async function onLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <header
    class="h-20 shrink-0 flex items-center px-4 sm:px-8 gap-4"
    style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%); border-bottom: 1px solid rgba(255,255,255,0.1);"
  >
    <!-- Logo -->
    <div class="flex items-center gap-2.5 shrink-0">
      <div class="flex h-10 w-10 items-center justify-center rounded-xl border border-white/10 bg-white/5 overflow-hidden">
        <img :src="logoUmag" alt="UMAG" class="h-full w-full object-cover" />
      </div>
      <div class="leading-tight hidden sm:block">
        <p class="text-[13px] font-semibold text-slate-100">Biblioteca UMAG</p>
        <p class="text-[10px] uppercase tracking-wider text-slate-400">Panel de administración</p>
      </div>
    </div>

    <div class="h-6 w-px bg-white/10 shrink-0 hidden md:block" />

    <!-- Navegación -->
    <nav class="flex-1 min-w-0 overflow-x-auto">
      <div class="flex items-center gap-1.5 w-full justify-start sm:justify-center">
        <router-link
          v-for="link in generalLinks"
          :key="link.name"
          :to="{ name: link.name }"
          class="flex items-center gap-1.5 h-9 px-3 rounded-lg text-[13px] font-medium shrink-0 transition-colors"
          :class="route.name === link.name
            ? 'bg-[#b08d57] text-[#1a2430]'
            : 'text-slate-200 hover:bg-white/5'"
          :style="route.name === link.name ? 'box-shadow: inset 0 1px 0 rgba(255,255,255,0.28);' : ''"
        >
          <svg class="h-3.5 w-3.5 shrink-0" :class="route.name === link.name ? 'text-[#1a2430]' : 'text-slate-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="link.icon" />
          </svg>
          {{ link.label }}
          <kbd
            class="text-[10px] leading-none rounded-md border px-1.5 py-0.5 font-bold"
            :class="route.name === link.name ? 'border-[#1a2430]/30 bg-[#1a2430]/15 text-[#1a2430]' : 'border-white/30 bg-white/10 text-white'"
          >{{ link.shortcut }}</kbd>
        </router-link>

        <div class="h-6 w-px bg-white/10 shrink-0 mx-1" />

        <router-link
          v-for="link in moduleLinks"
          :key="link.name"
          :to="{ name: link.name }"
          class="flex items-center gap-1.5 h-9 px-3 rounded-lg text-[13px] font-medium shrink-0 transition-colors"
          :class="route.name === link.name
            ? 'bg-[#b08d57] text-[#1a2430]'
            : 'text-slate-200 hover:bg-white/5'"
          :style="route.name === link.name ? 'box-shadow: inset 0 1px 0 rgba(255,255,255,0.28);' : ''"
        >
          <svg class="h-3.5 w-3.5 shrink-0" :class="route.name === link.name ? 'text-[#1a2430]' : 'text-slate-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="link.icon" />
          </svg>
          {{ link.label }}
          <kbd
            class="text-[10px] leading-none rounded-md border px-1.5 py-0.5 font-bold"
            :class="route.name === link.name ? 'border-[#1a2430]/30 bg-[#1a2430]/15 text-[#1a2430]' : 'border-white/30 bg-white/10 text-white'"
          >{{ link.shortcut }}</kbd>
        </router-link>

        <div class="h-6 w-px bg-white/10 shrink-0 mx-1" />

        <div class="relative shrink-0">
          <button
            @click="abrirMenuGestion"
            class="flex items-center gap-1.5 h-9 px-3 rounded-lg text-[13px] font-medium shrink-0 transition-colors"
            :class="adminActive()
              ? 'bg-[#b08d57] text-[#1a2430]'
              : 'text-slate-200 hover:bg-white/5'"
            :style="adminActive() ? 'box-shadow: inset 0 1px 0 rgba(255,255,255,0.28);' : ''"
          >
            <svg class="h-3.5 w-3.5 shrink-0" :class="adminActive() ? 'text-[#1a2430]' : 'text-slate-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            Menú Gestiones Admin
          </button>
        </div>
      </div>
    </nav>

    <Teleport to="body">
      <div
        v-if="menuGestionAbierto"
        class="fixed inset-0 z-[200] flex items-start justify-center overflow-y-auto bg-black/50 backdrop-blur-sm p-4 sm:p-8"
        @click.self="cerrarMenuGestion"
      >
        <div class="w-full max-w-3xl lg:max-w-4xl my-2 sm:my-6 rounded-2xl bg-white shadow-2xl">
          <div class="px-5 py-5 sm:px-7 sm:py-6">
            <div class="flex items-start justify-between gap-4 mb-5">
              <div class="flex items-center gap-2.5">
                <span class="w-1 h-6 rounded-full bg-indigo-600 shrink-0"></span>
                <div>
                  <h2 class="text-lg sm:text-xl font-serif font-bold text-gray-900">Menú de Gestión</h2>
                  <p class="text-xs text-gray-500 mt-0.5">Selecciona un módulo administrativo para continuar</p>
                </div>
              </div>
              <button
                @click="cerrarMenuGestion"
                class="shrink-0 h-8 w-8 flex items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 hover:text-gray-800 hover:border-gray-300 shadow-sm transition-colors"
                aria-label="Cerrar menú de gestión"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-3">
              <router-link
                v-for="link in adminLinksVisibles()"
                :key="link.name"
                :to="{ name: link.name }"
                @click="cerrarMenuGestion"
                class="group flex flex-col items-center rounded-xl border border-t-[3px] border-gray-200 bg-white p-3.5 text-center shadow-sm transition-all hover:shadow-md active:scale-[0.98]"
                :class="[ADMIN_COLORS[link.color].border, ADMIN_COLORS[link.color].topBorder, route.name === link.name ? 'ring-2 ring-indigo-400' : '']"
              >
                <div
                  class="mb-2 flex h-9 w-9 items-center justify-center rounded-xl transition-colors group-hover:text-white"
                  :class="[ADMIN_COLORS[link.color].icon, ADMIN_COLORS[link.color].iconHover]"
                >
                  <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="link.icon" />
                  </svg>
                </div>
                <div class="text-xs font-semibold text-gray-900 leading-tight">{{ link.label }}</div>
              </router-link>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <div class="h-6 w-px bg-white/10 shrink-0" />

    <!-- Usuario -->
    <div class="flex items-center gap-3 shrink-0">
      <div class="hidden sm:block text-right leading-tight">
        <p class="text-[13px] font-medium text-slate-100">{{ auth.staff?.nombre ?? 'Personal' }}</p>
        <p class="text-[11px] text-slate-400 capitalize">{{ auth.staff?.rol ?? 'staff' }}</p>
      </div>
      <div class="h-9 w-9 rounded-full bg-white/10 flex items-center justify-center text-slate-100 font-medium text-sm">
        {{ (auth.staff?.nombre ?? 'P').charAt(0) }}
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
</template>
