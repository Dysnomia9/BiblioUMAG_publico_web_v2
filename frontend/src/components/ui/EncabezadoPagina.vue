<script setup lang="ts">
defineProps<{
  titulo: string
  descripcion?: string
}>()
</script>

<template>
  <div
    class="rounded-xl shadow-md mb-6 overflow-hidden"
    style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
  >
    <div class="px-6 py-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-serif font-bold tracking-tight text-white">{{ titulo }}</h1>
        <p v-if="descripcion" class="text-sm text-white/60 mt-1">{{ descripcion }}</p>
      </div>
      <div v-if="$slots.acciones" class="flex flex-wrap gap-2 shrink-0">
        <slot name="acciones" />
      </div>
    </div>
  </div>
</template>
