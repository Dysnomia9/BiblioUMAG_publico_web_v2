<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { abrirImpresionLomo, lineasDeLomo, OPCIONES_LOMO, type EtiquetaLomo } from '@/utils/etiquetaLomo'
import type { Libro, Paginado } from '@/types'

/**
 * Etiquetas de lomo: horizontales, con la clasificación apilada a la izquierda y el
 * código de lomo (8 dígitos) parado a la derecha. Las copias se agregan por código
 * (el de 14 dígitos o el de lomo) o por título; la clasificación se puede corregir
 * antes de imprimir y nada de esto se guarda.
 */
const toast = useToast()
const route = useRoute()

const etiquetas = ref<EtiquetaLomo[]>([])
const opciones = reactive({ ...OPCIONES_LOMO })
const codigosTexto = ref('')
const agregando = ref(false)

async function agregar(params: { codigos?: string[]; libro_ids?: number[] }) {
  agregando.value = true
  try {
    const { data } = await api.post<{ etiquetas: EtiquetaLomo[]; no_encontrados: string[] }>('/ejemplares/etiquetas-lomo', params)
    const yaEstan = new Set(etiquetas.value.map((e) => e.id))
    const nuevas = data.etiquetas.filter((e) => !yaEstan.has(e.id))
    etiquetas.value.push(...nuevas)
    if (nuevas.length) toast.success(`${nuevas.length} ${nuevas.length === 1 ? 'etiqueta agregada' : 'etiquetas agregadas'}`)
    else if (data.etiquetas.length) toast.info('Esas copias ya estaban en la lista')
    if (data.no_encontrados.length) toast.error(`No se encontró ninguna copia con: ${data.no_encontrados.join(', ')}`)
    return data
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudieron buscar las copias'))
    return null
  } finally {
    agregando.value = false
  }
}

async function agregarCodigos() {
  const codigos = codigosTexto.value.split(/[\s,;]+/).map((c) => c.trim()).filter(Boolean)
  if (!codigos.length) {
    toast.error('Escanea o escribe al menos un código (de 14 u 8 dígitos)')
    return
  }
  const data = await agregar({ codigos })
  if (data && !data.no_encontrados.length) codigosTexto.value = ''
}

// ---------- Agregar por título ----------
const busqueda = ref('')
const resultados = ref<Libro[]>([])
const buscando = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

function onBuscar() {
  clearTimeout(timer)
  const q = busqueda.value.trim()
  if (q.length < 2) {
    resultados.value = []
    return
  }
  timer = setTimeout(async () => {
    buscando.value = true
    try {
      const { data } = await api.get<Paginado<Libro>>('/libros', { params: { q, por_pagina: 8 } })
      resultados.value = data.data
    } catch {
      resultados.value = []
    } finally {
      buscando.value = false
    }
  }, 250)
}

async function agregarLibro(libro: Libro) {
  await agregar({ libro_ids: [libro.id] })
}

// Desde el listado de libros («⋯» → Etiquetas de lomo) llega ?lomo=<id del libro>.
onMounted(() => {
  const ids = String(route.query.lomo ?? '')
    .split(',')
    .map(Number)
    .filter((n) => Number.isInteger(n) && n > 0)
  if (ids.length) agregar({ libro_ids: ids })
})

function quitar(id: number) {
  etiquetas.value = etiquetas.value.filter((e) => e.id !== id)
}

const sinClasificacion = computed(() => etiquetas.value.filter((e) => !e.clasificacion?.trim()).length)

function imprimir() {
  if (!etiquetas.value.length) {
    toast.error('Agrega al menos una copia')
    return
  }
  if (opciones.anchoMm < 20 || opciones.altoMm < 10 || opciones.anchoMm > 150 || opciones.altoMm > 80) {
    toast.error('Revisa el tamaño: entre 20 y 150 mm de ancho, y entre 10 y 80 mm de alto')
    return
  }
  if (!abrirImpresionLomo(etiquetas.value, opciones)) {
    toast.error('El navegador bloqueó la ventana nueva — habilita las ventanas emergentes para este sitio')
  }
}

const claseInput = 'w-full px-3 py-2 border border-gray-200 bg-white rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm'
</script>

<template>
  <div class="space-y-5">
    <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-[#b08d57] bg-white shadow-sm">
      <header class="flex flex-wrap items-center justify-between gap-4 border-b border-amber-100 bg-amber-50/60 px-5 py-3.5 sm:flex-nowrap">
        <div class="border-l-[3px] border-[#b08d57] pl-3">
          <h3 class="font-serif text-base font-bold text-gray-900">Etiquetas de lomo</h3>
          <p class="mt-0.5 text-xs text-gray-600">
            Para pegar por fuera, en el lomo (donde se afirman las hojas): horizontal, con la clasificación apilada a la izquierda y el
            <strong>código de lomo</strong> de la copia (8 dígitos) parado a la derecha. El lector lo reconoce igual que el código de 14 dígitos.
          </p>
        </div>
        <div class="tema-claro hidden h-14 w-32 shrink-0 items-stretch rounded border border-dashed border-gray-300 bg-white p-1 sm:flex" aria-hidden="true">
          <div class="flex flex-1 flex-col justify-center pl-1 font-mono text-[9px] font-bold leading-tight text-gray-900">
            <span>425</span><span>E13</span><span>1990</span><span class="text-dorado-600">c.2</span>
          </div>
          <span class="my-0.5 w-5" style="background: repeating-linear-gradient(0deg, #111 0 1.5px, transparent 1.5px 2.5px);"></span>
          <span class="ml-0.5 flex items-center font-mono text-[8px] font-semibold text-gray-700 [writing-mode:vertical-rl] rotate-180">10118050</span>
        </div>
      </header>

      <div class="grid gap-4 p-5 md:grid-cols-2">
        <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
          <label for="lomo-codigos" class="mb-2 block text-xs text-gray-600"><strong class="text-sm font-semibold text-gray-900">Por código</strong> (de 14 u 8 dígitos, uno por línea)</label>
          <textarea
            id="lomo-codigos"
            v-model="codigosTexto"
            rows="3"
            placeholder="30000000000901&#10;10000004"
            class="mb-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 font-mono text-sm outline-none focus:ring-2 focus:ring-indigo-500"
          />
          <button type="button" :disabled="agregando" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 border-b-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-gray-50 disabled:opacity-60" @click="agregarCodigos">
            {{ agregando ? 'Buscando…' : 'Agregar estas copias' }}
          </button>
        </div>

        <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
          <label for="lomo-titulo" class="mb-2 block text-xs text-gray-600"><strong class="text-sm font-semibold text-gray-900">Por título</strong> (agrega todas sus copias)</label>
          <input id="lomo-titulo" v-model="busqueda" type="search" placeholder="Título, autor, ISBN o Dewey" :class="claseInput" @input="onBuscar" />
          <ul v-if="resultados.length" class="mt-2 max-h-48 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200">
            <li v-for="l in resultados" :key="l.id" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
              <span class="min-w-0">
                <span class="block truncate font-medium text-gray-900">{{ l.titulo }}</span>
                <span class="block text-xs text-gray-500">
                  <span class="font-mono">{{ l.clasificacion ?? 'sin clasificación' }}</span> · {{ l.ejemplares_count ?? l.ejemplares?.length ?? 0 }} {{ (l.ejemplares_count ?? 0) === 1 ? 'copia' : 'copias' }}
                </span>
              </span>
              <button type="button" class="shrink-0 text-xs font-semibold text-indigo-700 hover:underline" :disabled="agregando" @click="agregarLibro(l)">Agregar</button>
            </li>
          </ul>
          <p v-else-if="busqueda.trim().length >= 2 && !buscando" class="mt-2 text-xs text-gray-400">Sin resultados.</p>
        </div>
      </div>
    </section>

    <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
      <div class="flex flex-wrap items-end gap-3 border-b border-gray-100 bg-indigo-50/40 px-5 py-3.5">
        <div>
          <label for="lomo-ancho" class="mb-1 block text-xs font-medium text-gray-700">Ancho</label>
          <div class="flex w-28 items-center overflow-hidden rounded-lg border border-gray-200 bg-white focus-within:ring-2 focus-within:ring-indigo-500">
            <input id="lomo-ancho" v-model.number="opciones.anchoMm" type="number" min="20" max="150" class="w-full min-w-0 border-0 bg-transparent px-3 py-2 text-sm outline-none" />
            <span class="border-l border-gray-200 bg-gray-50 px-2 py-2 text-xs text-gray-500">mm</span>
          </div>
        </div>
        <div>
          <label for="lomo-alto" class="mb-1 block text-xs font-medium text-gray-700">Alto</label>
          <div class="flex w-28 items-center overflow-hidden rounded-lg border border-gray-200 bg-white focus-within:ring-2 focus-within:ring-indigo-500">
            <input id="lomo-alto" v-model.number="opciones.altoMm" type="number" min="10" max="80" class="w-full min-w-0 border-0 bg-transparent px-3 py-2 text-sm outline-none" />
            <span class="border-l border-gray-200 bg-gray-50 px-2 py-2 text-xs text-gray-500">mm</span>
          </div>
        </div>
        <div>
          <label for="lomo-lectura" class="mb-1 block text-xs font-medium text-gray-700">El código se lee</label>
          <select id="lomo-lectura" v-model="opciones.lectura" :class="claseInput">
            <option value="abajo-arriba">De abajo hacia arriba</option>
            <option value="arriba-abajo">De arriba hacia abajo</option>
          </select>
        </div>
        <label class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700">
          <input v-model="opciones.conCopia" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
          Agregar volumen y n.º de copia
          <code class="rounded bg-indigo-50 px-1.5 py-0.5 text-xs text-indigo-700">v.2, c.2</code>
        </label>
        <label class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700">
          <input v-model="opciones.bordeDeCorte" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
          Línea de corte
        </label>
      </div>

      <div class="p-5">
        <p v-if="sinClasificacion" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200">
        {{ sinClasificacion }} {{ sinClasificacion === 1 ? 'copia no tiene' : 'copias no tienen' }} clasificación: escríbela abajo (solo para esta
        impresión) o se imprimirá solo el código.
      </p>

        <div class="overflow-x-auto rounded-xl border border-gray-200">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
                <th class="px-3 py-2.5">Vista previa</th>
                <th class="px-3 py-2.5">Libro</th>
                <th class="px-3 py-2.5">Clasificación (una línea por espacio)</th>
                <th class="px-3 py-2.5"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <tr v-for="e in etiquetas" :key="e.id" class="align-middle">
              <td class="px-3 py-2">
                <!-- Miniatura: la clasificación apilada y el código parado a la derecha. -->
                <div class="tema-claro flex h-14 w-28 items-stretch rounded border border-dashed border-gray-300 bg-white p-1">
                  <div class="flex w-[45%] flex-col items-center justify-center border-r border-gray-900 pr-1 font-bold leading-tight text-[9px] text-gray-900">
                    <span v-for="(l, i) in lineasDeLomo(e, opciones.conCopia)" :key="i" class="whitespace-nowrap">{{ l }}</span>
                  </div>
                  <div class="flex flex-1 items-center justify-center">
                    <span class="font-mono text-[9px] font-semibold text-gray-700 [writing-mode:vertical-rl]" :class="opciones.lectura === 'abajo-arriba' ? 'rotate-180' : ''">{{ e.codigo_lomo }}</span>
                  </div>
                </div>
              </td>
              <td class="px-3 py-2">
                <p class="font-medium text-gray-900">{{ e.titulo ?? '—' }}</p>
                <p class="text-xs text-gray-500">Copia {{ e.numero_copia }} · <span class="font-mono">{{ e.codigo_barras }}</span> · lomo <span class="font-mono">{{ e.codigo_lomo }}</span></p>
              </td>
              <td class="px-3 py-2">
                <input
                  v-model="e.clasificacion"
                  placeholder="Ej: 998 E56 2026"
                  :aria-label="`Clasificación de ${e.titulo ?? 'la copia'}`"
                  class="w-44 px-2 py-1.5 border rounded-lg font-mono text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                  :class="e.clasificacion?.trim() ? 'border-gray-300' : 'border-amber-300 bg-amber-50/50'"
                />
              </td>
              <td class="px-3 py-2 text-right">
                <button type="button" class="text-xs font-medium text-red-600 hover:text-red-700" @click="quitar(e.id)">Quitar</button>
              </td>
            </tr>
              <tr v-if="!etiquetas.length">
                <td colspan="4" class="px-3 py-8 text-center">
                  <span class="mx-auto mb-2 flex h-9 w-9 items-center justify-center rounded-full bg-amber-50 text-dorado-600" aria-hidden="true">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5h18v9H3v-9zM7 10.5h4m-4 3h3m5-3v3m2.5-3v3" /></svg>
                  </span>
                  <span class="text-sm text-gray-500">Agrega copias por código o por título.</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-dashed border-gray-200 pt-4">
          <button
            type="button"
            class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:opacity-50"
            :disabled="!etiquetas.length"
            @click="imprimir"
          >
            Generar e imprimir {{ etiquetas.length ? `(${etiquetas.length})` : '' }}
          </button>
          <button v-if="etiquetas.length" type="button" class="text-sm font-medium text-gray-600 hover:text-gray-900" @click="etiquetas = []">Vaciar lista</button>
          <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-dorado-700">Cambiar la clasificación acá no modifica la ficha del libro: es solo para esta impresión.</p>
        </div>
      </div>
    </section>
  </div>
</template>
