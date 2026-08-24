<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import PortalLayout from '@/components/layout/PortalLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import apiUsuario from '@/services/apiUsuario'
import { useToast } from '@/composables/useToast'
import type { Libro, ReservaLibro } from '@/types'

const router = useRouter()
const toast = useToast()

const busqueda = ref('')
const libros = ref<Libro[]>([])
const apiError = ref(false)
const cargando = ref(false)

const misReservas = ref<ReservaLibro[]>([])
const reservandoId = ref<number | null>(null)
const cancelandoId = ref<number | null>(null)

const reservaBadges: Record<string, { label: string; cls: string }> = {
  pendiente: { label: 'Lista para retirar', cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  en_cola: { label: 'En lista de espera', cls: 'bg-sky-50 text-sky-700 border-sky-200' },
}

async function cargar() {
  cargando.value = true
  try {
    const { data } = await apiUsuario.get<Libro[]>('/mi/catalogo', { params: busqueda.value ? { q: busqueda.value } : {} })
    libros.value = data
    apiError.value = false
  } catch {
    apiError.value = true
    libros.value = []
  } finally {
    cargando.value = false
  }
}

async function cargarMisReservas() {
  try {
    const { data } = await apiUsuario.get<ReservaLibro[]>('/mi/reservas-libro')
    misReservas.value = data
  } catch {
    misReservas.value = []
  }
}

function reservaDe(libro: Libro) {
  return misReservas.value.find((r) => r.libro_id === libro.id)
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined
watch(busqueda, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(cargar, 300)
})
onUnmounted(() => clearTimeout(debounceTimer))

onMounted(() => {
  cargar()
  cargarMisReservas()
})

function hayDisponible(libro: Libro) {
  return (libro.ejemplares_disponibles ?? 0) > 0
}

async function reservar(libro: Libro) {
  reservandoId.value = libro.id
  try {
    await apiUsuario.post('/mi/reservas-libro', { libro_id: libro.id })
    toast.success(hayDisponible(libro) ? `Reservaste "${libro.titulo}"` : `Te uniste a la lista de espera de "${libro.titulo}"`)
    await Promise.all([cargar(), cargarMisReservas()])
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo registrar la reserva')
  } finally {
    reservandoId.value = null
  }
}

async function cancelar(reserva: ReservaLibro) {
  cancelandoId.value = reserva.id
  try {
    await apiUsuario.patch(`/mi/reservas-libro/${reserva.id}/cancelar`)
    toast.success(reserva.estado === 'en_cola' ? 'Saliste de la lista de espera' : 'Reserva cancelada')
    await Promise.all([cargar(), cargarMisReservas()])
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo cancelar la reserva')
  } finally {
    cancelandoId.value = null
  }
}
</script>

<template>
  <PortalLayout>
    <div class="max-w-2xl mx-auto">
      <button
        @click="router.push({ name: 'portal-home' })"
        class="mb-5 flex items-center gap-2 text-sm text-gray-600 hover:text-indigo-700"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
        Volver al inicio
      </button>

      <div class="mb-5">
        <h1 class="text-xl font-serif font-bold text-gray-900">Catálogo de biblioteca</h1>
        <p class="text-sm text-gray-500 mt-0.5">Consulta la disponibilidad y reserva libros desde el portal virtual</p>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div v-if="misReservas.length" class="bg-white border border-gray-200 rounded-lg overflow-hidden mb-5">
        <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-200 text-xs font-medium text-gray-500 uppercase tracking-wide">
          Mis reservas
        </div>
        <div class="divide-y divide-gray-100">
          <div v-for="r in misReservas" :key="r.id" class="flex items-center justify-between gap-3 px-4 py-3">
            <div class="min-w-0">
              <p class="font-medium text-gray-900 text-sm truncate">{{ r.libro?.titulo }}</p>
              <div class="flex items-center gap-2 mt-0.5">
                <span class="inline-block text-[11px] font-medium px-2 py-0.5 rounded border" :class="reservaBadges[r.estado]?.cls">
                  {{ reservaBadges[r.estado]?.label }}
                </span>
                <span v-if="r.estado === 'en_cola' && r.posicion" class="text-[11px] text-gray-400">
                  Lugar #{{ r.posicion }} en la fila
                  <template v-if="r.proxima_fecha_devolucion">
                    · una copia debería devolverse el {{ new Date(r.proxima_fecha_devolucion).toLocaleDateString('es-CL') }}
                  </template>
                </span>
                <span v-else-if="r.estado === 'pendiente' && r.fecha_retiro" class="text-[11px] text-gray-400">
                  Retirar antes del {{ new Date(r.fecha_retiro).toLocaleDateString('es-CL') }}
                </span>
              </div>
            </div>
            <button
              @click="cancelar(r)"
              :disabled="cancelandoId === r.id"
              class="text-xs text-red-600 hover:text-red-700 font-medium disabled:opacity-50 shrink-0"
            >
              {{ r.estado === 'en_cola' ? 'Salir de la fila' : 'Cancelar' }}
            </button>
          </div>
        </div>
      </div>

      <div class="relative mb-5">
        <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4.5 h-4.5 text-gray-400" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
        </svg>
        <input
          v-model="busqueda"
          placeholder="Buscar por título, autor o área..."
          class="w-full pl-10 pr-4 py-2.5 bg-white border border-gray-300 rounded-lg text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
        />
      </div>

      <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <div class="hidden sm:grid grid-cols-[1fr_120px_90px_120px] gap-4 px-4 py-2.5 bg-gray-50 border-b border-gray-200 text-xs font-medium text-gray-500 uppercase tracking-wide">
          <span>Título / Autor</span>
          <span>Área</span>
          <span>Estado</span>
          <span class="text-right">Reservar</span>
        </div>
        <div class="divide-y divide-gray-100">
          <div
            v-for="libro in libros"
            :key="libro.id"
            class="grid grid-cols-1 sm:grid-cols-[1fr_120px_90px_120px] gap-1 sm:gap-4 px-4 py-3 hover:bg-gray-50/60 transition-colors items-center"
          >
            <div class="min-w-0">
              <p class="font-medium text-gray-900 text-sm truncate">{{ libro.titulo }}</p>
              <p class="text-xs text-gray-500 truncate">{{ libro.autores?.map((a) => a.nombre).join(', ') }}</p>
            </div>
            <div class="text-xs text-gray-600 flex items-center sm:block">
              <span class="sm:hidden text-gray-400 mr-1">Área:</span>{{ libro.categorias?.map((c) => c.nombre).join(', ') }}
            </div>
            <div>
              <span
                class="inline-block text-xs font-medium px-2 py-0.5 rounded border"
                :class="hayDisponible(libro) ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-red-50 text-red-700 border-red-200'"
              >
                {{ hayDisponible(libro) ? `Disponible (${libro.ejemplares_disponibles})` : 'Prestado' }}
              </span>
            </div>
            <div class="sm:text-right">
              <span v-if="reservaDe(libro)" class="text-xs text-gray-400">
                {{ reservaDe(libro)?.estado === 'en_cola' ? 'En espera' : 'Reservado' }}
              </span>
              <button
                v-else
                @click="reservar(libro)"
                :disabled="reservandoId === libro.id"
                class="text-xs font-medium px-3 py-1.5 rounded-lg border transition-colors disabled:opacity-50"
                :class="hayDisponible(libro)
                  ? 'bg-emerald-600 text-white border-emerald-600 hover:bg-emerald-700'
                  : 'bg-white text-sky-700 border-sky-300 hover:bg-sky-50'"
              >
                {{ hayDisponible(libro) ? 'Reservar' : 'Unirme a la espera' }}
              </button>
            </div>
          </div>
          <div v-if="!cargando && !libros.length" class="px-4 py-10 text-center text-sm text-gray-400">
            Sin resultados para tu búsqueda.
          </div>
        </div>
      </div>
    </div>
  </PortalLayout>
</template>
