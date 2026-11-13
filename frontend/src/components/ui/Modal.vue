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
    /** Con false, un clic en el fondo no cierra el cuadro: solo Esc o sus botones (formularios largos). */
    cerrarAlClicAfuera?: boolean
    /** Fondo suave en el pie fijo, para separarlo del contenido. */
    pieConFondo?: boolean
    /**
     * Formato de las tarjetas nuevas: borde de color arriba, encabezado con fondo suave y título
     * serif, contenido desplazable y pie con fondo. El tono pinta el borde y el encabezado.
     */
    destacado?: boolean
    tono?: 'primario' | 'peligro' | 'exito'
    /** Con destacado: la cabecera va en el degradado morado (como Préstamos) y el contenido la pone el slot «cabecera». */
    degradado?: boolean
  }>(),
  { ancho: 'sm', bloqueado: false, pieFijo: false, cerrarAlClicAfuera: true, pieConFondo: false, destacado: false, tono: 'primario', degradado: false },
)

const TONOS_DESTACADO = {
  primario: { borde: 'border-t-indigo-600', cabecera: 'bg-indigo-50/50' },
  peligro: { borde: 'border-t-red-600', cabecera: 'bg-red-50/60' },
  exito: { borde: 'border-t-emerald-600', cabecera: 'bg-emerald-50/50' },
}

const emit = defineEmits<{ cerrar: [] }>()

const ANCHOS = { sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-2xl', xl: 'max-w-4xl' }

function cerrar() {
  if (!props.bloqueado) emit('cerrar')
}

// El fondo cierra el cuadro solo si el clic empezó y terminó en él. Al seleccionar texto
// arrastrando desde un campo hasta afuera, el navegador marca el clic en el fondo (el
// ancestro común de donde se apretó y donde se soltó), y el cuadro se cerraba.
let presionEnFondo = false

function onPresionFondo(e: PointerEvent) {
  presionEnFondo = e.target === e.currentTarget
}

function onClicFondo(e: MouseEvent) {
  const empezoEnFondo = presionEnFondo
  presionEnFondo = false
  if (props.cerrarAlClicAfuera && empezoEnFondo && e.target === e.currentTarget) cerrar()
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
      @pointerdown="onPresionFondo"
      @click="onClicFondo"
    >
      <div
        v-if="destacado"
        class="flex w-full max-h-[92vh] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl"
        :class="[ANCHOS[ancho], degradado ? '' : ['border-t-[3px]', TONOS_DESTACADO[tono].borde]]"
        role="dialog"
        aria-modal="true"
        :aria-label="titulo"
      >
        <div
          class="relative shrink-0 overflow-hidden"
          :class="degradado ? 'px-5 pb-3.5 pt-3.5 text-white' : ['border-b border-gray-100 px-5 pb-3 pt-4', TONOS_DESTACADO[tono].cabecera]"
          :style="degradado ? 'background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);' : undefined"
        >
          <slot name="cabecera">
            <h3 class="font-serif text-lg font-bold leading-snug text-gray-900">{{ titulo }}</h3>
            <p v-if="descripcion" class="mt-1 text-sm text-gray-500">{{ descripcion }}</p>
          </slot>
        </div>
        <div class="overflow-y-auto overscroll-contain px-5 py-4">
          <slot />
        </div>
        <div v-if="$slots.pie" class="flex shrink-0 flex-col-reverse gap-3 border-t border-gray-100 bg-gray-50/70 px-5 py-3 sm:flex-row">
          <slot name="pie" />
        </div>
      </div>
      <div
        v-else
        class="bg-white rounded-2xl shadow-2xl w-full max-h-[92vh]"
        :class="[ANCHOS[ancho], pieFijo ? 'flex flex-col' : 'p-6 overflow-y-auto overscroll-contain']"
        role="dialog"
        aria-modal="true"
        :aria-label="titulo"
      >
        <div :class="pieFijo ? 'p-6 overflow-y-auto overscroll-contain' : ''">
          <slot name="cabecera">
            <h3 class="text-lg font-bold text-gray-900 mb-1">{{ titulo }}</h3>
            <p v-if="descripcion" class="text-sm text-gray-500 mb-5">{{ descripcion }}</p>
          </slot>
          <div :class="descripcion ? '' : 'mt-4'">
            <slot />
          </div>
        </div>
        <div
          v-if="$slots.pie"
          class="flex flex-col-reverse sm:flex-row gap-3"
          :class="[pieFijo ? 'shrink-0 px-6 py-4 border-t border-gray-100' : 'mt-6', pieConFondo ? 'rounded-b-2xl bg-gray-50' : '']"
        >
          <slot name="pie" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
