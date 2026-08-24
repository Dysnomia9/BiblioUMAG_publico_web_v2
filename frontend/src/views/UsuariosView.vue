<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import { generarConstanciaNoMulta } from '@/utils/constancia'
import type { ConfiguracionInstitucional, Usuario } from '@/types'

const toast = useToast()

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
      toast.error(`${usuario.nombre} tiene ${multas.cantidad} multa(s) pendiente(s) — no se puede emitir la constancia`)
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
  estudiante: 'bg-biblioteca-100 text-biblioteca-700',
  docente: 'bg-acento-500/10 text-acento-600',
  funcionario: 'bg-biblioteca-800/10 text-biblioteca-800',
}

const hayResultados = computed(() => usuarios.value.length > 0)
</script>

<template>
  <StaffLayout>
    <div class="mb-5 sm:mb-6 flex items-start justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-xl sm:text-2xl font-serif font-semibold text-biblioteca-900">Usuarios</h1>
        <p class="text-sm text-biblioteca-500 mt-0.5">
          Información de usuarios registrados en el sistema institucional (solo lectura)
        </p>
      </div>
      <a
        href="https://umag.elogim.com/"
        target="_blank"
        rel="noopener"
        class="flex items-center gap-2 px-4 py-2.5 bg-biblioteca-800 text-white rounded-lg hover:bg-biblioteca-900 transition-colors font-medium text-sm shrink-0"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
        </svg>
        Base de Datos Digital
      </a>
    </div>

    <ApiErrorBanner v-if="apiError" />

    <!-- Filtros -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
      <input
        v-model="filtros.q"
        type="text"
        placeholder="Buscar por nombre, RUT o carrera..."
        class="sm:col-span-1 w-full rounded-lg border border-biblioteca-200 px-3.5 py-2.5 text-sm text-biblioteca-900 placeholder:text-biblioteca-400 focus:outline-none focus:ring-2 focus:ring-biblioteca-400"
      />
      <select
        v-model="filtros.tipo"
        class="w-full rounded-lg border border-biblioteca-200 px-3.5 py-2.5 text-sm text-biblioteca-900 focus:outline-none focus:ring-2 focus:ring-biblioteca-400"
      >
        <option value="">Todos los tipos</option>
        <option value="estudiante">Estudiante</option>
        <option value="docente">Docente</option>
        <option value="funcionario">Funcionario</option>
      </select>
      <select
        v-model="filtros.activo"
        class="w-full rounded-lg border border-biblioteca-200 px-3.5 py-2.5 text-sm text-biblioteca-900 focus:outline-none focus:ring-2 focus:ring-biblioteca-400"
      >
        <option value="">Todos los estados</option>
        <option value="true">Activos</option>
        <option value="false">Inactivos</option>
      </select>
    </div>

    <!-- Tabla desktop -->
    <div class="hidden lg:block bg-white border border-biblioteca-200 rounded-xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-biblioteca-100 text-left text-xs text-biblioteca-500 uppercase tracking-wide">
              <th class="px-5 py-3 font-medium">RUT</th>
              <th class="px-5 py-3 font-medium">Nombre</th>
              <th class="px-5 py-3 font-medium">Carrera</th>
              <th class="px-5 py-3 font-medium">Tipo</th>
              <th class="px-5 py-3 font-medium">Estado</th>
              <th class="px-5 py-3 font-medium">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-biblioteca-100">
            <tr v-for="u in usuarios" :key="u.id">
              <td class="px-5 py-3 font-mono text-biblioteca-700">{{ u.rut }}</td>
              <td class="px-5 py-3">
                <p class="font-medium text-biblioteca-900">{{ u.nombre }} {{ u.apellido }}</p>
                <p class="text-xs text-biblioteca-500">{{ u.email ?? '-' }}</p>
              </td>
              <td class="px-5 py-3 text-biblioteca-700">{{ u.carrera ?? '-' }}</td>
              <td class="px-5 py-3">
                <span class="text-xs font-medium px-2 py-0.5 rounded-full" :class="tipoBadge[u.tipo]">
                  {{ tipoLabel[u.tipo] }}
                </span>
              </td>
              <td class="px-5 py-3">
                <span
                  class="text-xs font-medium px-2 py-0.5 rounded-full"
                  :class="u.activo ? 'bg-biblioteca-100 text-biblioteca-700' : 'bg-red-100 text-red-700'"
                >
                  {{ u.activo ? 'Activo' : 'Inactivo' }}
                </span>
              </td>
              <td class="px-5 py-3">
                <button
                  @click="pedirConfirmacionConstancia(u)"
                  :disabled="generandoConstanciaRut === u.rut"
                  class="text-xs font-medium text-biblioteca-700 hover:text-biblioteca-900 underline disabled:opacity-50"
                >
                  {{ generandoConstanciaRut === u.rut ? 'Generando…' : 'Constancia de No Multa' }}
                </button>
              </td>
            </tr>
            <tr v-if="!hayResultados && !loading">
              <td colspan="6" class="px-5 py-8 text-center text-sm text-biblioteca-400">
                Sin usuarios que coincidan con la búsqueda.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Cards mobile/tablet -->
    <div class="lg:hidden space-y-3">
      <div
        v-for="u in usuarios"
        :key="u.id"
        class="bg-white border border-biblioteca-200 rounded-xl p-4"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium text-biblioteca-900 truncate">{{ u.nombre }} {{ u.apellido }}</p>
            <p class="text-xs font-mono text-biblioteca-500">{{ u.rut }}</p>
          </div>
          <span class="text-xs font-medium px-2 py-0.5 rounded-full shrink-0" :class="tipoBadge[u.tipo]">
            {{ tipoLabel[u.tipo] }}
          </span>
        </div>
        <p class="text-xs text-biblioteca-500 mt-2">{{ u.carrera ?? 'Sin carrera' }} · {{ u.email ?? 'Sin email' }}</p>
        <div class="mt-3 flex items-center justify-between gap-2">
          <span
            class="text-xs font-medium px-2 py-0.5 rounded-full"
            :class="u.activo ? 'bg-biblioteca-100 text-biblioteca-700' : 'bg-red-100 text-red-700'"
          >
            {{ u.activo ? 'Activo' : 'Inactivo' }}
          </span>
          <button
            @click="pedirConfirmacionConstancia(u)"
            :disabled="generandoConstanciaRut === u.rut"
            class="text-xs font-medium text-biblioteca-700 hover:text-biblioteca-900 underline disabled:opacity-50"
          >
            {{ generandoConstanciaRut === u.rut ? 'Generando…' : 'Constancia de No Multa' }}
          </button>
        </div>
      </div>
      <p v-if="!hayResultados && !loading" class="text-center text-sm text-biblioteca-400 py-8">
        Sin usuarios que coincidan con la búsqueda.
      </p>
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
          RUT {{ usuarioParaConstancia.rut }}.
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
            class="flex-1 px-4 py-2.5 bg-biblioteca-800 text-white rounded-lg hover:bg-biblioteca-900 transition-colors font-medium text-sm"
          >
            Sí, descargar
          </button>
        </div>
      </div>
    </div>
  </StaffLayout>
</template>
