<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import api from '@/services/api'
import type { MultasPendientesResumen } from '@/types'

const cargando = ref(true)
const apiError = ref(false)
const resumen = ref<MultasPendientesResumen | null>(null)
const busqueda = ref('')

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<MultasPendientesResumen>('/reportes/multas-pendientes')
    resumen.value = data
    apiError.value = false
  } catch {
    apiError.value = true
    resumen.value = null
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

function formatMonto(monto: number) {
  return new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP' }).format(monto)
}

const filtrados = computed(() => {
  if (!resumen.value) return []
  const q = busqueda.value.trim().toLowerCase()
  if (!q) return resumen.value.usuarios
  return resumen.value.usuarios.filter(
    (u) => `${u.nombre} ${u.apellido}`.toLowerCase().includes(q) || u.rut.toLowerCase().includes(q),
  )
})
</script>

<template>
  <StaffLayout>
    <div class="max-w-4xl mx-auto">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Multas Pendientes</h1>
          <p class="text-sm text-white/60 mt-1">Deuda por atraso agrupada por usuario, en todos los préstamos</p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div v-if="resumen" class="grid grid-cols-2 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-md p-5">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Usuarios con deuda</p>
          <p class="text-2xl font-bold text-gray-900">{{ resumen.total_usuarios }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-md p-5">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Monto total pendiente</p>
          <p class="text-2xl font-bold text-gray-900">{{ formatMonto(resumen.monto_total) }}</p>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-md p-4 mb-6">
        <input
          v-model="busqueda"
          type="text"
          placeholder="Buscar por nombre o RUT"
          class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
        />
      </div>

      <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="bg-gray-100 border-b-2 border-gray-200">
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Usuario</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">RUT</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide"># Préstamos con multa</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Monto total pendiente</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              <tr v-for="(u, idx) in filtrados" :key="u.usuario_id" :class="idx % 2 === 0 ? 'bg-white' : 'bg-biblioteca-50'">
                <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ u.nombre }} {{ u.apellido }}</td>
                <td class="px-6 py-3 text-sm font-mono text-gray-600">{{ u.rut }}</td>
                <td class="px-6 py-3 text-sm text-gray-600">{{ u.cantidad_prestamos }}</td>
                <td class="px-6 py-3 text-sm font-semibold text-red-700">{{ formatMonto(u.monto_total) }}</td>
              </tr>
              <tr v-if="!cargando && !filtrados.length">
                <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-400">Sin multas pendientes.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </StaffLayout>
</template>
