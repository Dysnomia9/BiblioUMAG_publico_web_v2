<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { separarContenido, unirContenido } from '@/utils/contenido'
import NotaContenido from './NotaContenido.vue'

const props = defineProps<{ modelValue: string; id: string }>()
const emit = defineEmits<{ 'update:modelValue': [valor: string] }>()

const modo = ref<'bloques' | 'texto'>('bloques')
let siguienteId = 0
const nuevaFila = (valor = '') => ({ id: siguienteId++, valor })
const filas = ref([nuevaFila()])
let ultimaEmision: string | undefined

function cargar(valor: string) {
  const bloques = separarContenido(valor)
  filas.value = bloques.length ? bloques.map((b) => nuevaFila(b.valor)) : [nuevaFila()]
}
watch(() => props.modelValue, (valor) => {
  // No reconstruir las cajas al escribir: conserva el cursor y los espacios.
  if (valor !== ultimaEmision) cargar(valor)
}, { immediate: true })

function guardar() {
  ultimaEmision = unirContenido(filas.value.map((f) => f.valor))
  emit('update:modelValue', ultimaEmision)
}

function editarTexto(evento: Event) {
  const valor = (evento.target as HTMLTextAreaElement).value
  cargar(valor)
  ultimaEmision = valor
  emit('update:modelValue', valor)
}

function cambiarModo(nuevo: 'bloques' | 'texto') {
  if (nuevo === 'bloques') cargar(props.modelValue)
  modo.value = nuevo
}

function enfocar(idFila: number) {
  nextTick(() => document.getElementById(`${props.id}-${idFila}`)?.focus())
}

function agregar() {
  const fila = nuevaFila()
  filas.value.push(fila)
  enfocar(fila.id)
}

function quitar(indice: number) {
  filas.value.splice(indice, 1)
  if (!filas.value.length) filas.value.push(nuevaFila())
  guardar()
  enfocar(filas.value[Math.min(indice, filas.value.length - 1)].id)
}

/** También acepta pegar una nota completa directamente en cualquiera de las cajas. */
function separarFila(indice: number) {
  const bloques = separarContenido(filas.value[indice].valor)
  if (bloques.length > 1) filas.value.splice(indice, 1, ...bloques.map((b) => nuevaFila(b.valor)))
  guardar()
}

function pegar(evento: ClipboardEvent, indice: number) {
  const pegado = evento.clipboardData?.getData('text/plain') ?? ''
  if (separarContenido(pegado).length < 2) return
  evento.preventDefault()
  const caja = evento.target as HTMLTextAreaElement
  const valor = filas.value[indice].valor
  filas.value[indice].valor = valor.slice(0, caja.selectionStart) + pegado + valor.slice(caja.selectionEnd)
  separarFila(indice)
  enfocar(filas.value[indice].id)
}

const etiquetas = computed(() => filas.value.map((f, i) => separarContenido(f.valor)[0]?.etiqueta ?? `Bloque ${i + 1}`))
const CAMPO = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none transition-shadow placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30'
</script>

<template>
  <div :id="id" class="space-y-3" role="group" :aria-labelledby="`${id}-etiqueta`">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p :id="`${id}-ayuda`" class="text-xs text-gray-500">Un bloque por tomo o sección. Al pegar una nota con <strong class="font-mono text-gray-700">--</strong>, se separa automáticamente.</p>
      <div class="inline-flex shrink-0 rounded-lg border border-gray-200 bg-gray-50 p-0.5" role="group" aria-label="Forma de editar el contenido">
        <button v-for="opcion in (['bloques', 'texto'] as const)" :key="opcion" type="button" :aria-pressed="modo === opcion" class="rounded-md px-2.5 py-1.5 text-xs font-semibold transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500" :class="modo === opcion ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-500 hover:text-gray-800'" @click="cambiarModo(opcion)">
          {{ opcion === 'bloques' ? 'Por tomos o secciones' : 'Texto completo' }}
        </button>
      </div>
    </div>

    <template v-if="modo === 'bloques'">
      <div class="space-y-2">
        <div v-for="(fila, i) in filas" :key="fila.id" class="rounded-xl border border-gray-200 bg-gray-50/80 p-3">
          <div class="mb-2 flex items-center justify-between gap-3">
            <label :for="`${id}-${fila.id}`" class="rounded-md bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800">{{ etiquetas[i] }}</label>
            <button v-if="filas.length > 1 || fila.valor" type="button" class="rounded px-1 text-xs font-medium text-gray-500 hover:text-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500" :aria-label="`Quitar ${etiquetas[i]}`" @click="quitar(i)">Quitar</button>
          </div>
          <textarea :id="`${id}-${fila.id}`" v-model="fila.valor" rows="2" placeholder="Ej.: t.1: Título del tomo / autor. También puedes escribir un capítulo o contenido sin tomo." :class="CAMPO" :aria-describedby="`${id}-ayuda`" @input="guardar" @change="separarFila(i)" @paste="pegar($event, i)"></textarea>
        </div>
      </div>
      <button type="button" class="rounded-lg border border-dashed border-indigo-300 px-3 py-2 text-xs font-semibold text-indigo-700 transition-colors hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500" @click="agregar">+ Agregar tomo o sección</button>
    </template>

    <template v-else>
      <label :for="`${id}-texto`" class="sr-only">Nota de contenido completa</label>
      <textarea :id="`${id}-texto`" :value="modelValue" rows="4" placeholder="t.1: Título del primer tomo -- t.2: Título del segundo tomo" :class="CAMPO" :aria-describedby="`${id}-ayuda`" @input="editarTexto"></textarea>
      <div v-if="modelValue.trim()" class="rounded-xl border border-indigo-100 bg-white p-3">
        <p class="mb-2 text-xs font-semibold text-gray-500">Así se verá en la ficha</p>
        <NotaContenido :valor="modelValue" />
      </div>
    </template>
    <p class="text-[11px] text-gray-500">Puedes identificar cada bloque con t.1:, v.1: o Cap. 1. · Se muestra en el portal.</p>
  </div>
</template>
