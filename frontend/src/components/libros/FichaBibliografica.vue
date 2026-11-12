<script setup lang="ts">
import { computed, ref } from 'vue'
import type { FilaFicha, ItemFicha } from '@/types'

/**
 * Ficha bibliográfica de un título. El personal la ve con los números de etiqueta
 * MARC y, al final, los campos de uso interno agrupados en un bloque que se puede
 * plegar; el portal recibe solo las filas públicas y las muestra sin números.
 */
const props = withDefaults(defineProps<{ filas: FilaFicha[]; conEtiquetas?: boolean; filtrable?: boolean }>(), { conEtiquetas: false, filtrable: false })

/** Pulsar un autor, una materia, una carrera o un profesor guía (solo con filtrable). */
const emit = defineEmits<{ filtrar: [item: ItemFicha] }>()

const sePuedeFiltrar = (item: ItemFicha) => props.filtrable && !!(item.autor_id || item.categoria_id || item.carrera_id || item.buscar)

const esEnlace = (fila: FilaFicha) => fila.etiqueta === '856' && /^https?:\/\//.test(fila.valor)
// ISBN, ISSN y clasificación se leen mejor en letra de código.
const MONO = new Set(['020', '022', '082', '090', '092'])

const publicas = computed(() => props.filas.filter((f) => !f.interno))
const internas = computed(() => props.filas.filter((f) => f.interno))
const verInternas = ref(true)
</script>

<template>
  <!-- Personal: etiqueta, campo y valor por fila; lo interno aparte, al final. -->
  <div v-if="conEtiquetas" class="text-sm">
    <dl class="divide-y divide-gray-100">
      <div v-for="(f, i) in publicas" :key="`p${i}`" class="grid grid-cols-[2.75rem_minmax(0,1fr)] items-baseline gap-x-3 gap-y-0.5 px-4 py-2 sm:grid-cols-[2.75rem_11rem_minmax(0,1fr)]">
        <span class="rounded-md bg-indigo-50 py-0.5 text-center font-mono text-[11px] font-semibold text-indigo-700">{{ f.etiqueta }}</span>
        <dt class="text-gray-500">{{ f.campo }}</dt>
        <dd class="col-start-2 whitespace-pre-line break-words font-medium text-gray-900 sm:col-start-auto" :class="MONO.has(f.etiqueta) ? 'font-mono text-[13px]' : ''">
          <a v-if="esEnlace(f)" :href="f.valor" target="_blank" rel="noopener noreferrer" class="text-indigo-700 underline hover:text-indigo-800">Abrir recurso electrónico</a>
          <!-- Varios valores (autores, materias…): una etiqueta cada uno, no unidos con «;». -->
          <span v-else-if="f.items?.length" class="flex flex-wrap gap-1.5">
            <template v-for="(item, k) in f.items" :key="k">
              <a v-if="item.url" :href="item.url" target="_blank" rel="noopener noreferrer" class="text-[13px] text-indigo-700 underline hover:text-indigo-800">{{ item.texto }}</a>
              <button
                v-else-if="sePuedeFiltrar(item)"
                type="button"
                class="rounded-md border border-indigo-100 bg-indigo-50/60 px-2 py-0.5 text-left text-[13px] font-medium text-indigo-800 transition-colors hover:border-indigo-300 hover:bg-indigo-100"
                :title="`Ver los libros de ${item.texto}`"
                @click="emit('filtrar', item)"
              >{{ item.texto }}</button>
              <span v-else class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-[13px]">{{ item.texto }}</span>
            </template>
          </span>
          <template v-else>{{ f.valor }}</template>
        </dd>
      </div>
    </dl>

    <template v-if="internas.length">
      <button
        type="button"
        class="flex w-full items-center justify-between gap-2 border-y border-gray-200 bg-gray-50 px-4 py-2 text-left"
        :aria-expanded="verInternas"
        @click="verInternas = !verInternas"
      >
        <span class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Uso interno · No se muestra a usuarios</span>
        <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-gray-500">
          {{ internas.length }} {{ internas.length === 1 ? 'campo' : 'campos' }}
          <svg class="h-3 w-3 transition-transform" :class="verInternas ? '' : '-rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
        </span>
      </button>
      <dl v-if="verInternas" class="divide-y divide-gray-100">
        <div v-for="(f, i) in internas" :key="`i${i}`" class="grid grid-cols-[2.75rem_minmax(0,1fr)] items-baseline gap-x-3 gap-y-0.5 px-4 py-2 sm:grid-cols-[2.75rem_11rem_minmax(0,1fr)]">
          <span class="rounded-md bg-gray-100 py-0.5 text-center font-mono text-[11px] font-semibold text-gray-500">{{ f.etiqueta }}</span>
          <dt class="text-gray-500">{{ f.campo }}</dt>
          <dd class="col-start-2 whitespace-pre-line break-words text-gray-600 sm:col-start-auto" :class="MONO.has(f.etiqueta) ? 'font-mono text-[13px]' : ''">
            <span v-if="f.items?.length" class="flex flex-wrap gap-1.5">
              <template v-for="(item, k) in f.items" :key="k">
                <a v-if="item.url" :href="item.url" target="_blank" rel="noopener noreferrer" class="text-[13px] text-indigo-700 underline">{{ item.texto }}</a>
                <span v-else class="rounded-md border border-gray-200 bg-white px-2 py-0.5 text-[13px]">{{ item.texto }}</span>
              </template>
            </span>
            <template v-else>{{ f.valor }}</template>
          </dd>
        </div>
      </dl>
    </template>
    <p v-if="!filas.length" class="px-4 py-2 text-xs text-gray-400">Sin datos bibliográficos registrados.</p>
  </div>

  <!-- Portal: solo las filas públicas, sin números de etiqueta. -->
  <dl v-else class="divide-y divide-gray-100 text-sm">
    <div v-for="(f, i) in filas" :key="i" class="grid grid-cols-1 sm:grid-cols-[minmax(0,15rem)_1fr] gap-x-4 gap-y-0.5 py-2">
      <dt class="text-gray-500">{{ f.campo }}</dt>
      <dd class="whitespace-pre-line break-words text-gray-900">
        <a v-if="esEnlace(f)" :href="f.valor" target="_blank" rel="noopener noreferrer" class="text-indigo-700 underline hover:text-indigo-800">
          Abrir recurso electrónico
        </a>
        <span v-else-if="f.items?.length" class="flex flex-wrap gap-1.5">
          <template v-for="(item, k) in f.items" :key="k">
            <a v-if="item.url" :href="item.url" target="_blank" rel="noopener noreferrer" class="text-indigo-700 underline hover:text-indigo-800">{{ item.texto }}</a>
            <span v-else class="rounded-md bg-gray-100 px-2 py-0.5">{{ item.texto }}</span>
          </template>
        </span>
        <template v-else>{{ f.valor }}</template>
      </dd>
    </div>
    <p v-if="!filas.length" class="py-2 text-xs text-gray-400">Sin datos bibliográficos registrados.</p>
  </dl>
</template>
