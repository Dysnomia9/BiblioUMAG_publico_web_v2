<script setup lang="ts">
import { computed } from 'vue'
import { separarContenido } from '@/utils/contenido'

const props = defineProps<{ valor: string }>()
const bloques = computed(() => separarContenido(props.valor))
</script>

<template>
  <ul class="space-y-1 whitespace-normal" aria-label="Contenido por tomos o secciones">
    <li v-for="(bloque, i) in bloques" :key="i" class="flex flex-col items-start gap-x-3 gap-y-1 rounded-lg border border-gray-200 bg-gray-50/80 px-2.5 py-1.5 sm:flex-row">
      <span v-if="bloque.etiqueta" class="shrink-0 rounded-md bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800">{{ bloque.etiqueta }}</span>
      <span v-if="bloque.texto" class="min-w-0 whitespace-pre-line break-words text-[13px] leading-relaxed">{{ bloque.texto }}</span>
    </li>
  </ul>
</template>
