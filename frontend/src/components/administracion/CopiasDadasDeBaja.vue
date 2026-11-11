<script setup lang="ts">
import { onMounted, ref } from 'vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import Paginador from '@/components/ui/Paginador.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import type { Ejemplar, Libro, Paginado } from '@/types'

/**
 * «Eliminados»: las copias dadas de baja, con el motivo, quién y cuándo. Nada se borra
 * de verdad: una copia se puede reincorporar (queda en Inventario, para volver a procesarla).
 */
type CopiaDeBaja = Ejemplar & {
  libro: Pick<Libro, 'id' | 'titulo'> & { tipo_material?: { nombre: string } | null }
  baja_motivo: string | null
  baja_en: string | null
  baja_por: string | null
}

const toast = useToast()

const copias = ref<CopiaDeBaja[]>([])
const meta = ref<Omit<Paginado<CopiaDeBaja>, 'data'>>({ current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null })
const busqueda = ref('')
const pagina = ref(1)
const cargando = ref(true)
const apiError = ref(false)

let temporizador: ReturnType<typeof setTimeout> | undefined

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<Paginado<CopiaDeBaja>>('/ejemplares/dadas-de-baja', {
      params: { q: busqueda.value.trim() || undefined, page: pagina.value, por_pagina: 20 },
    })
    const { data: filas, ...resto } = data
    copias.value = filas
    meta.value = resto
    apiError.value = false
  } catch {
    apiError.value = true
  } finally {
    cargando.value = false
  }
}

function buscar() {
  clearTimeout(temporizador)
  temporizador = setTimeout(() => {
    pagina.value = 1
    cargar()
  }, 300)
}

function irAPagina(n: number) {
  pagina.value = n
  cargar()
}

function fecha(iso: string | null) {
  return iso ? new Date(iso).toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—'
}

/** «Migración Horizon: estado DB…» se muestra con la causa en negrita y el detalle debajo. */
function partesMotivo(motivo: string | null) {
  if (!motivo) return { causa: 'Sin motivo registrado', detalle: '' }
  const i = motivo.indexOf(':')
  return i > 0 ? { causa: motivo.slice(0, i).trim(), detalle: motivo.slice(i + 1).trim() } : { causa: motivo, detalle: '' }
}

// --- Reincorporar ---
const aReincorporar = ref<CopiaDeBaja | null>(null)
const nota = ref('')
const reincorporando = ref(false)

function pedirReincorporar(copia: CopiaDeBaja) {
  aReincorporar.value = copia
  nota.value = ''
}

async function reincorporar() {
  const copia = aReincorporar.value
  if (!copia) return
  reincorporando.value = true
  try {
    await api.patch(`/ejemplares/${copia.id}/estado`, { estado_proceso: 'inventario', motivo: nota.value.trim() || null })
    toast.success('Copia reincorporada: quedó en Inventario')
    aReincorporar.value = null
    cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo reincorporar la copia'))
  } finally {
    reincorporando.value = false
  }
}

onMounted(cargar)
</script>

<template>
  <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
    <header class="flex flex-col gap-3 border-b border-gray-100 bg-indigo-50/40 px-5 py-3 lg:flex-row lg:items-center lg:justify-between">
      <div>
        <h3 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
          <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
          Eliminados
        </h3>
        <p class="mt-0.5 pl-3 text-xs text-gray-500">
          Copias dadas de baja (por pérdida, daño u obsolescencia). No se borran: siguen en los historiales y se pueden reincorporar.
        </p>
      </div>
      <div class="relative w-full shrink-0 lg:w-80">
        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.350-4.350M11 19a8 8 0 100-16 8 8 0 000 16z" /></svg>
        <input
          v-model="busqueda"
          type="search"
          placeholder="Buscar por título, autor o código de barras"
          aria-label="Buscar copias dadas de baja"
          class="w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-9 pr-3 text-sm outline-none placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-500"
          @input="buscar"
        />
      </div>
    </header>

    <ApiErrorBanner v-if="apiError" class="m-5" />

    <div class="overflow-x-auto">
      <table class="w-full">
        <thead>
          <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
            <th class="px-4 py-2.5">Material</th>
            <th class="px-4 py-2.5">Motivo</th>
            <th class="px-4 py-2.5">Baja</th>
            <th class="px-4 py-2.5 text-right">Acción</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="c in copias" :key="c.id" class="even:bg-gray-50/50">
            <td class="border-l-[3px] border-l-gray-300 px-4 py-2.5">
              <span class="block text-sm font-semibold text-gray-900">{{ c.libro.titulo }}</span>
              <span class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-gray-500">
                Copia {{ c.numero_copia }}
                <span class="rounded border border-gray-200 bg-white px-1.5 py-px font-mono text-gray-700">{{ c.codigo_barras }}</span>
                <span v-if="c.libro.tipo_material" class="rounded bg-indigo-50 px-1.5 py-px font-semibold text-indigo-700">{{ c.libro.tipo_material.nombre }}</span>
              </span>
            </td>
            <td class="px-4 py-2.5">
              <span class="block text-sm font-semibold text-gray-700">{{ partesMotivo(c.baja_motivo).causa }}</span>
              <span v-if="partesMotivo(c.baja_motivo).detalle" class="block text-xs text-gray-500">{{ partesMotivo(c.baja_motivo).detalle }}</span>
            </td>
            <td class="whitespace-nowrap px-4 py-2.5 text-sm text-gray-700">
              {{ fecha(c.baja_en) }}
              <span v-if="c.baja_por" class="block text-[11px] text-gray-500">{{ c.baja_por }}</span>
            </td>
            <td class="px-4 py-2.5 text-right">
              <button type="button" class="rounded-lg border border-emerald-300 bg-emerald-50/60 px-3 py-1 text-xs font-semibold text-emerald-700 shadow-sm transition-colors hover:bg-emerald-100" @click="pedirReincorporar(c)">Reincorporar</button>
            </td>
          </tr>
          <tr v-if="!cargando && !copias.length">
            <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-400">
              {{ busqueda.trim() ? 'Sin copias dadas de baja que coincidan con la búsqueda.' : 'No hay copias dadas de baja.' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <Paginador
      class="border-t border-gray-100 px-4 py-2"
      :pagina="meta.current_page"
      :paginas="meta.last_page"
      :total="meta.total"
      :desde="meta.from"
      :hasta="meta.to"
      unidad="copias"
      :cargando="cargando"
      @update:pagina="irAPagina"
    />

    <DialogoConfirmacion
      :abierto="!!aReincorporar"
      titulo="¿Reincorporar esta copia?"
      confirmar="Sí, reincorporar"
      destacado
      :cargando="reincorporando"
      @confirmar="reincorporar"
      @cancelar="aReincorporar = null"
    >
      <div class="rounded-lg border border-l-[3px] border-gray-200 border-l-indigo-600 bg-indigo-50/30 px-4 py-3">
        <p class="font-bold text-gray-900">{{ aReincorporar?.libro.titulo }}</p>
        <p class="mt-1 flex items-center gap-1.5 text-xs text-gray-500">
          Copia {{ aReincorporar?.numero_copia }}
          <span class="rounded border border-gray-200 bg-white px-1.5 py-px font-mono text-gray-700">{{ aReincorporar?.codigo_barras }}</span>
        </p>
      </div>
      <!-- Lo que hace este botón: la copia sale de baja y queda en Inventario. -->
      <p class="mt-3 flex flex-wrap items-center gap-1.5 text-xs font-semibold">
        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-gray-500 line-through">De baja</span>
        <span class="text-gray-400" aria-hidden="true">→</span>
        <span class="rounded-full bg-indigo-50 px-2.5 py-0.5 text-indigo-700">Inventario</span>
      </p>
      <p class="mt-2">
        Vuelve a la colección en estado <strong class="text-gray-900">Inventario</strong>; desde Estado de ejemplares se pasa a «En estante» cuando esté lista.
      </p>
      <label for="nota-reincorporar" class="mt-3 block text-xs font-medium text-gray-600">Nota (opcional)</label>
      <input
        id="nota-reincorporar"
        v-model="nota"
        type="text"
        maxlength="500"
        placeholder="Ej: apareció en otra sala"
        class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm outline-none placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-500"
      />
    </DialogoConfirmacion>
  </section>
</template>
