<script setup lang="ts">
import { computed, ref } from 'vue'
import { sinTildes } from '@/utils/texto'

/**
 * Selector múltiple con autocompletado: escribe para filtrar el catálogo existente
 * (`opciones`), o presiona Enter con un texto que no matchea nada para agregarlo
 * como valor nuevo (el backend hace firstOrCreate por nombre — ver LibroController).
 * Lo elegido queda como fichas dentro del mismo recuadro; Retroceso con el campo
 * vacío quita la última.
 */
const props = withDefaults(
  defineProps<{
    modelValue: string[]
    opciones: { id: number; nombre: string }[]
    placeholder?: string
    /** Clases de las fichas (fondo, texto y anillo), según la sección. */
    claseFicha?: string
  }>(),
  { placeholder: '', claseFicha: 'bg-indigo-50 text-indigo-800 ring-indigo-200' },
)

const emit = defineEmits<{ 'update:modelValue': [string[]] }>()

const texto = ref('')
const abierto = ref(false)
const campo = ref<HTMLInputElement | null>(null)

const sugerencias = computed(() => {
  const q = sinTildes(texto.value.trim())
  return props.opciones
    .map((o) => o.nombre)
    .filter((nombre) => !props.modelValue.includes(nombre))
    .filter((nombre) => !q || sinTildes(nombre).includes(q))
    .slice(0, 8)
})

// «informatica» ya es «Informática»: no se ofrece crear un duplicado que solo cambia en las tildes.
const existente = computed(() => sugerencias.value.find((s) => sinTildes(s) === sinTildes(texto.value.trim())))
const coincideExacto = computed(() => !!existente.value)

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
  agregar(existente.value ?? texto.value)
}

function onRetroceso() {
  if (!texto.value && props.modelValue.length) quitar(props.modelValue[props.modelValue.length - 1])
}

function onBlur() {
  window.setTimeout(() => (abierto.value = false), 150)
}
</script>

<template>
  <div class="relative">
    <div
      class="flex min-h-[2.75rem] w-full cursor-text flex-wrap items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-2 py-1.5 transition-shadow focus-within:border-indigo-400 focus-within:ring-2 focus-within:ring-indigo-500/30"
      @click="campo?.focus()"
    >
      <span
        v-for="nombre in modelValue"
        :key="nombre"
        class="inline-flex items-center gap-1 rounded-md py-1 pl-2 pr-1 text-xs font-medium ring-1 ring-inset"
        :class="claseFicha"
      >
        {{ nombre }}
        <button type="button" @click.stop="quitar(nombre)" class="rounded p-0.5 opacity-60 transition-opacity hover:opacity-100" :aria-label="`Quitar ${nombre}`">
          <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </span>
      <input
        ref="campo"
        v-model="texto"
        type="text"
        :placeholder="placeholder"
        class="min-w-[9rem] flex-1 border-0 bg-transparent px-1 py-1 text-sm text-gray-900 outline-none placeholder:text-gray-400"
        @focus="abierto = true"
        @input="abierto = true"
        @blur="onBlur"
        @keydown.enter.prevent="onEnter"
        @keydown.backspace="onRetroceso"
      />
    </div>

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
</template>
