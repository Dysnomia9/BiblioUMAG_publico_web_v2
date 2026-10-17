<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import PortalLayout from '@/components/layout/PortalLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import apiUsuario from '@/services/apiUsuario'
import type { IngresosPortal } from '@/types'

const datos = ref<IngresosPortal | null>(null)
const apiError = ref(false)

onMounted(async () => {
  try {
    const { data } = await apiUsuario.get<IngresosPortal>('/mi/ingresos')
    datos.value = data
  } catch {
    apiError.value = true
  }
})

const ORIGEN = {
  qr: { texto: 'Con el código QR', clases: 'bg-indigo-50 text-indigo-600', icono: 'M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M9 9h6v6H9V9z' },
  rut: { texto: 'Con tu RUT, en el portal', clases: 'bg-amber-50 text-amber-700', icono: 'M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z' },
  meson: { texto: 'Registrado en el mesón', clases: 'bg-emerald-50 text-emerald-700', icono: 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z' },
}

function tituloDia(fecha: string) {
  const hoy = new Date().toLocaleDateString('sv-SE')
  const ayer = new Date(Date.now() - 86400000).toLocaleDateString('sv-SE')
  if (fecha === hoy) return 'Hoy'
  if (fecha === ayer) return 'Ayer'
  const texto = new Date(`${fecha}T12:00:00`).toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long' })
  return texto.charAt(0).toUpperCase() + texto.slice(1)
}

const grupos = computed(() => {
  const porDia = new Map<string, IngresosPortal['entradas']>()
  for (const entrada of datos.value?.entradas ?? []) {
    porDia.set(entrada.fecha, [...(porDia.get(entrada.fecha) ?? []), entrada])
  }
  return [...porDia.entries()].map(([fecha, entradas]) => ({ fecha, titulo: tituloDia(fecha), entradas }))
})
</script>

<template>
  <PortalLayout>
    <div class="max-w-2xl mx-auto">
      <div class="mb-5">
        <h1 class="text-2xl sm:text-3xl font-serif font-bold tracking-tight text-gray-900">Mis ingresos</h1>
        <p class="mt-1 text-sm text-gray-500">Las veces que registraste tu entrada a la biblioteca.</p>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <section v-if="datos" class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <p class="text-sm font-medium text-gray-500">Este mes</p>
        <p class="mt-1 flex items-baseline gap-2">
          <span class="text-4xl font-serif font-bold text-gray-900">{{ datos.este_mes }}</span>
          <span class="text-sm text-gray-500">{{ datos.este_mes === 1 ? 'ingreso' : 'ingresos' }}</span>
        </p>
        <p class="mt-1 text-xs text-gray-400">Abajo se muestran tus últimos {{ datos.entradas.length }} registros.</p>
      </section>
      <p v-else-if="!apiError" class="py-6 text-sm text-gray-400">Cargando tus ingresos…</p>

      <div v-for="grupo in grupos" :key="grupo.fecha" class="mt-5">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ grupo.titulo }}</p>
        <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white shadow-sm">
          <li v-for="entrada in grupo.entradas" :key="entrada.id" class="flex items-center gap-3 px-4 py-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl" :class="ORIGEN[entrada.origen].clases">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ORIGEN[entrada.origen].icono" />
              </svg>
            </span>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-900">{{ entrada.hora }}</p>
              <p class="text-xs text-gray-500">{{ ORIGEN[entrada.origen].texto }}</p>
            </div>
          </li>
        </ul>
      </div>

      <p v-if="datos && !datos.entradas.length" class="mt-5 rounded-2xl border border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-400">
        Todavía no registras ingresos. Márcalos desde <strong>Entrada</strong> cuando llegues a la biblioteca.
      </p>
    </div>
  </PortalLayout>
</template>
