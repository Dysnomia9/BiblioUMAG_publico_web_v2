<script setup lang="ts">
import { computed } from 'vue'
import type { SerieItem } from '@/types'

const props = withDefaults(
  defineProps<{
    data: SerieItem[]
    color?: string
  }>(),
  { color: '#4338CA' },
)

const max = computed(() => Math.max(1, ...props.data.map((d) => d.value)))
</script>

<template>
  <div class="h-full flex flex-col">
    <div class="flex-1 flex items-end gap-1">
      <div
        v-for="(d, i) in data"
        :key="i"
        class="flex-1 flex flex-col items-center justify-end h-full group relative min-w-0"
      >
        <span class="text-[10px] text-gray-500 mb-1 opacity-0 group-hover:opacity-100 transition-opacity tabular-nums">{{ d.value }}</span>
        <div
          class="w-full rounded-t transition-all"
          :style="{ height: `${(d.value / max) * 100}%`, minHeight: d.value ? '3px' : '1px', backgroundColor: color, opacity: d.value ? 1 : 0.15 }"
        />
      </div>
      <p v-if="!data.length" class="w-full text-center text-sm text-gray-400 pb-4">Sin datos para mostrar.</p>
    </div>
    <div v-if="data.length" class="flex gap-1 mt-2">
      <span
        v-for="(d, i) in data"
        :key="i"
        class="flex-1 text-center text-[9px] text-gray-400 truncate"
        :title="d.label"
      >
        {{ d.label }}
      </span>
    </div>
  </div>
</template>
