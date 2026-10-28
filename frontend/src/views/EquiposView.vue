<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { categoriaDe, COLORES_RECURSO, ICONOS_RECURSO, useCategoriasRecurso } from '@/utils/recursos'
import { fechaCorta } from '@/utils/prestamos'
import type { Equipo } from '@/types'

const toast = useToast()
const auth = useAuthStore()

// Las categorías (notebook, bicicleta…) se administran en Administración → Catálogos.
const { categorias, cargar: cargarCategorias } = useCategoriasRecurso()
const categoriasActivas = computed(() => categorias.value.filter((c) => c.activo))
const filtroCategoria = ref('')
const visibles = computed(() => (filtroCategoria.value ? equipos.value.filter((e) => e.tipo === filtroCategoria.value) : equipos.value))

const equipos = ref<Equipo[]>([])
const cargando = ref(true)
const apiError = ref(false)

const codigoNuevo = ref('')
const codigoBarrasNuevo = ref('')
const tipoNuevo = ref('')
// Por defecto, la primera categoría activa (o la que se está mirando).
watch([categoriasActivas, filtroCategoria], () => {
  if (filtroCategoria.value && categoriasActivas.value.some((c) => c.clave === filtroCategoria.value)) tipoNuevo.value = filtroCategoria.value
  else if (!categoriasActivas.value.some((c) => c.clave === tipoNuevo.value)) tipoNuevo.value = categoriasActivas.value[0]?.clave ?? ''
})
const creando = ref(false)
const confirmandoCrear = ref(false)

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

onMounted(() => {
  cargar()
  cargarCategorias()
})

function pedirConfirmacionCrear() {
  if (!codigoNuevo.value.trim()) {
    toast.error('Ingrese un código de inventario')
    return
  }
  if (!codigoBarrasNuevo.value.trim()) {
    toast.error('Ingrese un código de barras')
    return
  }
  if (!tipoNuevo.value) {
    toast.error('Elija la categoría del recurso')
    return
  }
  confirmandoCrear.value = true
}

async function crearEquipo() {
  creando.value = true
  try {
    await api.post('/equipos', {
      codigo_inventario: codigoNuevo.value.trim(),
      codigo_barras: codigoBarrasNuevo.value.trim(),
      tipo: tipoNuevo.value,
    })
    toast.success('Recurso registrado')
    codigoNuevo.value = ''
    codigoBarrasNuevo.value = ''
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo registrar el recurso')
  } finally {
    creando.value = false
    confirmandoCrear.value = false
  }
}

const equipoACambiar = ref<Equipo | null>(null)
const cambiandoActivo = ref(false)

// Dar de baja pide el motivo: se ve en el inventario y queda en la bitácora.
const MOTIVOS_FRECUENTES = ['Dañado, sin reparación', 'Extraviado', 'Robado', 'Obsoleto o fuera de uso']
const motivoBaja = ref('')
const campoMotivo = ref<HTMLTextAreaElement | null>(null)

function pedirCambioActivo(equipo: Equipo) {
  motivoBaja.value = ''
  equipoACambiar.value = equipo
}

/** Un motivo frecuente queda escrito y el cursor al final, por si se quiere agregar el detalle. */
async function elegirMotivo(motivo: string) {
  motivoBaja.value = motivo
  await nextTick()
  campoMotivo.value?.focus()
  campoMotivo.value?.setSelectionRange(motivo.length, motivo.length)
}

async function cambiarActivo() {
  const equipo = equipoACambiar.value
  if (!equipo) return
  cambiandoActivo.value = true
  try {
    await api.patch(`/equipos/${equipo.id}/activo`, equipo.activo ? { activo: false, motivo: motivoBaja.value.trim() } : { activo: true })
    toast.success(equipo.activo ? 'Recurso dado de baja' : 'Recurso reactivado')
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo actualizar el recurso')
  } finally {
    cambiandoActivo.value = false
    equipoACambiar.value = null
  }
}

const nombreCategoria = (clave: string) => categoriaDe(clave)?.nombre ?? clave
const fondoDe = (clave: string) => (COLORES_RECURSO[categoriaDe(clave)?.color ?? 'slate'] ?? COLORES_RECURSO.slate).fondo
const iconoDe = (clave: string) => (ICONOS_RECURSO[categoriaDe(clave)?.icono ?? 'caja'] ?? ICONOS_RECURSO.caja).trazo
const cuantos = (clave: string) => equipos.value.filter((e) => e.tipo === clave && e.activo).length
</script>

<template>
  <StaffLayout>
    <div class="max-w-4xl mx-auto">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Recursos de apoyo</h1>
          <p class="text-sm text-white/60 mt-1">Inventario de recursos no bibliográficos para préstamo: notebooks, audífonos, cargadores, bicicletas…</p>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-md p-6 mb-6">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
          <h4 class="text-sm font-semibold text-gray-700">Registrar recurso nuevo</h4>
          <router-link
            v-if="auth.puede('catalogos.gestionar')"
            :to="{ name: 'administracion', query: { seccion: 'catalogos' } }"
            class="text-xs font-semibold text-indigo-700 hover:underline"
          >¿Falta una categoría? Créala en Administración →</router-link>
        </div>
        <div class="flex gap-3 flex-wrap items-end">
          <div class="flex-1 min-w-[180px]">
            <label class="block text-xs font-medium text-gray-600 mb-1">Nombre / identificador</label>
            <input
              v-model="codigoNuevo"
              placeholder="Ej: Bicicleta 05"
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none font-mono"
              @keydown.enter="pedirConfirmacionCrear"
            />
          </div>
          <div class="flex-1 min-w-[180px]">
            <label class="block text-xs font-medium text-gray-600 mb-1">Código de barras</label>
            <input
              v-model="codigoBarrasNuevo"
              placeholder="Ej: 7512000000123"
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none font-mono"
              @keydown.enter="pedirConfirmacionCrear"
            />
          </div>
          <div class="min-w-[160px]">
            <label class="block text-xs font-medium text-gray-600 mb-1">Categoría</label>
            <select
              v-model="tipoNuevo"
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
            >
              <option v-for="c in categoriasActivas" :key="c.clave" :value="c.clave">{{ c.nombre }}</option>
            </select>
          </div>
          <button
            @click="pedirConfirmacionCrear"
            :disabled="creando"
            class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium disabled:opacity-60"
          >
            Registrar
          </button>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="mb-3 flex flex-wrap gap-1.5" role="group" aria-label="Filtrar por categoría">
        <button
          type="button"
          class="rounded-full px-3 py-1 text-xs font-medium ring-1 transition"
          :class="!filtroCategoria ? 'bg-indigo-600 text-white ring-transparent' : 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50'"
          @click="filtroCategoria = ''"
        >
          Todas ({{ equipos.filter((e) => e.activo).length }})
        </button>
        <button
          v-for="c in categorias"
          :key="c.clave"
          type="button"
          class="rounded-full px-3 py-1 text-xs font-medium ring-1 transition"
          :class="filtroCategoria === c.clave ? 'bg-indigo-600 text-white ring-transparent' : 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50'"
          @click="filtroCategoria = c.clave"
        >
          {{ c.nombre_plural }} ({{ cuantos(c.clave) }}){{ c.activo ? '' : ' · inactiva' }}
        </button>
      </div>

      <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="bg-gray-100 border-b-2 border-gray-200">
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Nombre</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Código de barras</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Categoría</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Disponible</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Estado</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              <tr v-for="(e, idx) in visibles" :key="e.id" :class="idx % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                <td class="px-6 py-3 text-sm font-mono text-gray-900">{{ e.codigo_inventario }}</td>
                <td class="px-6 py-3 text-sm font-mono text-gray-500">{{ e.codigo_barras }}</td>
                <td class="px-6 py-3 text-sm text-gray-600">
                  <span class="inline-flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-md" :class="fondoDe(e.tipo)">
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" :d="iconoDe(e.tipo)" /></svg>
                    </span>
                    {{ nombreCategoria(e.tipo) }}
                  </span>
                </td>
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
                    class="text-xs px-2.5 py-1 rounded-full font-medium whitespace-nowrap"
                    :class="e.activo ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-700'"
                  >
                    {{ e.activo ? 'Activo' : 'De baja' }}
                  </span>
                  <template v-if="!e.activo && (e.motivo_baja || e.dado_de_baja_el)">
                    <p v-if="e.motivo_baja" class="mt-1.5 max-w-[16rem] text-xs text-gray-600 line-clamp-2" :title="e.motivo_baja">{{ e.motivo_baja }}</p>
                    <p v-if="e.dado_de_baja_el" class="mt-0.5 text-[11px] text-gray-400">Desde el {{ fechaCorta(e.dado_de_baja_el) }}</p>
                  </template>
                </td>
                <td class="px-6 py-3">
                  <button
                    @click="pedirCambioActivo(e)"
                    :disabled="e.activo && !e.disponible"
                    :title="e.activo && !e.disponible ? 'No se puede dar de baja un recurso que está prestado' : ''"
                    class="text-sm font-medium disabled:opacity-40 disabled:cursor-not-allowed"
                    :class="e.activo ? 'text-red-600 hover:text-red-700' : 'text-indigo-700 hover:text-indigo-800'"
                  >
                    {{ e.activo ? 'Dar de baja' : 'Reactivar' }}
                  </button>
                </td>
              </tr>
              <tr v-if="!cargando && !visibles.length">
                <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-400">Sin recursos registrados{{ filtroCategoria ? ' en esta categoría' : '' }}.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <DialogoConfirmacion
      :abierto="confirmandoCrear"
      titulo="¿Registrar este recurso?"
      confirmar="Sí, registrar"
      :cargando="creando"
      @confirmar="crearEquipo"
      @cancelar="confirmandoCrear = false"
    >
      <p>
        Se agregará <strong class="text-gray-900">{{ codigoNuevo.trim() }}</strong> ({{ nombreCategoria(tipoNuevo).toLowerCase() }}, código de barras
        <span class="font-mono">{{ codigoBarrasNuevo.trim() }}</span>) al inventario y quedará disponible para préstamo.
      </p>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="!!equipoACambiar"
      :titulo="equipoACambiar?.activo ? '¿Dar de baja este recurso?' : '¿Reactivar este recurso?'"
      :confirmar="equipoACambiar?.activo ? 'Sí, dar de baja' : 'Sí, reactivar'"
      :tono="equipoACambiar?.activo ? 'peligro' : 'exito'"
      :cargando="cambiandoActivo"
      :deshabilitado="!!equipoACambiar?.activo && !motivoBaja.trim()"
      @confirmar="cambiarActivo"
      @cancelar="equipoACambiar = null"
    >
      <template v-if="equipoACambiar?.activo">
        <p>
          <strong class="text-gray-900">{{ equipoACambiar.codigo_inventario }}</strong> ({{ nombreCategoria(equipoACambiar.tipo).toLowerCase() }})
          dejará de ofrecerse para préstamo. Su historial se conserva y puedes reactivarlo cuando quieras.
        </p>
        <p class="mt-2">
          Estado: <span class="line-through">Activo</span> → <strong class="text-gray-900">De baja</strong>
        </p>
        <label for="motivo-baja" class="mt-4 mb-1.5 block font-medium text-gray-700">Motivo de la baja</label>
        <div class="mb-2 flex flex-wrap gap-1.5">
          <button
            v-for="m in MOTIVOS_FRECUENTES"
            :key="m"
            type="button"
            class="rounded-full px-2.5 py-1 text-xs font-medium ring-1 transition"
            :class="motivoBaja === m ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50'"
            @click="elegirMotivo(m)"
          >
            {{ m }}
          </button>
        </div>
        <textarea
          id="motivo-baja"
          ref="campoMotivo"
          v-model="motivoBaja"
          rows="3"
          maxlength="500"
          placeholder="Ej: la pantalla se trizó y el servicio técnico dijo que no tiene arreglo"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm text-gray-900"
        />
        <p class="mt-1 text-xs text-gray-400">Se verá en el inventario y queda en la bitácora con tu nombre.</p>
      </template>
      <template v-else-if="equipoACambiar">
        <p><strong class="text-gray-900">{{ equipoACambiar.codigo_inventario }}</strong> volverá a estar disponible para préstamo.</p>
        <p v-if="equipoACambiar.motivo_baja" class="mt-2">Se había dado de baja por: «{{ equipoACambiar.motivo_baja }}».</p>
      </template>
    </DialogoConfirmacion>
  </StaffLayout>
</template>
