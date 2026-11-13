<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import FondoListadoReservas from '@/components/salas/FondoListadoReservas.vue'
import Paginador from '@/components/ui/Paginador.vue'
import DetalleReserva from '@/components/salas/DetalleReserva.vue'
import SeriesReservas from '@/components/salas/SeriesReservas.vue'
import AvisoMultaAtraso from '@/components/salas/AvisoMultaAtraso.vue'
import AvisoTiempoSinUsar from '@/components/salas/AvisoTiempoSinUsar.vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { SITUACIONES, duracion, fechaReserva, horaDe, reservaEditable } from '@/utils/reservas'
import type { AtrasoDevolucion, ListadoReservas, ReservaListado, SituacionReserva, TiempoSinUsar, VistaReservas } from '@/types'

const auth = useAuthStore()
// Ver el listado es de quien ve los listados; cambiar una reserva, del permiso de editarlas.
const puedeEditar = auth.puede('reservas.editar')

// Pestaña, sala, fechas y búsqueda se resuelven en el servidor y la lista llega de a 50:
// son todas las reservas de salas de la historia de la biblioteca.
const reservas = ref<ReservaListado[]>([])
const conteos = ref<Record<VistaReservas, number>>({ activas: 0, finalizadas: 0, no_show: 0, canceladas: 0, todas: 0 })
const salas = ref<ListadoReservas['salas']>([])
const meta = ref({ current_page: 1, last_page: 1, total: 0, from: null as number | null, to: null as number | null })
const cargando = ref(true)
const apiError = ref(false)

const busqueda = ref('')
const salaId = ref<number | ''>('')
const desde = ref('')
const hasta = ref('')
// Se abre en Todas: la reserva que se busca puede estar en cualquier estado.
const vista = ref<VistaReservas>('todas')
const pagina = ref(1)

const seleccionada = ref<ReservaListado | null>(null)
// Si la llave volvió fuera de hora, el aviso para decidir si se cursa la multa.
const atrasoDevolucion = ref<AtrasoDevolucion | null>(null)
// Si volvió antes de tiempo, el aviso para liberar lo que quedaba del bloque.
const tiempoSinUsar = ref<TiempoSinUsar | null>(null)

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<ListadoReservas>('/reservas', {
      params: {
        vista: vista.value,
        sala_id: salaId.value || undefined,
        desde: desde.value || undefined,
        hasta: hasta.value || undefined,
        q: busqueda.value.trim() || undefined,
        page: pagina.value,
        por_pagina: 50,
      },
    })
    reservas.value = data.data
    conteos.value = data.conteos
    salas.value = data.salas
    meta.value = { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to }
    apiError.value = false
  } catch {
    apiError.value = true
    reservas.value = []
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

// Un filtro nuevo vuelve a la primera página; la búsqueda espera a que se deje de escribir.
let busquedaTimer: ReturnType<typeof setTimeout> | undefined
watch(busqueda, () => {
  clearTimeout(busquedaTimer)
  busquedaTimer = setTimeout(() => {
    pagina.value = 1
    cargar()
  }, 300)
})
watch([vista, salaId, desde, hasta], () => {
  pagina.value = 1
  cargar()
})

function irAPagina(nueva: number) {
  pagina.value = nueva
  cargar()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function limpiarFechas() {
  desde.value = ''
  hasta.value = ''
}

const VISTAS = [
  { id: 'todas' as const, texto: 'Todas', punto: '', cuenta: 'bg-gray-100 text-gray-600' },
  { id: 'activas' as const, texto: 'Activas', punto: 'bg-emerald-500', cuenta: 'bg-emerald-50 text-emerald-700' },
  { id: 'finalizadas' as const, texto: 'Finalizadas', punto: 'bg-sky-500', cuenta: 'bg-sky-50 text-sky-700' },
  { id: 'no_show' as const, texto: 'No se presentaron', punto: 'bg-orange-500', cuenta: 'bg-orange-50 text-orange-700' },
  { id: 'canceladas' as const, texto: 'Canceladas', punto: 'bg-gray-400', cuenta: 'bg-gray-100 text-gray-600' },
]

// El borde izquierdo de cada fila: el color de su situación.
const BARRA_SITUACION: Record<SituacionReserva, string> = {
  pendiente: 'bg-amber-500',
  en_uso: 'bg-indigo-500',
  sin_devolver: 'bg-red-500',
  finalizada: 'bg-sky-500',
  no_show: 'bg-orange-500',
  cancelada: 'bg-gray-400',
}

const ICONO_PUERTA = 'M13.5 21v-7.500A.75.75 0 0114.250 12.750h0M3 21h18M5.250 21V4.500A1.500 1.500 0 016.750 3h10.500a1.500 1.500 0 011.500 1.500V21M14.250 12h.008v.008h-.008V12z'

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}
</script>

<template>
  <StaffLayout>
    <div class="mx-auto max-w-[88rem]">
      <!-- La caja de título con motivo propio: el pasillo de salas, las llaves y el horario. -->
      <div class="relative mb-4 overflow-hidden rounded-2xl shadow-md" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
        <FondoListadoReservas />
        <div class="relative min-w-0 px-6 py-4 lg:pr-[27rem]">
          <nav class="text-xs text-white/75" aria-label="Ruta">
            <button type="button" class="font-medium text-white hover:underline" @click="abrirMenuGestion">Gestión</button>
            <span class="mx-1.5">›</span>
            <span>Consultas e historiales</span>
          </nav>
          <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Listado de reservas</h1>
          <p class="mt-0.5 text-sm text-white/75">Historial de todas las reservas de salas y logias</p>
          <p v-if="puedeEditar" class="mt-1 flex items-start gap-1.5 text-xs text-white/75">
            <svg class="mt-px h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.250 11.250l.041-.020a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.750h.008v.008H12V8.250z" /></svg>
            Abre una reserva activa para cambiarle la sala, el día o el horario, corregir sus integrantes o cancelarla.
          </p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <!-- Solo quien tiene «Reservas especiales de salas» (los administradores) -->
      <SeriesReservas v-if="auth.puede('salas.repetir')" @cambio="cargar" />

      <div class="mb-3 flex flex-wrap gap-2" role="group" aria-label="Filtrar por estado">
        <button
          v-for="v in VISTAS"
          :key="v.id"
          type="button"
          class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm font-semibold shadow-sm transition-colors"
          :class="vista === v.id ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-200 bg-white text-gray-800 hover:bg-gray-50'"
          :aria-pressed="vista === v.id"
          @click="vista = v.id"
        >
          <span v-if="v.punto" class="h-2 w-2 rounded-full" :class="v.punto" aria-hidden="true"></span>
          {{ v.texto }}
          <span class="rounded-full px-2 text-xs font-bold tabular-nums" :class="vista === v.id ? 'bg-blanco/20 text-white' : v.cuenta">{{ conteos[v.id].toLocaleString('es-CL') }}</span>
        </button>
      </div>

      <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
        <div class="flex flex-col gap-2 border-b border-gray-100 bg-indigo-50/40 p-3 lg:flex-row">
          <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.350-4.350M11 19a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
            <input
              v-model="busqueda"
              type="text"
              placeholder="Buscar por RUT o nombre de un integrante, o por sala"
              aria-label="Buscar reservas"
              class="h-10 w-full rounded-xl border border-gray-200 bg-white pl-10 pr-4 text-sm text-gray-900 outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
            />
          </div>
          <select
            v-model="salaId"
            aria-label="Sala"
            class="h-10 rounded-xl border border-gray-200 bg-white px-3 text-sm text-gray-900 outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30 lg:w-56"
          >
            <option value="">Todas las salas</option>
            <option v-for="s in salas" :key="s.id" :value="s.id">{{ s.codigo }} · {{ s.nombre }}{{ s.activa === false ? ' (de baja)' : '' }}</option>
          </select>
          <div class="flex flex-wrap items-center gap-2 lg:flex-nowrap">
            <label class="flex h-10 min-w-[11.5rem] flex-1 items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 text-sm lg:min-w-0 lg:flex-none">
              <span class="text-xs font-medium text-gray-500">Desde</span>
              <input v-model="desde" type="date" :max="hasta || undefined" class="min-w-0 flex-1 bg-transparent text-gray-900 outline-none" aria-label="Desde el día" />
            </label>
            <label class="flex h-10 min-w-[11.5rem] flex-1 items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 text-sm lg:min-w-0 lg:flex-none">
              <span class="text-xs font-medium text-gray-500">Hasta</span>
              <input v-model="hasta" type="date" :min="desde || undefined" class="min-w-0 flex-1 bg-transparent text-gray-900 outline-none" aria-label="Hasta el día" />
            </label>
            <button v-if="desde || hasta" type="button" class="shrink-0 text-xs font-medium text-indigo-700 hover:underline" @click="limpiarFechas">Quitar fechas</button>
          </div>
        </div>

        <!-- Arriba y abajo de la lista, para cambiar de página sin bajar hasta el final. -->
        <div class="hidden border-b border-gray-100 px-4 py-2 sm:block">
          <Paginador
            :pagina="meta.current_page"
            :paginas="meta.last_page"
            :total="meta.total"
            :desde="meta.from"
            :hasta="meta.to"
            unidad="reservas"
            :cargando="cargando"
            @update:pagina="irAPagina"
          />
        </div>

        <div class="hidden border-b border-gray-200 bg-gray-50 px-5 py-2.5 text-[11px] font-bold uppercase tracking-wider text-gray-500 lg:grid lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1fr)_minmax(0,1.4fr)_minmax(0,1fr)_9.5rem_5.5rem] lg:gap-4">
          <span>Sala</span><span>Día y horario</span><span>Integrantes</span><span>Llave</span><span>Estado</span><span class="sr-only">Acciones</span>
        </div>
        <ul class="divide-y divide-gray-100">
          <li
            v-for="r in reservas"
            :key="r.id"
            class="relative grid cursor-pointer grid-cols-2 gap-x-3 gap-y-2 px-4 py-2.5 transition-colors hover:bg-gray-50 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1fr)_minmax(0,1.4fr)_minmax(0,1fr)_9.5rem_5.5rem] lg:items-center lg:gap-4 lg:px-5"
            :class="r.situacion === 'sin_devolver' ? 'bg-red-50/50' : ''"
            @click="seleccionada = r"
          >
            <span class="absolute inset-y-0 left-0 w-1" :class="BARRA_SITUACION[r.situacion]" aria-hidden="true"></span>
            <div class="col-span-2 flex min-w-0 items-center gap-3 sm:col-span-1">
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="r.sala?.tipo === 'logia' ? 'bg-indigo-50 text-indigo-600' : 'bg-teal-50 text-teal-600'" aria-hidden="true">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.750"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONO_PUERTA" /></svg>
              </span>
              <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-gray-900">{{ r.sala?.nombre ?? '—' }}</span>
                <span class="block text-xs text-gray-500"><span class="font-mono font-semibold">{{ r.sala?.codigo ?? '—' }}</span> · Reserva <strong class="font-semibold text-gray-700">#{{ r.id }}</strong></span>
              </span>
            </div>
            <div class="text-sm text-gray-700">
              <span class="block font-semibold text-gray-900">{{ fechaReserva(r.fecha) }}</span>
              <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-gray-600">
                {{ r.hora_inicio }} a {{ r.hora_fin }}
                <span class="rounded-full bg-gray-100 px-1.5 text-[11px] font-semibold text-gray-700">{{ duracion(r.duracion_minutos) }}</span>
              </span>
              <span v-if="r.hora_fin_reservada" class="block text-xs text-emerald-700">Liberada · era hasta las {{ r.hora_fin_reservada }}</span>
            </div>
            <div class="col-span-2 min-w-0 sm:col-span-1">
              <span class="block truncate text-sm font-semibold text-gray-900">{{ r.titular.nombre ?? r.titular.rut }}</span>
              <span class="mt-0.5 flex flex-wrap items-center gap-1.5">
                <span class="rounded border border-gray-200 bg-gray-50 px-1.5 font-mono text-[11px] text-gray-700">{{ r.titular.rut }}</span>
                <span v-if="r.cantidad_personas > 1" class="rounded-full bg-indigo-50 px-2 text-[11px] font-semibold text-indigo-700">+{{ r.cantidad_personas - 1 }} {{ r.cantidad_personas === 2 ? 'persona' : 'personas' }}</span>
              </span>
            </div>
            <div class="min-w-0 text-xs text-gray-500">
              <template v-if="r.hora_prestamo_real">
                <span class="block truncate font-semibold text-gray-900">Entregó {{ horaDe(r.hora_prestamo_real) }}</span>
                <span v-if="r.prestado_por" class="block truncate">por {{ r.prestado_por }}</span>
                <span v-if="r.hora_devolucion_real" class="mt-0.5 block truncate"><strong class="font-semibold text-gray-700">Recibió {{ horaDe(r.hora_devolucion_real) }}</strong><template v-if="r.devuelto_por"> · {{ r.devuelto_por }}</template></span>
              </template>
              <span v-else class="text-gray-400">Sin registro</span>
            </div>
            <div>
              <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="SITUACIONES[r.situacion].clase">
                <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                {{ SITUACIONES[r.situacion].texto }}
              </span>
            </div>
            <div class="text-right">
              <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-semibold shadow-sm transition-colors"
                :class="puedeEditar && reservaEditable(r) ? 'border-indigo-600 bg-indigo-600 text-white hover:bg-indigo-700' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-100'"
                @click.stop="seleccionada = r"
              >
                <svg v-if="!(puedeEditar && reservaEditable(r))" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.510 7.360 4.500 12 4.500c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.490 16.640 19.500 12 19.500c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                {{ puedeEditar && reservaEditable(r) ? 'Editar' : 'Ver' }}
              </button>
            </div>
          </li>
          <li v-if="!cargando && !reservas.length" class="px-6 py-10 text-center text-sm text-gray-400">Sin reservas que coincidan con los filtros.</li>
        </ul>
      </section>

      <Paginador
        class="mt-4"
        :pagina="meta.current_page"
        :paginas="meta.last_page"
        :total="meta.total"
        :desde="meta.from"
        :hasta="meta.to"
        unidad="reservas"
        :cargando="cargando"
        @update:pagina="irAPagina"
      />
    </div>

    <DetalleReserva
      :reserva="seleccionada"
      :salas="salas"
      :puede-editar="puedeEditar"
      @cerrar="seleccionada = null"
      @actualizada="cargar"
      @atraso="atrasoDevolucion = $event"
      @sin-usar="tiempoSinUsar = $event"
    />
    <AvisoMultaAtraso :aviso="atrasoDevolucion" @cerrar="atrasoDevolucion = null" />
    <AvisoTiempoSinUsar :aviso="tiempoSinUsar" @cerrar="tiempoSinUsar = null" @liberado="cargar" />
  </StaffLayout>
</template>
