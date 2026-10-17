<script setup lang="ts">
import Modal from './Modal.vue'

withDefaults(
  defineProps<{
    abierto: boolean
    titulo: string
    confirmar?: string
    cancelar?: string
    tono?: 'primario' | 'peligro' | 'exito'
    cargando?: boolean
    deshabilitado?: boolean
  }>(),
  { confirmar: 'Confirmar', cancelar: 'Cancelar', tono: 'primario', cargando: false, deshabilitado: false },
)

const emit = defineEmits<{ confirmar: []; cancelar: [] }>()

const TONOS = {
  primario: 'bg-indigo-600 hover:bg-indigo-700',
  peligro: 'bg-red-600 hover:bg-red-700',
  exito: 'bg-emerald-600 hover:bg-emerald-700',
}
</script>

<template>
  <Modal :abierto="abierto" :titulo="titulo" :bloqueado="cargando" @cerrar="emit('cancelar')">
    <div class="text-sm text-gray-500">
      <slot />
    </div>
    <template #pie>
      <button
        class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
        :disabled="cargando"
        @click="emit('cancelar')"
      >
        {{ cancelar }}
      </button>
      <button
        class="flex-1 px-4 py-2.5 text-white rounded-lg transition-colors font-medium text-sm disabled:opacity-60"
        :class="TONOS[tono]"
        :disabled="cargando || deshabilitado"
        @click="emit('confirmar')"
      >
        {{ cargando ? 'Procesando…' : confirmar }}
      </button>
    </template>
  </Modal>
</template>
