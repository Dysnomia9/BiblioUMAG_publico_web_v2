<script setup lang="ts">
import { computed, onMounted, reactive, ref, toRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import EncabezadoPagina from '@/components/ui/EncabezadoPagina.vue'
import GuiaUso from '@/components/ui/GuiaUso.vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import SelectorDocumento from '@/components/ui/SelectorDocumento.vue'
import Paginacion from '@/components/ui/Paginacion.vue'
import DetalleDeuda from '@/components/multas/DetalleDeuda.vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { largoSegunModo, placeholderSegunModo } from '@/utils/documento'
import { useModoDocumento } from '@/composables/useModoDocumento'
import { useListadoPaginado } from '@/composables/useListadoPaginado'
import { contieneCadaPalabra, sinTildes } from '@/utils/texto'
import { ESTADOS_MULTA as ESTADOS, TIPOS_MULTA as TIPOS, fechaMulta as formatFecha, formatMonto } from '@/utils/multas'
import type { DeudaUsuario, EstadoMulta, Multa, MultasResumen, Paginado, TipoMulta, Usuario } from '@/types'

const auth = useAuthStore()
const toast = useToast()
const route = useRoute()

const pestana = ref<'deudas' | 'listado'>('deudas')
const errorResumen = ref(false)

const resumen = ref<MultasResumen | null>(null)
const busquedaDeudas = ref('')
const filtros = reactive({ q: '', estado: 'pendiente' as EstadoMulta | '', tipo: '' as TipoMulta | '' })

const deudasFiltradas = computed(() => {
  if (!resumen.value) return []
  const q = sinTildes(busquedaDeudas.value.trim())
  if (!q) return resumen.value.usuarios
  return resumen.value.usuarios.filter((u) => contieneCadaPalabra(`${u.nombre} ${u.apellido}`, q) || u.rut.toLowerCase().includes(q))
})

// Las deudas llegan todas juntas (son solo quienes deben); se muestran de a 25.
const DEUDAS_POR_PAGINA = 25
const paginaDeudas = ref(1)
const deudasVisibles = computed(() => deudasFiltradas.value.slice((paginaDeudas.value - 1) * DEUDAS_POR_PAGINA, paginaDeudas.value * DEUDAS_POR_PAGINA))
watch(busquedaDeudas, () => (paginaDeudas.value = 1))
watch(deudasFiltradas, (lista) => {
  // Tras pagar la última deuda de la última página, se vuelve a la anterior.
  const ultima = Math.max(1, Math.ceil(lista.length / DEUDAS_POR_PAGINA))
  if (paginaDeudas.value > ultima) paginaDeudas.value = ultima
})

async function cargarResumen() {
  try {
    const { data } = await api.get<MultasResumen>('/multas/resumen')
    resumen.value = data
    errorResumen.value = false
  } catch {
    errorResumen.value = true
  }
}

// «Todas las multas» crece con los años: el servidor la entrega de a 25, las más nuevas primero.
const {
  filas: multas,
  total: totalMultas,
  porPagina: multasPorPagina,
  pagina: paginaMultas,
  cargando: cargandoListado,
  error: errorListado,
  cargar: cargarListado,
  reiniciar: reiniciarListado,
} = useListadoPaginado<Multa>(async (p) => {
  const { data } = await api.get<Paginado<Multa>>('/multas', {
    params: { q: filtros.q.trim() || undefined, estado: filtros.estado || undefined, tipo: filtros.tipo || undefined, page: p },
  })
  return data
})
const apiError = computed(() => errorResumen.value || errorListado.value)

let temporizador: ReturnType<typeof setTimeout> | undefined
watch(() => [filtros.estado, filtros.tipo], reiniciarListado)
watch(
  () => filtros.q,
  () => {
    clearTimeout(temporizador)
    temporizador = setTimeout(reiniciarListado, 300)
  },
)

// «Ver detalle» de la deuda: un popup con cada multa, para pagarla o anularla ahí mismo.
const deudaAbierta = ref<DeudaUsuario | null>(null)
// Sube tras pagar o anular, para que el detalle abierto se recargue.
const versionDeuda = ref(0)

/** Todas las multas de la persona (también pagadas y anuladas) en la pestaña del listado. */
function verEnListado(rut: string) {
  deudaAbierta.value = null
  filtros.q = rut
  filtros.estado = ''
  pestana.value = 'listado'
}

onMounted(async () => {
  const rut = typeof route.query.rut === 'string' ? route.query.rut : ''
  if (rut) verEnListado(rut)
  await Promise.all([cargarResumen(), cargarListado()])
})

async function recargar() {
  await Promise.all([cargarResumen(), cargarListado()])
}

// ---- Registrar pago ----
const multaAPagar = ref<Multa | null>(null)
const pagando = ref(false)

async function confirmarPago() {
  if (!multaAPagar.value) return
  pagando.value = true
  try {
    await api.patch(`/multas/${multaAPagar.value.id}/pagar`)
    toast.success(`Pago de ${formatMonto(multaAPagar.value.monto)} registrado`)
    multaAPagar.value = null
    versionDeuda.value++
    await recargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo registrar el pago'))
  } finally {
    pagando.value = false
  }
}

// ---- Anular ----
const multaAAnular = ref<Multa | null>(null)
const motivoAnulacion = ref('')
const anulando = ref(false)

function pedirAnulacion(m: Multa) {
  multaAAnular.value = m
  motivoAnulacion.value = ''
}

async function confirmarAnulacion() {
  if (!multaAAnular.value) return
  if (motivoAnulacion.value.trim().length < 5) {
    toast.error('Escribe el motivo de la anulación (al menos 5 caracteres)')
    return
  }
  anulando.value = true
  try {
    await api.patch(`/multas/${multaAAnular.value.id}/anular`, { motivo: motivoAnulacion.value.trim() })
    toast.success('Multa anulada. Queda en el historial con el motivo.')
    multaAAnular.value = null
    versionDeuda.value++
    await recargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo anular la multa'))
  } finally {
    anulando.value = false
  }
}

// ---- Cursar multa manual ----
const nueva = reactive({
  abierto: false,
  confirmando: false,
  guardando: false,
  rut: '',
  buscando: false,
  usuario: null as Usuario | null,
  errorRut: '',
  tipo: 'deterioro' as Exclude<TipoMulta, 'atraso'>,
  monto: null as number | null,
  detalle: '',
})

// RUT, o pasaporte/DNI de un estudiante extranjero.
const { modo: modoRutMulta, escribir: escribirRutMulta, error: errorRutMulta } = useModoDocumento(toRef(nueva, 'rut'))

function abrirNueva() {
  Object.assign(nueva, { abierto: true, confirmando: false, rut: '', usuario: null, errorRut: '', tipo: 'deterioro', monto: null, detalle: '' })
  modoRutMulta.value = 'rut'
}

async function buscarUsuarioMulta() {
  const error = errorRutMulta()
  if (error) {
    nueva.errorRut = error
    return
  }
  nueva.buscando = true
  nueva.errorRut = ''
  try {
    const { data } = await api.get<Usuario>(`/usuarios/rut/${encodeURIComponent(nueva.rut)}`)
    nueva.usuario = data
  } catch (e: any) {
    nueva.usuario = null
    nueva.errorRut = e?.response?.status === 404 ? `No hay ningún usuario con ese ${modoRutMulta.value === 'rut' ? 'RUT' : 'pasaporte o DNI'}.` : mensajeDeError(e, 'No se pudo buscar el usuario')
  } finally {
    nueva.buscando = false
  }
}

function pedirConfirmacionNueva() {
  if (!nueva.usuario) {
    toast.error('Primero busca al usuario por su RUT')
    return
  }
  if (!nueva.monto || nueva.monto < 1) {
    toast.error('Indica el monto de la multa en pesos')
    return
  }
  if (nueva.detalle.trim().length < 5) {
    toast.error('Describe el motivo (ej: «Pérdida del libro Cálculo I»)')
    return
  }
  nueva.confirmando = true
}

async function guardarNueva() {
  if (!nueva.usuario || !nueva.monto) return
  nueva.guardando = true
  try {
    await api.post('/multas', { usuario_id: nueva.usuario.id, tipo: nueva.tipo, monto: Math.round(nueva.monto), detalle: nueva.detalle.trim() })
    toast.success(`Multa de ${formatMonto(nueva.monto)} cursada a ${nueva.usuario.nombre} ${nueva.usuario.apellido}`)
    Object.assign(nueva, { abierto: false, confirmando: false })
    await recargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo cursar la multa'))
    nueva.confirmando = false
  } finally {
    nueva.guardando = false
  }
}
</script>

<template>
  <StaffLayout>
    <div class="max-w-5xl mx-auto">
      <EncabezadoPagina titulo="Multas" descripcion="Deudas de los usuarios con la biblioteca: pendientes, pagadas y anuladas">
        <template #acciones>
          <button
            v-if="auth.puede('multas.crear')"
            @click="abrirNueva"
            class="px-4 py-2 bg-white text-indigo-700 rounded-lg hover:bg-indigo-50 transition-colors font-medium text-sm shadow-sm"
          >
            + Cursar multa
          </button>
        </template>
      </EncabezadoPagina>

      <GuiaUso
        :pasos="[
          'Las multas por <strong>atraso</strong> se generan solas al registrar una devolución fuera de plazo.',
          'En «Deuda por usuario», <strong>Ver detalle</strong> muestra cada multa de la persona (fecha, motivo y si se importó de Horizon) y permite pagarla o anularla ahí mismo.',
          'Cuando el usuario paga, pulse <strong>Marcar pagada</strong>: queda registrado quién lo hizo y cuándo.',
          'Si una multa se cursó por error, <strong>anúlela</strong> indicando el motivo: no se borra, queda en el historial.',
          'Mientras tenga multas <strong>pendientes</strong>, la persona no puede llevarse ni reservar material. Al pagar o anular, se desbloquea al instante.',
        ]"
        consejo="Registrar pagos, anular y cursar multas dependen del rol de cada cuenta (Menú Gestiones Admin → Personal y Roles)."
      />

      <ApiErrorBanner v-if="apiError" />

      <div v-if="resumen" class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-md p-5">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Usuarios con deuda</p>
          <p class="text-2xl font-bold text-gray-900">{{ resumen.total_usuarios }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-md p-5">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Multas pendientes</p>
          <p class="text-2xl font-bold text-gray-900">{{ resumen.total_multas }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-md p-5">
          <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Monto total pendiente</p>
          <p class="text-2xl font-bold text-red-700">{{ formatMonto(resumen.monto_total) }}</p>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row gap-1 mb-4 bg-white rounded-xl shadow-sm p-1">
        <button
          @click="pestana = 'deudas'"
          class="flex-1 py-2.5 px-4 rounded-lg text-sm font-medium transition-colors"
          :class="pestana === 'deudas' ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100'"
        >
          Deuda por usuario
        </button>
        <button
          @click="pestana = 'listado'"
          class="flex-1 py-2.5 px-4 rounded-lg text-sm font-medium transition-colors"
          :class="pestana === 'listado' ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100'"
        >
          Todas las multas
        </button>
      </div>

      <!-- Deuda por usuario -->
      <template v-if="pestana === 'deudas'">
        <div class="bg-white rounded-xl shadow-md p-4 mb-4">
          <input
            v-model="busquedaDeudas"
            type="text"
            placeholder="Buscar por nombre o RUT"
            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
          />
        </div>
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr class="bg-gray-100 border-b-2 border-gray-200">
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Usuario</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">RUT</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Correo</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide"># Multas</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Monto pendiente</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Acción</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200">
                <tr v-for="(u, idx) in deudasVisibles" :key="u.usuario_id" :class="idx % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                  <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ u.nombre }} {{ u.apellido }}</td>
                  <td class="px-6 py-3 text-sm font-mono text-gray-600 whitespace-nowrap">{{ u.rut }}</td>
                  <td class="px-6 py-3 text-sm">
                    <a v-if="u.email" :href="`mailto:${u.email}`" class="text-indigo-700 hover:underline break-all">{{ u.email }}</a>
                    <span v-else class="text-gray-400">Sin correo</span>
                  </td>
                  <td class="px-6 py-3 text-sm text-gray-600">{{ u.cantidad_multas }}</td>
                  <td class="px-6 py-3 text-sm font-semibold text-red-700 whitespace-nowrap">{{ formatMonto(u.monto_total) }}</td>
                  <td class="px-6 py-3">
                    <button @click="deudaAbierta = u" class="text-sm text-indigo-700 hover:text-indigo-800 font-medium whitespace-nowrap">Ver detalle</button>
                  </td>
                </tr>
                <tr v-if="!deudasFiltradas.length">
                  <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-400">Sin multas pendientes.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <Paginacion
          v-model="paginaDeudas"
          class="mt-4"
          :total="deudasFiltradas.length"
          :por-pagina="DEUDAS_POR_PAGINA"
          singular="persona con deuda"
          plural="personas con deuda"
        />
      </template>

      <!-- Todas las multas -->
      <template v-else>
        <div class="bg-white rounded-xl shadow-md p-4 mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
          <input
            v-model="filtros.q"
            type="text"
            placeholder="Buscar por RUT o nombre"
            class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
          />
          <select v-model="filtros.estado" class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
            <option value="pendiente">Pendientes</option>
            <option value="pagada">Pagadas</option>
            <option value="anulada">Anuladas</option>
            <option value="">Todos los estados</option>
          </select>
          <select v-model="filtros.tipo" class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
            <option value="">Todos los motivos</option>
            <option v-for="(label, clave) in TIPOS" :key="clave" :value="clave">{{ label }}</option>
          </select>
        </div>

        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr class="bg-gray-100 border-b-2 border-gray-200">
                  <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Fecha</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Usuario</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Motivo</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Monto</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Estado</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Acción</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200">
                <tr v-for="(m, idx) in multas" :key="m.id" class="align-top" :class="idx % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                  <td class="px-4 py-3 text-sm font-mono text-gray-600 whitespace-nowrap">{{ formatFecha(m.created_at) }}</td>
                  <td class="px-4 py-3 text-sm text-gray-900">
                    <div class="font-medium">{{ m.usuario?.nombre }} {{ m.usuario?.apellido }}</div>
                    <div class="text-xs font-mono text-gray-500">{{ m.usuario?.rut }}</div>
                    <a
                      v-if="m.usuario?.email"
                      :href="`mailto:${m.usuario.email}`"
                      class="block text-xs text-indigo-700 hover:underline break-all"
                    >
                      {{ m.usuario.email }}
                    </a>
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-700 max-w-xs">
                    <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-indigo-50 text-indigo-700">{{ TIPOS[m.tipo] }}</span>
                    <p class="text-xs text-gray-500 mt-1">{{ m.detalle }}</p>
                  </td>
                  <td class="px-4 py-3 text-sm font-semibold whitespace-nowrap" :class="m.estado === 'pendiente' ? 'text-red-700' : 'text-gray-500'">{{ formatMonto(m.monto) }}</td>
                  <td class="px-4 py-3 text-sm">
                    <span class="text-xs px-2.5 py-1 rounded-full font-medium" :class="ESTADOS[m.estado].cls">{{ ESTADOS[m.estado].label }}</span>
                    <p v-if="m.estado === 'pagada'" class="text-xs text-gray-500 mt-1">{{ formatFecha(m.pagada_en) }} · {{ m.pagada_por?.nombre ?? '—' }}</p>
                    <p v-if="m.estado === 'anulada'" class="text-xs text-gray-500 mt-1">{{ m.anulada_por?.nombre ?? '—' }}: «{{ m.motivo_anulacion }}»</p>
                  </td>
                  <td class="px-4 py-3 text-sm whitespace-nowrap">
                    <template v-if="m.estado === 'pendiente'">
                      <button v-if="auth.puede('multas.cobrar')" @click="multaAPagar = m" class="block text-indigo-700 hover:text-indigo-800 font-medium">Marcar pagada</button>
                      <button v-if="auth.puede('multas.anular')" @click="pedirAnulacion(m)" class="block text-red-600 hover:text-red-700 font-medium mt-1">Anular</button>
                      <span v-if="!auth.puede('multas.cobrar') && !auth.puede('multas.anular')" class="text-xs text-gray-400">—</span>
                    </template>
                  </td>
                </tr>
                <tr v-if="!cargandoListado && !multas.length">
                  <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-400">Sin multas que coincidan con los filtros.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <Paginacion v-model="paginaMultas" class="mt-4" :total="totalMultas" :por-pagina="multasPorPagina" singular="multa" plural="multas" />
      </template>
    </div>

    <!-- Antes que los diálogos de pagar y anular, para que se abran encima del detalle. -->
    <DetalleDeuda
      :deuda="deudaAbierta"
      :version="versionDeuda"
      @cerrar="deudaAbierta = null"
      @pagar="multaAPagar = $event"
      @anular="pedirAnulacion"
      @ver-todas="verEnListado"
    />

    <DialogoConfirmacion
      :abierto="!!multaAPagar"
      titulo="¿Confirmar pago de multa?"
      confirmar="Sí, pagada"
      tono="exito"
      :cargando="pagando"
      @confirmar="confirmarPago"
      @cancelar="multaAPagar = null"
    >
      <p class="mb-2">
        Se registrará el pago de <strong>{{ formatMonto(multaAPagar?.monto ?? 0) }}</strong> de
        <strong>{{ multaAPagar?.usuario?.nombre }} {{ multaAPagar?.usuario?.apellido }}</strong>.
      </p>
      <p>Confirma solo si el usuario ya pagó: queda registrado a tu nombre.</p>
    </DialogoConfirmacion>

    <Modal
      :abierto="!!multaAAnular"
      titulo="Anular multa"
      descripcion="La multa deja de cobrarse pero no se borra: queda en el historial con el motivo y tu nombre."
      ancho="md"
      :bloqueado="anulando"
      @cerrar="multaAAnular = null"
    >
      <div class="text-sm bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-4">
        <p class="font-medium text-gray-900">{{ multaAAnular?.usuario?.nombre }} {{ multaAAnular?.usuario?.apellido }} · {{ formatMonto(multaAAnular?.monto ?? 0) }}</p>
        <p class="text-gray-500">{{ multaAAnular?.detalle }}</p>
      </div>
      <label class="block text-sm font-medium text-gray-700 mb-1" for="motivo-anulacion">Motivo de la anulación</label>
      <textarea
        id="motivo-anulacion"
        v-model="motivoAnulacion"
        rows="3"
        maxlength="500"
        placeholder="Ej: el libro se devolvió a tiempo y el lector registró mal la fecha"
        class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"
      />
      <template #pie>
        <button @click="multaAAnular = null" :disabled="anulando" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm">Cancelar</button>
        <button
          @click="confirmarAnulacion"
          :disabled="anulando || motivoAnulacion.trim().length < 5"
          class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium text-sm disabled:opacity-60"
        >
          {{ anulando ? 'Anulando…' : 'Sí, anular' }}
        </button>
      </template>
    </Modal>

    <Modal
      :abierto="nueva.abierto"
      titulo="Cursar una multa"
      descripcion="Para deterioro, pérdida de material u otro motivo. Las multas por atraso se generan solas al devolver."
      ancho="md"
      :bloqueado="nueva.guardando"
      @cerrar="nueva.abierto = false"
    >
      <div class="space-y-3">
        <div>
          <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
            <label class="block text-sm font-medium text-gray-700" for="multa-rut">{{ modoRutMulta === 'rut' ? 'RUT del usuario' : 'Pasaporte o DNI del usuario' }}</label>
            <SelectorDocumento v-model="modoRutMulta" compacto />
          </div>
          <div class="flex gap-2">
            <input
              id="multa-rut"
              :value="nueva.rut"
              :placeholder="placeholderSegunModo(modoRutMulta)"
              :maxlength="largoSegunModo(modoRutMulta)"
              class="flex-1 min-w-0 px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
              @input="escribirRutMulta($event); nueva.usuario = null; nueva.errorRut = ''"
              @keydown.enter.prevent="buscarUsuarioMulta"
            />
            <button @click="buscarUsuarioMulta" :disabled="nueva.buscando" class="px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm">
              {{ nueva.buscando ? 'Buscando…' : 'Buscar' }}
            </button>
          </div>
          <p v-if="nueva.errorRut" class="text-xs text-red-600 mt-1">{{ nueva.errorRut }}</p>
          <p v-if="nueva.usuario" class="mt-2 text-sm bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg px-3 py-2">
            {{ nueva.usuario.nombre }} {{ nueva.usuario.apellido }} · {{ nueva.usuario.carrera ?? nueva.usuario.tipo }}
          </p>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="multa-tipo">Motivo</label>
            <select id="multa-tipo" v-model="nueva.tipo" :disabled="!nueva.usuario" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm disabled:bg-gray-50">
              <option value="deterioro">Deterioro</option>
              <option value="perdida">Pérdida</option>
              <option value="otro">Otro motivo</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="multa-monto">Monto (CLP)</label>
            <input id="multa-monto" v-model.number="nueva.monto" :disabled="!nueva.usuario" type="number" min="1" step="1" placeholder="25000" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm disabled:bg-gray-50" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="multa-detalle">Descripción</label>
          <textarea
            id="multa-detalle"
            v-model="nueva.detalle"
            :disabled="!nueva.usuario"
            rows="3"
            maxlength="500"
            placeholder="Ej: Pérdida del libro «Cálculo de una variable»"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm disabled:bg-gray-50"
          />
        </div>
      </div>
      <template #pie>
        <button @click="nueva.abierto = false" :disabled="nueva.guardando" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm">Cancelar</button>
        <button
          @click="pedirConfirmacionNueva"
          :disabled="nueva.guardando || !nueva.usuario"
          class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
        >
          Cursar multa
        </button>
      </template>
    </Modal>

    <DialogoConfirmacion
      :abierto="nueva.confirmando"
      titulo="¿Cursar esta multa?"
      confirmar="Sí, cursar multa"
      tono="peligro"
      :cargando="nueva.guardando"
      @confirmar="guardarNueva"
      @cancelar="nueva.confirmando = false"
    >
      <p>
        Se cursará una multa de <strong class="text-gray-900">{{ formatMonto(nueva.monto ?? 0) }}</strong> por {{ TIPOS[nueva.tipo].toLowerCase() }} a
        <strong class="text-gray-900">{{ nueva.usuario?.nombre }} {{ nueva.usuario?.apellido }}</strong>: «{{ nueva.detalle.trim() }}».
      </p>
      <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
        Mientras no la pague o se anule, no podrá pedir préstamos ni hacer reservas.
      </p>
    </DialogoConfirmacion>
  </StaffLayout>
</template>
