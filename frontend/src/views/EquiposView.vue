<script setup lang="ts">
import { onMounted, ref } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import type { Equipo } from '@/types'

const toast = useToast()

const equipos = ref<Equipo[]>([])
const cargando = ref(true)
const apiError = ref(false)

const codigoNuevo = ref('')
const codigoBarrasNuevo = ref('')
const tipoNuevo = ref<'audifonos' | 'notebook' | 'cargador'>('audifonos')
const creando = ref(false)

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<Equipo[]>('/equipos')
    equipos.value = data
    apiError.value = false
  } catch {
    apiError.value = true
    equipos.value = []
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

async function crearEquipo() {
  if (!codigoNuevo.value.trim()) {
    toast.error('Ingrese un código de inventario')
    return
  }
  if (!codigoBarrasNuevo.value.trim()) {
    toast.error('Ingrese un código de barras')
    return
  }
  creando.value = true
  try {
    await api.post('/equipos', {
      codigo_inventario: codigoNuevo.value.trim(),
      codigo_barras: codigoBarrasNuevo.value.trim(),
      tipo: tipoNuevo.value,
    })
    toast.success('Equipo registrado')
    codigoNuevo.value = ''
    codigoBarrasNuevo.value = ''
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo registrar el equipo')
  } finally {
    creando.value = false
  }
}

async function cambiarActivo(equipo: Equipo) {
  try {
    await api.patch(`/equipos/${equipo.id}/activo`, { activo: !equipo.activo })
    toast.success(equipo.activo ? 'Equipo dado de baja' : 'Equipo reactivado')
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo actualizar el equipo')
  }
}

const TIPO_LABELS: Record<string, string> = {
  audifonos: 'Audífonos',
  notebook: 'Notebook',
  cargador: 'Cargador',
}
</script>

<template>
  <StaffLayout>
    <div class="max-w-4xl mx-auto">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Equipos</h1>
          <p class="text-sm text-white/60 mt-1">Inventario de audífonos, notebooks y cargadores disponibles para préstamo</p>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-md p-6 mb-6">
        <h4 class="text-sm font-semibold text-gray-700 mb-3">Registrar equipo nuevo</h4>
        <div class="flex gap-3 flex-wrap items-end">
          <div class="flex-1 min-w-[180px]">
            <label class="block text-xs font-medium text-gray-600 mb-1">Nombre / identificador</label>
            <input
              v-model="codigoNuevo"
              placeholder="Ej: Notebook 04"
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none font-mono"
              @keydown.enter="crearEquipo"
            />
          </div>
          <div class="flex-1 min-w-[180px]">
            <label class="block text-xs font-medium text-gray-600 mb-1">Código de barras</label>
            <input
              v-model="codigoBarrasNuevo"
              placeholder="Ej: 7512000000123"
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none font-mono"
              @keydown.enter="crearEquipo"
            />
          </div>
          <div class="min-w-[160px]">
            <label class="block text-xs font-medium text-gray-600 mb-1">Tipo</label>
            <select
              v-model="tipoNuevo"
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
            >
              <option value="audifonos">Audífonos</option>
              <option value="notebook">Notebook</option>
              <option value="cargador">Cargador</option>
            </select>
          </div>
          <button
            @click="crearEquipo"
            :disabled="creando"
            class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium disabled:opacity-60"
          >
            {{ creando ? 'Guardando…' : 'Registrar' }}
          </button>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="bg-gray-100 border-b-2 border-gray-200">
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Nombre</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Código de barras</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Tipo</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Disponible</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Estado</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              <tr v-for="(e, idx) in equipos" :key="e.id" :class="idx % 2 === 0 ? 'bg-white' : 'bg-biblioteca-50'">
                <td class="px-6 py-3 text-sm font-mono text-gray-900">{{ e.codigo_inventario }}</td>
                <td class="px-6 py-3 text-sm font-mono text-gray-500">{{ e.codigo_barras }}</td>
                <td class="px-6 py-3 text-sm text-gray-600">{{ TIPO_LABELS[e.tipo] }}</td>
                <td class="px-6 py-3">
                  <span
                    class="text-xs px-2.5 py-1 rounded-full font-medium"
                    :class="e.disponible ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'"
                  >
                    {{ e.disponible ? 'Sí' : 'No' }}
                  </span>
                </td>
                <td class="px-6 py-3">
                  <span
                    class="text-xs px-2.5 py-1 rounded-full font-medium"
                    :class="e.activo ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-700'"
                  >
                    {{ e.activo ? 'Activo' : 'De baja' }}
                  </span>
                </td>
                <td class="px-6 py-3">
                  <button
                    @click="cambiarActivo(e)"
                    :disabled="e.activo && !e.disponible"
                    :title="e.activo && !e.disponible ? 'No se puede dar de baja un equipo actualmente prestado' : ''"
                    class="text-sm font-medium disabled:opacity-40 disabled:cursor-not-allowed"
                    :class="e.activo ? 'text-red-600 hover:text-red-700' : 'text-indigo-700 hover:text-indigo-800'"
                  >
                    {{ e.activo ? 'Dar de baja' : 'Reactivar' }}
                  </button>
                </td>
              </tr>
              <tr v-if="!cargando && !equipos.length">
                <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-400">Sin equipos registrados.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </StaffLayout>
</template>
