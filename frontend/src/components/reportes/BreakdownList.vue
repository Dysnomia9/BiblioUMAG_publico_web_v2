<script setup lang="ts">
import { computed } from 'vue'
import type { SerieItem } from '@/types'

const props = withDefaults(
  defineProps<{
    data: SerieItem[]
    total: number
    color?: 'indigo' | 'emerald' | 'amber' | 'blue' | 'purple'
    itemColor?: (label: string) => string
  }>(),
  { color: 'indigo' },
)

const barColor: Record<string, string> = {
  indigo: 'bg-indigo-500',
  emerald: 'bg-emerald-500',
  amber: 'bg-amber-500',
  blue: 'bg-blue-500',
  purple: 'bg-purple-500',
}

const max = computed(() => Math.max(1, ...props.data.map((d) => d.value)))
</script>

<template>
  <div class="space-y-2.5">
    <div v-for="d in data" :key="d.label" class="text-sm">
      <div class="flex items-center justify-between mb-1">
        <span class="text-gray-700 truncate flex items-center gap-2">
          <span v-if="itemColor" class="inline-block w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: itemColor(d.label) }" />
          {{ d.label }}
        </span>
        <span class="text-gray-500 tabular-nums shrink-0 ml-2">{{ d.value }} <span class="text-gray-400">({{ total ? Math.round((d.value / total) * 100) : 0 }}%)</span></span>
      </div>
      <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
        <div
          class="h-full rounded-full"
          :class="itemColor ? '' : barColor[color]"
          :style="{ width: `${(d.value / max) * 100}%`, backgroundColor: itemColor ? itemColor(d.label) : undefined }"
        />
      </div>
    </div>
    <p v-if="!data.length" class="text-sm text-gray-400 text-center py-4">Sin datos para mostrar.</p>
  </div>
</template>
