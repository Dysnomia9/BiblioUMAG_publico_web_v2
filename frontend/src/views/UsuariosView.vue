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

const hayResultados = computed(() => usuarios.value.length > 0)

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}

const ETIQUETAS_DOCUMENTO = { rut: 'RUT', pasaporte: 'Pasaporte', dni: 'DNI', otro: 'Otro' } as const
const etiquetaDocumento = (u: Usuario) => ETIQUETAS_DOCUMENTO[u.tipo_documento ?? 'rut']

function estadoDe(u: Usuario) {
  if (u.activo) return { texto: 'Activo', clase: 'border-emerald-300 bg-emerald-50/60 text-emerald-700' }
  if (u.inactividad === 'vencida') return { texto: 'Vencida', clase: 'border-amber-300 bg-amber-50/60 text-amber-800' }
  return { texto: 'Inactivo', clase: 'border-red-300 bg-red-50/60 text-red-700' }
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
                  <span class="block text-sm font-bold leading-snug text-gray-900">{{ u.nombre }} {{ u.apellido }}</span>
                  <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs">
                    <span class="font-semibold text-gray-600">{{ tipoLabel[u.tipo] }}</span>
                    <span class="h-3 w-px bg-gray-300" aria-hidden="true"></span>
                    <a v-if="u.email" :href="`mailto:${u.email}`" class="break-all text-indigo-700 hover:underline">{{ u.email }}</a>
                    <span v-else class="italic text-gray-400">Sin correo</span>
                  </span>
                </td>
                <td class="px-4 py-2.5 whitespace-nowrap">
                  <span class="mr-1.5 rounded px-1.5 py-0.5 text-[10px] font-bold uppercase" :class="u.tipo_documento && u.tipo_documento !== 'rut' ? 'bg-amber-50 text-amber-800' : 'bg-indigo-50 text-indigo-700'">{{ etiquetaDocumento(u) }}</span>
                  <span class="font-mono text-xs text-gray-800">{{ u.rut }}</span>
                  <span v-if="u.pais && u.tipo_documento !== 'rut'" class="mt-0.5 block text-[11px] text-gray-500">{{ u.pais }}</span>
                </td>
                <td class="px-4 py-2.5 text-sm text-gray-700">{{ u.carrera ?? '—' }}</td>
                <td class="px-4 py-2.5 whitespace-nowrap">
                  <span class="inline-block rounded border px-1.5 py-px text-[10px] font-bold uppercase tracking-widest" :class="estadoDe(u).clase">{{ estadoDe(u).texto }}</span>
                  <span v-if="vigenciaDe(u)" class="mt-0.5 block text-[11px] text-gray-500">{{ vigenciaDe(u) }}</span>
                </td>
                <td class="px-4 py-2.5">
                  <div class="flex items-center justify-end gap-2">
                    <!-- Consulta: un solo bloque neutro; solo los íconos llevan color. -->
                    <div class="inline-flex divide-x divide-gray-200 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                      <router-link
                        v-if="auth.puede('prestamos.gestionar')"
                        :to="{ name: 'prestamo', query: { rut: u.rut } }"
                        class="inline-flex items-center gap-1.5 whitespace-nowrap px-2.5 py-1 text-xs font-semibold text-gray-800 transition-colors hover:bg-gray-50"
                        :aria-label="`Ver préstamos de ${u.nombre} ${u.apellido}`"
                      >
                        <svg class="h-3.5 w-3.5 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.500 5c1.747 0 3.332.477 4.500 1.253v13C19.832 18.477 18.247 18 16.500 18c-1.746 0-3.332.477-4.500 1.253" /></svg>
                        Préstamos
                      </router-link>
                      <button
                        type="button"
                        :disabled="generandoConstanciaRut === u.rut"
                        class="inline-flex items-center gap-1.5 whitespace-nowrap px-2.5 py-1 text-xs font-semibold text-gray-800 transition-colors hover:bg-gray-50 disabled:opacity-50"
                        title="Constancia de No Multa"
                        :aria-label="`Constancia de No Multa de ${u.nombre} ${u.apellido}`"
                        @click="pedirConfirmacionConstancia(u)"
                      >
                        <svg class="h-3.5 w-3.5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.500 14.250v-2.625a3.375 3.375 0 00-3.375-3.375h-1.500A1.125 1.125 0 0113.500 7.125v-1.500a3.375 3.375 0 00-3.375-3.375H6.750A1.125 1.125 0 005.625 3.375v17.250c0 .621.504 1.125 1.125 1.125h10.500c.621 0 1.125-.504 1.125-1.125V14.250zM9 15l2 2 4-4" /></svg>
                        {{ generandoConstanciaRut === u.rut ? 'Generando…' : 'Constancia' }}
                      </button>
                    </div>
                    <!-- Acción principal aparte, del mismo ancho sea Renovar o Desactivar. -->
                    <button
                      v-if="!u.activo && auth.puede('usuarios.habilitar')"
                      type="button"
                      class="inline-flex w-[6.25rem] items-center justify-center whitespace-nowrap rounded-lg border border-emerald-600 bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700"
                      @click="pedirCambioEstado(u, true)"
                    >
                      Renovar
                    </button>
                    <button
                      v-else-if="u.activo && auth.puede('usuarios.desactivar')"
                      type="button"
                      class="inline-flex w-[6.25rem] items-center justify-center whitespace-nowrap rounded-lg border border-red-200 bg-white px-2.5 py-1 text-xs font-semibold text-red-700 shadow-sm transition-colors hover:bg-red-50"
                      @click="pedirCambioEstado(u, false)"
                    >
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
            <div class="flex items-start justify-between gap-2.5">
              <div class="min-w-0">
                <p class="text-sm font-bold text-gray-900">{{ u.nombre }} {{ u.apellido }}</p>
                <p class="font-mono text-xs text-gray-600">{{ describirDocumento(u.rut, u.tipo_documento, u.pais) }}</p>
              </div>
              <span class="shrink-0 rounded border px-1.5 py-px text-[10px] font-bold uppercase tracking-widest" :class="estadoDe(u).clase">{{ estadoDe(u).texto }}</span>
            </div>
            <p class="mt-1.5 flex flex-wrap items-center gap-x-2 text-xs">
              <span class="font-semibold text-gray-600">{{ tipoLabel[u.tipo] }}</span>
              <span class="h-3 w-px bg-gray-300" aria-hidden="true"></span>
              <span class="text-gray-500">{{ u.carrera ?? 'Sin carrera' }}</span>
            </p>
            <p class="mt-0.5 break-all text-xs" :class="u.email ? 'text-indigo-700' : 'italic text-gray-400'">{{ u.email ?? 'Sin correo' }}</p>
            <p v-if="vigenciaDe(u)" class="mt-0.5 text-[11px] text-gray-500">{{ vigenciaDe(u) }}</p>
            <div class="mt-2.5 flex flex-wrap items-center gap-2">
              <!-- Consulta: un solo bloque neutro; solo los íconos llevan color. -->
              <div class="inline-flex divide-x divide-gray-200 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                <router-link
                  v-if="auth.puede('prestamos.gestionar')"
                  :to="{ name: 'prestamo', query: { rut: u.rut } }"
                  class="inline-flex items-center gap-1.5 whitespace-nowrap px-2.5 py-1 text-xs font-semibold text-gray-800 transition-colors hover:bg-gray-50"
                  :aria-label="`Ver préstamos de ${u.nombre} ${u.apellido}`"
                >
                  <svg class="h-3.5 w-3.5 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.500 5c1.747 0 3.332.477 4.500 1.253v13C19.832 18.477 18.247 18 16.500 18c-1.746 0-3.332.477-4.500 1.253" /></svg>
                  Préstamos
                </router-link>
                <button
                  type="button"
                  :disabled="generandoConstanciaRut === u.rut"
                  class="inline-flex items-center gap-1.5 whitespace-nowrap px-2.5 py-1 text-xs font-semibold text-gray-800 transition-colors hover:bg-gray-50 disabled:opacity-50"
                  title="Constancia de No Multa"
                  :aria-label="`Constancia de No Multa de ${u.nombre} ${u.apellido}`"
                  @click="pedirConfirmacionConstancia(u)"
                >
                  <svg class="h-3.5 w-3.5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.500 14.250v-2.625a3.375 3.375 0 00-3.375-3.375h-1.500A1.125 1.125 0 0113.500 7.125v-1.500a3.375 3.375 0 00-3.375-3.375H6.750A1.125 1.125 0 005.625 3.375v17.250c0 .621.504 1.125 1.125 1.125h10.500c.621 0 1.125-.504 1.125-1.125V14.250zM9 15l2 2 4-4" /></svg>
                  {{ generandoConstanciaRut === u.rut ? 'Generando…' : 'Constancia' }}
                </button>
              </div>
              <!-- Acción principal aparte, del mismo ancho sea Renovar o Desactivar. -->
              <button
                v-if="!u.activo && auth.puede('usuarios.habilitar')"
                type="button"
                class="inline-flex w-[6.25rem] items-center justify-center whitespace-nowrap rounded-lg border border-emerald-600 bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700"
                @click="pedirCambioEstado(u, true)"
              >
                Renovar
              </button>
              <button
                v-else-if="u.activo && auth.puede('usuarios.desactivar')"
                type="button"
                class="inline-flex w-[6.25rem] items-center justify-center whitespace-nowrap rounded-lg border border-red-200 bg-white px-2.5 py-1 text-xs font-semibold text-red-700 shadow-sm transition-colors hover:bg-red-50"
                @click="pedirCambioEstado(u, false)"
              >
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
