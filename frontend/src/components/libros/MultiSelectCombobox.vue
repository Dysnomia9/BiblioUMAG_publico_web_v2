<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { contieneCadaPalabra, sinTildes } from '@/utils/texto'

/**
 * Selector múltiple con autocompletado: escribe para filtrar el catálogo existente,
 * o presiona Enter con un texto que no matchea nada para agregarlo como valor nuevo
 * (el backend hace firstOrCreate por nombre — ver LibroController). Lo elegido queda
 * como fichas dentro del mismo recuadro; Retroceso con el campo vacío quita la última.
 *
 * El catálogo llega en `opciones` si es corto (carreras), o se pide al servidor con
 * `buscar` a medida que se escribe si son miles (autores y materias del catálogo real).
 */
// Solo se usa el nombre: las carreras que sugieren los usuarios todavía no tienen id.
type Opcion = { id: number | null; nombre: string; /** Cuántos libros la usan (autores y materias). */ libros?: number }

const props = withDefaults(
  defineProps<{
    modelValue: string[]
    opciones?: Opcion[]
    buscar?: (texto: string) => Promise<Opcion[]>
    placeholder?: string
    /** Clases de las fichas (fondo, texto y anillo), según la sección. */
    claseFicha?: string
  }>(),
  { opciones: () => [], buscar: undefined, placeholder: '', claseFicha: 'bg-indigo-50 text-indigo-800 ring-indigo-200' },
)

const emit = defineEmits<{ 'update:modelValue': [string[]] }>()

const texto = ref('')
const abierto = ref(false)
const campo = ref<HTMLInputElement | null>(null)

const remotas = ref<Opcion[]>([])
let temporizador: ReturnType<typeof setTimeout> | undefined
let ultimaConsulta = 0

const cargandoRemotas = ref(false)

async function cargarRemotas() {
  if (!props.buscar) return
  const consulta = ++ultimaConsulta
  cargandoRemotas.value = true
  try {
    const resultado = await props.buscar(texto.value.trim())
    // Una respuesta lenta de una búsqueda anterior no pisa la de lo último que se escribió.
    if (consulta === ultimaConsulta) remotas.value = resultado
  } catch {
    if (consulta === ultimaConsulta) remotas.value = []
  } finally {
    if (consulta === ultimaConsulta) cargandoRemotas.value = false
  }
}

// Recién desde 2 letras: con el campo vacío no se muestra una tira de nombres.
const MINIMO = 2
const escribioBastante = computed(() => texto.value.trim().length >= MINIMO)

watch(texto, () => {
  if (!props.buscar || !escribioBastante.value) return
  clearTimeout(temporizador)
  temporizador = setTimeout(cargarRemotas, 250)
})
onUnmounted(() => clearTimeout(temporizador))

// Cada palabra escrita en cualquier parte del nombre, igual que la búsqueda del servidor.
// No se sugiere lo que ya está agregado, aunque cambien tildes o mayúsculas.
const yaAgregados = computed(() => new Set(props.modelValue.map((n) => sinTildes(n).toLowerCase())))
const sugerencias = computed<Opcion[]>(() =>
  !escribioBastante.value ? [] : (props.buscar ? remotas.value : props.opciones)
    .filter((o) => !yaAgregados.value.has(sinTildes(o.nombre).toLowerCase()))
    .filter((o) => contieneCadaPalabra(o.nombre, texto.value))
    .slice(0, 8),
)

/** «Pérez, Juan» → «PJ»: la inicial de las dos primeras palabras. */
function iniciales(nombre: string) {
  return nombre.split(/[\s,.;:()\-]+/).filter((w) => /\p{L}/u.test(w)).slice(0, 2).map((w) => w.charAt(0)).join('').toUpperCase()
}

/** El nombre partido en trozos, con lo escrito marcado (sin importar tildes ni mayúsculas). */
function resaltado(nombre: string): { t: string; m: boolean }[] {
  const base = sinTildes(nombre)
  const marcas = new Array(nombre.length).fill(false)
  for (const palabra of sinTildes(texto.value).split(/\s+/).filter(Boolean)) {
    const i = base.indexOf(palabra)
    if (i >= 0) marcas.fill(true, i, i + palabra.length)
  }
  const partes: { t: string; m: boolean }[] = []
  for (let i = 0; i < nombre.length; i++) {
    const ultima = partes[partes.length - 1]
    if (ultima && ultima.m === marcas[i]) ultima.t += nombre[i]
    else partes.push({ t: nombre[i], m: marcas[i] })
  }
  return partes
}

// Con ↑ ↓ se elige una sugerencia (la última posición es «Crear»); Enter la agrega.
const activo = ref(-1)
watch(sugerencias, () => (activo.value = -1))

function mover(paso: number) {
  const total = sugerencias.value.length + (texto.value.trim() && !coincideExacto.value ? 1 : 0)
  if (!total) return
  abierto.value = true
  activo.value = (activo.value + paso + total) % total
}

function alEnfocar() {
  abierto.value = true
  if (props.buscar && escribioBastante.value) cargarRemotas()
}

// «informatica» ya es «Informática»: no se ofrece crear un duplicado que solo cambia en las tildes.
const existente = computed(() => sugerencias.value.find((s) => sinTildes(s.nombre) === sinTildes(texto.value.trim()))?.nombre)
const coincideExacto = computed(() => !!existente.value)

function agregar(nombre: string) {
  const limpio = nombre.replace(/\s+/g, ' ').trim()
  if (!limpio || yaAgregados.value.has(sinTildes(limpio).toLowerCase())) {
    texto.value = ''
    return
  }
  emit('update:modelValue', [...props.modelValue, limpio])
  texto.value = ''
  abierto.value = false
}

function quitar(nombre: string) {
  emit('update:modelValue', props.modelValue.filter((n) => n !== nombre))
}

function onEnter() {
  if (activo.value >= 0 && activo.value < sugerencias.value.length) return agregar(sugerencias.value[activo.value].nombre)
  if (!texto.value.trim()) return
  agregar(existente.value ?? texto.value)
}

function onRetroceso() {
  if (!texto.value && props.modelValue.length) quitar(props.modelValue[props.modelValue.length - 1])
}

// Al pasar a otro campo, lo escrito se agrega como si se hubiera presionado Enter (si
// coincide con uno existente, se usa ese; si no, se crea al guardar la ficha).
function onBlur() {
  window.setTimeout(() => {
    abierto.value = false
    if (texto.value.trim()) onEnter()
  }, 150)
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
        autocomplete="off"
        autocorrect="off"
        spellcheck="false"
        :placeholder="placeholder"
        class="min-w-[9rem] flex-1 border-0 bg-transparent px-1 py-1 text-sm text-gray-900 outline-none placeholder:text-gray-400"
        @focus="alEnfocar"
        @input="abierto = true"
        @blur="onBlur"
        @keydown.enter.prevent="onEnter"
        @keydown.down.prevent="mover(1)"
        @keydown.up.prevent="mover(-1)"
        @keydown.esc="abierto = false"
        @keydown.backspace="onRetroceso"
      />
    </div>

    <div
      v-if="abierto && (sugerencias.length || escribioBastante)"
      class="absolute z-10 mt-1 w-full overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl ring-1 ring-black/5"
    >
      <template v-if="sugerencias.length">
        <p class="border-b border-gray-100 bg-gray-50 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-500">Ya en el catálogo</p>
        <ul class="max-h-60 overflow-y-auto py-1" role="listbox">
          <li v-for="(s, i) in sugerencias" :key="s.nombre" role="option" :aria-selected="activo === i">
            <button
              type="button"
              class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm transition-colors"
              :class="activo === i ? 'bg-indigo-50' : 'hover:bg-gray-50'"
              @mousedown.prevent="agregar(s.nombre)"
              @mouseenter="activo = i"
            >
              <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-[10px] font-bold text-indigo-700" aria-hidden="true">
                {{ iniciales(s.nombre) }}
              </span>
              <span class="min-w-0 flex-1 truncate text-gray-700">
                <template v-for="(p, k) in resaltado(s.nombre)" :key="k"><strong v-if="p.m" class="font-semibold text-gray-900">{{ p.t }}</strong><template v-else>{{ p.t }}</template></template>
              </span>
              <span v-if="s.libros !== undefined" class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium tabular-nums text-gray-500">
                {{ s.libros.toLocaleString('es-CL') }} {{ s.libros === 1 ? 'libro' : 'libros' }}
              </span>
            </button>
          </li>
        </ul>
      </template>
      <p v-else-if="cargandoRemotas" class="px-3 py-2.5 text-xs text-gray-400">Buscando…</p>
      <button
        v-if="texto.trim() && !coincideExacto"
        type="button"
        class="flex w-full items-center gap-2.5 border-t border-dashed border-gray-200 px-3 py-2.5 text-left text-sm transition-colors"
        :class="activo === sugerencias.length ? 'bg-emerald-50' : 'hover:bg-emerald-50/60'"
        @mousedown.prevent="agregar(texto)"
        @mouseenter="activo = sugerencias.length"
      >
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700" aria-hidden="true">+</span>
        <span class="min-w-0 flex-1 truncate">
          <span class="text-emerald-800">Crear nuevo:</span> <strong class="font-semibold text-gray-900">{{ texto.trim() }}</strong>
        </span>
        <span class="shrink-0 text-[11px] text-gray-400">Enter o Tab</span>
      </button>
    </div>
  </div>
</template>
