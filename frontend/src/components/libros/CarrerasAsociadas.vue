<script setup lang="ts">
/**
 * La tira de carreras de la ficha de un título: a qué carreras sirve. La ven el personal
 * (Listado de material) y los estudiantes (catálogo del portal).
 */
defineProps<{ carreras: string[] }>()
</script>

<template>
  <div v-if="carreras.length" class="mb-3 rounded-xl border border-violet-200 bg-violet-50 px-4 py-3">
    <p class="mb-2 text-[11px] font-bold uppercase tracking-wider text-violet-800">
      {{ carreras.length === 1 ? 'Carrera asociada' : 'Carreras asociadas' }}
    </p>
    <ul class="flex flex-wrap gap-2">
      <li v-for="c in carreras" :key="c" class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-violet-900 ring-1 ring-inset ring-violet-200">
        {{ c }}
      </li>
    </ul>
  </div>
</template>
