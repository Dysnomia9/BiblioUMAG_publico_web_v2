<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Modal from './Modal.vue'

/**
 * Confirmación para acciones que sacan algo de circulación: además de aceptar, hay que
 * escribir la palabra pedida (CONFIRMAR), para que no se haga por un clic de más.
 */
const props = withDefaults(
  defineProps<{
    abierto: boolean
    titulo: string
    confirmar?: string
    palabra?: string
    cargando?: boolean
    /** Algo más que falta para poder confirmar (por ejemplo, el motivo). */
    deshabilitado?: boolean
  }>(),
  { confirmar: 'Confirmar', palabra: 'CONFIRMAR', cargando: false, deshabilitado: false },
)

const emit = defineEmits<{ confirmar: []; cancelar: [] }>()

const escrito = ref('')
const coincide = computed(() => escrito.value.trim().toUpperCase() === props.palabra)

// Cada vez que se abre, se empieza de cero.
watch(() => props.abierto, (abierto) => {
  if (abierto) escrito.value = ''
})
</script>

<template>
  <Modal :abierto="abierto" :titulo="titulo" :bloqueado="cargando" @cerrar="emit('cancelar')">
    <div class="space-y-4 text-sm text-gray-500">
      <slot />
      <div>
        <label for="confirmacion-escrita" class="mb-1.5 block text-xs font-semibold text-gray-700">
          Para continuar, escribe <span class="font-mono text-red-700">{{ palabra }}</span>
        </label>
        <input
          id="confirmacion-escrita"
          v-model="escrito"
          type="text"
          autocomplete="off"
          spellcheck="false"
          :placeholder="palabra"
          class="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 font-mono text-sm uppercase outline-none placeholder:text-gray-300 focus:border-red-400 focus:ring-2 focus:ring-red-500/30"
          @keydown.enter.prevent="coincide && !cargando && !deshabilitado && emit('confirmar')"
        />
      </div>
    </div>
    <template #pie>
      <button
        class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
        :disabled="cargando"
        @click="emit('cancelar')"
      >
        Cancelar
      </button>
      <button
        class="flex-1 px-4 py-2.5 text-white rounded-lg bg-red-600 hover:bg-red-700 transition-colors font-medium text-sm disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="cargando || deshabilitado || !coincide"
        @click="emit('confirmar')"
      >
        {{ cargando ? 'Procesando…' : confirmar }}
      </button>
    </template>
  </Modal>
</template>
