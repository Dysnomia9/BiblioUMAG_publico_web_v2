<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import api from '@/services/api'
import { STAFF_SHORTCUTS, type StaffShortcutColor } from '@/composables/useStaffShortcuts'
import { useAuthStore } from '@/stores/auth'
import { itemPorRuta } from '@/navegacion'
import type { ResumenDashboard } from '@/types'

const resumenVacio: ResumenDashboard = {
  usuariosActivos: 0,
  entradasHoy: 0,
  personasEnSala: 0,
  aforo: 0,
  prestamosActivos: 0,
  prestamosAtrasados: 0,
  ultimasEntradas: [],
  ultimosPrestamos: [],
}
const resumen = ref<ResumenDashboard>({ ...resumenVacio })
const apiError = ref(false)

const auth = useAuthStore()
// Solo los accesos a módulos que el rol de esta cuenta puede abrir.
const shortcuts = computed(() => STAFF_SHORTCUTS.filter((s) => auth.puedeAlguno(itemPorRuta(s.name)?.permiso)))

// Botones de acceso: franja arriba, un tinte suave que baja desde ella, el ícono en un
// cuadro del color y «Abrir módulo» en el mismo tono.
const COLORES_ACCESO: Record<StaffShortcutColor, { franja: string; tinte: string; icono: string; enlace: string; borde: string }> = {
  emerald: { franja: 'bg-emerald-400', tinte: 'from-emerald-50/80', icono: 'bg-emerald-400 shadow-emerald-400/30', enlace: 'text-emerald-700', borde: 'hover:border-emerald-300' },
  indigo: { franja: 'bg-indigo-400', tinte: 'from-indigo-50/80', icono: 'bg-indigo-400 shadow-indigo-400/30', enlace: 'text-indigo-700', borde: 'hover:border-indigo-300' },
  amber: { franja: 'bg-amber-400', tinte: 'from-amber-50/80', icono: 'bg-amber-400 shadow-amber-400/30', enlace: 'text-amber-700', borde: 'hover:border-amber-300' },
  violet: { franja: 'bg-violet-400', tinte: 'from-violet-50/80', icono: 'bg-violet-400 shadow-violet-400/30', enlace: 'text-violet-700', borde: 'hover:border-violet-300' },
}

// Línea morada en el borde superior de las dos tarjetas grandes.
const FRANJA = 'linear-gradient(90deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%)'

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
      <!-- Encabezado: título, fecha y rol, y las dos cifras del día -->
      <section class="mb-4 overflow-hidden rounded-2xl border border-indigo-100 bg-gradient-to-br from-white via-white to-indigo-50/60 shadow-sm">
        <div class="h-1" :style="{ background: FRANJA }" aria-hidden="true"></div>
        <div class="flex flex-col gap-4 p-4 sm:px-5 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h1 class="font-serif text-xl font-bold text-gray-900 sm:text-2xl">Menú Inicio</h1>
            <p class="mt-1 max-w-xl text-sm text-gray-600">Desde aquí puedes gestionar los módulos principales de la Biblioteca UMAG.</p>
            <div class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
              <span class="flex items-center gap-2 font-medium text-indigo-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                {{ fechaHoy }}
              </span>
              <span v-if="auth.staff?.rol_nombre" class="rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-700">
                Tu rol: {{ auth.staff.rol_nombre }}
              </span>
            </div>
          </div>

          <div class="grid gap-3 sm:grid-cols-2 lg:flex lg:shrink-0">
            <div class="flex items-center gap-3 rounded-2xl border border-gray-200 border-l-4 border-l-emerald-400 bg-white px-4 py-3 shadow-sm lg:w-52">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1a4 4 0 100-8 4 4 0 000 8zm6 3a4 4 0 00-3-3.87M9 12a4 4 0 100-8 4 4 0 000 8z" />
                </svg>
              </span>
              <div class="min-w-0">
                <p class="flex items-start gap-1.5 text-[11px] font-semibold uppercase leading-snug tracking-wider text-gray-600">
                  <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500 motion-safe:animate-pulse" aria-hidden="true"></span>
                  Personas en biblioteca
                </p>
                <p class="mt-0.5 text-xl font-bold tabular-nums text-gray-900">
                  {{ resumen.personasEnSala }} <span v-if="resumen.aforo" class="text-sm font-normal text-gray-400">/ {{ resumen.aforo }}</span>
                </p>
              </div>
            </div>

            <div class="flex items-center gap-3 rounded-2xl border border-gray-200 border-l-4 border-l-indigo-400 bg-white px-4 py-3 shadow-sm lg:w-52">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-indigo-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14M5 5v14" />
                </svg>
              </span>
              <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase leading-snug tracking-wider text-gray-600">Entradas hoy</p>
                <p class="mt-0.5 text-xl font-bold tabular-nums text-gray-900">{{ resumen.entradasHoy }}</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <ApiErrorBanner v-if="apiError" />

      <!-- Accesos rápidos: los módulos de F1 a F4 que el rol puede abrir -->
      <section v-if="shortcuts.length" class="mb-4 overflow-hidden rounded-2xl border border-indigo-100 bg-white shadow-[0_12px_32px_-16px_rgb(67_56_202/0.25)]">
        <div class="h-1" :style="{ background: FRANJA }" aria-hidden="true"></div>
        <div class="border-b border-indigo-100/70 bg-indigo-50/50 px-4 py-3 sm:px-5">
          <div class="flex items-center gap-3">
            <span class="h-6 w-1 shrink-0 rounded-full bg-indigo-600" aria-hidden="true"></span>
            <h2 class="font-serif text-lg font-bold text-gray-900">Accesos rápidos</h2>
          </div>
          <p class="ml-4 text-xs text-gray-600">Selecciona un módulo para comenzar</p>
        </div>

        <div class="p-3 sm:p-4">
          <div class="grid grid-cols-1 gap-3 rounded-2xl border border-gray-100 bg-gray-50/70 p-2.5 sm:grid-cols-2 xl:grid-cols-4">
            <router-link
              v-for="s in shortcuts"
              :key="s.key"
              :to="{ name: s.name }"
              class="group relative flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-gradient-to-b to-white to-60% p-4 pt-5 shadow-sm transition-all hover:shadow-md motion-safe:hover:-translate-y-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
              :class="[COLORES_ACCESO[s.color].tinte, COLORES_ACCESO[s.color].borde]"
            >
              <span class="absolute inset-x-0 top-0 h-1" :class="COLORES_ACCESO[s.color].franja" aria-hidden="true"></span>
              <span class="flex items-start justify-between gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl text-white shadow-lg" :class="COLORES_ACCESO[s.color].icono">
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="s.icon" />
                  </svg>
                </span>
                <kbd class="min-w-[2rem] rounded-md border border-gray-200 bg-white px-2 py-0.5 text-center font-sans text-[11px] font-semibold text-gray-700 shadow-[0_2px_0_rgb(15_23_42/0.07)]">{{ s.key }}</kbd>
              </span>
              <span class="mt-2.5 text-base font-semibold text-gray-900">{{ s.label }}</span>
              <span class="mt-1.5 inline-flex items-center gap-1.5 text-xs font-semibold" :class="COLORES_ACCESO[s.color].enlace">
                Abrir módulo
                <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
              </span>
            </router-link>
          </div>
        </div>
      </section>

      <p class="text-center text-xs text-gray-400 mt-auto pt-6 pb-2">
        © {{ new Date().getFullYear() }} Biblioteca UMAG - Universidad de Magallanes. Todos los derechos reservados.
      </p>
    </div>
  </StaffLayout>
</template>
