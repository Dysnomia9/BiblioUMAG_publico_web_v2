<script setup lang="ts">
import type { LibroCatalogo } from '@/types'

/** Las copias en estante de un título: dónde está cada una, su colección y su nota pública. */
defineProps<{ copias: NonNullable<LibroCatalogo['copias']> }>()
</script>

<template>
  <div v-if="copias.length">
    <p class="mt-4 mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">Ejemplares</p>
    <ul class="divide-y divide-gray-100 text-sm">
      <li v-for="c in copias" :key="c.numero_copia" class="flex items-start justify-between gap-3 py-2">
        <div class="min-w-0">
          <p class="text-gray-900">
            Copia {{ c.numero_copia }}<template v-if="c.volumen"> · {{ c.volumen }}</template>
          </p>
          <p class="text-xs text-gray-500">{{ [c.coleccion, c.ubicacion].filter(Boolean).join(' · ') || 'Sin colección registrada' }}</p>
          <p v-if="c.nota_publica" class="mt-0.5 text-xs text-gray-600">{{ c.nota_publica }}</p>
        </div>
        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="c.disponible ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'">
          {{ c.disponible ? 'Disponible' : 'No disponible' }}
        </span>
      </li>
    </ul>
  </div>
</template>
