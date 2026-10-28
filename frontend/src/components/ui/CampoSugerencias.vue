<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'

/**
 * Campo de texto con sugerencias propias, en vez del <datalist> del navegador (que se ve
 * distinto en cada uno y no sigue el modo oscuro). Pensado para el lector de códigos: el
 * Enter manda lo escrito («enviar») salvo que se haya marcado una sugerencia con las flechas.
 * La lista se abre al escribir, al hacer clic o con la flecha, no al recibir el foco. Flota
 * sobre la página (no la corta un panel con overflow-hidden) y se abre hacia arriba si abajo
 * no cabe.
 */
type Sugerencia = { valor: string; nombre: string }

const props = withDefaults(
  defineProps<{
    modelValue: string
    sugerencias: Sugerencia[]
    /** Encabezado de la lista: «Notebooks libres». */
    titulo?: string
    /** Lo que se dice cuando no hay ninguna sugerencia: «No quedan notebooks libres». */
    sinSugerencias?: string
    placeholder?: string
    disabled?: boolean
    /** Clases del campo, para que se vea como los demás de la pantalla. */
    claseCampo?: string
    etiqueta?: string
    idCampo?: string
  }>(),
  { titulo: '', sinSugerencias: 'Sin sugerencias', placeholder: '', disabled: false, claseCampo: '', etiqueta: undefined, idCampo: undefined },
)

const emit = defineEmits<{ 'update:modelValue': [string]; enviar: [] }>()

const idLista = useId()
const abierto = ref(false)
// Sugerencia marcada con las flechas; -1 es ninguna (el Enter manda lo escrito).
const marcada = ref(-1)

const texto = computed({
  get: () => props.modelValue ?? '',
  set: (valor: string) => emit('update:modelValue', valor),
})

// Con una sugerencia ya elegida en el campo se muestran todas, para poder cambiarla.
const visibles = computed(() => {
  const busqueda = texto.value.trim().toLowerCase()
  if (!busqueda || props.sugerencias.some((s) => s.valor === texto.value.trim())) return props.sugerencias
  return props.sugerencias.filter((s) => s.valor.toLowerCase().includes(busqueda) || s.nombre.toLowerCase().includes(busqueda))
})

const campo = ref<HTMLInputElement | null>(null)
const posicion = ref<Record<string, string>>({})
const ALTO_LISTA = 290

function ubicar() {
  const r = campo.value?.getBoundingClientRect()
  if (!r) return
  const ancho = Math.max(r.width, 240)
  const izquierda = `${Math.max(8, Math.min(r.left, window.innerWidth - ancho - 8))}px`
  const abajo = window.innerHeight - r.bottom
  posicion.value =
    abajo < ALTO_LISTA && r.top > abajo
      ? { left: izquierda, width: `${ancho}px`, bottom: `${window.innerHeight - r.top + 6}px` }
      : { left: izquierda, width: `${ancho}px`, top: `${r.bottom + 6}px` }
}

function dejarDeSeguir() {
  window.removeEventListener('scroll', ubicar, true)
  window.removeEventListener('resize', ubicar)
}

watch(abierto, (valor) => {
  if (!valor) return dejarDeSeguir()
  ubicar()
  window.addEventListener('scroll', ubicar, true)
  window.addEventListener('resize', ubicar)
})
onBeforeUnmount(dejarDeSeguir)

function abrir() {
  if (props.disabled) return
  abierto.value = true
  marcada.value = -1
}

function alEscribir() {
  abierto.value = true
  marcada.value = -1
}

function elegir(sugerencia: Sugerencia) {
  texto.value = sugerencia.valor
  abierto.value = false
}

async function mover(paso: number) {
  if (!abierto.value) return abrir()
  marcada.value = Math.min(Math.max(marcada.value + paso, -1), visibles.value.length - 1)
  await nextTick()
  document.getElementById(`${idLista}-${marcada.value}`)?.scrollIntoView({ block: 'nearest' })
}

function alEnter() {
  const sugerencia = abierto.value && marcada.value >= 0 ? visibles.value[marcada.value] : undefined
  abierto.value = false
  if (sugerencia) elegir(sugerencia)
  else emit('enviar')
}
</script>

<template>
  <div class="relative">
    <input
      :id="idCampo"
      ref="campo"
      v-model="texto"
      type="text"
      role="combobox"
      autocomplete="off"
      aria-autocomplete="list"
      :aria-label="etiqueta"
      :aria-expanded="abierto"
      :aria-controls="idLista"
      :aria-activedescendant="abierto && marcada >= 0 ? `${idLista}-${marcada}` : undefined"
      :placeholder="placeholder"
      :disabled="disabled"
      :class="[claseCampo, 'pr-8']"
      @input="alEscribir"
      @click="abrir"
      @blur="abierto = false"
      @keydown.down.prevent="mover(1)"
      @keydown.up.prevent="mover(-1)"
      @keydown.enter.prevent="alEnter"
      @keydown.esc="abierto = false"
    />
    <button
      v-if="!disabled"
      type="button"
      tabindex="-1"
      class="absolute inset-y-0 right-0 flex items-center px-2.5 text-gray-400 transition-colors hover:text-gray-700"
      :aria-label="abierto ? 'Cerrar sugerencias' : 'Ver sugerencias'"
      @mousedown.prevent="abierto ? (abierto = false) : abrir()"
    >
      <svg class="h-4 w-4 transition-transform" :class="abierto ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
      </svg>
    </button>

    <Teleport to="body">
      <div v-if="abierto" class="fixed z-50 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl" :style="posicion">
        <p v-if="titulo && sugerencias.length" class="border-b border-gray-100 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
          {{ titulo }} · {{ sugerencias.length }}
        </p>
        <ul v-if="visibles.length" :id="idLista" role="listbox" class="max-h-60 overflow-y-auto p-1">
          <li
            v-for="(s, i) in visibles"
            :id="`${idLista}-${i}`"
            :key="s.valor"
            role="option"
            :aria-selected="s.valor === texto.trim()"
            class="flex cursor-pointer items-center justify-between gap-3 rounded-lg px-2.5 py-2"
            :class="marcada === i ? 'bg-indigo-50' : s.valor === texto.trim() ? 'bg-gray-50' : 'hover:bg-gray-50'"
            @mousedown.prevent="elegir(s)"
          >
            <span class="min-w-0 truncate text-sm font-medium text-gray-900">{{ s.nombre }}</span>
            <span v-if="s.nombre !== s.valor" class="shrink-0 font-mono text-xs text-gray-500">{{ s.valor }}</span>
          </li>
        </ul>
        <p v-else class="px-3 py-3 text-sm text-gray-400">
          {{ sugerencias.length ? 'Sin coincidencias' : sinSugerencias }}
        </p>
      </div>
    </Teleport>
  </div>
</template>
