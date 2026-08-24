<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

type NombreModuloLibro =
  | 'catalogacion-libros'
  | 'estado-libro'
  | 'listado-libros'
  | 'cambio-masivo-estado'
  | 'historial-estado-libro'
  | 'historial-prestamos-libro'

const props = defineProps<{ actual: NombreModuloLibro }>()

const auth = useAuthStore()

type Color = 'violet' | 'sky' | 'emerald' | 'amber' | 'indigo' | 'rose'

const MODULOS: { name: NombreModuloLibro; label: string; icon: string; color: Color; adminOnly: boolean }[] = [
  { name: 'catalogacion-libros', label: 'Catalogación de Libros', icon: 'M12 4.5v15m0-15c-2.485 0-4.5.672-6 2.25v13.5c1.5-1.578 3.515-2.25 6-2.25s4.5.672 6 2.25V6.75c-1.5-1.578-3.515-2.25-6-2.25z', color: 'violet', adminOnly: true },
  { name: 'estado-libro', label: 'Estado de Libro', icon: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', color: 'sky', adminOnly: false },
  { name: 'listado-libros', label: 'Listado de Libros', icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253', color: 'emerald', adminOnly: false },
  { name: 'cambio-masivo-estado', label: 'Cambio Masivo de Estado', icon: 'M4 7h16M4 12h10m-10 5h16', color: 'amber', adminOnly: true },
  { name: 'historial-estado-libro', label: 'Historial de Estado', icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', color: 'indigo', adminOnly: false },
  { name: 'historial-prestamos-libro', label: 'Historial de Libros', icon: 'M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25', color: 'rose', adminOnly: false },
]

const COLORES: Record<Color, { icon: string; iconHover: string; border: string; topBorder: string }> = {
  violet: { icon: 'bg-violet-50 text-violet-600', iconHover: 'group-hover:bg-violet-600', border: 'hover:border-violet-300', topBorder: 'border-t-violet-400' },
  sky: { icon: 'bg-sky-50 text-sky-600', iconHover: 'group-hover:bg-sky-600', border: 'hover:border-sky-300', topBorder: 'border-t-sky-400' },
  emerald: { icon: 'bg-emerald-50 text-emerald-600', iconHover: 'group-hover:bg-emerald-600', border: 'hover:border-emerald-300', topBorder: 'border-t-emerald-400' },
  amber: { icon: 'bg-amber-50 text-amber-600', iconHover: 'group-hover:bg-amber-600', border: 'hover:border-amber-300', topBorder: 'border-t-amber-400' },
  indigo: { icon: 'bg-indigo-50 text-indigo-600', iconHover: 'group-hover:bg-indigo-600', border: 'hover:border-indigo-300', topBorder: 'border-t-indigo-400' },
  rose: { icon: 'bg-rose-50 text-rose-600', iconHover: 'group-hover:bg-rose-600', border: 'hover:border-rose-300', topBorder: 'border-t-rose-400' },
}

const relacionados = computed(() =>
  MODULOS.filter((m) => m.name !== props.actual && (!m.adminOnly || auth.staff?.rol === 'admin')),
)
const izquierda = computed(() => relacionados.value[0])
const derecha = computed(() => relacionados.value[1])
</script>

<template>
  <div class="max-w-6xl mx-auto lg:grid lg:grid-cols-[7.5rem_1fr_7.5rem] lg:gap-5 lg:items-start">
    <aside class="hidden lg:flex lg:flex-col lg:gap-1.5 lg:sticky lg:top-6">
      <p v-if="izquierda" class="text-center text-[10px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Ver también</p>
      <router-link
        v-if="izquierda"
        :to="{ name: izquierda.name }"
        class="group flex flex-col items-center gap-2 rounded-xl border border-t-4 border-gray-200 bg-white p-3 text-center shadow-sm transition-all hover:shadow-md active:scale-[0.98]"
        :class="[COLORES[izquierda.color].border, COLORES[izquierda.color].topBorder]"
      >
        <div
          class="flex h-9 w-9 items-center justify-center rounded-lg transition-colors group-hover:text-white"
          :class="[COLORES[izquierda.color].icon, COLORES[izquierda.color].iconHover]"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" :d="izquierda.icon" />
          </svg>
        </div>
        <span class="text-[11px] font-semibold text-gray-700 leading-tight">{{ izquierda.label }}</span>
      </router-link>
    </aside>

    <div class="min-w-0">
      <div v-if="relacionados.length" class="lg:hidden flex flex-wrap gap-2 mb-5">
        <router-link
          v-for="m in relacionados"
          :key="m.name"
          :to="{ name: m.name }"
          class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 shadow-sm transition-colors hover:bg-gray-50"
        >
          <svg class="h-3.5 w-3.5" :class="COLORES[m.color].icon.split(' ')[1]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="m.icon" />
          </svg>
          {{ m.label }}
        </router-link>
      </div>

      <slot />
    </div>

    <aside class="hidden lg:flex lg:flex-col lg:gap-1.5 lg:sticky lg:top-6">
      <p v-if="derecha" class="text-center text-[10px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Ver también</p>
      <router-link
        v-if="derecha"
        :to="{ name: derecha.name }"
        class="group flex flex-col items-center gap-2 rounded-xl border border-t-4 border-gray-200 bg-white p-3 text-center shadow-sm transition-all hover:shadow-md active:scale-[0.98]"
        :class="[COLORES[derecha.color].border, COLORES[derecha.color].topBorder]"
      >
        <div
          class="flex h-9 w-9 items-center justify-center rounded-lg transition-colors group-hover:text-white"
          :class="[COLORES[derecha.color].icon, COLORES[derecha.color].iconHover]"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" :d="derecha.icon" />
          </svg>
        </div>
        <span class="text-[11px] font-semibold text-gray-700 leading-tight">{{ derecha.label }}</span>
      </router-link>
    </aside>
  </div>
</template>
