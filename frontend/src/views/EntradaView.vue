<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import { formatRut } from '@/composables/useRut'
import type { Entrada } from '@/types'

const toast = useToast()

// Oculto visualmente por mientras (botón, badge y modal) — flujo/backend intactos.
const MOSTRAR_CONVENIO = false

const hoy = new Date().toISOString().slice(0, 10)

const rut = ref('')
const entradas = ref<Entrada[]>([])
const personasEnSala = ref(0)
const apiError = ref(false)
const registrando = ref(false)
const selectedDate = ref(hoy)

// Modo "búsqueda": rango de fechas + texto libre por RUT/nombre, para auditoría
// puntual ("¿cuándo vino esta persona?") — separado del modo "día" que usa mesón
// a diario. Ver EntradaController::index().
const modo = ref<'dia' | 'busqueda'>('dia')
const busquedaDesde = ref('')
const busquedaHasta = ref('')
const busquedaTexto = ref('')
const buscando = ref(false)

const externoModalOpen = ref(false)
const rutExterno = ref('')
const nombreExterno = ref('')
const registrandoExterno = ref(false)

const convenioModalOpen = ref(false)
const rutConvenio = ref('')
const nombreConvenio = ref('')
const registrandoConvenio = ref(false)

const visitaModalOpen = ref(false)
const rutVisita = ref('')
const nombreVisita = ref('')
const registrandoVisita = ref(false)

const VIA_LABELS: Record<string, string> = {
  qr: 'QR Móvil',
  manual: 'Manual',
}

const VIA_STYLES: Record<string, string> = {
  qr: 'bg-violet-50 text-violet-700 border border-violet-200',
  manual: 'bg-slate-100 text-slate-700 border border-slate-200',
}

const TIPO_USUARIO_LABELS: Record<string, string> = {
  estudiante: 'Estudiante',
  docente: 'Docente',
  funcionario: 'Funcionario',
}

const TIPO_USUARIO_STYLES: Record<string, string> = {
  estudiante: 'bg-indigo-50 text-indigo-700 border border-indigo-200',
  docente: 'bg-emerald-50 text-emerald-700 border border-emerald-200',
  funcionario: 'bg-sky-50 text-sky-700 border border-sky-200',
}

function formatHora(iso: string) {
  return new Date(iso).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })
}

async function cargar() {
  const params =
    modo.value === 'busqueda'
      ? {
          desde: busquedaDesde.value || undefined,
          hasta: busquedaHasta.value || undefined,
          q: busquedaTexto.value.trim() || undefined,
        }
      : { fecha: selectedDate.value }

  buscando.value = true
  try {
    const { data } = await api.get('/entrada', { params })
    entradas.value = data.entradas
    // personasEnSala solo viene en modo "día" — en búsqueda se deja el último
    // valor conocido en vez de resetear a 0, para no hacer parpadear el contador.
    if (data.modo === 'dia') personasEnSala.value = data.personasEnSala
    apiError.value = false
  } catch {
    apiError.value = true
    entradas.value = []
  } finally {
    buscando.value = false
  }
}

onMounted(cargar)
watch(selectedDate, cargar)

let busquedaTimer: ReturnType<typeof setTimeout> | undefined
watch([busquedaDesde, busquedaHasta, busquedaTexto], () => {
  if (modo.value !== 'busqueda') return
  clearTimeout(busquedaTimer)
  busquedaTimer = setTimeout(cargar, 300)
})

function cambiarModo(nuevo: 'dia' | 'busqueda') {
  if (modo.value === nuevo) return
  modo.value = nuevo
  if (nuevo === 'dia') {
    cargar()
  } else if (busquedaDesde.value || busquedaHasta.value || busquedaTexto.value.trim()) {
    cargar()
  } else {
    entradas.value = []
  }
}

function formatFechaLarga(fecha: string) {
  return fecha === hoy ? 'hoy' : new Date(`${fecha}T12:00:00`).toLocaleDateString('es-CL')
}

function formatFechaCorta(iso: string) {
  return new Date(iso).toLocaleDateString('es-CL')
}

async function registrarEntrada(via: 'manual' | 'qr' = 'manual') {
  if (!rut.value.trim()) {
    toast.error('Ingrese un RUT para buscar')
    return
  }

  registrando.value = true
  try {
    const { data } = await api.post('/entrada', { rut: rut.value, via })
    toast.success(`Entrada registrada: ${data.usuario.nombre} ${data.usuario.apellido}`)
    rut.value = ''
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo registrar la entrada')
  } finally {
    registrando.value = false
  }
}

function onRutInput(event: Event) {
  rut.value = formatRut((event.target as HTMLInputElement).value)
}

function abrirModalExterno() {
  rutExterno.value = ''
  nombreExterno.value = ''
  externoModalOpen.value = true
}

function onRutExternoInput(event: Event) {
  rutExterno.value = formatRut((event.target as HTMLInputElement).value)
}

async function registrarExterno() {
  if (!rutExterno.value.trim()) {
    toast.error('Ingrese el RUT del visitante')
    return
  }

  registrandoExterno.value = true
  try {
    await api.post('/entrada/externo', {
      rut: rutExterno.value,
      nombre: nombreExterno.value.trim() || undefined,
    })
    toast.success('Entrada de externo registrada')
    externoModalOpen.value = false
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo registrar la entrada')
  } finally {
    registrandoExterno.value = false
  }
}

function abrirModalConvenio() {
  rutConvenio.value = ''
  nombreConvenio.value = ''
  convenioModalOpen.value = true
}

function onRutConvenioInput(event: Event) {
  rutConvenio.value = formatRut((event.target as HTMLInputElement).value)
}

async function registrarConvenio() {
  if (!rutConvenio.value.trim()) {
    toast.error('Ingrese el RUT de la persona de convenio')
    return
  }

  registrandoConvenio.value = true
  try {
    await api.post('/entrada/convenio', {
      rut: rutConvenio.value,
      nombre: nombreConvenio.value.trim() || undefined,
    })
    toast.success('Entrada de convenio registrada')
    convenioModalOpen.value = false
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo registrar la entrada')
  } finally {
    registrandoConvenio.value = false
  }
}

function abrirModalVisita() {
  rutVisita.value = ''
  nombreVisita.value = ''
  visitaModalOpen.value = true
}

function onRutVisitaInput(event: Event) {
  rutVisita.value = formatRut((event.target as HTMLInputElement).value)
}

async function registrarVisita() {
  if (!rutVisita.value.trim()) {
    toast.error('Ingrese el RUT de la visita')
    return
  }

  registrandoVisita.value = true
  try {
    await api.post('/entrada/visita', {
      rut: rutVisita.value,
      nombre: nombreVisita.value.trim() || undefined,
    })
    toast.success('Entrada de visita registrada')
    visitaModalOpen.value = false
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo registrar la entrada')
  } finally {
    registrandoVisita.value = false
  }
}
</script>

<template>
  <StaffLayout>
    <div class="max-w-5xl mx-auto">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="flex items-center justify-between px-6 py-5">
          <div>
            <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Registro de Entrada</h1>
            <p class="text-sm text-white/60 mt-1">Control de acceso a la biblioteca</p>
          </div>
          <div class="bg-white/15 backdrop-blur-sm border border-white/20 rounded-xl px-5 py-3 text-center">
            <p class="text-xs text-white/60">En sala</p>
            <p class="text-2xl font-mono font-bold text-white">{{ personasEnSala }} <span class="text-sm text-white/70">/ 220</span></p>
          </div>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="bg-white rounded-xl shadow-md p-6 mb-6">
        <div class="flex gap-4 flex-wrap">
          <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium text-gray-700 mb-1.5">RUT</label>
            <div class="relative">
              <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
              </svg>
              <input
                :value="rut"
                @input="onRutInput"
                type="text"
                placeholder="12.345.678-5"
                maxlength="12"
                class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none"
                @keydown.enter="registrarEntrada('manual')"
              />
            </div>
          </div>
          <div class="flex items-end gap-2">
            <button
              @click="registrarEntrada('manual')"
              :disabled="registrando"
              class="flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium disabled:opacity-60"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M12.5 7a4 4 0 11-8 0 4 4 0 018 0zM20 8v6m3-3h-6" />
              </svg>
              Registrar Entrada
            </button>
            <button
              @click="abrirModalExterno"
              class="flex items-center gap-2 px-5 py-2.5 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-medium"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1a4 4 0 100-8 4 4 0 000 8zm6 3a4 4 0 00-3-3.87M9 12a4 4 0 100-8 4 4 0 000 8z" />
              </svg>
              Externo
            </button>
            <button
              v-if="MOSTRAR_CONVENIO"
              @click="abrirModalConvenio"
              class="flex items-center gap-2 px-5 py-2.5 bg-sky-50 text-sky-700 border border-sky-200 rounded-lg hover:bg-sky-100 transition-colors font-medium"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              Convenio
            </button>
            <button
              @click="abrirModalVisita"
              class="flex items-center gap-2 px-5 py-2.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-lg hover:bg-emerald-100 transition-colors font-medium"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
              </svg>
              Visita
            </button>
          </div>
        </div>
      </div>

      <div class="bg-[#FCFBF8] rounded-xl shadow-md border border-stone-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-stone-200 bg-white">
          <div class="flex items-center justify-between flex-wrap gap-3 mb-3">
            <h3 class="font-serif font-semibold text-gray-900">
              {{ modo === 'dia' ? `Historial de ${formatFechaLarga(selectedDate)}` : 'Buscar en el historial' }}
            </h3>
            <div class="flex gap-1 bg-stone-100 rounded-lg p-1">
              <button
                @click="cambiarModo('dia')"
                class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors"
                :class="modo === 'dia' ? 'bg-white shadow-sm text-gray-900' : 'text-stone-500 hover:text-stone-700'"
              >
                Por día
              </button>
              <button
                @click="cambiarModo('busqueda')"
                class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors"
                :class="modo === 'busqueda' ? 'bg-white shadow-sm text-gray-900' : 'text-stone-500 hover:text-stone-700'"
              >
                Buscar por rango / RUT / nombre
              </button>
            </div>
          </div>

          <input
            v-if="modo === 'dia'"
            v-model="selectedDate"
            type="date"
            :max="hoy"
            class="px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
          />

          <div v-else class="flex gap-3 flex-wrap">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Desde</label>
              <input v-model="busquedaDesde" type="date" :max="hoy" class="px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Hasta</label>
              <input v-model="busquedaHasta" type="date" :max="hoy" class="px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
            </div>
            <div class="flex-1 min-w-[200px]">
              <label class="block text-xs font-medium text-gray-600 mb-1">RUT o nombre</label>
              <input
                v-model="busquedaTexto"
                type="text"
                placeholder="Buscar por RUT o nombre..."
                class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
              />
            </div>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full border-collapse">
            <thead>
              <tr class="bg-[#F4F1EA] border-b border-stone-300">
                <th v-if="modo === 'busqueda'" class="px-6 py-3 text-left text-xs font-semibold text-stone-600 uppercase tracking-wide border-r border-stone-200">Fecha</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-stone-600 uppercase tracking-wide border-r border-stone-200">Hora</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-stone-600 uppercase tracking-wide border-r border-stone-200">RUT</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-stone-600 uppercase tracking-wide border-r border-stone-200">Nombre</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-stone-600 uppercase tracking-wide">Vía</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(e, idx) in entradas"
                :key="e.id"
                class="border-b border-stone-200 last:border-b-0 transition-colors hover:bg-[#F4F1EA]/70"
                :class="idx % 2 === 0 ? 'bg-white' : 'bg-[#FAF8F3]'"
              >
                <td v-if="modo === 'busqueda'" class="px-6 py-3 text-sm font-mono text-stone-500 border-r border-stone-100">
                  {{ formatFechaCorta(e.fecha_hora_entrada) }}
                </td>
                <td class="px-6 py-3 text-sm font-mono text-stone-500 border-r border-stone-100">
                  {{ formatHora(e.fecha_hora_entrada) }}
                </td>
                <td class="px-6 py-3 text-sm font-mono text-gray-900 border-r border-stone-100">{{ e.usuario?.rut ?? e.rut_externo }}</td>
                <td class="px-6 py-3 text-sm text-gray-900 border-r border-stone-100">
                  <span v-if="e.usuario" class="flex items-center gap-1.5">
                    {{ e.usuario.nombre }} {{ e.usuario.apellido }}
                    <span
                      v-if="e.usuario.tipo && e.usuario.tipo !== 'estudiante'"
                      class="text-[10px] px-1.5 py-0.5 rounded-full font-medium"
                      :class="TIPO_USUARIO_STYLES[e.usuario.tipo]"
                    >
                      {{ TIPO_USUARIO_LABELS[e.usuario.tipo] }}
                    </span>
                  </span>
                  <span v-else class="flex items-center gap-1.5">
                    {{ e.nombre_externo || 'Sin nombre' }}
                    <span
                      v-if="MOSTRAR_CONVENIO && e.es_convenio"
                      class="text-[10px] px-1.5 py-0.5 rounded-full font-medium bg-sky-50 text-sky-700 border border-sky-200"
                    >
                      Convenio
                    </span>
                    <span
                      v-else-if="e.es_visita"
                      class="text-[10px] px-1.5 py-0.5 rounded-full font-medium bg-emerald-50 text-emerald-700 border border-emerald-200"
                    >
                      Visita
                    </span>
                    <span v-else class="text-[10px] px-1.5 py-0.5 rounded-full font-medium bg-amber-50 text-amber-700 border border-amber-200">
                      Externo
                    </span>
                  </span>
                </td>
                <td class="px-6 py-3">
                  <span class="text-xs px-2.5 py-1 rounded-full font-medium" :class="VIA_STYLES[e.via]">
                    {{ VIA_LABELS[e.via] }}
                  </span>
                </td>
              </tr>
              <tr v-if="!entradas.length">
                <td :colspan="modo === 'busqueda' ? 5 : 4" class="px-6 py-8 text-center text-sm text-gray-400">
                  {{ modo === 'busqueda' ? (buscando ? 'Buscando…' : 'Sin resultados para esta búsqueda.') : `Sin registros de entrada para ${formatFechaLarga(selectedDate)}.` }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div
        v-if="externoModalOpen"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="externoModalOpen = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">Registrar visitante externo</h3>
          <p class="text-sm text-gray-500 mb-5">
            Para personas que no están en el sistema institucional. Se guarda directamente con los datos que declaren.
          </p>

          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">RUT</label>
              <input
                :value="rutExterno"
                @input="onRutExternoInput"
                type="text"
                placeholder="12.345.678-5"
                maxlength="12"
                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
                @keydown.enter="registrarExterno"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nombre (opcional)</label>
              <input
                v-model="nombreExterno"
                type="text"
                placeholder="Nombre (opcional)"
                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none"
                @keydown.enter="registrarExterno"
              />
            </div>
          </div>

          <div class="flex gap-3 mt-6">
            <button
              @click="externoModalOpen = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="registrarExterno"
              :disabled="registrandoExterno"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ registrandoExterno ? 'Registrando…' : 'Registrar' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="convenioModalOpen && MOSTRAR_CONVENIO"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="convenioModalOpen = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">Registrar persona de convenio</h3>
          <p class="text-sm text-gray-500 mb-5">
            Para personas cubiertas por un convenio institucional que no están en el sistema.
          </p>

          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">RUT</label>
              <input
                :value="rutConvenio"
                @input="onRutConvenioInput"
                type="text"
                placeholder="12.345.678-5"
                maxlength="12"
                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 outline-none"
                @keydown.enter="registrarConvenio"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nombre (opcional)</label>
              <input
                v-model="nombreConvenio"
                type="text"
                placeholder="Nombre (opcional)"
                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 outline-none"
                @keydown.enter="registrarConvenio"
              />
            </div>
          </div>

          <div class="flex gap-3 mt-6">
            <button
              @click="convenioModalOpen = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="registrarConvenio"
              :disabled="registrandoConvenio"
              class="flex-1 px-4 py-2.5 bg-sky-600 text-white rounded-lg hover:bg-sky-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ registrandoConvenio ? 'Registrando…' : 'Registrar' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="visitaModalOpen"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="visitaModalOpen = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">Registrar visita</h3>
          <p class="text-sm text-gray-500 mb-5">
            Igual que un visitante externo, pero se etiqueta aparte como "Visita" para reportería.
          </p>

          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">RUT</label>
              <input
                :value="rutVisita"
                @input="onRutVisitaInput"
                type="text"
                placeholder="12.345.678-5"
                maxlength="12"
                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 outline-none"
                @keydown.enter="registrarVisita"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nombre (opcional)</label>
              <input
                v-model="nombreVisita"
                type="text"
                placeholder="Nombre (opcional)"
                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 outline-none"
                @keydown.enter="registrarVisita"
              />
            </div>
          </div>

          <div class="flex gap-3 mt-6">
            <button
              @click="visitaModalOpen = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="registrarVisita"
              :disabled="registrandoVisita"
              class="flex-1 px-4 py-2.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ registrandoVisita ? 'Registrando…' : 'Registrar' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </StaffLayout>
</template>
