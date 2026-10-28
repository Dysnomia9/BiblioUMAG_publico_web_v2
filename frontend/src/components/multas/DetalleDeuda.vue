<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { ESTADOS_MULTA, TIPOS_MULTA, fechaMulta, formatMonto, origenMulta } from '@/utils/multas'
import type { DeudaUsuario, Multa, Paginado } from '@/types'

/**
 * Detalle de la deuda de una persona (Multas → Deuda por usuario): cada multa pendiente con
 * su fecha, motivo, el préstamo que la originó y si se importó de Horizon, con Marcar pagada y
 * Anular ahí mismo (los diálogos de confirmación son los de la pantalla, que se abren encima).
 * Debajo, las pagadas y anuladas. Con `version` la pantalla avisa que algo cambió y se recarga.
 */
const props = defineProps<{ deuda: DeudaUsuario | null; version: number }>()
const emit = defineEmits<{ cerrar: []; pagar: [multa: Multa]; anular: [multa: Multa]; verTodas: [rut: string] }>()

const auth = useAuthStore()
const multas = ref<Multa[]>([])
const total = ref(0)
const cargando = ref(false)
const error = ref(false)
const verHistorial = ref(false)
// Más que esto no cabe en el detalle: el resto se ve en «Todas las multas».
const MAXIMO = 100

async function cargar() {
  if (!props.deuda) return
  cargando.value = true
  try {
    const { data } = await api.get<Paginado<Multa>>('/multas', { params: { usuario_id: props.deuda.usuario_id, por_pagina: MAXIMO } })
    multas.value = data.data
    total.value = data.total
    error.value = false
  } catch {
    error.value = true
  } finally {
    cargando.value = false
  }
}

watch(
  () => props.deuda?.usuario_id,
  (id) => {
    multas.value = []
    verHistorial.value = false
    if (id) cargar()
  },
)
watch(() => props.version, cargar)

const pendientes = computed(() => multas.value.filter((m) => m.estado === 'pendiente'))
const historial = computed(() => multas.value.filter((m) => m.estado !== 'pendiente'))
const montoPendiente = computed(() => pendientes.value.reduce((suma, m) => suma + m.monto, 0))
const masAntigua = computed(() => pendientes.value.at(-1)?.created_at ?? null)

function prestamoDe(m: Multa) {
  const p = m.prestamo
  if (!p) return null
  const partes = [`prestado el ${fechaMulta(p.fecha_prestamo)}`]
  if (p.fecha_devolucion) partes.push(`debía volver el ${fechaMulta(p.fecha_devolucion)}`)
  if (p.fecha_devolucion_real) partes.push(`volvió el ${fechaMulta(p.fecha_devolucion_real)}`)
  return { titulo: p.libro_titulo, detalle: partes.join(' · ') }
}
</script>

<template>
  <Modal
    :abierto="!!deuda"
    :titulo="deuda ? `Deuda de ${deuda.nombre} ${deuda.apellido}` : ''"
    :descripcion="deuda ? `${deuda.rut}${deuda.email ? ` · ${deuda.email}` : ''}` : ''"
    ancho="lg"
    pie-fijo
    @cerrar="emit('cerrar')"
  >
    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">No se pudieron cargar las multas. Cierra y vuelve a intentarlo.</p>
    <p v-else-if="cargando && !multas.length" class="py-8 text-center text-sm text-gray-400">Cargando multas…</p>
    <template v-else>
      <div
        class="mb-4 flex flex-wrap items-baseline justify-between gap-2 rounded-xl px-4 py-3"
        :class="pendientes.length ? 'bg-red-50 text-red-800' : 'bg-emerald-50 text-emerald-800'"
      >
        <p v-if="pendientes.length" class="text-sm">
          Debe <strong class="text-lg">{{ formatMonto(montoPendiente) }}</strong> en {{ pendientes.length }}
          {{ pendientes.length === 1 ? 'multa pendiente' : 'multas pendientes' }}
        </p>
        <p v-else class="text-sm font-semibold">Sin deuda pendiente</p>
        <p v-if="masAntigua" class="text-xs">La más antigua, del {{ fechaMulta(masAntigua) }}</p>
      </div>

      <ul class="space-y-3">
        <li v-for="m in pendientes" :key="m.id" class="rounded-xl border border-gray-200 p-4">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1.5">
              <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ TIPOS_MULTA[m.tipo] }}</span>
              <span v-if="m.origen === 'importada'" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800">Importada</span>
              <span class="text-xs text-gray-500">{{ fechaMulta(m.created_at) }}</span>
            </div>
            <p class="text-base font-bold text-red-700">{{ formatMonto(m.monto) }}</p>
          </div>
          <p v-if="m.detalle" class="mt-2 text-sm text-gray-800">{{ m.detalle }}</p>
          <dl class="mt-2 space-y-0.5 text-xs text-gray-500">
            <div v-if="prestamoDe(m)">
              <dt class="sr-only">Préstamo</dt>
              <dd>
                <span class="font-medium text-gray-700">{{ prestamoDe(m)!.titulo }}</span> · {{ prestamoDe(m)!.detalle }}<template v-if="m.dias_atraso">
                  · {{ m.dias_atraso }} {{ m.dias_atraso === 1 ? 'día' : 'días' }} de atraso</template
                >
              </dd>
            </div>
            <div v-else-if="m.dias_atraso">
              <dt class="sr-only">Atraso</dt>
              <dd>{{ m.dias_atraso }} {{ m.dias_atraso === 1 ? 'día' : 'días' }} de atraso</dd>
            </div>
            <div>
              <dt class="sr-only">Origen</dt>
              <dd>{{ origenMulta(m) }}</dd>
            </div>
          </dl>
          <div v-if="auth.puede('multas.cobrar') || auth.puede('multas.anular')" class="mt-3 flex flex-wrap gap-2">
            <button
              v-if="auth.puede('multas.cobrar')"
              type="button"
              class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700"
              @click="emit('pagar', m)"
            >
              Marcar pagada
            </button>
            <button
              v-if="auth.puede('multas.anular')"
              type="button"
              class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 transition-colors hover:bg-red-50"
              @click="emit('anular', m)"
            >
              Anular
            </button>
          </div>
        </li>
      </ul>

      <div v-if="historial.length" class="mt-4">
        <button type="button" class="text-sm font-semibold text-indigo-700 hover:underline" @click="verHistorial = !verHistorial">
          {{ verHistorial ? 'Ocultar pagadas y anuladas' : `Ver pagadas y anuladas (${historial.length})` }}
        </button>
        <ul v-if="verHistorial" class="mt-2 divide-y divide-gray-100 rounded-xl border border-gray-200">
          <li v-for="m in historial" :key="m.id" class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1 px-4 py-2.5 text-sm">
            <div class="min-w-0">
              <p class="text-gray-800">
                <span class="text-xs text-gray-500">{{ fechaMulta(m.created_at) }} · {{ TIPOS_MULTA[m.tipo] }}</span>
                <span v-if="m.origen === 'importada'" class="ml-1 text-xs text-amber-700">· importada</span>
              </p>
              <p v-if="m.detalle" class="text-xs text-gray-500">{{ m.detalle }}</p>
              <p class="text-xs text-gray-500">
                <template v-if="m.estado === 'pagada'">Pagada el {{ fechaMulta(m.pagada_en) }}<template v-if="m.pagada_por"> · {{ m.pagada_por.nombre }}</template></template>
                <template v-else>Anulada<template v-if="m.anulada_por"> por {{ m.anulada_por.nombre }}</template>: «{{ m.motivo_anulacion }}»</template>
              </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
              <span class="font-semibold text-gray-500">{{ formatMonto(m.monto) }}</span>
              <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="ESTADOS_MULTA[m.estado].cls">{{ ESTADOS_MULTA[m.estado].label }}</span>
            </div>
          </li>
        </ul>
      </div>
      <p v-if="total > MAXIMO" class="mt-3 text-xs text-gray-500">Se muestran las {{ MAXIMO }} más recientes de {{ total }}: el resto, en Todas las multas.</p>
    </template>

    <template #pie>
      <button
        type="button"
        class="px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm sm:mr-auto"
        @click="emit('cerrar')"
      >
        Cerrar
      </button>
      <button
        v-if="deuda"
        type="button"
        class="px-4 py-2.5 text-sm font-semibold text-indigo-700 hover:underline"
        @click="emit('verTodas', deuda.rut)"
      >
        Ver en Todas las multas →
      </button>
    </template>
  </Modal>
</template>
