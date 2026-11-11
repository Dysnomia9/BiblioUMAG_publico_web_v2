<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import FondoListadoPrestamos from '@/components/ui/FondoListadoPrestamos.vue'
import Paginador from '@/components/ui/Paginador.vue'
import api from '@/services/api'
import { devolucionDe, estadoDe, fechaCorta } from '@/utils/prestamos'
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
// Abre en Todos: el préstamo que se busca puede estar en cualquier estado.
const vista = ref<VistaPrestamos>('todos')
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
  { id: 'todos' as const, texto: 'Todos', punto: '', cuenta: 'bg-gray-100 text-gray-600' },
  { id: 'activos' as const, texto: 'Activos', punto: 'bg-emerald-500', cuenta: 'bg-emerald-50 text-emerald-700' },
  { id: 'devueltos' as const, texto: 'Devueltos', punto: 'bg-sky-500', cuenta: 'bg-sky-50 text-sky-700' },
  { id: 'vencidos' as const, texto: 'Vencidos', punto: 'bg-red-500', cuenta: 'bg-red-50 text-red-700' },
]

const TIPOS = [
  { id: 'todos' as const, texto: 'Todos', icono: '' },
  { id: 'libros' as const, texto: 'Libros', icono: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.500 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.500 18s3.332.477 4.500 1.253m0-13C13.168 5.477 14.754 5 16.500 5c1.747 0 3.332.477 4.500 1.253v13C19.832 18.477 18.247 18 16.500 18c-1.746 0-3.332.477-4.500 1.253' },
  { id: 'recursos' as const, texto: 'Recursos de apoyo', icono: 'M9 17.250v1.007a3 3 0 01-.879 2.122L7.500 21h9l-.621-.621A3 3 0 0115 18.257V17.250m6-12V15a2.250 2.250 0 01-2.250 2.250H5.250A2.250 2.250 0 013 15V5.250m18 0A2.250 2.250 0 0018.750 3H5.250A2.250 2.250 0 003 5.250' },
]

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}

const TONOS_AVATAR = ['bg-indigo-50 text-indigo-700', 'bg-amber-50 text-amber-800', 'bg-teal-50 text-teal-700', 'bg-violet-50 text-violet-700']
const tonoAvatar = (p: Prestamo) => TONOS_AVATAR[[...`${p.usuario?.nombre ?? ''}${p.usuario?.apellido ?? ''}`].reduce((suma, letra) => suma + letra.charCodeAt(0), 0) % TONOS_AVATAR.length]

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
      <!-- La caja de título con motivo propio: el fichero, el libro de registro y el plazo. -->
      <div class="relative mb-4 overflow-hidden rounded-2xl shadow-md" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
        <FondoListadoPrestamos />
        <div class="relative min-w-0 px-6 py-4">
          <nav class="text-xs text-white/75" aria-label="Ruta">
            <button type="button" class="font-medium text-white hover:underline" @click="abrirMenuGestion">Gestión</button>
            <span class="mx-1.5">›</span>
            <span>Préstamos</span>
          </nav>
          <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Listado de préstamos</h1>
          <p class="mt-0.5 text-sm text-white/75">Historial de todos los préstamos de libros y recursos de apoyo</p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

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
          <span class="rounded-full px-2 text-xs font-bold tabular-nums" :class="vista === v.id ? 'bg-blanco/20 text-white' : v.cuenta">
            {{ conteos[v.id].toLocaleString('es-CL') }}
          </span>
        </button>
      </div>

      <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
        <div class="flex flex-col gap-2 border-b border-gray-100 bg-indigo-50/40 p-3 sm:flex-row">
          <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.350-4.350M11 19a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
            <input
              v-model="busqueda"
              type="text"
              placeholder="Buscar por RUT, nombre, título o código del ítem"
              aria-label="Buscar préstamos"
              class="h-10 w-full rounded-xl border border-gray-200 bg-white pl-10 pr-4 text-sm text-gray-900 outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30 sm:pr-40"
            />
            <span class="pointer-events-none absolute right-4 top-1/2 hidden -translate-y-1/2 text-xs text-gray-400 sm:block">RUT con o sin puntos</span>
          </div>
          <div class="flex shrink-0 gap-0.5 rounded-xl border border-gray-200 bg-white p-1" role="group" aria-label="Tipo de ítem">
            <button
              v-for="t in TIPOS"
              :key="t.id"
              type="button"
              class="inline-flex flex-1 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1 text-xs font-semibold transition-colors"
              :class="tipo === t.id ? 'bg-indigo-50 text-indigo-700' : 'text-gray-700 hover:bg-gray-50'"
              :aria-pressed="tipo === t.id"
              @click="tipo = t.id"
            >
              <svg v-if="t.icono" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.750" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="t.icono" /></svg>
              {{ t.texto }}
            </button>
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
            unidad="préstamos"
            :cargando="cargando"
            @update:pagina="irAPagina"
          />
        </div>

        <div
          class="hidden border-b border-gray-200 bg-gray-50 px-5 py-2.5 text-[11px] font-bold uppercase tracking-wider text-gray-500 lg:grid lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1.5fr)_7rem_minmax(0,1fr)_7rem] lg:gap-4"
        >
          <span>Usuario</span><span>Ítem</span><span>Prestado</span><span>Devolución</span><span>Estado</span>
        </div>
        <ul class="divide-y divide-gray-100">
          <li
            v-for="p in prestamos"
            :key="p.id"
            class="relative grid grid-cols-2 gap-x-3 gap-y-2 px-4 py-2.5 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1.5fr)_7rem_minmax(0,1fr)_7rem] lg:items-center lg:gap-4 lg:px-5"
            :class="estadoDe(p).fila"
          >
            <!-- El color del estado: verde activo, ámbar vence hoy, rojo vencido, celeste devuelto. -->
            <span class="absolute inset-y-0 left-0 w-1" :class="estadoDe(p).barra" aria-hidden="true"></span>
            <div class="col-span-2 flex min-w-0 items-center gap-3 sm:col-span-1">
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-bold" :class="tonoAvatar(p)" aria-hidden="true">{{ iniciales(p) }}</span>
              <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-gray-900">{{ p.usuario?.nombre }} {{ p.usuario?.apellido }}</span>
                <span class="mt-0.5 inline-block rounded border border-gray-200 bg-gray-50 px-1.5 font-mono text-[11px] text-gray-700">{{ p.usuario?.rut ?? '—' }}</span>
              </span>
            </div>
            <div class="col-span-2 flex min-w-0 items-center gap-3 sm:col-span-1">
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="itemPrestamo(p.tipo_item).fondo">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="itemPrestamo(p.tipo_item).icono" />
                </svg>
              </span>
              <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-gray-900">{{ p.libro_titulo }}</span>
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
              <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="estadoDe(p).clase">
                <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                {{ estadoDe(p).texto }}
              </span>
              <span v-if="multa(p)" class="mt-1 block text-xs font-semibold" :class="multa(p)!.clase">{{ multa(p)!.texto }}</span>
            </div>
          </li>
          <li v-if="!cargando && !prestamos.length" class="px-6 py-10 text-center text-sm text-gray-400">Sin préstamos que coincidan con los filtros.</li>
        </ul>
      </section>

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
