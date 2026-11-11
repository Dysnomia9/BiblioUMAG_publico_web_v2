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
  <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
    <header class="border-b border-gray-100 bg-indigo-50/40 px-5 py-3">
      <h3 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
        <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
        Días sin atención
      </h3>
      <p class="mt-0.5 max-w-3xl pl-3 text-xs text-gray-500">
        Feriados, vacaciones y cierres. Ninguna devolución puede caer en estos días y no se cobran como atraso. Sin ubicación, cierra toda la biblioteca; con
        ubicación, solo afecta al material que está ahí. Los días que no se abre cada semana (domingos) ya están en el horario.
      </p>
    </header>

    <div class="space-y-3 p-4">
      <div class="grid grid-cols-1 gap-3 rounded-xl border border-dashed border-indigo-200 bg-indigo-50/20 p-3 sm:grid-cols-[10rem_12rem_minmax(0,1fr)_auto] sm:items-end">
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
        <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] transition-colors hover:bg-indigo-700" @click="pedirConfirmacion">
          Agregar
        </button>
      </div>

      <div v-if="dias.length" class="divide-y divide-gray-100 rounded-lg border border-gray-200">
        <div v-for="d in dias" :key="d.id" class="flex items-center justify-between gap-3 px-4 py-2.5" :class="d.fecha < hoy ? 'bg-gray-50/60' : ''">
          <div class="min-w-0">
            <p class="text-sm font-bold first-letter:uppercase" :class="d.fecha < hoy ? 'text-gray-500' : 'text-gray-900'">{{ fechaLarga(d.fecha) }}</p>
            <p class="mt-1 flex flex-wrap gap-1.5 text-xs">
              <span class="rounded border border-gray-200 bg-white px-1.5 py-px text-gray-700">{{ d.ubicacion?.nombre ?? 'Toda la biblioteca' }}</span>
              <span v-if="d.motivo" class="rounded border border-amber-200 bg-amber-50 px-1.5 py-px text-amber-800">{{ d.motivo }}</span>
            </p>
          </div>
          <button type="button" class="shrink-0 text-xs font-semibold text-red-700 hover:underline" @click="aQuitar = d">
            Quitar
          </button>
        </div>
      </div>
      <div v-else class="flex flex-col items-center gap-2 rounded-lg border border-gray-200 px-4 py-5 text-center">
        <svg class="h-9 w-9 text-indigo-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.750 3v2.250M17.250 3v2.250M3 18.750V7.500a2.250 2.250 0 012.250-2.250h13.500A2.250 2.250 0 0121 7.500v11.250m-18 0A2.250 2.250 0 005.250 21h13.500A2.250 2.250 0 0021 18.750m-18 0v-7.500A2.250 2.250 0 015.250 9h13.500A2.250 2.250 0 0121 11.250v7.500" /></svg>
        <p class="text-sm text-gray-500">{{ verAnteriores ? 'Sin días sin atención registrados.' : 'Sin días sin atención de aquí en adelante.' }}</p>
      </div>
      <button type="button" class="text-xs font-semibold text-indigo-700 hover:underline" @click="alternarAnteriores">
        {{ verAnteriores ? 'Ver solo los próximos' : 'Ver también los pasados' }}
      </button>
    </div>

    <DialogoConfirmacion :abierto="nuevo.confirmando" titulo="¿Registrar este día sin atención?" confirmar="Sí, registrar" destacado :cargando="nuevo.guardando" @confirmar="crear" @cancelar="nuevo.confirmando = false">
      <div class="rounded-lg border border-l-[3px] border-gray-200 border-l-indigo-600 bg-indigo-50/30 px-4 py-3">
        <p class="font-bold text-gray-900 first-letter:uppercase">{{ nuevo.fecha ? fechaLarga(nuevo.fecha) : '' }}</p>
        <p class="mt-1.5 flex flex-wrap gap-1.5 text-xs">
          <span class="rounded border border-gray-200 bg-white px-1.5 py-px text-gray-700">{{ ubicacionNueva ?? 'Toda la biblioteca' }}</span>
          <span v-if="nuevo.motivo.trim()" class="rounded border border-amber-200 bg-amber-50 px-1.5 py-px text-amber-800">{{ nuevo.motivo.trim() }}</span>
        </p>
      </div>
      <p class="mt-3">Desde ahora ninguna devolución podrá quedar para ese día, y no se cobrará como atraso.</p>
    </DialogoConfirmacion>

    <DialogoConfirmacion :abierto="!!aQuitar" titulo="¿Quitar este día sin atención?" confirmar="Sí, quitar" tono="peligro" destacado :cargando="quitando" @confirmar="quitar" @cancelar="aQuitar = null">
      <p v-if="aQuitar">
        El <strong class="text-gray-900">{{ fechaLarga(aQuitar.fecha) }}</strong> volverá a ser un día con atención<template v-if="aQuitar.ubicacion"> en {{ aQuitar.ubicacion.nombre }}</template>.
      </p>
    </DialogoConfirmacion>
  </section>
</template>
