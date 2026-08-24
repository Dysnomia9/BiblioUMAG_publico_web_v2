<script setup lang="ts">
import { computed } from 'vue'
import type { SerieItem } from '@/types'

const props = withDefaults(
  defineProps<{
    data: SerieItem[]
    total: number
    columna: string
    itemColor?: (label: string) => string
  }>(),
  {},
)

const max = computed(() => Math.max(1, ...props.data.map((d) => d.value)))
</script>

<template>
  <div class="rounded-lg border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-gray-100 border-b-2 border-gray-200 text-left">
            <th class="py-2.5 px-3 font-semibold text-gray-700 text-xs uppercase tracking-wide">{{ columna }}</th>
            <th class="py-2.5 px-3 font-semibold text-gray-700 text-xs uppercase tracking-wide text-right">Cantidad</th>
            <th class="py-2.5 px-3 font-semibold text-gray-700 text-xs uppercase tracking-wide text-right">%</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <tr
            v-for="(d, idx) in data"
            :key="d.label"
            class="hover:bg-indigo-50/40 transition-colors"
            :class="idx % 2 === 0 ? 'bg-white' : 'bg-biblioteca-50'"
          >
            <td class="py-2.5 px-3 text-gray-800">
              <span class="flex items-center gap-2">
                <span
                  class="inline-block w-2.5 h-2.5 rounded-full shrink-0"
                  :style="{ backgroundColor: itemColor ? itemColor(d.label) : '#6366f1' }"
                />
                {{ d.label }}
              </span>
            </td>
            <td class="py-2.5 px-3 text-right tabular-nums text-gray-700 font-medium">{{ d.value }}</td>
            <td class="py-2.5 px-3">
              <div class="flex items-center justify-end gap-2">
                <span class="text-right tabular-nums text-gray-500 w-9 shrink-0">{{ total ? Math.round((d.value / total) * 100) : 0 }}%</span>
                <div class="w-16 h-1.5 rounded-full bg-gray-100 overflow-hidden shrink-0">
                  <div
                    class="h-full rounded-full"
                    :style="{ width: `${(d.value / max) * 100}%`, backgroundColor: itemColor ? itemColor(d.label) : '#6366f1' }"
                  />
                </div>
              </div>
            </td>
          </tr>
          <tr v-if="!data.length">
            <td colspan="3" class="py-6 text-center text-gray-400">Sin datos para mostrar.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
