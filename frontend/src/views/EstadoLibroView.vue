<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import LibrosModuloNav from '@/components/libros/LibrosModuloNav.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import type { Ejemplar, EstadoLibroPersonalizado, EstadoProcesoEjemplar, Libro } from '@/types'

const toast = useToast()

const busqueda = ref('')
const librosEncontrados = ref<Libro[]>([])
const buscando = ref(false)
const apiError = ref(false)
const buscado = ref(false)

const estadosPersonalizados = ref<EstadoLibroPersonalizado[]>([])

const ejemplarSeleccionado = ref<Ejemplar | null>(null)
const nuevoEstado = ref<EstadoProcesoEjemplar>('inventario')
const estadoPersonalizadoId = ref<number | null>(null)
const guardando = ref(false)

const estados: { value: EstadoProcesoEjemplar; label: string; descripcion: string }[] = [
  { value: 'inventario', label: 'Inventario', descripcion: 'Recién ingresado, aún no procesado' },
  { value: 'procesos_tecnicos', label: 'En procesos técnicos', descripcion: 'Siendo plastificado o denominado' },
  { value: 'por_colocar', label: 'Por colocar', descripcion: 'Catalogado, organizado y plastificado, pendiente de ubicar' },
  { value: 'en_estante', label: 'En estante', descripcion: 'Acomodado y disponible para servicio bibliotecario' },
  { value: 'estanteria_auxiliar', label: 'Estantería auxiliar', descripcion: 'Ubicación secundaria' },
  { value: 'de_baja', label: 'De baja', descripcion: 'Obsoleto / retirado de la colección' },
  { value: 'coleccion_movil', label: 'Colección móvil', descripcion: 'En préstamo itinerante fuera de la biblioteca' },
]

const estadoBadges: Record<string, { label: string; cls: string }> = {
  inventario: { label: 'Inventario', cls: 'bg-gray-100 text-gray-700' },
  procesos_tecnicos: { label: 'En procesos técnicos', cls: 'bg-amber-100 text-amber-700' },
  por_colocar: { label: 'Por colocar', cls: 'bg-sky-100 text-sky-700' },
  en_estante: { label: 'En estante', cls: 'bg-emerald-100 text-emerald-700' },
  estanteria_auxiliar: { label: 'Estantería auxiliar', cls: 'bg-indigo-100 text-indigo-700' },
  de_baja: { label: 'De baja', cls: 'bg-red-100 text-red-700' },
  coleccion_movil: { label: 'Colección móvil', cls: 'bg-teal-100 text-teal-700' },
  personalizado: { label: 'Personalizado', cls: 'bg-fuchsia-100 text-fuchsia-700' },
}

function badgeDe(ejemplar: Ejemplar) {
  if (ejemplar.estado_proceso === 'personalizado') {
    return { label: ejemplar.estado_personalizado?.nombre ?? 'Personalizado', cls: estadoBadges.personalizado.cls }
  }
  return estadoBadges[ejemplar.estado_proceso] ?? { label: ejemplar.estado_proceso, cls: 'bg-gray-100 text-gray-700' }
}

// Aplana los libros encontrados a su lista de ejemplares (cada copia es su propia fila
// seleccionable — el estado vive en la copia, no en la obra).
const ejemplaresEncontrados = computed(() =>
  librosEncontrados.value.flatMap((libro) => (libro.ejemplares ?? []).map((e) => ({ ...e, libro })))
)

async function cargarEstadosPersonalizados() {
  try {
    const { data } = await api.get<EstadoLibroPersonalizado[]>('/estados-libro-personalizados', { params: { activo: 1 } })
    estadosPersonalizados.value = data
  } catch {
    estadosPersonalizados.value = []
  }
}

onMounted(cargarEstadosPersonalizados)

async function buscar() {
  if (!busqueda.value.trim()) {
    toast.error('Ingrese un título o código de barras para buscar')
    return
  }
  buscando.value = true
  buscado.value = true
  try {
    const { data } = await api.get<Libro[]>('/libros', { params: { q: busqueda.value.trim() } })
    librosEncontrados.value = data
    apiError.value = false
  } catch {
    apiError.value = true
    librosEncontrados.value = []
  } finally {
    buscando.value = false
  }
}

function seleccionar(ejemplar: Ejemplar) {
  ejemplarSeleccionado.value = ejemplar
  nuevoEstado.value = ejemplar.estado_proceso
  estadoPersonalizadoId.value = ejemplar.estado_personalizado_id
}

const confirmandoEstado = ref(false)

function pedirConfirmacionEstado() {
  if (!ejemplarSeleccionado.value) return
  if (nuevoEstado.value === 'personalizado' && !estadoPersonalizadoId.value) {
    toast.error('Selecciona cuál estado personalizado aplicar')
    return
  }
  confirmandoEstado.value = true
}

async function confirmarGuardarEstado() {
  if (!ejemplarSeleccionado.value) return
  guardando.value = true
  try {
    const { data } = await api.patch<Ejemplar>(`/ejemplares/${ejemplarSeleccionado.value.id}/estado`, {
      estado_proceso: nuevoEstado.value,
      estado_personalizado_id: nuevoEstado.value === 'personalizado' ? estadoPersonalizadoId.value : null,
    })
    toast.success('Estado actualizado')
    const libro = librosEncontrados.value.find((l) => l.id === ejemplarSeleccionado.value?.libro_id)
    const idx = libro?.ejemplares?.findIndex((e) => e.id === data.id)
    if (libro?.ejemplares && idx !== undefined && idx !== -1) libro.ejemplares[idx] = data
    ejemplarSeleccionado.value = { ...data, libro: ejemplarSeleccionado.value.libro }
    confirmandoEstado.value = false
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo actualizar el estado')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <StaffLayout>
    <LibrosModuloNav actual="estado-libro">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Estado de Libro</h1>
          <p class="text-sm text-white/60 mt-1">Gestiona el ciclo de proceso físico de una copia ya catalogada</p>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-md p-6 mb-6">
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Buscar por título o código de barras</label>
        <div class="flex gap-3">
          <div class="relative flex-1">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
            <input
              v-model="busqueda"
              type="text"
              placeholder="Título o código de barras"
              class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none"
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

      <div v-if="buscado && !buscando" class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden mb-6">
        <div class="divide-y divide-gray-100">
          <button
            v-for="e in ejemplaresEncontrados"
            :key="e.id"
            @click="seleccionar(e)"
            class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-indigo-50/50 transition-colors"
            :class="ejemplarSeleccionado?.id === e.id ? 'bg-indigo-50' : ''"
          >
            <div>
              <p class="text-sm font-medium text-gray-900">
                {{ e.libro.titulo }}
                <span v-if="e.numero_copia > 1" class="text-gray-400 font-normal">(Copia {{ e.numero_copia }})</span>
              </p>
              <p class="text-xs text-gray-500 font-mono">{{ e.codigo_barras }}</p>
            </div>
            <span class="text-xs px-2.5 py-1 rounded-full font-medium shrink-0" :class="badgeDe(e).cls">
              {{ badgeDe(e).label }}
            </span>
          </button>
          <p v-if="!ejemplaresEncontrados.length" class="px-4 py-8 text-center text-sm text-gray-400">Sin copias que coincidan con la búsqueda.</p>
        </div>
      </div>

      <div v-if="ejemplarSeleccionado" class="bg-white rounded-xl shadow-md p-6">
        <h3 class="font-semibold text-gray-900 mb-1">
          {{ ejemplarSeleccionado.libro?.titulo }}
          <span v-if="ejemplarSeleccionado.numero_copia > 1" class="text-gray-400 font-normal text-sm">(Copia {{ ejemplarSeleccionado.numero_copia }})</span>
        </h3>
        <p class="text-sm text-gray-500 mb-4 font-mono">{{ ejemplarSeleccionado.codigo_barras }}</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-3">
          <button
            v-for="e in estados"
            :key="e.value"
            @click="nuevoEstado = e.value"
            class="text-left px-3 py-2.5 rounded-lg border text-sm transition-colors"
            :class="nuevoEstado === e.value ? 'border-indigo-500 bg-indigo-50 text-indigo-900' : 'border-gray-200 hover:bg-gray-50 text-gray-700'"
          >
            <span class="font-medium block">{{ e.label }}</span>
            <span class="text-xs text-gray-500">{{ e.descripcion }}</span>
          </button>

          <button
            @click="nuevoEstado = 'personalizado'"
            class="text-left px-3 py-2.5 rounded-lg border text-sm transition-colors sm:col-span-2"
            :class="nuevoEstado === 'personalizado' ? 'border-fuchsia-500 bg-fuchsia-50 text-fuchsia-900' : 'border-gray-200 hover:bg-gray-50 text-gray-700'"
          >
            <span class="font-medium block">Personalizado</span>
            <span class="text-xs text-gray-500">Un estado definido por el admin (ver Administración)</span>
          </button>
        </div>

        <div v-if="nuevoEstado === 'personalizado'" class="mb-5">
          <label class="block text-xs font-medium text-gray-600 mb-1">¿Cuál estado personalizado?</label>
          <select
            v-model="estadoPersonalizadoId"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-fuchsia-500 outline-none text-sm"
          >
            <option :value="null" disabled>Selecciona uno...</option>
            <option v-for="ep in estadosPersonalizados" :key="ep.id" :value="ep.id">{{ ep.nombre }}</option>
          </select>
          <p v-if="!estadosPersonalizados.length" class="text-xs text-amber-600 mt-1.5">
            Todavía no hay estados personalizados creados — puedes crear uno en Administración.
          </p>
        </div>

        <div class="flex justify-end">
          <button
            @click="pedirConfirmacionEstado"
            :disabled="guardando || (nuevoEstado === ejemplarSeleccionado.estado_proceso && estadoPersonalizadoId === ejemplarSeleccionado.estado_personalizado_id)"
            class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Guardar estado
          </button>
        </div>
      </div>

      <div
        v-if="confirmandoEstado && ejemplarSeleccionado"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="confirmandoEstado = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Confirmar cambio de estado?</h3>
          <p class="text-sm text-gray-500 mb-6">
            <strong>{{ ejemplarSeleccionado.libro?.titulo }}</strong> (copia {{ ejemplarSeleccionado.numero_copia }}) pasará de
            <strong>{{ badgeDe(ejemplarSeleccionado).label }}</strong> a
            <strong>{{ nuevoEstado === 'personalizado' ? estadosPersonalizados.find((e) => e.id === estadoPersonalizadoId)?.nombre : estados.find((e) => e.value === nuevoEstado)?.label }}</strong>.
          </p>
          <div class="flex gap-3">
            <button
              @click="confirmandoEstado = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="confirmarGuardarEstado"
              :disabled="guardando"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ guardando ? 'Guardando…' : 'Sí, cambiar' }}
            </button>
          </div>
        </div>
      </div>
    </LibrosModuloNav>
  </StaffLayout>
</template>
