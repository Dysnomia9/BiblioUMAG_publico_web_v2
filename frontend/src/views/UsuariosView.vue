<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { generarConstanciaNoMulta } from '@/utils/constancia'
import { describirDocumento } from '@/utils/documento'
import type { ConfiguracionInstitucional, Usuario } from '@/types'

const toast = useToast()
const auth = useAuthStore()

const usuarios = ref<Usuario[]>([])
const loading = ref(true)
const apiError = ref(false)
const generandoConstanciaRut = ref<string | null>(null)
const usuarioParaConstancia = ref<Usuario | null>(null)

function pedirConfirmacionConstancia(usuario: Usuario) {
  usuarioParaConstancia.value = usuario
}

async function confirmarConstancia() {
  if (!usuarioParaConstancia.value) return
  const usuario = usuarioParaConstancia.value
  usuarioParaConstancia.value = null
  generandoConstanciaRut.value = usuario.rut
  try {
    const [porRutRes, configRes] = await Promise.all([
      api.get<Usuario>(`/usuarios/rut/${usuario.rut}`),
      api.get<ConfiguracionInstitucional>('/configuracion'),
    ])

    const multas = porRutRes.data.multas_pendientes
    if (multas && multas.cantidad > 0) {
      const deuda = multas.cantidad === 1 ? '1 multa pendiente' : `${multas.cantidad} multas pendientes`
      toast.error(`${usuario.nombre} tiene ${deuda}: no se puede emitir la constancia`)
      return
    }

    await generarConstanciaNoMulta(porRutRes.data, configRes.data)
    toast.success('Constancia generada')
  } catch {
    toast.error('No se pudo generar la constancia')
  } finally {
    generandoConstanciaRut.value = null
  }
}

const filtros = reactive({
  q: '',
  tipo: '',
  activo: '',
})

let debounceTimer: ReturnType<typeof setTimeout> | undefined

async function cargarUsuarios() {
  loading.value = true
  try {
    const { data } = await api.get<Usuario[]>('/usuarios', {
      params: {
        q: filtros.q || undefined,
        tipo: filtros.tipo || undefined,
        activo: filtros.activo === '' ? undefined : filtros.activo,
      },
    })
    usuarios.value = data
    apiError.value = false
  } catch {
    apiError.value = true
    usuarios.value = []
  } finally {
    loading.value = false
  }
}

watch(filtros, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(cargarUsuarios, 300)
}, { deep: true })

onMounted(cargarUsuarios)

const tipoLabel: Record<string, string> = {
  estudiante: 'Estudiante',
  docente: 'Docente',
  funcionario: 'Funcionario',
}

const tipoBadge: Record<string, string> = {
  estudiante: 'bg-indigo-100 text-indigo-700',
  docente: 'bg-amber-100 text-amber-800',
  funcionario: 'bg-sky-100 text-sky-700',
}

const hayResultados = computed(() => usuarios.value.length > 0)
</script>

<template>
  <StaffLayout>
    <div class="max-w-6xl mx-auto">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Usuarios</h1>
            <p class="text-sm text-white/60 mt-1">Información de usuarios registrados en el sistema institucional (solo lectura)</p>
          </div>
          <router-link
            v-if="auth.puede('usuarios.gestionar')"
            :to="{ name: 'administracion', query: { seccion: 'estudiantes' } }"
            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-white/15 px-4 py-2 text-sm font-semibold text-white ring-1 ring-white/30 transition hover:bg-white/25"
          >
            Crear, editar o cargar estudiantes →
          </router-link>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <!-- Filtros -->
      <div class="bg-white rounded-xl shadow-md p-4 mb-6 grid grid-cols-1 sm:grid-cols-3 gap-3">
        <input
          v-model="filtros.q"
          type="text"
          placeholder="Buscar por nombre, RUT, pasaporte o carrera..."
          class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"
        />
        <select
          v-model="filtros.tipo"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"
        >
          <option value="">Todos los tipos</option>
          <option value="estudiante">Estudiante</option>
          <option value="docente">Docente</option>
          <option value="funcionario">Funcionario</option>
        </select>
        <select
          v-model="filtros.activo"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"
        >
          <option value="">Todos los estados</option>
          <option value="true">Activos</option>
          <option value="false">Inactivos</option>
        </select>
      </div>

      <!-- Tabla desktop -->
      <div class="hidden lg:block bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="bg-gray-100 border-b-2 border-gray-200">
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">RUT</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Nombre</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Carrera</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Tipo</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Estado</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              <tr v-for="(u, idx) in usuarios" :key="u.id" class="hover:bg-indigo-50/40 transition-colors" :class="idx % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                <td class="px-5 py-3 text-sm font-mono text-gray-700 whitespace-nowrap">{{ describirDocumento(u.rut, u.tipo_documento, u.pais) }}</td>
                <td class="px-5 py-3 text-sm">
                  <p class="font-medium text-gray-900">{{ u.nombre }} {{ u.apellido }}</p>
                  <p class="text-xs text-gray-500">{{ u.email ?? '-' }}</p>
                </td>
                <td class="px-5 py-3 text-sm text-gray-600">{{ u.carrera ?? '-' }}</td>
                <td class="px-5 py-3">
                  <span class="text-xs font-medium px-2.5 py-1 rounded-full" :class="tipoBadge[u.tipo]">
                    {{ tipoLabel[u.tipo] }}
                  </span>
                </td>
                <td class="px-5 py-3">
                  <span class="text-xs font-medium px-2.5 py-1 rounded-full" :class="u.activo ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">
                    {{ u.activo ? 'Activo' : 'Inactivo' }}
                  </span>
                </td>
                <td class="px-5 py-3 text-sm whitespace-nowrap">
                  <router-link
                    v-if="auth.puede('prestamos.gestionar')"
                    :to="{ name: 'prestamo', query: { rut: u.rut } }"
                    class="font-medium text-indigo-700 hover:text-indigo-800 mr-3"
                  >
                    Ver préstamos
                  </router-link>
                  <button
                    @click="pedirConfirmacionConstancia(u)"
                    :disabled="generandoConstanciaRut === u.rut"
                    class="font-medium text-indigo-700 hover:text-indigo-800 disabled:opacity-50"
                  >
                    {{ generandoConstanciaRut === u.rut ? 'Generando…' : 'Constancia de No Multa' }}
                  </button>
                </td>
              </tr>
              <tr v-if="!hayResultados && !loading">
                <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-400">
                  Sin usuarios que coincidan con la búsqueda.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Cards mobile/tablet -->
      <div class="lg:hidden space-y-3">
        <div v-for="u in usuarios" :key="u.id" class="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium text-gray-900 truncate">{{ u.nombre }} {{ u.apellido }}</p>
              <p class="text-xs font-mono text-gray-500">{{ describirDocumento(u.rut, u.tipo_documento, u.pais) }}</p>
            </div>
            <span class="text-xs font-medium px-2 py-0.5 rounded-full shrink-0" :class="tipoBadge[u.tipo]">
              {{ tipoLabel[u.tipo] }}
            </span>
          </div>
          <p class="text-xs text-gray-500 mt-2">{{ u.carrera ?? 'Sin carrera' }} · {{ u.email ?? 'Sin email' }}</p>
          <div class="mt-3 flex items-center justify-between gap-2 flex-wrap">
            <span class="text-xs font-medium px-2 py-0.5 rounded-full" :class="u.activo ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">
              {{ u.activo ? 'Activo' : 'Inactivo' }}
            </span>
            <div class="flex items-center gap-3 text-xs">
              <router-link v-if="auth.puede('prestamos.gestionar')" :to="{ name: 'prestamo', query: { rut: u.rut } }" class="font-medium text-indigo-700">
                Ver préstamos
              </router-link>
              <button
                @click="pedirConfirmacionConstancia(u)"
                :disabled="generandoConstanciaRut === u.rut"
                class="font-medium text-indigo-700 disabled:opacity-50"
              >
                {{ generandoConstanciaRut === u.rut ? 'Generando…' : 'Constancia de No Multa' }}
              </button>
            </div>
          </div>
        </div>
        <p v-if="!hayResultados && !loading" class="text-center text-sm text-gray-400 py-8">
          Sin usuarios que coincidan con la búsqueda.
        </p>
      </div>
    </div>

    <div
      v-if="usuarioParaConstancia"
      class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
      @click.self="usuarioParaConstancia = null"
    >
      <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
        <h3 class="text-lg font-bold text-gray-900 mb-1">¿Generar Constancia de No Multa?</h3>
        <p class="text-sm text-gray-500 mb-6">
          Se descargará un PDF con la constancia de <strong>{{ usuarioParaConstancia.nombre }} {{ usuarioParaConstancia.apellido }}</strong>,
          RUT {{ usuarioParaConstancia.rut }}. Si tiene multas pendientes, no se emitirá.
        </p>
        <div class="flex gap-3">
          <button
            @click="usuarioParaConstancia = null"
            class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
          >
            Cancelar
          </button>
          <button
            @click="confirmarConstancia"
            class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm"
          >
            Sí, descargar
          </button>
        </div>
      </div>
    </div>
  </StaffLayout>
</template>
