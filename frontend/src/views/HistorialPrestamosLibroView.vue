<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import EncabezadoGestion from '@/components/ui/EncabezadoGestion.vue'
import Modal from '@/components/ui/Modal.vue'
import FichaBibliografica from '@/components/libros/FichaBibliografica.vue'
import PestanasLibros from '@/components/libros/PestanasLibros.vue'
import Paginador from '@/components/ui/Paginador.vue'
import api from '@/services/api'
import { inicialesPortada, tonoPortada } from '@/utils/portada'
import type { Libro, LibroHistorial, LibroHistorialItem, Paginado } from '@/types'

const route = useRoute()

const busqueda = ref('')
// La lista va de a 30 títulos, con botones de página; los préstamos de un título se
// piden recién al elegirlo, para no traer el historial de toda la biblioteca de una vez.
const resultados = ref<LibroHistorialItem[]>([])
const total = ref(0)
const pagina = ref(1)
const meta = ref({ current_page: 1, last_page: 1, from: null as number | null, to: null as number | null })
const cargando = ref(true)
const apiError = ref(false)
const elegidoId = ref<number | null>(typeof route.query.libro === 'string' ? Number(route.query.libro) : null)
const elegido = ref<LibroHistorial | null>(null)
const cargandoDetalle = ref(false)
const panel = ref<HTMLElement | null>(null)

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<Paginado<LibroHistorialItem>>('/libros/historial', {
      params: { q: busqueda.value.trim() || undefined, page: pagina.value, por_pagina: 30 },
    })
    resultados.value = data.data
    total.value = data.total
    meta.value = { current_page: data.current_page, last_page: data.last_page, from: data.from, to: data.to }
    apiError.value = false
    if (!elegidoId.value) elegidoId.value = data.data[0]?.libro.id ?? null
  } catch {
    apiError.value = true
    resultados.value = []
  } finally {
    cargando.value = false
  }
}

async function cargarDetalle(id: number | null) {
  if (!id) {
    elegido.value = null
    return
  }
  cargandoDetalle.value = true
  try {
    const { data } = await api.get<LibroHistorial>(`/libros/${id}/historial`)
    if (elegidoId.value === id) elegido.value = data
  } catch {
    elegido.value = null
  } finally {
    cargandoDetalle.value = false
  }
}

watch(elegidoId, (id) => cargarDetalle(id))

onMounted(() => {
  cargar()
  if (elegidoId.value) cargarDetalle(elegidoId.value)
})

let buscarTimer: ReturnType<typeof setTimeout> | undefined
function onBuscarInput() {
  clearTimeout(buscarTimer)
  buscarTimer = setTimeout(() => {
    pagina.value = 1
    cargar()
  }, 250)
}

function irAPagina(nueva: number) {
  pagina.value = nueva
  cargar()
}

const lista = computed(() => resultados.value)

async function elegir(id: number) {
  elegidoId.value = id
  if (window.matchMedia('(max-width: 1023px)').matches) {
    await nextTick()
    panel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }
}

const DIA = 86_400_000
const hoy = () => new Date()
const dias = (desde: string, hasta: Date) => Math.max(0, Math.round((hasta.getTime() - new Date(desde).getTime()) / DIA))
const fecha = (iso: string) => new Date(iso).toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' })
/** Días calendario de `vence` a `referencia` (0 si se devolvió ese mismo día o antes), igual que el cálculo de multas. */
const diasDeAtraso = (vence: Date, referencia: Date) =>
  Math.max(0, Math.round((new Date(referencia.toDateString()).getTime() - new Date(vence.toDateString()).getTime()) / DIA))

/** Cada préstamo del libro elegido, con su resultado: a tiempo, con atraso o en curso. */
const prestamos = computed(() => {
  if (!elegido.value) return []
  return elegido.value.ejemplares
    .flatMap((ej) =>
      ej.prestamos.map((p) => {
        const devuelto = p.fecha_devolucion_real ? new Date(p.fecha_devolucion_real) : null
        // Los de 2007 a mayo de 2014 vienen de Horizon devueltos pero sin fecha: se dan por devueltos.
        const devueltoSinFecha = !devuelto && p.estado === 'devuelto'
        const vence = p.fecha_devolucion ? new Date(p.fecha_devolucion) : null
        const referencia = devuelto ?? hoy()
        const atraso = vence && !devueltoSinFecha ? diasDeAtraso(vence, referencia) : 0
        let resultado: { texto: string; clase: string }
        if (devueltoSinFecha) resultado = { texto: 'Devuelto', clase: 'bg-gray-100 text-gray-600' }
        else if (devuelto) resultado = atraso ? { texto: `Atraso ${atraso} d`, clase: 'bg-red-50 text-red-700' } : p.en_sala ? { texto: 'En sala', clase: 'bg-sky-50 text-sky-700' } : { texto: 'A tiempo', clase: 'bg-emerald-50 text-emerald-700' }
        else resultado = atraso ? { texto: `Vencido ${atraso} d`, clase: 'bg-red-50 text-red-700' } : { texto: p.en_sala ? 'En sala' : 'En curso', clase: 'bg-indigo-50 text-indigo-700' }
        return {
          id: p.id,
          copia: ej.numero_copia,
          codigo: ej.codigo_barras,
          usuario: p.usuario ?? 'Usuario eliminado',
          prestado: fecha(p.fecha_prestamo),
          devuelto: devuelto ? fecha(p.fecha_devolucion_real!) : devueltoSinFecha ? 'Sin fecha' : '—',
          // Sin fecha de devolución no se sabe cuánto duró.
          dias: devueltoSinFecha ? null : dias(p.fecha_prestamo, referencia),
          conAtraso: !!devuelto && atraso > 0,
          estaDevuelto: !!devuelto || devueltoSinFecha,
          fechaPrestamo: p.fecha_prestamo,
          resultado,
        }
      }),
    )
    .sort((a, b) => b.fechaPrestamo.localeCompare(a.fechaPrestamo))
})

const indicadores = computed(() => {
  const devueltos = prestamos.value.filter((p) => p.estaDevuelto && p.dias !== null)
  const promedio = devueltos.length ? Math.round(devueltos.reduce((s, p) => s + p.dias!, 0) / devueltos.length) : null
  const ultimo = prestamos.value[0]?.fechaPrestamo
  return [
    { titulo: 'Préstamos', valor: String(prestamos.value.length), clase: 'bg-indigo-50 text-indigo-800' },
    { titulo: 'Duración promedio', valor: promedio === null ? '—' : `${promedio} ${promedio === 1 ? 'día' : 'días'}`, clase: 'bg-teal-50 text-teal-800' },
    { titulo: 'Devueltos con atraso', valor: String(prestamos.value.filter((p) => p.conAtraso).length), clase: 'bg-red-50 text-red-800' },
    {
      titulo: 'Último préstamo',
      valor: ultimo ? fecha(ultimo).slice(0, 5) : '—',
      clase: 'bg-amber-50 text-amber-900',
    },
  ]
})

function detalle(r: LibroHistorialItem) {
  return `${r.libro.isbn ? `ISBN ${r.libro.isbn}` : 'Sin ISBN'} · ${r.copias} ${r.copias === 1 ? 'copia' : 'copias'}`
}

// «Ver ficha»: la ficha completa del título, en una ventana.
const fichaAbierta = ref(false)
const ficha = ref<Libro | null>(null)
async function verFicha() {
  if (!elegido.value) return
  fichaAbierta.value = true
  ficha.value = null
  try {
    const { data } = await api.get<Libro>(`/libros/${elegido.value.libro.id}`)
    ficha.value = data
  } catch {
    fichaAbierta.value = false
  }
}
</script>

<template>
  <StaffLayout>
    <div class="mx-auto max-w-[88rem]">
      <EncabezadoGestion seccion="Catálogo" titulo="Historial de material">
        <template #acciones>
          <PestanasLibros actual="historial-prestamos-libro" />
        </template>
      </EncabezadoGestion>

      <ApiErrorBanner v-if="apiError" />

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-[23rem_minmax(0,1fr)] lg:items-start">
        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div class="space-y-3 border-b border-gray-100 p-4">
            <div class="relative">
              <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
              </svg>
              <input
                v-model="busqueda"
                type="text"
                placeholder="Título, ISBN o código de barras"
                aria-label="Buscar libro"
                class="h-11 w-full rounded-xl border border-gray-300 bg-white pl-10 pr-4 text-sm text-gray-900 outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
                @input="onBuscarInput"
              />
            </div>
            <p class="text-sm text-gray-500">{{ total.toLocaleString('es-CL') }} {{ total === 1 ? 'libro' : 'libros' }} · de la A a la Z</p>
          </div>
          <ul class="max-h-[26rem] divide-y divide-gray-100 overflow-y-auto lg:max-h-[calc(100vh-17rem)]">
            <li v-for="r in lista" :key="r.libro.id">
              <button
                type="button"
                class="w-full border-l-4 px-4 py-3 text-left transition-colors"
                :class="elegidoId === r.libro.id ? 'border-indigo-600 bg-indigo-50/60' : 'border-transparent hover:bg-gray-50'"
                @click="elegir(r.libro.id)"
              >
                <span class="flex items-start justify-between gap-3">
                  <span class="min-w-0">
                    <span class="block font-semibold text-gray-900">{{ r.libro.titulo }}</span>
                    <span class="block text-xs text-gray-500">{{ detalle(r) }}</span>
                  </span>
                  <span class="flex h-8 min-w-[2rem] shrink-0 items-center justify-center rounded-lg bg-indigo-50 px-2 text-sm font-bold text-indigo-700">
                    {{ r.total_prestamos }}
                  </span>
                </span>
              </button>
            </li>
            <li v-if="!cargando && !lista.length" class="px-4 py-8 text-center text-sm text-gray-400">Sin libros que coincidan.</li>
          </ul>
          <Paginador
            class="border-t border-gray-100 px-4 py-3"
            compacto
            :pagina="meta.current_page"
            :paginas="meta.last_page"
            :total="total"
            :desde="meta.from"
            :hasta="meta.to"
            unidad="libros"
            :cargando="cargando"
            @update:pagina="irAPagina"
          />
        </section>

        <section ref="panel" class="scroll-mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm lg:sticky lg:top-6">
          <p v-if="!elegido" class="px-6 py-16 text-center text-sm text-gray-400">
            {{ cargandoDetalle ? 'Cargando el historial…' : 'Elige un libro de la lista para ver su historial de préstamos.' }}
          </p>
          <template v-else>
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-100 bg-gray-50/60 px-6 py-5">
              <div class="flex min-w-0 items-center gap-4">
                <div
                  class="flex h-16 w-12 shrink-0 items-end rounded-sm border-l-4 px-1.5 pb-1 font-serif text-base font-bold"
                  :class="tonoPortada(elegido.libro.titulo)"
                  aria-hidden="true"
                >
                  {{ inicialesPortada(elegido.libro.titulo) }}
                </div>
                <div class="min-w-0">
                  <h2 class="font-serif text-xl font-bold text-gray-900 sm:text-2xl">{{ elegido.libro.titulo }}</h2>
                  <p class="mt-0.5 text-sm text-gray-500">
                    {{ elegido.libro.isbn ? `ISBN ${elegido.libro.isbn}` : 'Sin ISBN' }}<template v-if="elegido.libro.clasificacion"> · Dewey {{ elegido.libro.clasificacion }}</template>
                    · {{ elegido.ejemplares.length }} {{ elegido.ejemplares.length === 1 ? 'copia' : 'copias' }}
                  </p>
                </div>
              </div>
              <button type="button" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 transition-colors hover:bg-gray-50" @click="verFicha">
                Ver ficha
              </button>
            </div>

            <div class="px-6 py-5">
              <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                <div v-for="k in indicadores" :key="k.titulo" class="rounded-xl px-4 py-3" :class="k.clase">
                  <p class="text-xs font-semibold">{{ k.titulo }}</p>
                  <p class="mt-1 font-serif text-2xl font-bold tabular-nums text-gray-900">{{ k.valor }}</p>
                </div>
              </div>

              <div class="mt-5 overflow-hidden rounded-xl border border-gray-100">
                <div class="hidden bg-gray-50 px-4 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 md:grid md:grid-cols-[minmax(0,1.1fr)_minmax(0,1.3fr)_6.5rem_6.5rem_3.5rem_7rem] md:gap-3">
                  <span>Copia</span><span>Usuario</span><span>Prestado</span><span>Devuelto</span><span>Días</span><span>Resultado</span>
                </div>
                <ul class="divide-y divide-gray-100">
                  <li
                    v-for="p in prestamos"
                    :key="p.id"
                    class="grid grid-cols-2 gap-x-3 gap-y-1 px-4 py-3 text-sm md:grid-cols-[minmax(0,1.1fr)_minmax(0,1.3fr)_6.5rem_6.5rem_3.5rem_7rem] md:items-center md:gap-y-0"
                  >
                    <span class="min-w-0">
                      <span class="block font-medium text-gray-900">Copia {{ p.copia }}</span>
                      <span class="block font-mono text-xs text-gray-500">{{ p.codigo }}</span>
                    </span>
                    <span class="truncate font-medium text-gray-900 md:font-semibold">{{ p.usuario }}</span>
                    <span class="text-gray-600"><span class="text-xs text-gray-400 md:hidden">Prestado </span>{{ p.prestado }}</span>
                    <span class="text-gray-600"><span class="text-xs text-gray-400 md:hidden">Devuelto </span>{{ p.devuelto }}</span>
                    <span class="tabular-nums text-gray-600"><span class="text-xs text-gray-400 md:hidden">Días </span>{{ p.dias ?? '—' }}</span>
                    <span><span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="p.resultado.clase">{{ p.resultado.texto }}</span></span>
                  </li>
                  <li v-if="!prestamos.length" class="px-4 py-8 text-center text-sm text-gray-400">Este libro todavía no se ha prestado.</li>
                </ul>
              </div>
            </div>
          </template>
        </section>
      </div>
    </div>

    <Modal :abierto="fichaAbierta" :titulo="elegido?.libro.titulo ?? 'Ficha'" ancho="lg" @cerrar="fichaAbierta = false">
      <p v-if="!ficha" class="py-6 text-center text-sm text-gray-400">Cargando ficha…</p>
      <FichaBibliografica v-else :filas="ficha.ficha ?? []" con-etiquetas />
      <template #pie>
        <button type="button" class="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100" @click="fichaAbierta = false">
          Cerrar
        </button>
      </template>
    </Modal>
  </StaffLayout>
</template>
