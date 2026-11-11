<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import Paginacion from '@/components/ui/Paginacion.vue'
import FondoUsuarios from '@/components/usuarios/FondoUsuarios.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { useListadoPaginado } from '@/composables/useListadoPaginado'
import { useAuthStore } from '@/stores/auth'
import { generarConstanciaNoMulta } from '@/utils/constancia'
import { describirDocumento } from '@/utils/documento'
import { fechaVigencia, useVigencia } from '@/utils/vigencia'
import type { ConfiguracionInstitucional, Paginado, Usuario } from '@/types'

const toast = useToast()
const vigencia = useVigencia()
vigencia.cargar()
const auth = useAuthStore()

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

// De a 25: con el reporte de matrícula son miles de personas.
const {
  filas: usuarios,
  total,
  porPagina,
  pagina,
  cargando: loading,
  error: apiError,
  cargar: cargarUsuarios,
  reiniciar,
} = useListadoPaginado<Usuario>(async (p) => {
  const { data } = await api.get<Paginado<Usuario>>('/usuarios', {
    params: {
      q: filtros.q || undefined,
      tipo: filtros.tipo || undefined,
      activo: filtros.activo === '' ? undefined : filtros.activo,
      page: p,
    },
  })
  return data
})

let debounceTimer: ReturnType<typeof setTimeout> | undefined
watch(filtros, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(reiniciar, 300)
}, { deep: true })
// Al cambiar de página se vuelve al comienzo del listado.
const listado = ref<HTMLElement | null>(null)
watch(pagina, () => listado.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))

onMounted(cargarUsuarios)

// Habilitar a quien está inactivo lo puede hacer todo el personal; desactivar, solo el
// Administrador/a (el servidor vuelve a revisar el permiso).
const cambioEstado = ref<{ usuario: Usuario; activo: boolean } | null>(null)
const guardandoEstado = ref(false)

function pedirCambioEstado(usuario: Usuario, activo: boolean) {
  cambioEstado.value = { usuario, activo }
}

async function confirmarCambioEstado() {
  if (!cambioEstado.value) return
  const { usuario, activo } = cambioEstado.value
  guardandoEstado.value = true
  try {
    await api.patch(`/usuarios/${usuario.id}/${activo ? 'habilitar' : 'desactivar'}`)
    toast.success(`${usuario.nombre} ${usuario.apellido} ${activo ? 'quedó con la cuenta renovada' : 'quedó inactivo/a'}`)
    cambioEstado.value = null
    await cargarUsuarios()
  } catch (e) {
    toast.error(mensajeDeError(e, activo ? 'No se pudo renovar la cuenta' : 'No se pudo desactivar'))
  } finally {
    guardandoEstado.value = false
  }
}

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

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}

const iniciales = (u: Usuario) => `${u.nombre.trim().charAt(0)}${u.apellido.trim().charAt(0)}`.toUpperCase()
const TONOS_AVATAR = ['bg-indigo-50 text-indigo-700', 'bg-amber-50 text-amber-800', 'bg-teal-50 text-teal-700', 'bg-violet-50 text-violet-700']
const tonoAvatar = (texto: string) => TONOS_AVATAR[[...texto].reduce((suma, letra) => suma + letra.charCodeAt(0), 0) % TONOS_AVATAR.length]

const ETIQUETAS_DOCUMENTO = { rut: 'RUT', pasaporte: 'Pasaporte', dni: 'DNI', otro: 'Otro' } as const
const etiquetaDocumento = (u: Usuario) => ETIQUETAS_DOCUMENTO[u.tipo_documento ?? 'rut']

function estadoDe(u: Usuario) {
  if (u.activo) return { texto: 'Activo', clase: 'bg-emerald-50 text-emerald-700', punto: 'bg-emerald-500' }
  if (u.inactividad === 'vencida') return { texto: 'Vencida', clase: 'bg-amber-50 text-amber-800', punto: 'bg-amber-500' }
  return { texto: 'Inactivo', clase: 'bg-red-50 text-red-700', punto: 'bg-red-500' }
}

function vigenciaDe(u: Usuario) {
  if (u.vigente_hasta) return `${u.activo ? 'Vigente hasta' : 'Venció el'} ${fechaVigencia(u.vigente_hasta)}`
  return u.activo ? 'Sin vencimiento' : ''
}
</script>

<template>
  <StaffLayout>
    <div class="max-w-6xl mx-auto">
      <!-- La caja de título con motivo propio: credenciales de biblioteca. -->
      <div class="relative mb-5 overflow-hidden rounded-2xl shadow-md" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
        <FondoUsuarios />
        <div class="relative flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="min-w-0">
            <nav class="text-xs text-white/75" aria-label="Ruta">
              <button type="button" class="font-medium text-white hover:underline" @click="abrirMenuGestion">Gestión</button>
              <span class="mx-1.5">›</span>
              <span>Atención de público</span>
            </nav>
            <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Usuarios</h1>
            <p class="mt-0.5 max-w-lg text-sm text-white/75">
              Información de usuarios registrados en el sistema institucional{{ auth.puede('usuarios.habilitar') ? '. Desde aquí puedes renovar la cuenta de quien esté vencido o inactivo.' : ' (solo lectura)' }}
            </p>
          </div>
          <router-link
            v-if="auth.puede('usuarios.gestionar')"
            :to="{ name: 'administracion', query: { seccion: 'estudiantes' } }"
            class="inline-flex shrink-0 items-center gap-2 self-start rounded-full bg-white py-1 pl-4 pr-1 text-sm font-semibold text-indigo-700 shadow-sm transition hover:bg-gray-50 sm:self-auto"
          >
            Crear, editar o cargar estudiantes
            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-indigo-600 text-white" aria-hidden="true">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
            </span>
          </router-link>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <section ref="listado" class="scroll-mt-4 overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
        <!-- Filtros -->
        <div class="grid grid-cols-1 gap-2 border-b border-gray-100 bg-indigo-50/40 p-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
          <div class="relative">
            <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.350-4.350M11 19a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
            <input
              v-model="filtros.q"
              type="text"
              placeholder="Buscar por nombre, RUT, pasaporte o carrera..."
              aria-label="Buscar usuarios"
              class="h-10 w-full rounded-xl border border-gray-200 bg-white pl-10 pr-4 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
            />
          </div>
          <select v-model="filtros.tipo" aria-label="Filtrar por tipo" class="h-10 w-full rounded-xl border border-gray-200 bg-white px-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="">Todos los tipos</option>
            <option value="estudiante">Estudiante</option>
            <option value="docente">Docente</option>
            <option value="funcionario">Funcionario</option>
          </select>
          <select v-model="filtros.activo" aria-label="Filtrar por estado" class="h-10 w-full rounded-xl border border-gray-200 bg-white px-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="">Todos los estados</option>
            <option value="true">Activos</option>
            <option value="false">Inactivos</option>
          </select>
        </div>

        <!-- Tabla desktop -->
        <div class="hidden overflow-x-auto lg:block">
          <table class="w-full">
            <thead>
              <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
                <th class="px-4 py-2.5">Usuario</th>
                <th class="px-4 py-2.5">Documento</th>
                <th class="px-4 py-2.5">Carrera</th>
                <th class="px-4 py-2.5">Estado</th>
                <th class="px-4 py-2.5 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="u in usuarios" :key="u.id" class="even:bg-gray-50/50">
                <td class="px-4 py-2.5">
                  <span class="flex items-center gap-2.5">
                    <span class="relative flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-bold" :class="tonoAvatar(u.nombre + u.apellido)" aria-hidden="true">
                      {{ iniciales(u) }}
                      <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-white" :class="estadoDe(u).punto"></span>
                    </span>
                    <span class="min-w-0">
                      <span class="block text-sm font-semibold leading-snug text-gray-900">{{ u.nombre }} {{ u.apellido }}</span>
                      <span class="mt-0.5 flex flex-wrap items-center gap-x-1.5">
                        <span class="rounded-full px-2 py-px text-[10px] font-semibold" :class="tipoBadge[u.tipo]">{{ tipoLabel[u.tipo] }}</span>
                        <a v-if="u.email" :href="`mailto:${u.email}`" class="break-all text-xs text-indigo-700 hover:underline">{{ u.email }}</a>
                        <span v-else class="text-xs italic text-gray-400">Sin correo</span>
                      </span>
                    </span>
                  </span>
                </td>
                <td class="px-4 py-2.5 whitespace-nowrap">
                  <span class="mr-1.5 rounded px-1.5 py-0.5 text-[10px] font-bold uppercase" :class="u.tipo_documento && u.tipo_documento !== 'rut' ? 'bg-amber-50 text-amber-800' : 'bg-indigo-50 text-indigo-700'">{{ etiquetaDocumento(u) }}</span>
                  <span class="font-mono text-xs text-gray-800">{{ u.rut }}</span>
                  <span v-if="u.pais && u.tipo_documento !== 'rut'" class="mt-0.5 block text-[11px] text-gray-500">{{ u.pais }}</span>
                </td>
                <td class="px-4 py-2.5 text-sm text-gray-700">{{ u.carrera ?? '—' }}</td>
                <td class="px-4 py-2.5 whitespace-nowrap">
                  <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="estadoDe(u).clase">
                    <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                    {{ estadoDe(u).texto }}
                  </span>
                  <span v-if="vigenciaDe(u)" class="mt-0.5 block text-[11px] text-gray-500">{{ vigenciaDe(u) }}</span>
                </td>
                <td class="px-4 py-2.5">
                  <div class="flex items-center justify-end gap-1.5">
                    <router-link
                      v-if="auth.puede('prestamos.gestionar')"
                      :to="{ name: 'prestamo', query: { rut: u.rut } }"
                      class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold transition-colors bg-sky-50 text-sky-700 hover:bg-sky-100"
                      :aria-label="`Ver préstamos de ${u.nombre} ${u.apellido}`"
                    >
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.500 5c1.747 0 3.332.477 4.500 1.253v13C19.832 18.477 18.247 18 16.500 18c-1.746 0-3.332.477-4.500 1.253" /></svg>
                      Préstamos
                    </router-link>
                    <button
                      type="button"
                      :disabled="generandoConstanciaRut === u.rut"
                      class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold transition-colors bg-amber-50 text-amber-800 hover:bg-amber-100 disabled:opacity-50"
                      title="Constancia de No Multa"
                      :aria-label="`Constancia de No Multa de ${u.nombre} ${u.apellido}`"
                      @click="pedirConfirmacionConstancia(u)"
                    >
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.500 14.250v-2.625a3.375 3.375 0 00-3.375-3.375h-1.500A1.125 1.125 0 0113.500 7.125v-1.500a3.375 3.375 0 00-3.375-3.375H6.750A1.125 1.125 0 005.625 3.375v17.250c0 .621.504 1.125 1.125 1.125h10.500c.621 0 1.125-.504 1.125-1.125V14.250zM9 15l2 2 4-4" /></svg>
                      {{ generandoConstanciaRut === u.rut ? 'Generando…' : 'Constancia' }}
                    </button>
                    <button
                      v-if="!u.activo && auth.puede('usuarios.habilitar')"
                      type="button"
                      class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-1 text-xs font-semibold transition-colors border border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-700"
                      @click="pedirCambioEstado(u, true)"
                    >
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356M4.984 19.644v-4.992h4.992m-5.356-.598a8.250 8.250 0 0013.803 3.181l3.181-3.183M3.003 9.750l3.181-3.183a8.250 8.250 0 0113.803 3.181" /></svg>
                      Renovar
                    </button>
                    <button
                      v-else-if="u.activo && auth.puede('usuarios.desactivar')"
                      type="button"
                      class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-1 text-xs font-semibold transition-colors border border-red-200 bg-white text-red-700 hover:bg-red-50"
                      @click="pedirCambioEstado(u, false)"
                    >
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                      Desactivar
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!hayResultados && !loading">
                <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-400">
                  Sin usuarios que coincidan con la búsqueda.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Cards mobile/tablet -->
        <div class="divide-y divide-gray-100 lg:hidden">
          <div v-for="u in usuarios" :key="u.id" class="p-3.5">
            <div class="flex items-start gap-2.5">
              <span class="relative flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-bold" :class="tonoAvatar(u.nombre + u.apellido)" aria-hidden="true">
                {{ iniciales(u) }}
                <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-white" :class="estadoDe(u).punto"></span>
              </span>
              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-gray-900">{{ u.nombre }} {{ u.apellido }}</p>
                <p class="font-mono text-xs text-gray-600">{{ describirDocumento(u.rut, u.tipo_documento, u.pais) }}</p>
              </div>
              <span class="shrink-0 rounded-full px-2 py-px text-[10px] font-semibold" :class="tipoBadge[u.tipo]">{{ tipoLabel[u.tipo] }}</span>
            </div>
            <p class="mt-2 text-xs text-gray-500">{{ u.carrera ?? 'Sin carrera' }} · {{ u.email ?? 'Sin correo' }}</p>
            <p class="mt-2">
              <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="estadoDe(u).clase">
                <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                {{ estadoDe(u).texto }}
              </span>
              <span v-if="vigenciaDe(u)" class="ml-2 text-[11px] text-gray-500">{{ vigenciaDe(u) }}</span>
            </p>
            <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
              <router-link
                v-if="auth.puede('prestamos.gestionar')"
                :to="{ name: 'prestamo', query: { rut: u.rut } }"
                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold transition-colors bg-sky-50 text-sky-700 hover:bg-sky-100"
                :aria-label="`Ver préstamos de ${u.nombre} ${u.apellido}`"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.500 5c1.747 0 3.332.477 4.500 1.253v13C19.832 18.477 18.247 18 16.500 18c-1.746 0-3.332.477-4.500 1.253" /></svg>
                Préstamos
              </router-link>
              <button
                type="button"
                :disabled="generandoConstanciaRut === u.rut"
                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold transition-colors bg-amber-50 text-amber-800 hover:bg-amber-100 disabled:opacity-50"
                title="Constancia de No Multa"
                :aria-label="`Constancia de No Multa de ${u.nombre} ${u.apellido}`"
                @click="pedirConfirmacionConstancia(u)"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.500 14.250v-2.625a3.375 3.375 0 00-3.375-3.375h-1.500A1.125 1.125 0 0113.500 7.125v-1.500a3.375 3.375 0 00-3.375-3.375H6.750A1.125 1.125 0 005.625 3.375v17.250c0 .621.504 1.125 1.125 1.125h10.500c.621 0 1.125-.504 1.125-1.125V14.250zM9 15l2 2 4-4" /></svg>
                {{ generandoConstanciaRut === u.rut ? 'Generando…' : 'Constancia' }}
              </button>
              <button
                v-if="!u.activo && auth.puede('usuarios.habilitar')"
                type="button"
                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-1 text-xs font-semibold transition-colors border border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-700"
                @click="pedirCambioEstado(u, true)"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356M4.984 19.644v-4.992h4.992m-5.356-.598a8.250 8.250 0 0013.803 3.181l3.181-3.183M3.003 9.750l3.181-3.183a8.250 8.250 0 0113.803 3.181" /></svg>
                Renovar
              </button>
              <button
                v-else-if="u.activo && auth.puede('usuarios.desactivar')"
                type="button"
                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-1 text-xs font-semibold transition-colors border border-red-200 bg-white text-red-700 hover:bg-red-50"
                @click="pedirCambioEstado(u, false)"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                Desactivar
              </button>
            </div>
          </div>
          <p v-if="!hayResultados && !loading" class="py-8 text-center text-sm text-gray-400">
            Sin usuarios que coincidan con la búsqueda.
          </p>
        </div>
      </section>

      <Paginacion v-model="pagina" class="mt-4" :total="total" :por-pagina="porPagina" singular="usuario" plural="usuarios" />
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

    <DialogoConfirmacion
      :abierto="!!cambioEstado"
      :titulo="cambioEstado?.activo === false ? '¿Desactivar a este usuario?' : '¿Renovar la cuenta de este usuario?'"
      :confirmar="cambioEstado?.activo === false ? 'Sí, desactivar' : 'Sí, renovar'"
      :tono="cambioEstado?.activo === false ? 'peligro' : 'exito'"
      :cargando="guardandoEstado"
      @confirmar="confirmarCambioEstado"
      @cancelar="cambioEstado = null"
    >
      <template v-if="cambioEstado">
        <p class="mb-2">
          <strong class="text-gray-900">{{ cambioEstado.usuario.nombre }} {{ cambioEstado.usuario.apellido }}</strong>,
          {{ describirDocumento(cambioEstado.usuario.rut, cambioEstado.usuario.tipo_documento, cambioEstado.usuario.pais) }}
        </p>
        <p>
          Estado: <span class="line-through">{{ cambioEstado.activo ? 'Inactivo' : 'Activo' }}</span> →
          <strong class="text-gray-900">{{ cambioEstado.activo ? `Vigente ${vigencia.hastaPara(cambioEstado.usuario.tipo)}` : 'Inactivo' }}</strong>
        </p>
        <p v-if="cambioEstado.activo" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-amber-800">
          Podrá marcar su entrada, pedir préstamos y reservas, y entrar al portal. Si tiene multas pendientes o libros vencidos, los
          préstamos y reservas siguen bloqueados hasta que se resuelvan.
        </p>
        <p v-else class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-red-700">
          No podrá marcar su entrada, pedir préstamos ni entrar al portal hasta que alguien lo vuelva a habilitar. Se cierran las sesiones
          que tenga abiertas en el portal.
        </p>
      </template>
    </DialogoConfirmacion>
  </StaffLayout>
</template>
