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
    <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
      <header class="border-b border-gray-100 bg-indigo-50/40 px-5 py-3">
        <h3 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
          <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
          Tiempo para presentarse a una sala
        </h3>
        <p class="mt-0.5 max-w-3xl pl-3 text-xs text-gray-500">Cuánto tiene un grupo, desde la hora de inicio de su reserva, para presentarse en el mesón. Si no llega, la sala se libera sola y queda registrada la inasistencia.</p>
      </header>

      <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-[16rem_minmax(0,1fr)] sm:items-start">
        <div class="rounded-xl border border-indigo-100 bg-indigo-50/60 px-4 py-3">
          <p class="text-xs text-gray-600">Hoy rige</p>
          <p class="font-serif text-2xl font-bold leading-tight text-indigo-900">{{ textoMinutos(minutosDeHoy) }}</p>
          <p v-if="periodoDeHoy" class="text-xs text-indigo-800">{{ periodoDeHoy.motivo ?? 'fechas con otro tiempo' }}</p>
        </div>
        <div>
          <label for="plazo-general" class="mb-1 block text-xs font-medium text-gray-600">Tiempo general (minutos)</label>
          <div class="flex items-center gap-2.5">
            <input id="plazo-general" v-model.number="formGeneral.minutos" type="number" :min="minimo" :max="maximo" :class="CAMPO" class="!w-28" />
            <button type="button" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] transition-colors hover:bg-indigo-700" @click="pedirCambioGeneral">Guardar</button>
          </div>
          <p class="mt-1.5 text-[11px] text-gray-500">{{ NOTA }}</p>
        </div>
      </div>
    </section>

    <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
      <header class="border-b border-gray-100 bg-indigo-50/40 px-5 py-3">
        <h3 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
          <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
          Fechas con otro tiempo
        </h3>
        <p class="mt-0.5 max-w-3xl pl-3 text-xs text-gray-500">Para las semanas en que las salas se usan más, como las de pruebas: las reservas de esos días tienen este tiempo y, al terminar, vuelve solo el tiempo general.</p>
      </header>

      <div class="space-y-3 p-4">
        <div class="grid grid-cols-1 gap-3 rounded-xl border border-dashed border-indigo-200 bg-indigo-50/20 p-3 sm:grid-cols-[10rem_10rem_6rem_minmax(0,1fr)_auto] sm:items-end">
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
          <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] transition-colors hover:bg-indigo-700" @click="pedirPeriodo">Agregar</button>
        </div>

        <div v-if="periodos.length" class="divide-y divide-gray-100 rounded-lg border border-gray-200">
          <div v-for="p in periodos" :key="p.id" class="flex items-center justify-between gap-3 px-4 py-2.5">
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-2 text-sm font-bold text-gray-900">
                {{ textoMinutos(p.minutos) }}
                <span v-if="p.id === periodoDeHoy?.id" class="rounded border border-emerald-300 bg-emerald-50/60 px-1.5 py-px text-[10px] font-bold uppercase tracking-widest text-emerald-700">Rige hoy</span>
              </p>
              <p class="mt-0.5 text-xs text-gray-500">{{ rangoDe(p) }}<template v-if="p.motivo"> · {{ p.motivo }}</template></p>
            </div>
            <button type="button" class="shrink-0 text-xs font-semibold text-red-700 hover:underline" @click="aQuitar = p">Quitar</button>
          </div>
        </div>
        <div v-else class="flex flex-col items-center gap-2 rounded-lg border border-gray-200 px-4 py-5 text-center">
          <svg class="h-9 w-9 text-indigo-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l3.500 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          <p class="text-sm text-gray-500">Sin fechas con otro tiempo: rige el tiempo general.</p>
        </div>
      </div>
    </section>

    <DialogoConfirmacion :abierto="formGeneral.confirmando" titulo="¿Cambiar el tiempo general?" confirmar="Sí, cambiar" destacado :cargando="formGeneral.guardando" @confirmar="guardarGeneral" @cancelar="formGeneral.confirmando = false">
      <div class="rounded-lg border border-l-[3px] border-gray-200 border-l-indigo-600 bg-indigo-50/30 px-4 py-3">
        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Tiempo general</p>
        <p class="mt-0.5 text-gray-700"><span class="text-gray-400 line-through">{{ textoMinutos(general) }}</span> → <strong class="text-gray-900">{{ textoMinutos(Number(formGeneral.minutos)) }}</strong></p>
      </div>
      <p class="mt-3">{{ NOTA }}</p>
      <p v-if="periodoDeHoy" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
        Hoy rigen otras fechas con {{ textoMinutos(periodoDeHoy.minutos) }}: este cambio se notará cuando terminen.
      </p>
    </DialogoConfirmacion>

    <DialogoConfirmacion :abierto="nuevo.confirmando" titulo="¿Agregar estas fechas?" confirmar="Sí, agregar" destacado :cargando="nuevo.guardando" @confirmar="crearPeriodo" @cancelar="nuevo.confirmando = false">
      <div class="rounded-lg border border-l-[3px] border-gray-200 border-l-indigo-600 bg-indigo-50/30 px-4 py-3">
        <p class="font-bold text-gray-900">{{ rangoDe(nuevo) }}</p>
        <p class="mt-0.5 text-gray-700"><strong class="text-gray-900">{{ textoMinutos(Number(nuevo.minutos)) }}</strong> para presentarse</p>
        <span v-if="nuevo.motivo.trim()" class="mt-1.5 inline-block rounded border border-amber-200 bg-amber-50 px-1.5 py-px text-xs text-amber-800">{{ nuevo.motivo.trim() }}</span>
      </div>
      <p class="mt-3">{{ NOTA }}</p>
    </DialogoConfirmacion>

    <DialogoConfirmacion :abierto="!!aQuitar" titulo="¿Quitar estas fechas?" confirmar="Sí, quitar" tono="peligro" destacado :cargando="quitando" @confirmar="quitar" @cancelar="aQuitar = null">
      <template v-if="aQuitar">
        <p><strong class="text-gray-900">{{ rangoDe(aQuitar) }}</strong>: las reservas que se hagan para esos días tendrán {{ textoMinutos(general) }}.</p>
        <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2.5 text-xs">Las que ya están hechas conservan el tiempo que tenían.</p>
      </template>
    </DialogoConfirmacion>
  </template>
</template>
