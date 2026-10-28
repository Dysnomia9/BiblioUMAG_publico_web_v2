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
  <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
    <div class="border-b border-gray-100 p-5">
      <h2 class="text-base font-semibold text-gray-900">Eliminados</h2>
      <p class="mt-0.5 text-sm text-gray-500">
        Copias dadas de baja (por pérdida, daño u obsolescencia). No se borran: siguen en los historiales y se pueden reincorporar.
      </p>
      <input
        v-model="busqueda"
        type="search"
        placeholder="Buscar por título, autor o código de barras"
        aria-label="Buscar copias dadas de baja"
        class="mt-4 h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30 sm:max-w-md"
        @input="buscar"
      />
    </div>

    <ApiErrorBanner v-if="apiError" class="m-5" />

    <div class="overflow-x-auto">
      <table class="w-full">
        <thead>
          <tr class="border-b border-gray-200 bg-gray-50">
            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Material</th>
            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Motivo</th>
            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Baja</th>
            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Acción</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="c in copias" :key="c.id" class="align-top">
            <td class="px-5 py-3 text-sm text-gray-900">
              {{ c.libro.titulo }}
              <p class="font-mono text-xs text-gray-500">
                Copia {{ c.numero_copia }} · {{ c.codigo_barras }}<template v-if="c.libro.tipo_material"> · <span class="font-sans">{{ c.libro.tipo_material.nombre }}</span></template>
              </p>
            </td>
            <td class="px-5 py-3 text-sm text-gray-700">{{ c.baja_motivo ?? 'Sin motivo registrado' }}</td>
            <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-600">
              {{ fecha(c.baja_en) }}
              <p class="text-xs text-gray-400">{{ c.baja_por ?? '—' }}</p>
            </td>
            <td class="px-5 py-3">
              <button type="button" class="text-sm font-medium text-indigo-700 hover:text-indigo-800" @click="pedirReincorporar(c)">Reincorporar</button>
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
      class="border-t border-gray-100 px-5 py-3"
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
      :cargando="reincorporando"
      @confirmar="reincorporar"
      @cancelar="aReincorporar = null"
    >
      <p>
        <strong class="text-gray-900">{{ aReincorporar?.libro.titulo }}</strong> (copia {{ aReincorporar?.numero_copia }} ·
        <span class="font-mono">{{ aReincorporar?.codigo_barras }}</span>) vuelve a la colección en estado
        <strong class="text-gray-900">Inventario</strong>; desde Estado de ejemplares se pasa a «En estante» cuando esté lista.
      </p>
      <label for="nota-reincorporar" class="mt-3 block text-xs font-semibold text-gray-700">Nota (opcional)</label>
      <input
        id="nota-reincorporar"
        v-model="nota"
        type="text"
        maxlength="500"
        placeholder="Ej: apareció en otra sala"
        class="mt-1.5 h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
      />
    </DialogoConfirmacion>
  </section>
</template>
