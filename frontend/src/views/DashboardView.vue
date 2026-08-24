<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import api from '@/services/api'
import { STAFF_SHORTCUTS, type StaffShortcutColor } from '@/composables/useStaffShortcuts'
import type { ResumenDashboard } from '@/types'

const router = useRouter()
const resumenVacio: ResumenDashboard = {
  usuariosActivos: 0,
  entradasHoy: 0,
  personasEnSala: 0,
  prestamosActivos: 0,
  prestamosAtrasados: 0,
  ultimasEntradas: [],
  ultimosPrestamos: [],
}
const resumen = ref<ResumenDashboard>({ ...resumenVacio })
const apiError = ref(false)
const aforo = 220

const shortcuts = STAFF_SHORTCUTS

const SHORTCUT_COLORS: Record<StaffShortcutColor, { icon: string; iconHover: string; border: string; topBorder: string }> = {
  emerald: { icon: 'bg-emerald-50 text-emerald-600', iconHover: 'group-hover:bg-emerald-600', border: 'hover:border-emerald-300', topBorder: 'border-t-emerald-400' },
  indigo: { icon: 'bg-indigo-50 text-indigo-600', iconHover: 'group-hover:bg-indigo-600', border: 'hover:border-indigo-300', topBorder: 'border-t-indigo-400' },
  amber: { icon: 'bg-amber-50 text-amber-600', iconHover: 'group-hover:bg-amber-600', border: 'hover:border-amber-300', topBorder: 'border-t-amber-400' },
  violet: { icon: 'bg-violet-50 text-violet-600', iconHover: 'group-hover:bg-violet-600', border: 'hover:border-violet-300', topBorder: 'border-t-violet-400' },
}

const fechaHoy = computed(() => {
  const str = new Date().toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
  return str.charAt(0).toUpperCase() + str.slice(1)
})

onMounted(async () => {
  try {
    const { data } = await api.get<ResumenDashboard>('/dashboard/resumen')
    resumen.value = data
    apiError.value = false
  } catch {
    apiError.value = true
    resumen.value = { ...resumenVacio }
  }
})
</script>

<template>
  <StaffLayout>
    <div class="max-w-6xl mx-auto min-h-[calc(100vh-8rem)] flex flex-col">
      <!-- Hero -->
      <div class="rounded-2xl border-2 border-indigo-200 bg-gradient-to-br from-indigo-50 via-white to-purple-50/60 p-6 sm:p-8 mb-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
          <div>
            <h1 class="text-2xl sm:text-3xl font-serif font-bold text-gray-900 mb-2">Menú Inicio</h1>
            <p class="text-sm text-gray-500 mb-4 max-w-md">
              Desde aquí puedes gestionar los módulos principales de la Biblioteca UMAG.
            </p>
            <div class="flex items-center gap-2 text-sm font-medium text-indigo-700">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              {{ fechaHoy }}
            </div>
          </div>

          <div class="flex flex-wrap gap-4">
            <div class="flex items-center gap-3 bg-white rounded-xl border border-gray-100 border-l-4 border-l-emerald-400 shadow-sm px-5 py-4">
              <div class="h-11 w-11 shrink-0 rounded-full bg-emerald-50 flex items-center justify-center text-emerald-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1a4 4 0 100-8 4 4 0 000 8zm6 3a4 4 0 00-3-3.87M9 12a4 4 0 100-8 4 4 0 000 8z" />
                </svg>
              </div>
              <div>
                <p class="text-[11px] font-medium uppercase tracking-wide text-gray-400 flex items-center gap-1.5">
                  <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                  Personas en Biblioteca
                </p>
                <p class="text-2xl font-bold text-gray-900 tabular-nums">
                  {{ resumen.personasEnSala }} <span class="text-sm font-normal text-gray-400">/ {{ aforo }}</span>
                </p>
              </div>
            </div>

            <div class="flex items-center gap-3 bg-white rounded-xl border border-gray-100 border-l-4 border-l-indigo-400 shadow-sm px-5 py-4">
              <div class="h-11 w-11 shrink-0 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14M5 5v14" />
                </svg>
              </div>
              <div>
                <p class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Entradas hoy</p>
                <p class="text-2xl font-bold text-gray-900 tabular-nums">{{ resumen.entradasHoy }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <!-- Accesos rápidos -->
      <div class="bg-white rounded-2xl border-2 border-gray-200 shadow-sm p-6 mb-6">
        <div class="flex items-center gap-3 mb-1">
          <span class="w-1 h-6 rounded-full bg-indigo-600 shrink-0"></span>
          <h2 class="text-lg font-bold text-gray-900">Accesos rápidos</h2>
        </div>
        <p class="text-sm text-gray-400 mb-6 ml-4">Selecciona un módulo para comenzar</p>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
          <button
            v-for="shortcut in shortcuts"
            :key="shortcut.key"
            @click="router.push({ name: shortcut.name })"
            class="group flex flex-col items-center rounded-xl border border-t-4 border-gray-200 bg-white p-6 text-center transition-all hover:shadow-md active:scale-[0.98]"
            :class="[SHORTCUT_COLORS[shortcut.color].border, SHORTCUT_COLORS[shortcut.color].topBorder]"
          >
            <div
              class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl transition-colors group-hover:text-white"
              :class="[SHORTCUT_COLORS[shortcut.color].icon, SHORTCUT_COLORS[shortcut.color].iconHover]"
            >
              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" :d="shortcut.icon" />
              </svg>
            </div>
            <div class="text-[15px] font-semibold text-gray-900">{{ shortcut.label }}</div>
            <kbd class="mt-4 rounded border border-gray-200 bg-gray-100 px-2.5 py-1 text-[11px] font-semibold text-gray-500">
              {{ shortcut.key }}
            </kbd>
          </button>
        </div>
      </div>

      <p class="text-center text-xs text-gray-400 mt-auto pt-6 pb-2">
        © {{ new Date().getFullYear() }} Biblioteca UMAG - Universidad de Magallanes. Todos los derechos reservados.
      </p>
    </div>
  </StaffLayout>
</template>
