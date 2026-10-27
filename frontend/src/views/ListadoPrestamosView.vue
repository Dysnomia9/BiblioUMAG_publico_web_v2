<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import EncabezadoGestion from '@/components/ui/EncabezadoGestion.vue'
import Paginador from '@/components/ui/Paginador.vue'
import api from '@/services/api'
import { devolucionDe, estaVencido, estadoDe, fechaCorta } from '@/utils/prestamos'
import { itemPrestamo, useCategoriasRecurso } from '@/utils/recursos'
import type { ListadoPrestamos, Prestamo, VistaPrestamos } from '@/types'

// Pestaña, tipo y búsqueda se resuelven en el servidor y la lista llega de a 50: son
// todos los préstamos de la historia de la biblioteca.
const prestamos = ref<Prestamo[]>([])
const conteos = ref<Record<VistaPrestamos, number>>({ activos: 0, vencidos: 0, devueltos: 0, todos: 0 })
const meta = ref({ current_page: 1, last_page: 1, total: 0, from: null as number | null, to: null as number | null })
const cargando = ref(true)
const apiError = ref(false)

const busqueda = ref('')
const tipo = ref<'todos' | 'libros' | 'recursos'>('todos')
const vista = ref<VistaPrestamos>('activos')
const pagina = ref(1)

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<ListadoPrestamos>('/prestamos', {
      params: {
        vista: vista.value,
        tipo: tipo.value === 'todos' ? undefined : tipo.value,
        q: busqueda.value.trim() || undefined,
        page: pagina.value,
        por_pagina: 50,
      },
    })
    prestamos.value = data.data
    conteos.value = data.conteos
    meta.value = { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to }
    apiError.value = false
  } catch {
    apiError.value = true
    prestamos.value = []
  } finally {
    cargando.value = false
  }
}

const { cargar: cargarCategorias } = useCategoriasRecurso()
onMounted(() => {
  cargar()
  cargarCategorias()
})

// Un filtro nuevo vuelve a la primera página; la búsqueda espera a que se deje de escribir.
let busquedaTimer: ReturnType<typeof setTimeout> | undefined
watch(busqueda, () => {
  clearTimeout(busquedaTimer)
  busquedaTimer = setTimeout(() => {
    pagina.value = 1
    cargar()
  }, 300)
})
watch([tipo, vista], () => {
  pagina.value = 1
  cargar()
})

function irAPagina(nueva: number) {
  pagina.value = nueva
  cargar()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const VISTAS = [
  { id: 'activos' as const, texto: 'Activos' },
  { id: 'vencidos' as const, texto: 'Vencidos' },
  { id: 'devueltos' as const, texto: 'Devueltos' },
  { id: 'todos' as const, texto: 'Todos' },
]

function iniciales(p: Prestamo) {
  return `${p.usuario?.nombre?.charAt(0) ?? ''}${p.usuario?.apellido?.charAt(0) ?? ''}`.toUpperCase() || '—'
}

function multa(p: Prestamo) {
  const pendiente = p.multas?.find((m) => m.estado === 'pendiente')
  if (pendiente) return { texto: `Multa ${new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP' }).format(pendiente.monto)}`, clase: 'text-red-700' }
  if (p.multas?.some((m) => m.estado === 'pagada')) return { texto: 'Multa pagada', clase: 'text-gray-500' }
  return null
}
</script>

<template>
  <StaffLayout>
    <div class="mx-auto max-w-[88rem]">
      <EncabezadoGestion seccion="Préstamos" titulo="Listado de préstamos" descripcion="Historial de todos los préstamos de libros y recursos de apoyo" />

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
          <span
            class="rounded-full px-2 py-0.5 text-xs font-bold"
            :class="vista === v.id ? 'bg-blanco/20 text-white' : v.id === 'vencidos' && conteos.vencidos ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-600'"
          >
            {{ conteos[v.id] }}
          </span>
        </button>
      </div>

      <div class="mb-5 flex flex-col gap-2 sm:flex-row">
        <div class="relative flex-1">
          <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
          </svg>
          <input
            v-model="busqueda"
            type="text"
            placeholder="Buscar por RUT, nombre, título o código del ítem"
            aria-label="Buscar préstamos"
            class="h-11 w-full rounded-xl border border-gray-200 bg-white pl-10 pr-4 text-sm text-gray-900 shadow-sm outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30 sm:pr-40"
          />
          <span class="pointer-events-none absolute right-4 top-1/2 hidden -translate-y-1/2 text-xs text-gray-400 sm:block">RUT con o sin puntos</span>
        </div>
        <div class="flex shrink-0 rounded-xl bg-gray-200/60 p-1" role="group" aria-label="Tipo de ítem">
          <button
            v-for="t in [{ id: 'todos', texto: 'Todos' }, { id: 'libros', texto: 'Libros' }, { id: 'recursos', texto: 'Recursos de apoyo' }] as const"
            :key="t.id"
            type="button"
            class="flex-1 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
            :class="tipo === t.id ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
            @click="tipo = t.id"
          >
            {{ t.texto }}
          </button>
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
        unidad="préstamos"
        :cargando="cargando"
        @update:pagina="irAPagina"
      />

      <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div
          class="hidden border-b border-gray-200 bg-gray-50/80 px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500 lg:grid lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1.5fr)_7rem_minmax(0,1fr)_7rem] lg:gap-4"
        >
          <span>Usuario</span><span>Ítem</span><span>Prestado</span><span>Devolución</span><span>Estado</span>
        </div>
        <ul class="divide-y divide-gray-100">
          <li
            v-for="p in prestamos"
            :key="p.id"
            class="grid grid-cols-2 gap-x-3 gap-y-2.5 px-4 py-3.5 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1.5fr)_7rem_minmax(0,1fr)_7rem] lg:items-center lg:gap-4 lg:px-5"
            :class="estaVencido(p) ? 'bg-red-50/40' : ''"
          >
            <div class="col-span-2 flex min-w-0 items-center gap-3 sm:col-span-1">
              <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-bold text-indigo-700">{{ iniciales(p) }}</span>
              <span class="min-w-0">
                <span class="block truncate font-semibold text-gray-900">{{ p.usuario?.nombre }} {{ p.usuario?.apellido }}</span>
                <span class="block text-xs text-gray-500">{{ p.usuario?.rut ?? '—' }}</span>
              </span>
            </div>
            <div class="col-span-2 flex min-w-0 items-center gap-3 sm:col-span-1">
              <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="itemPrestamo(p.tipo_item).fondo">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="itemPrestamo(p.tipo_item).icono" />
                </svg>
              </span>
              <span class="min-w-0">
                <span class="block truncate font-semibold text-gray-900">{{ p.libro_titulo }}</span>
                <span class="block truncate text-xs text-gray-500">{{ itemPrestamo(p.tipo_item).nombre }} · <span class="font-mono">{{ p.codigo_barras ?? '—' }}</span></span>
              </span>
            </div>
            <div class="text-sm text-gray-700">
              <span class="text-xs text-gray-400 lg:hidden">Prestado </span>{{ fechaCorta(p.fecha_prestamo) }}
              <span v-if="p.prestado_por" class="block truncate text-xs text-gray-400">por {{ p.prestado_por }}</span>
            </div>
            <div class="text-sm">
              <span class="text-gray-700"><span class="text-xs text-gray-400 lg:hidden">Devolución </span>{{ devolucionDe(p).linea1 }}</span>
              <span class="block text-xs font-semibold" :class="devolucionDe(p).clase">{{ devolucionDe(p).linea2 }}</span>
            </div>
            <div class="col-span-2 lg:col-span-1">
              <span class="inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :class="estadoDe(p).clase">{{ estadoDe(p).texto }}</span>
              <span v-if="multa(p)" class="mt-1 block text-xs font-semibold" :class="multa(p)!.clase">{{ multa(p)!.texto }}</span>
            </div>
          </li>
          <li v-if="!cargando && !prestamos.length" class="px-6 py-10 text-center text-sm text-gray-400">Sin préstamos que coincidan con los filtros.</li>
        </ul>
      </div>

      <Paginador
        class="mt-4"
        :pagina="meta.current_page"
        :paginas="meta.last_page"
        :total="meta.total"
        :desde="meta.from"
        :hasta="meta.to"
        unidad="préstamos"
        :cargando="cargando"
        @update:pagina="irAPagina"
      />
    </div>
  </StaffLayout>
</template>
