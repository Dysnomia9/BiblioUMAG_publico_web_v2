<script setup lang="ts">
import { ref } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import LibrosModuloNav from '@/components/libros/LibrosModuloNav.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import type { LibroHistorial } from '@/types'

const toast = useToast()

const busqueda = ref('')
const resultados = ref<LibroHistorial[]>([])
const buscando = ref(false)
const apiError = ref(false)
const buscado = ref(false)
const expandidos = ref<Set<number>>(new Set())

async function buscar() {
  buscando.value = true
  buscado.value = true
  try {
    const { data } = await api.get<LibroHistorial[]>('/libros/historial', {
      params: { q: busqueda.value.trim() || undefined },
    })
    resultados.value = data
    apiError.value = false
  } catch {
    apiError.value = true
    resultados.value = []
    toast.error('No se pudo cargar el historial')
  } finally {
    buscando.value = false
  }
}

function toggle(libroId: number) {
  if (expandidos.value.has(libroId)) expandidos.value.delete(libroId)
  else expandidos.value.add(libroId)
  expandidos.value = new Set(expandidos.value)
}
</script>

<template>
  <StaffLayout>
    <LibrosModuloNav actual="historial-prestamos-libro">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Historial de Libros</h1>
          <p class="text-sm text-white/60 mt-1">Busca un libro por título o código de barras y revisa cuántas veces se prestó cada copia</p>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-md p-6 mb-6">
        <div class="flex gap-3">
          <div class="relative flex-1">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
            <input
              v-model="busqueda"
              type="text"
              placeholder="Título o código de barras (vacío = todos los libros)"
              class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
              @keydown.enter="buscar"
            />
          </div>
          <button
            @click="buscar"
            :disabled="buscando"
            class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium disabled:opacity-60"
          >
            Buscar
          </button>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div v-if="buscado && !buscando" class="space-y-3">
        <div v-for="r in resultados" :key="r.libro.id" class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
          <button @click="toggle(r.libro.id)" class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-indigo-50/40 transition-colors">
            <div>
              <p class="text-sm font-semibold text-gray-900">{{ r.libro.titulo }}</p>
              <p v-if="r.libro.isbn" class="text-xs text-gray-400 font-mono">ISBN {{ r.libro.isbn }}</p>
            </div>
            <div class="flex items-center gap-3">
              <span class="text-xs px-3 py-1 rounded-full font-semibold bg-indigo-100 text-indigo-700">
                {{ r.total_prestamos }} préstamo(s) en total
              </span>
              <svg class="w-4 h-4 text-gray-400 transition-transform" :class="expandidos.has(r.libro.id) ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
              </svg>
            </div>
          </button>

          <div v-if="expandidos.has(r.libro.id)" class="border-t border-gray-100 divide-y divide-gray-100">
            <div v-for="ej in r.ejemplares" :key="ej.id" class="px-5 py-3">
              <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-mono text-gray-600">Copia {{ ej.numero_copia }} · {{ ej.codigo_barras }}</span>
                <span class="text-xs font-semibold text-gray-500">{{ ej.total_prestamos }} préstamo(s)</span>
              </div>
              <ul v-if="ej.prestamos.length" class="space-y-1">
                <li v-for="p in ej.prestamos" :key="p.id" class="text-xs text-gray-500 flex items-center justify-between">
                  <span>{{ p.usuario ?? 'Usuario eliminado' }}</span>
                  <span>{{ new Date(p.fecha_prestamo).toLocaleDateString('es-CL') }} · {{ p.estado }}</span>
                </li>
              </ul>
              <p v-else class="text-xs text-gray-400">Esta copia nunca se ha prestado.</p>
            </div>
          </div>
        </div>

        <p v-if="!resultados.length" class="bg-white rounded-xl shadow-md p-8 text-center text-sm text-gray-400">
          Sin libros que coincidan con la búsqueda.
        </p>
      </div>
    </LibrosModuloNav>
  </StaffLayout>
</template>
