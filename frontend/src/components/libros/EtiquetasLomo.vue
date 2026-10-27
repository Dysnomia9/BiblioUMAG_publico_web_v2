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

const claseInput = 'w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm'
</script>

<template>
  <div class="space-y-6">
    <div class="bg-white rounded-xl shadow-md p-6">
      <h3 class="font-semibold text-gray-900 mb-1">Etiquetas de lomo</h3>
      <p class="text-xs text-gray-500 mb-4">
        Para pegar por fuera, en el lomo (donde se afirman las hojas): horizontal, con la clasificación apilada a la izquierda y el
        <strong>código de lomo</strong> de la copia (8 dígitos) parado a la derecha. El lector lo reconoce igual que el código de 14 dígitos.
      </p>

      <div class="grid gap-5 md:grid-cols-2">
        <div>
          <label for="lomo-codigos" class="block text-xs font-medium text-gray-600 mb-1">Por código (de 14 u 8 dígitos, uno por línea)</label>
          <textarea
            id="lomo-codigos"
            v-model="codigosTexto"
            rows="4"
            placeholder="30000000000901&#10;10000004"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm font-mono mb-2"
          />
          <button
            type="button"
            :disabled="agregando"
            class="px-3 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors font-medium text-xs disabled:opacity-60"
            @click="agregarCodigos"
          >
            {{ agregando ? 'Buscando…' : 'Agregar estas copias' }}
          </button>
        </div>

        <div>
          <label for="lomo-titulo" class="block text-xs font-medium text-gray-600 mb-1">Por título (agrega todas sus copias)</label>
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
    </div>

    <div class="bg-white rounded-xl shadow-md p-6">
      <div class="flex flex-wrap items-end gap-3 mb-4">
        <div class="w-28">
          <label for="lomo-ancho" class="block text-xs font-medium text-gray-600 mb-1">Ancho (mm)</label>
          <input id="lomo-ancho" v-model.number="opciones.anchoMm" type="number" min="20" max="150" :class="claseInput" />
        </div>
        <div class="w-28">
          <label for="lomo-alto" class="block text-xs font-medium text-gray-600 mb-1">Alto (mm)</label>
          <input id="lomo-alto" v-model.number="opciones.altoMm" type="number" min="10" max="80" :class="claseInput" />
        </div>
        <div>
          <label for="lomo-lectura" class="block text-xs font-medium text-gray-600 mb-1">El código se lee</label>
          <select id="lomo-lectura" v-model="opciones.lectura" :class="claseInput">
            <option value="abajo-arriba">De abajo hacia arriba</option>
            <option value="arriba-abajo">De arriba hacia abajo</option>
          </select>
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
          <input v-model="opciones.conCopia" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
          Agregar volumen y n.º de copia (v.2, c.2)
        </label>
        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
          <input v-model="opciones.bordeDeCorte" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
          Línea de corte
        </label>
      </div>

      <p v-if="sinClasificacion" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200">
        {{ sinClasificacion }} {{ sinClasificacion === 1 ? 'copia no tiene' : 'copias no tienen' }} clasificación: escríbela abajo (solo para esta
        impresión) o se imprimirá solo el código.
      </p>

      <div class="rounded-lg border border-gray-200 overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
              <th class="px-3 py-2">Vista previa</th>
              <th class="px-3 py-2">Libro</th>
              <th class="px-3 py-2">Clasificación (una línea por espacio)</th>
              <th class="px-3 py-2"></th>
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
              <td colspan="4" class="px-3 py-8 text-center text-sm text-gray-400">Agrega copias por código o por título.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mt-4 flex flex-wrap items-center gap-3">
        <button
          type="button"
          class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
          :disabled="!etiquetas.length"
          @click="imprimir"
        >
          Generar e imprimir {{ etiquetas.length ? `(${etiquetas.length})` : '' }}
        </button>
        <button v-if="etiquetas.length" type="button" class="text-sm font-medium text-gray-600 hover:text-gray-900" @click="etiquetas = []">Vaciar lista</button>
        <p class="text-xs text-gray-500">Cambiar la clasificación acá no modifica la ficha del libro: es solo para esta impresión.</p>
      </div>
    </div>
  </div>
</template>
