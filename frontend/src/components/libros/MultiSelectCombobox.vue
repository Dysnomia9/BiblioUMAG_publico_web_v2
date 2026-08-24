<script setup lang="ts">
import { computed, ref } from 'vue'

/**
 * Selector múltiple con autocompletado: escribe para filtrar el catálogo existente
 * (`opciones`), o presiona Enter con un texto que no matchea nada para agregarlo
 * como valor nuevo (el backend hace firstOrCreate por nombre — ver LibroController).
 */
const props = defineProps<{
  modelValue: string[]
  opciones: { id: number; nombre: string }[]
  placeholder?: string
}>()

const emit = defineEmits<{ 'update:modelValue': [string[]] }>()

const texto = ref('')
const abierto = ref(false)

const sugerencias = computed(() => {
  const q = texto.value.trim().toLowerCase()
  return props.opciones
    .map((o) => o.nombre)
    .filter((nombre) => !props.modelValue.includes(nombre))
    .filter((nombre) => !q || nombre.toLowerCase().includes(q))
    .slice(0, 8)
})

const coincideExacto = computed(() => sugerencias.value.some((s) => s.toLowerCase() === texto.value.trim().toLowerCase()))

function agregar(nombre: string) {
  const limpio = nombre.trim()
  if (!limpio || props.modelValue.includes(limpio)) return
  emit('update:modelValue', [...props.modelValue, limpio])
  texto.value = ''
  abierto.value = false
}

function quitar(nombre: string) {
  emit('update:modelValue', props.modelValue.filter((n) => n !== nombre))
}

function onEnter() {
  if (!texto.value.trim()) return
  agregar(texto.value)
}

function onBlur() {
  window.setTimeout(() => (abierto.value = false), 150)
}
</script>

<template>
  <div>
    <div v-if="modelValue.length" class="flex flex-wrap gap-1.5 mb-2">
      <span
        v-for="nombre in modelValue"
        :key="nombre"
        class="inline-flex items-center gap-1 rounded-full bg-indigo-50 text-indigo-700 text-xs font-medium pl-2.5 pr-1.5 py-1"
      >
        {{ nombre }}
        <button type="button" @click="quitar(nombre)" class="rounded-full hover:bg-indigo-200 p-0.5" :aria-label="`Quitar ${nombre}`">
          <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </span>
    </div>

    <div class="relative">
      <input
        v-model="texto"
        type="text"
        :placeholder="placeholder"
        class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"
        @focus="abierto = true"
        @blur="onBlur"
        @keydown.enter.prevent="onEnter"
      />

      <div
        v-if="abierto && (sugerencias.length || texto.trim())"
        class="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-56 overflow-y-auto py-1"
      >
        <button
          v-for="s in sugerencias"
          :key="s"
          type="button"
          class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-indigo-50"
          @mousedown.prevent="agregar(s)"
        >
          {{ s }}
        </button>
        <button
          v-if="texto.trim() && !coincideExacto"
          type="button"
          class="w-full text-left px-3 py-2 text-sm text-indigo-700 font-medium hover:bg-indigo-50 border-t border-gray-100"
          @mousedown.prevent="agregar(texto)"
        >
          + Crear "{{ texto.trim() }}"
        </button>
      </div>
    </div>
  </div>
</template>
