<script lang="ts">
// Modales abiertos, del más antiguo al más reciente: con una confirmación encima de un
// formulario, Esc cierra solo la confirmación.
const abiertos: symbol[] = []
</script>

<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    abierto: boolean
    titulo: string
    descripcion?: string
    ancho?: 'sm' | 'md' | 'lg' | 'xl'
    /** Bloquea cerrar con Esc/clic afuera mientras se guarda. */
    bloqueado?: boolean
    /** Deja los botones del pie siempre a la vista; solo se desplaza el contenido. */
    pieFijo?: boolean
  }>(),
  { ancho: 'sm', bloqueado: false, pieFijo: false },
)

const emit = defineEmits<{ cerrar: [] }>()

const ANCHOS = { sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-2xl', xl: 'max-w-4xl' }

function cerrar() {
  if (!props.bloqueado) emit('cerrar')
}

const id = Symbol()

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && abiertos[abiertos.length - 1] === id) cerrar()
}

function quitarDeLaPila() {
  const i = abiertos.indexOf(id)
  if (i !== -1) abiertos.splice(i, 1)
}

watch(
  () => props.abierto,
  (abierto) => {
    if (abierto) {
      abiertos.push(id)
      document.addEventListener('keydown', onKeydown)
    } else {
      quitarDeLaPila()
      document.removeEventListener('keydown', onKeydown)
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  quitarDeLaPila()
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="abierto"
      class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
      @click.self="cerrar"
    >
      <div
        class="bg-white rounded-2xl shadow-2xl w-full max-h-[92vh]"
        :class="[ANCHOS[ancho], pieFijo ? 'flex flex-col' : 'p-6 overflow-y-auto overscroll-contain']"
        role="dialog"
        aria-modal="true"
        :aria-label="titulo"
      >
        <div :class="pieFijo ? 'p-6 overflow-y-auto overscroll-contain' : ''">
          <h3 class="text-lg font-bold text-gray-900 mb-1">{{ titulo }}</h3>
          <p v-if="descripcion" class="text-sm text-gray-500 mb-5">{{ descripcion }}</p>
          <div :class="descripcion ? '' : 'mt-4'">
            <slot />
          </div>
        </div>
        <div
          v-if="$slots.pie"
          class="flex flex-col-reverse sm:flex-row gap-3"
          :class="pieFijo ? 'shrink-0 px-6 py-4 border-t border-gray-100' : 'mt-6'"
        >
          <slot name="pie" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
