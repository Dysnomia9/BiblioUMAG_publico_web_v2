<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import EncabezadoGestion from '@/components/ui/EncabezadoGestion.vue'
import Paginador from '@/components/ui/Paginador.vue'
import DetalleReserva from '@/components/salas/DetalleReserva.vue'
import AvisoMultaAtraso from '@/components/salas/AvisoMultaAtraso.vue'
import AvisoTiempoSinUsar from '@/components/salas/AvisoTiempoSinUsar.vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { SITUACIONES, duracion, fechaReserva, horaDe, reservaEditable } from '@/utils/reservas'
import type { AtrasoDevolucion, ListadoReservas, ReservaListado, TiempoSinUsar, VistaReservas } from '@/types'

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
  { id: 'todas' as const, texto: 'Todas' },
  { id: 'activas' as const, texto: 'Activas' },
  { id: 'finalizadas' as const, texto: 'Finalizadas' },
  { id: 'no_show' as const, texto: 'No se presentaron' },
  { id: 'canceladas' as const, texto: 'Canceladas' },
]

const ICONO_SALA = 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21'

function iniciales(r: ReservaListado) {
  return (r.titular.nombre ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p.charAt(0).toUpperCase())
    .join('') || '—'
}
</script>

<template>
  <StaffLayout>
    <div class="mx-auto max-w-[88rem]">
      <EncabezadoGestion seccion="Consultas e historiales" titulo="Listado de reservas" descripcion="Historial de todas las reservas de salas y logias">
        <template v-if="puedeEditar" #debajo>
          <p class="mt-2 text-xs text-gray-500">Abre una reserva activa para cambiarle la sala, el día o el horario, corregir sus integrantes o cancelarla.</p>
        </template>
      </EncabezadoGestion>

      <ApiErrorBanner v-if="apiError" />

      <div class="mb-4 flex flex-wrap gap-2">
        <button
          v-for="v in VISTAS"
          :key="v.id"
          type="button"
          class="inline-flex items-center gap-2 rounded-xl border px-4 py-2 text-sm font-semibold transition-colors"
          :class="vista === v.id ? 'border-gray-900 bg-gray-900 text-white dark:border-indigo-500 dark:bg-indigo-500' : 'border-gray-200 bg-white text-gray-800 hover:bg-gray-50'"
          @click="vista = v.id"
        >
          {{ v.texto }}
          <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="vista === v.id ? 'bg-blanco/20 text-white' : 'bg-gray-100 text-gray-600'">
            {{ conteos[v.id] }}
          </span>
        </button>
      </div>

      <div class="mb-5 flex flex-col gap-2 lg:flex-row">
        <div class="relative flex-1">
          <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
          </svg>
          <input
            v-model="busqueda"
            type="text"
            placeholder="Buscar por RUT o nombre de un integrante, o por sala"
            aria-label="Buscar reservas"
            class="h-11 w-full rounded-xl border border-gray-200 bg-white pl-10 pr-4 text-sm text-gray-900 shadow-sm outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
          />
        </div>
        <select
          v-model="salaId"
          aria-label="Sala"
          class="h-11 rounded-xl border border-gray-200 bg-white px-3 text-sm text-gray-900 shadow-sm outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30 lg:w-56"
        >
          <option value="">Todas las salas</option>
          <option v-for="s in salas" :key="s.id" :value="s.id">{{ s.nombre }}</option>
        </select>
        <div class="flex items-center gap-2">
          <label class="flex h-11 flex-1 items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 text-sm shadow-sm lg:flex-none">
            <span class="text-xs text-gray-500">Desde</span>
            <input v-model="desde" type="date" :max="hasta || undefined" class="min-w-0 flex-1 bg-transparent text-gray-900 outline-none" aria-label="Desde el día" />
          </label>
          <label class="flex h-11 flex-1 items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 text-sm shadow-sm lg:flex-none">
            <span class="text-xs text-gray-500">Hasta</span>
            <input v-model="hasta" type="date" :min="desde || undefined" class="min-w-0 flex-1 bg-transparent text-gray-900 outline-none" aria-label="Hasta el día" />
          </label>
          <button v-if="desde || hasta" type="button" class="shrink-0 text-xs font-medium text-indigo-700 hover:underline" @click="limpiarFechas">Quitar fechas</button>
        </div>
      </div>

      <!-- Arriba y abajo de la lista, para cambiar de página sin bajar hasta el final. -->
      <Paginador
        class="mb-3"
        :pagina="meta.current_page"
        :paginas="meta.last_page"
        :total="meta.total"
        :desde="meta.from"
        :hasta="meta.to"
        unidad="reservas"
        :cargando="cargando"
        @update:pagina="irAPagina"
      />

      <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div
          class="hidden border-b border-gray-200 bg-gray-50/80 px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500 lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.3fr)_minmax(0,1fr)_9.5rem_5.5rem] lg:gap-4"
        >
          <span>Sala</span><span>Día y horario</span><span>Integrantes</span><span>Llave</span><span>Estado</span><span class="sr-only">Acciones</span>
        </div>
        <ul class="divide-y divide-gray-100">
          <li
            v-for="r in reservas"
            :key="r.id"
            class="grid cursor-pointer grid-cols-2 gap-x-3 gap-y-2.5 px-4 py-3.5 transition-colors hover:bg-gray-50 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.3fr)_minmax(0,1fr)_9.5rem_5.5rem] lg:items-center lg:gap-4 lg:px-5"
            :class="r.situacion === 'sin_devolver' ? 'bg-red-50/40' : ''"
            @click="seleccionada = r"
          >
            <div class="col-span-2 flex min-w-0 items-center gap-3 sm:col-span-1">
              <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="r.sala?.tipo === 'logia' ? 'bg-indigo-50 text-indigo-600' : 'bg-teal-50 text-teal-600'">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONO_SALA" />
                </svg>
              </span>
              <span class="min-w-0">
                <span class="block truncate font-semibold text-gray-900">{{ r.sala?.nombre ?? '—' }}</span>
                <span class="block text-xs text-gray-500">{{ r.sala?.tipo === 'logia' ? 'Logia' : 'Sala' }} · #{{ r.id }}</span>
              </span>
            </div>
            <div class="text-sm text-gray-700">
              <span class="block font-medium text-gray-900">{{ fechaReserva(r.fecha) }}</span>
              <span class="block text-xs text-gray-500">{{ r.hora_inicio }} a {{ r.hora_fin }} · {{ duracion(r.duracion_minutos) }}</span>
              <span v-if="r.hora_fin_reservada" class="block text-xs text-emerald-700">Liberada · era hasta las {{ r.hora_fin_reservada }}</span>
            </div>
            <div class="col-span-2 flex min-w-0 items-center gap-3 sm:col-span-1 lg:col-span-1">
              <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-bold text-indigo-700">{{ iniciales(r) }}</span>
              <span class="min-w-0">
                <span class="block truncate font-semibold text-gray-900">{{ r.titular.nombre ?? r.titular.rut }}</span>
                <span class="block truncate text-xs text-gray-500">
                  {{ r.titular.rut }}<template v-if="r.cantidad_personas > 1"> · +{{ r.cantidad_personas - 1 }} {{ r.cantidad_personas === 2 ? 'persona' : 'personas' }}</template>
                </span>
              </span>
            </div>
            <div class="text-xs text-gray-600">
              <template v-if="r.hora_prestamo_real">
                <span class="block truncate">Entregó {{ horaDe(r.hora_prestamo_real) }}<template v-if="r.prestado_por"> · {{ r.prestado_por }}</template></span>
                <span v-if="r.hora_devolucion_real" class="block truncate">Recibió {{ horaDe(r.hora_devolucion_real) }}<template v-if="r.devuelto_por"> · {{ r.devuelto_por }}</template></span>
              </template>
              <span v-else class="text-gray-400">—</span>
            </div>
            <div>
              <span class="inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :class="SITUACIONES[r.situacion].clase">{{ SITUACIONES[r.situacion].texto }}</span>
            </div>
            <div class="text-right">
              <button
                type="button"
                class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors"
                :class="puedeEditar && reservaEditable(r) ? 'border-indigo-200 text-indigo-700 hover:bg-indigo-50' : 'border-gray-200 text-gray-600 hover:bg-gray-100'"
                @click.stop="seleccionada = r"
              >
                {{ puedeEditar && reservaEditable(r) ? 'Editar' : 'Ver' }}
              </button>
            </div>
          </li>
          <li v-if="!cargando && !reservas.length" class="px-6 py-10 text-center text-sm text-gray-400">Sin reservas que coincidan con los filtros.</li>
        </ul>
      </div>

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
