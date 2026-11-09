<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { textoMinutos } from '@/utils/reservas'
import type { PeriodoPlazoSala, PlazoSalas } from '@/types'

/**
 * Cuánto tiene un grupo para presentarse en el mesón antes de que su sala se libere: un
 * tiempo general y fechas con otro tiempo, como la semana de pruebas, que empiezan y
 * terminan solas. Cada reserva guarda el suyo al hacerse: un cambio vale para las siguientes.
 */
const toast = useToast()
const hoy = new Date().toLocaleDateString('sv-SE')

const apiError = ref(false)
const cargando = ref(true)
const general = ref(15)
const periodos = ref<PeriodoPlazoSala[]>([])
const minimo = ref(1)
const maximo = ref(30)

async function cargar() {
  try {
    const { data } = await api.get<PlazoSalas>('/plazo-salas')
    general.value = data.general
    periodos.value = data.periodos
    minimo.value = data.minimo
    maximo.value = data.maximo
    apiError.value = false
  } catch {
    apiError.value = true
  } finally {
    cargando.value = false
  }
}

onMounted(async () => {
  await cargar()
  formGeneral.minutos = general.value
})

const periodoDeHoy = computed(() => periodos.value.find((p) => p.desde <= hoy && p.hasta >= hoy) ?? null)
const minutosDeHoy = computed(() => periodoDeHoy.value?.minutos ?? general.value)

const fecha = (dia: string) => new Date(`${dia}T12:00:00`).toLocaleDateString('es-CL', { day: 'numeric', month: 'long', year: 'numeric' })
const rangoDe = (p: { desde: string; hasta: string }) => (p.desde === p.hasta ? `El ${fecha(p.desde)}` : `Del ${fecha(p.desde)} al ${fecha(p.hasta)}`)

const minutosValidos = (minutos: unknown) => Number.isInteger(minutos) && (minutos as number) >= minimo.value && (minutos as number) <= maximo.value
const textoRango = computed(() => `El tiempo va de ${minimo.value} a ${maximo.value} minutos`)

// ---------- Tiempo general ----------
const formGeneral = reactive({ minutos: 15 as number | '', confirmando: false, guardando: false })

function pedirCambioGeneral() {
  if (!minutosValidos(formGeneral.minutos)) {
    toast.error(textoRango.value)
    return
  }
  if (formGeneral.minutos === general.value) {
    toast.info('No hubo cambios que guardar')
    return
  }
  formGeneral.confirmando = true
}

async function guardarGeneral() {
  formGeneral.guardando = true
  try {
    await api.put('/plazo-salas', { minutos: formGeneral.minutos })
    toast.success('Tiempo actualizado. Vale para las reservas que se hagan desde ahora.')
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo cambiar el tiempo'))
  } finally {
    formGeneral.guardando = false
    formGeneral.confirmando = false
  }
}

// ---------- Fechas con otro tiempo ----------
const nuevo = reactive({ desde: '', hasta: '', minutos: 5 as number | '', motivo: '', confirmando: false, guardando: false })

function pedirPeriodo() {
  if (!nuevo.desde || !nuevo.hasta) {
    toast.error('Elige las dos fechas')
    return
  }
  if (nuevo.hasta < nuevo.desde || nuevo.hasta < hoy) {
    toast.error('La fecha final no puede ser anterior a la inicial ni a hoy')
    return
  }
  if (!minutosValidos(nuevo.minutos)) {
    toast.error(textoRango.value)
    return
  }
  nuevo.confirmando = true
}

async function crearPeriodo() {
  nuevo.guardando = true
  try {
    await api.post('/plazo-salas/periodos', { desde: nuevo.desde, hasta: nuevo.hasta, minutos: nuevo.minutos, motivo: nuevo.motivo.trim() || null })
    toast.success('Fechas agregadas')
    Object.assign(nuevo, { desde: '', hasta: '', motivo: '' })
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudieron agregar las fechas'))
  } finally {
    nuevo.guardando = false
    nuevo.confirmando = false
  }
}

const aQuitar = ref<PeriodoPlazoSala | null>(null)
const quitando = ref(false)

async function quitar() {
  if (!aQuitar.value) return
  quitando.value = true
  try {
    await api.delete(`/plazo-salas/periodos/${aQuitar.value.id}`)
    toast.success('Fechas quitadas')
    aQuitar.value = null
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudieron quitar las fechas'))
  } finally {
    quitando.value = false
  }
}

const CAMPO = 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500'
const NOTA = 'Vale para las reservas que se hagan desde ahora. Las que ya están hechas conservan el tiempo que tenían.'
</script>

<template>
  <ApiErrorBanner v-if="apiError" />

  <template v-else-if="!cargando">
    <div class="bg-white rounded-xl shadow-md p-6">
      <h3 class="font-semibold text-gray-900 mb-1">Tiempo para presentarse a una sala</h3>
      <p class="text-xs text-gray-500 mb-4 max-w-xl">
        Cuánto tiene un grupo, desde la hora de inicio de su reserva, para presentarse en el mesón. Si no llega, la sala se libera sola y queda registrada la inasistencia.
      </p>

      <p class="mb-4 rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-950">
        Hoy rige: <strong>{{ textoMinutos(minutosDeHoy) }}</strong><template v-if="periodoDeHoy"> ({{ periodoDeHoy.motivo ?? 'fechas con otro tiempo' }})</template>.
      </p>

      <div class="flex flex-wrap items-end gap-3">
        <div class="w-40">
          <label for="plazo-general" class="mb-1 block text-xs font-medium text-gray-600">Tiempo general (minutos)</label>
          <input id="plazo-general" v-model.number="formGeneral.minutos" type="number" :min="minimo" :max="maximo" :class="CAMPO" />
        </div>
        <button type="button" class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm" @click="pedirCambioGeneral">Guardar</button>
      </div>
      <p class="mt-2 text-xs text-gray-500">{{ NOTA }}</p>
    </div>

    <div class="bg-white rounded-xl shadow-md p-6">
      <h3 class="font-semibold text-gray-900 mb-1">Fechas con otro tiempo</h3>
      <p class="text-xs text-gray-500 mb-4 max-w-xl">
        Para las semanas en que las salas se usan más, como las de pruebas: las reservas de esos días tienen este tiempo y, al terminar, vuelve solo el tiempo general.
      </p>

      <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-[10rem_10rem_6rem_minmax(0,1fr)_auto] sm:items-end">
        <div>
          <label for="plazo-desde" class="mb-1 block text-xs font-medium text-gray-600">Desde</label>
          <input id="plazo-desde" v-model="nuevo.desde" type="date" :class="CAMPO" />
        </div>
        <div>
          <label for="plazo-hasta" class="mb-1 block text-xs font-medium text-gray-600">Hasta</label>
          <input id="plazo-hasta" v-model="nuevo.hasta" type="date" :min="nuevo.desde || hoy" :class="CAMPO" />
        </div>
        <div>
          <label for="plazo-minutos" class="mb-1 block text-xs font-medium text-gray-600">Minutos</label>
          <input id="plazo-minutos" v-model.number="nuevo.minutos" type="number" :min="minimo" :max="maximo" :class="CAMPO" />
        </div>
        <div>
          <label for="plazo-motivo" class="mb-1 block text-xs font-medium text-gray-600">Motivo</label>
          <input id="plazo-motivo" v-model="nuevo.motivo" maxlength="255" placeholder="Ej: Semana de pruebas" :class="CAMPO" />
        </div>
        <button type="button" class="px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm" @click="pedirPeriodo">Agregar</button>
      </div>

      <div class="rounded-lg border border-gray-200 divide-y divide-gray-100">
        <div v-for="p in periodos" :key="p.id" class="flex items-center justify-between gap-3 px-4 py-2.5">
          <div class="min-w-0">
            <p class="text-sm font-medium text-gray-900">
              {{ textoMinutos(p.minutos) }}
              <span v-if="p.id === periodoDeHoy?.id" class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">Rige hoy</span>
            </p>
            <p class="text-xs text-gray-500">{{ rangoDe(p) }}<template v-if="p.motivo"> · {{ p.motivo }}</template></p>
          </div>
          <button type="button" class="shrink-0 text-sm font-medium text-red-600 hover:text-red-700" @click="aQuitar = p">Quitar</button>
        </div>
        <p v-if="!periodos.length" class="px-4 py-6 text-center text-sm text-gray-400">Sin fechas con otro tiempo: rige el tiempo general.</p>
      </div>
    </div>

    <DialogoConfirmacion :abierto="formGeneral.confirmando" titulo="¿Cambiar el tiempo general?" confirmar="Sí, cambiar" :cargando="formGeneral.guardando" @confirmar="guardarGeneral" @cancelar="formGeneral.confirmando = false">
      <p>De {{ textoMinutos(general) }} a <strong class="text-gray-900">{{ textoMinutos(Number(formGeneral.minutos)) }}</strong>. {{ NOTA }}</p>
      <p v-if="periodoDeHoy" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
        Hoy rigen otras fechas con {{ textoMinutos(periodoDeHoy.minutos) }}: este cambio se notará cuando terminen.
      </p>
    </DialogoConfirmacion>

    <DialogoConfirmacion :abierto="nuevo.confirmando" titulo="¿Agregar estas fechas?" confirmar="Sí, agregar" :cargando="nuevo.guardando" @confirmar="crearPeriodo" @cancelar="nuevo.confirmando = false">
      <p>
        {{ rangoDe(nuevo) }}: <strong class="text-gray-900">{{ textoMinutos(Number(nuevo.minutos)) }}</strong> para presentarse<template v-if="nuevo.motivo.trim()"> ({{ nuevo.motivo.trim() }})</template>.
      </p>
      <p class="mt-2">{{ NOTA }}</p>
    </DialogoConfirmacion>

    <DialogoConfirmacion :abierto="!!aQuitar" titulo="¿Quitar estas fechas?" confirmar="Sí, quitar" tono="peligro" :cargando="quitando" @confirmar="quitar" @cancelar="aQuitar = null">
      <p v-if="aQuitar">{{ rangoDe(aQuitar) }}: las reservas que se hagan para esos días tendrán {{ textoMinutos(general) }}. Las que ya están hechas conservan el tiempo que tenían.</p>
    </DialogoConfirmacion>
  </template>
</template>
