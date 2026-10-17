<script setup lang="ts">
import type { FilaFicha } from '@/types'

/**
 * Ficha bibliográfica de un título. El personal la ve con los números de etiqueta
 * MARC y los campos de uso interno marcados; el portal recibe solo las filas
 * públicas y las muestra sin números.
 */
withDefaults(defineProps<{ filas: FilaFicha[]; conEtiquetas?: boolean }>(), { conEtiquetas: false })

const esEnlace = (fila: FilaFicha) => fila.etiqueta === '856' && /^https?:\/\//.test(fila.valor)
</script>

<template>
  <dl class="divide-y divide-gray-100 text-sm">
    <div v-for="(f, i) in filas" :key="i" class="grid grid-cols-1 sm:grid-cols-[minmax(0,15rem)_1fr] gap-x-4 gap-y-0.5 py-2">
      <dt class="flex flex-wrap items-center gap-x-2 gap-y-1 text-gray-500">
        <span class="flex items-center gap-2">
          <span
            v-if="conEtiquetas"
            class="rounded bg-indigo-50 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-100"
          >{{ f.etiqueta }}</span>
          {{ f.campo }}
        </span>
        <span v-if="f.interno" class="whitespace-nowrap rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500">uso interno</span>
      </dt>
      <dd class="break-words text-gray-900" :class="conEtiquetas ? 'font-medium' : ''">
        <a v-if="esEnlace(f)" :href="f.valor" target="_blank" rel="noopener noreferrer" class="text-indigo-700 underline hover:text-indigo-800">
          Abrir recurso electrónico
        </a>
        <template v-else>{{ f.valor }}</template>
      </dd>
    </div>
    <p v-if="!filas.length" class="py-2 text-xs text-gray-400">Sin datos bibliográficos registrados.</p>
  </dl>
</template>
