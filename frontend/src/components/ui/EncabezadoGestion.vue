<script setup lang="ts">
/**
 * Encabezado de las pantallas de gestión: ruta «Gestión › sección» (Gestión abre el
 * menú de la barra superior), título serif y, a la derecha, las acciones.
 */
defineProps<{ seccion: string; titulo: string; descripcion?: string }>()

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}
</script>

<template>
  <div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
    <div class="min-w-0">
      <nav class="mb-1 text-xs text-gray-500" aria-label="Ruta">
        <button type="button" class="font-medium text-indigo-700 hover:underline" @click="abrirMenuGestion">Gestión</button>
        <span class="mx-1.5 text-gray-400">›</span>
        <span>{{ seccion }}</span>
      </nav>
      <h1 class="font-serif text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">{{ titulo }}</h1>
      <p v-if="descripcion" class="mt-1 text-sm text-gray-500">{{ descripcion }}</p>
      <slot name="debajo" />
    </div>
    <div v-if="$slots.acciones" class="flex min-w-0 flex-wrap items-center gap-2">
      <slot name="acciones" />
    </div>
  </div>
</template>
