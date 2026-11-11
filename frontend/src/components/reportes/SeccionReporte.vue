<script setup lang="ts">
/** Tarjeta de una sección de Reportes: encabezado con su título y, debajo, el contenido. */
defineProps<{ titulo: string; subtitulo?: string }>()
</script>

<template>
  <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
    <header class="border-b border-gray-100 bg-indigo-50/40 px-5 py-3.5">
      <div class="border-l-[3px] border-indigo-600 pl-3">
        <h3 class="font-sans text-base font-bold text-gray-900">{{ titulo }}</h3>
        <p v-if="subtitulo || $slots.subtitulo" class="mt-0.5 text-xs text-gray-500">
          <slot name="subtitulo">{{ subtitulo }}</slot>
        </p>
      </div>
    </header>
    <div class="p-5">
      <slot />
    </div>
  </section>
</template>
