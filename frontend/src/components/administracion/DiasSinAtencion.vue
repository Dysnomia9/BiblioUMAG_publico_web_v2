<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import type { DiaSinAtencion, Ubicacion } from '@/types'

/**
 * Feriados, vacaciones y cierres. Una devolución no puede caer en ellos (la fecha que
 * propone una regla de préstamo se corre al siguiente día con atención) y no se cobran
 * como atraso. Los días que la biblioteca no abre cada semana ya están en el horario.
 */
const toast = useToast()

const hoy = new Date().toLocaleDateString('sv-SE')
const dias = ref<DiaSinAtencion[]>([])
const ubicaciones = ref<Ubicacion[]>([])
const verAnteriores = ref(false)

async function cargar() {
  try {
    const [d, u] = await Promise.all([
      api.get<DiaSinAtencion[]>('/dias-sin-atencion', { params: { desde: verAnteriores.value ? undefined : hoy } }),
      api.get<Ubicacion[]>('/ubicaciones'),
    ])
    dias.value = d.data
    ubicaciones.value = u.data
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudieron cargar los días sin atención'))
  }
}

onMounted(cargar)

function alternarAnteriores() {
  verAnteriores.value = !verAnteriores.value
  cargar()
}

const fechaLarga = (fecha: string) =>
  new Date(`${fecha}T12:00:00`).toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

const nuevo = reactive({ fecha: '', ubicacion_id: '' as number | '', motivo: '', confirmando: false, guardando: false })
const ubicacionNueva = computed(() => ubicaciones.value.find((u) => u.id === nuevo.ubicacion_id)?.nombre ?? null)

function pedirConfirmacion() {
  if (!nuevo.fecha) {
    toast.error('Elige la fecha')
    return
  }
  nuevo.confirmando = true
}

async function crear() {
  nuevo.guardando = true
  try {
    await api.post('/dias-sin-atencion', {
      fecha: nuevo.fecha,
      ubicacion_id: nuevo.ubicacion_id === '' ? null : nuevo.ubicacion_id,
      motivo: nuevo.motivo.trim() || null,
    })
    toast.success('Día sin atención registrado')
    Object.assign(nuevo, { fecha: '', motivo: '', confirmando: false })
    await cargar()
  } catch (e) {
    nuevo.confirmando = false
    toast.error(mensajeDeError(e, 'No se pudo registrar el día'))
  } finally {
    nuevo.guardando = false
  }
}

const aQuitar = ref<DiaSinAtencion | null>(null)
const quitando = ref(false)

async function quitar() {
  if (!aQuitar.value) return
  quitando.value = true
  try {
    await api.delete(`/dias-sin-atencion/${aQuitar.value.id}`)
    toast.success('Día quitado')
    aQuitar.value = null
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo quitar el día'))
  } finally {
    quitando.value = false
  }
}

const CAMPO = 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500'
</script>

<template>
  <div class="bg-white rounded-xl shadow-md p-6">
    <h3 class="font-semibold text-gray-900 mb-1">Días sin atención</h3>
    <p class="text-xs text-gray-500 mb-4 max-w-xl">
      Feriados, vacaciones y cierres. Ninguna devolución puede caer en estos días y no se cobran como atraso. Sin ubicación, cierra toda la biblioteca; con
      ubicación, solo afecta al material que está ahí. Los días que no se abre cada semana (domingos) ya están en el horario.
    </p>

    <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-[10rem_12rem_minmax(0,1fr)_auto] sm:items-end">
      <div>
        <label for="dia-fecha" class="mb-1 block text-xs font-medium text-gray-600">Fecha</label>
        <input id="dia-fecha" v-model="nuevo.fecha" type="date" :class="CAMPO" />
      </div>
      <div>
        <label for="dia-ubicacion" class="mb-1 block text-xs font-medium text-gray-600">Ubicación</label>
        <select id="dia-ubicacion" v-model="nuevo.ubicacion_id" :class="CAMPO">
          <option value="">Toda la biblioteca</option>
          <option v-for="u in ubicaciones" :key="u.id" :value="u.id">{{ u.nombre }}</option>
        </select>
      </div>
      <div>
        <label for="dia-motivo" class="mb-1 block text-xs font-medium text-gray-600">Motivo</label>
        <input id="dia-motivo" v-model="nuevo.motivo" maxlength="255" placeholder="Ej: Fiestas Patrias, vacaciones de invierno" :class="CAMPO" />
      </div>
      <button type="button" class="px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm" @click="pedirConfirmacion">
        Agregar
      </button>
    </div>

    <div class="rounded-lg border border-gray-200 divide-y divide-gray-100">
      <div v-for="d in dias" :key="d.id" class="flex items-center justify-between gap-3 px-4 py-2.5" :class="d.fecha < hoy ? 'bg-gray-50 text-gray-500' : ''">
        <div class="min-w-0">
          <p class="text-sm font-medium text-gray-900 first-letter:uppercase">{{ fechaLarga(d.fecha) }}</p>
          <p class="text-xs text-gray-500">{{ d.ubicacion?.nombre ?? 'Toda la biblioteca' }}<template v-if="d.motivo"> · {{ d.motivo }}</template></p>
        </div>
        <button type="button" class="shrink-0 text-sm font-medium text-red-600 hover:text-red-700" @click="aQuitar = d">Quitar</button>
      </div>
      <p v-if="!dias.length" class="px-4 py-6 text-center text-sm text-gray-400">
        {{ verAnteriores ? 'Sin días sin atención registrados.' : 'Sin días sin atención de aquí en adelante.' }}
      </p>
    </div>
    <button type="button" class="mt-3 text-xs font-medium text-indigo-700 hover:underline" @click="alternarAnteriores">
      {{ verAnteriores ? 'Ver solo los próximos' : 'Ver también los pasados' }}
    </button>

    <DialogoConfirmacion :abierto="nuevo.confirmando" titulo="¿Registrar este día sin atención?" confirmar="Sí, registrar" :cargando="nuevo.guardando" @confirmar="crear" @cancelar="nuevo.confirmando = false">
      <p>
        <strong class="text-gray-900 first-letter:uppercase">{{ nuevo.fecha ? fechaLarga(nuevo.fecha) : '' }}</strong>
        sin atención en {{ ubicacionNueva ?? 'toda la biblioteca' }}<template v-if="nuevo.motivo.trim()"> ({{ nuevo.motivo.trim() }})</template>.
      </p>
      <p class="mt-2">Desde ahora ninguna devolución podrá quedar para ese día, y no se cobrará como atraso.</p>
    </DialogoConfirmacion>

    <DialogoConfirmacion :abierto="!!aQuitar" titulo="¿Quitar este día sin atención?" confirmar="Sí, quitar" tono="peligro" :cargando="quitando" @confirmar="quitar" @cancelar="aQuitar = null">
      <p v-if="aQuitar">
        El {{ fechaLarga(aQuitar.fecha) }} volverá a ser un día con atención<template v-if="aQuitar.ubicacion"> en {{ aQuitar.ubicacion.nombre }}</template>.
      </p>
    </DialogoConfirmacion>
  </div>
</template>
