<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import LibrosModuloNav from '@/components/libros/LibrosModuloNav.vue'
import api from '@/services/api'
import type { EjemplarEstadoHistorial } from '@/types'

const route = useRoute()

const historial = ref<EjemplarEstadoHistorial[]>([])
const cargando = ref(true)
const apiError = ref(false)

const filtros = reactive({
  q: '',
  lote_id: (route.query.lote_id as string) || '',
  desde: '',
  hasta: '',
})

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<EjemplarEstadoHistorial[]>('/ejemplares/historial-estado', {
      params: {
        q: filtros.q.trim() || undefined,
        lote_id: filtros.lote_id.trim() || undefined,
        desde: filtros.desde || undefined,
        hasta: filtros.hasta || undefined,
      },
    })
    historial.value = data
    apiError.value = false
  } catch {
    apiError.value = true
    historial.value = []
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

let timer: ReturnType<typeof setTimeout> | undefined
function onFiltroInput() {
  clearTimeout(timer)
  timer = setTimeout(cargar, 250)
}

function etiquetaEstado(historial: EjemplarEstadoHistorial, cual: 'anterior' | 'nuevo') {
  const estado = cual === 'anterior' ? historial.estado_anterior : historial.estado_nuevo
  if (estado === 'personalizado') {
    const nombre = cual === 'anterior' ? historial.estado_personalizado_anterior?.nombre : historial.estado_personalizado_nuevo?.nombre
    return nombre ? `Personalizado: ${nombre}` : 'Personalizado'
  }
  return estado
}
</script>

<template>
  <StaffLayout>
    <LibrosModuloNav actual="historial-estado-libro">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Historial de Estado</h1>
          <p class="text-sm text-white/60 mt-1">Todos los cambios de estado de ejemplares, individuales y masivos</p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="bg-white rounded-xl shadow-md p-4 mb-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <input
          v-model="filtros.q"
          @input="onFiltroInput"
          placeholder="Título o código de barras"
          class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
        />
        <input
          v-model="filtros.lote_id"
          @input="onFiltroInput"
          placeholder="ID de lote (cambio masivo)"
          class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none font-mono"
        />
        <input v-model="filtros.desde" @change="cargar" type="date" class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
        <input v-model="filtros.hasta" @change="cargar" type="date" class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
      </div>

      <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="bg-gray-100 border-b-2 border-gray-200">
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Fecha</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Libro / Copia</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Cambio</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Staff</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Lote / Motivo</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              <tr v-for="h in historial" :key="h.id" class="hover:bg-indigo-50/40 transition-colors">
                <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ new Date(h.created_at).toLocaleString('es-CL') }}</td>
                <td class="px-4 py-3 text-sm text-gray-900">
                  {{ h.ejemplar?.libro?.titulo }}
                  <p class="text-xs text-gray-400 font-mono">Copia {{ h.ejemplar?.numero_copia }} · {{ h.ejemplar?.codigo_barras }}</p>
                </td>
                <td class="px-4 py-3 text-xs text-gray-600">
                  <span class="font-medium">{{ etiquetaEstado(h, 'anterior') }}</span>
                  →
                  <span class="font-medium text-indigo-700">{{ etiquetaEstado(h, 'nuevo') }}</span>
                </td>
                <td class="px-4 py-3 text-sm text-gray-600">{{ h.staff?.nombre ?? '—' }}</td>
                <td class="px-4 py-3 text-xs text-gray-500">
                  <span v-if="h.lote_id" class="font-mono">{{ h.lote_id.slice(0, 8) }}…</span>
                  <span v-if="h.motivo">{{ h.motivo }}</span>
                  <span v-if="!h.lote_id && !h.motivo">—</span>
                </td>
              </tr>
              <tr v-if="!cargando && !historial.length">
                <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-400">Sin cambios de estado registrados todavía.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </LibrosModuloNav>
  </StaffLayout>
</template>
