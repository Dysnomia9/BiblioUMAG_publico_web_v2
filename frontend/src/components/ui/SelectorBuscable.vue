<script setup lang="ts">
import { onUnmounted, ref, useId, watch } from 'vue'

/**
 * Selector de una opción que busca en el servidor, para listas que con el catálogo real
 * son miles (materias, áreas): al escribir llegan hasta 20 coincidencias y, sin escribir,
 * las más usadas. Reemplaza al <select> que traía la lista entera. La elegida queda
 * escrita en el campo; la ✕ o la primera opción («Todas…») quitan el filtro.
 */
type Opcion = { id: number; nombre: string }

const props = withDefaults(
  defineProps<{
    modelValue: number | ''
    buscar: (texto: string) => Promise<Opcion[]>
    /** Primera opción de la lista, la que quita el filtro: «Todas las áreas», «Cualquiera». */
    todas: string
    placeholder?: string
    /** Clases del campo, para que se vea como los demás filtros de la pantalla. */
    claseCampo?: string
    etiqueta?: string
    idCampo?: string
    /** Nombre de la opción cuando el valor llega ya elegido (filtros traídos de otra pantalla). */
    nombreElegido?: string | null
  }>(),
  { placeholder: '', claseCampo: '', etiqueta: undefined, idCampo: undefined, nombreElegido: null },
)

const emit = defineEmits<{ 'update:modelValue': [number | '']; elegir: [Opcion | null] }>()

const idLista = useId()
const texto = ref('')
const elegida = ref<Opcion | null>(null)
const opciones = ref<Opcion[]>([])
const abierto = ref(false)
// Opción marcada con las flechas: -1 es «Todas…», 0 en adelante, las de la lista.
const marcada = ref(-1)
let temporizador: ReturnType<typeof setTimeout> | undefined
let ultimaConsulta = 0

// El valor puede cambiar desde la pantalla: si limpia el filtro, el campo se vacía; si
// llega uno ya elegido, se muestra con su nombre.
watch(
  () => [props.modelValue, props.nombreElegido] as const,
  ([valor, nombre]) => {
    if (valor === '') {
      if (elegida.value) {
        elegida.value = null
        texto.value = ''
      }
    } else if (nombre && elegida.value?.id !== valor) {
      elegida.value = { id: valor, nombre }
      texto.value = nombre
    }
  },
  { immediate: true },
)

onUnmounted(() => clearTimeout(temporizador))

async function cargar() {
  const consulta = ++ultimaConsulta
  // Con la elegida escrita en el campo, se ofrecen las más usadas en vez de buscarla a ella.
  const busqueda = elegida.value && texto.value === elegida.value.nombre ? '' : texto.value.trim()
  try {
    const resultado = await props.buscar(busqueda)
    // Una respuesta lenta de una búsqueda anterior no pisa la de lo último que se escribió.
    if (consulta === ultimaConsulta) opciones.value = resultado
  } catch {
    if (consulta === ultimaConsulta) opciones.value = []
  }
}

function alEnfocar(evento: FocusEvent) {
  ;(evento.target as HTMLInputElement).select()
  abierto.value = true
  marcada.value = -1
  cargar()
}

function alEscribir() {
  abierto.value = true
  marcada.value = 0
  clearTimeout(temporizador)
  temporizador = setTimeout(cargar, 250)
}

function elegir(opcion: Opcion | null) {
  elegida.value = opcion
  texto.value = opcion?.nombre ?? ''
  abierto.value = false
  emit('update:modelValue', opcion?.id ?? '')
  emit('elegir', opcion)
}

function cerrar() {
  abierto.value = false
  texto.value = elegida.value?.nombre ?? ''
}

function alSalir() {
  // Deja terminar el clic en una opción antes de cerrar la lista.
  window.setTimeout(cerrar, 150)
}

function mover(paso: number) {
  if (!abierto.value) {
    abierto.value = true
    cargar()
    return
  }
  marcada.value = Math.min(Math.max(marcada.value + paso, -1), opciones.value.length - 1)
}

function confirmar() {
  if (!abierto.value) return
  elegir(marcada.value >= 0 ? (opciones.value[marcada.value] ?? null) : null)
}
</script>

<template>
  <div class="relative">
    <input
      :id="idCampo"
      v-model="texto"
      type="text"
      role="combobox"
      autocomplete="off"
      aria-autocomplete="list"
      :aria-label="etiqueta"
      :aria-expanded="abierto"
      :aria-controls="idLista"
      :aria-activedescendant="abierto ? `${idLista}-${marcada}` : undefined"
      :placeholder="placeholder"
      :class="[claseCampo, elegida ? 'pr-8' : '']"
      @focus="alEnfocar"
      @input="alEscribir"
      @blur="alSalir"
      @keydown.down.prevent="mover(1)"
      @keydown.up.prevent="mover(-1)"
      @keydown.enter.prevent="confirmar"
      @keydown.esc="cerrar"
    />
    <button
      v-if="elegida"
      type="button"
      class="absolute inset-y-0 right-0 flex items-center px-2 text-gray-400 transition-colors hover:text-gray-700"
      :aria-label="`Quitar ${elegida.nombre}`"
      @mousedown.prevent="elegir(null)"
    >
      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
      </svg>
    </button>

    <ul
      v-if="abierto"
      :id="idLista"
      role="listbox"
      class="absolute left-0 z-20 mt-1 max-h-64 w-full min-w-[14rem] overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
    >
      <li
        :id="`${idLista}--1`"
        role="option"
        :aria-selected="!elegida"
        class="cursor-pointer px-3 py-2 text-sm text-gray-500"
        :class="marcada === -1 ? 'bg-indigo-50' : 'hover:bg-indigo-50'"
        @mousedown.prevent="elegir(null)"
      >
        {{ todas }}
      </li>
      <li
        v-for="(opcion, i) in opciones"
        :id="`${idLista}-${i}`"
        :key="opcion.id"
        role="option"
        :aria-selected="elegida?.id === opcion.id"
        class="cursor-pointer px-3 py-2 text-sm text-gray-700"
        :class="[marcada === i ? 'bg-indigo-50' : 'hover:bg-indigo-50', elegida?.id === opcion.id ? 'font-semibold text-indigo-800' : '']"
        @mousedown.prevent="elegir(opcion)"
      >
        {{ opcion.nombre }}
      </li>
      <li v-if="!opciones.length && texto.trim() && texto !== elegida?.nombre" class="px-3 py-2 text-sm text-gray-400">Sin coincidencias</li>
    </ul>
  </div>
</template>
