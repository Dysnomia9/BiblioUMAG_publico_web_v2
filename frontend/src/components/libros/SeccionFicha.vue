<script setup lang="ts">
import { TONOS_FICHA, type TonoFicha } from './tonosFicha'

/** Tarjeta numerada de una sección del formulario de catalogación. */
defineProps<{ id: string; numero: number; titulo: string; detalle: string; tono: TonoFicha }>()
</script>

<template>
  <!-- Sin overflow-hidden: las listas desplegables de los campos (como la búsqueda de la
       obra al agregar una copia) deben poder salir de la tarjeta sin cortarse. -->
  <section :id="id" class="scroll-mt-6 rounded-2xl border border-gray-200 bg-white shadow-sm">
    <header class="flex items-center gap-3 rounded-t-2xl border-b border-gray-100 px-5 py-3.5" :class="TONOS_FICHA[tono].cabecera">
      <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm font-bold" :class="TONOS_FICHA[tono].numero">
        {{ numero }}
      </span>
      <div class="min-w-0">
        <h2 class="text-[15px] font-semibold leading-tight text-gray-900">{{ titulo }}</h2>
        <p class="mt-0.5 text-xs" :class="TONOS_FICHA[tono].detalle">{{ detalle }}</p>
      </div>
    </header>
    <div class="p-5">
      <slot />
    </div>
  </section>
</template>
