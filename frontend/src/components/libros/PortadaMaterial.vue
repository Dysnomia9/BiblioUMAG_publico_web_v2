<script setup lang="ts">
import { computed } from 'vue'
import { ICONOS_PORTADA, formaPortada, inicialesPortada, tonoPortada } from '@/utils/portada'

/**
 * La portada de un título en las listas del catálogo: un libro con sus iniciales y el lomo
 * de color, o el ícono de su tipo de material (tesis, revista, juego, DVD…) sobre el mismo
 * color. El color sale del área (ver tonoPortada()).
 */
const props = withDefaults(
  defineProps<{
    titulo: string
    tipo?: string | null
    plantilla?: string | null
    /** Lo que da el color: el área o, si no tiene, el tipo o el título. */
    claveTono?: string | null
    tamano?: 'sm' | 'md' | 'lg'
  }>(),
  { tipo: null, plantilla: null, claveTono: null, tamano: 'md' },
)

const forma = computed(() => formaPortada(props.tipo, props.plantilla))
const tono = computed(() => tonoPortada(props.claveTono || props.tipo || props.titulo))

const MEDIDAS = {
  sm: { caja: 'h-11 w-9', texto: 'px-1 pb-0.5 text-xs', icono: 'h-4 w-4' },
  md: { caja: 'h-14 w-10', texto: 'px-1.5 pb-1 text-sm', icono: 'h-5 w-5' },
  lg: { caja: 'h-20 w-14', texto: 'px-2 pb-1.5 text-lg', icono: 'h-7 w-7' },
}
const medida = computed(() => MEDIDAS[props.tamano])
</script>

<template>
  <div
    v-if="forma === 'libro'"
    class="flex shrink-0 items-end rounded-sm border-l-4 font-serif font-bold"
    :class="[medida.caja, medida.texto, tono]"
    aria-hidden="true"
  >
    {{ inicialesPortada(titulo) }}
  </div>
  <div v-else class="flex shrink-0 items-center justify-center rounded-md border" :class="[medida.caja, tono]" aria-hidden="true">
    <svg :class="medida.icono" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONOS_PORTADA[forma]" />
    </svg>
  </div>
</template>
