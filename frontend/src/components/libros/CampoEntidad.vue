<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import api from '@/services/api'
import { sinTildes } from '@/utils/texto'

/**
 * Campo de la entidad (110) o la entidad relacionada (710) con sugerencias de lo que ya
 * está en el catálogo: las entidades escritas en otras fichas y los autores registrados
 * (GET /entidades), igual que el combobox de autores del 100. Sigue siendo texto libre:
 * lo escrito se guarda tal cual, la sugerencia solo evita escribir distinto una que ya existe.
 *
 * Con `varias` (el 710) el campo puede llevar varias separadas por «;»: se sugiere para la
 * que se está escribiendo (lo que va después del último «;») y al elegir se reemplaza solo esa.
 */
type Opcion = { nombre: string; libros: number }

const props = withDefaults(
  defineProps<{
    modelValue: string
    idCampo?: string
    placeholder?: string
    claseCampo?: string
    varias?: boolean
  }>(),
  { idCampo: undefined, placeholder: '', claseCampo: '', varias: false },
)
const emit = defineEmits<{ 'update:modelValue': [string] }>()

const texto = computed({
  get: () => props.modelValue ?? '',
  set: (valor: string) => emit('update:modelValue', valor),
})

/** Lo que va antes de la entidad que se escribe («A; B; ») y la entidad misma. */
const partes = computed(() => {
  if (!props.varias) return { antes: '', actual: texto.value }
  const corte = texto.value.lastIndexOf(';')
  return corte < 0 ? { antes: '', actual: texto.value } : { antes: texto.value.slice(0, corte + 1), actual: texto.value.slice(corte + 1) }
})
const buscado = computed(() => partes.value.actual.trim())

const abierto = ref(false)
const opciones = ref<Opcion[]>([])
const cargando = ref(false)
const activo = ref(-1)
let temporizador: ReturnType<typeof setTimeout> | undefined
let ultimaConsulta = 0

// Desde 2 letras, como los autores.
const MINIMO = 2

async function cargar() {
  const consulta = ++ultimaConsulta
  cargando.value = true
  try {
    const { data } = await api.get<Opcion[]>('/entidades', { params: { q: buscado.value } })
    if (consulta === ultimaConsulta) opciones.value = data
  } catch {
    if (consulta === ultimaConsulta) opciones.value = []
  } finally {
    if (consulta === ultimaConsulta) cargando.value = false
  }
}

watch(buscado, (valor) => {
  clearTimeout(temporizador)
  if (valor.length < MINIMO) {
    opciones.value = []
    return
  }
  temporizador = setTimeout(cargar, 250)
})
onUnmounted(() => clearTimeout(temporizador))

const clave = (n: string) => sinTildes(n.trim()).toLowerCase()

// No se sugiere la que ya quedó escrita (ni, en el 710, las que ya van antes del «;»).
const yaEscritas = computed(() => new Set((props.varias ? texto.value.split(';') : [texto.value]).map(clave).filter(Boolean)))
const sugerencias = computed(() =>
  buscado.value.length < MINIMO ? [] : opciones.value.filter((o) => !yaEscritas.value.has(clave(o.nombre))).slice(0, 8),
)
watch(sugerencias, () => (activo.value = -1))

function elegir(nombre: string) {
  texto.value = props.varias && partes.value.antes ? `${partes.value.antes.trimEnd()} ${nombre}` : nombre
  abierto.value = false
}

function mover(paso: number) {
  const total = sugerencias.value.length
  if (!total) return
  abierto.value = true
  activo.value = (activo.value + paso + total) % total
}

function alEnter(evento: KeyboardEvent) {
  if (!abierto.value || activo.value < 0 || !sugerencias.value[activo.value]) return
  evento.preventDefault()
  elegir(sugerencias.value[activo.value].nombre)
}

/** El nombre partido en trozos, con lo escrito marcado (sin importar tildes ni mayúsculas). */
function resaltado(nombre: string): { t: string; m: boolean }[] {
  const base = sinTildes(nombre)
  const marcas = new Array(nombre.length).fill(false)
  for (const palabra of sinTildes(buscado.value).split(/\s+/).filter(Boolean)) {
    const i = base.indexOf(palabra)
    if (i >= 0) marcas.fill(true, i, i + palabra.length)
  }
  const trozos: { t: string; m: boolean }[] = []
  for (let i = 0; i < nombre.length; i++) {
    const ultimo = trozos[trozos.length - 1]
    if (ultimo && ultimo.m === marcas[i]) ultimo.t += nombre[i]
    else trozos.push({ t: nombre[i], m: marcas[i] })
  }
  return trozos
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
      autocorrect="off"
      spellcheck="false"
      aria-autocomplete="list"
      :aria-expanded="abierto && sugerencias.length > 0"
      :placeholder="placeholder"
      :class="claseCampo"
      @focus="abierto = true"
      @input="abierto = true"
      @blur="abierto = false"
      @keydown.down.prevent="mover(1)"
      @keydown.up.prevent="mover(-1)"
      @keydown.enter="alEnter"
      @keydown.esc="abierto = false"
    />
    <div
      v-if="abierto && buscado.length >= MINIMO && (sugerencias.length || cargando)"
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
              @mousedown.prevent="elegir(s.nombre)"
              @mouseenter="activo = i"
            >
              <span class="min-w-0 flex-1 truncate text-gray-700" :title="s.nombre">
                <template v-for="(p, k) in resaltado(s.nombre)" :key="k"><strong v-if="p.m" class="font-semibold text-gray-900">{{ p.t }}</strong><template v-else>{{ p.t }}</template></template>
              </span>
              <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium tabular-nums text-gray-500">
                {{ s.libros.toLocaleString('es-CL') }} {{ s.libros === 1 ? 'libro' : 'libros' }}
              </span>
            </button>
          </li>
        </ul>
      </template>
      <p v-else class="px-3 py-2.5 text-xs text-gray-400">Buscando…</p>
    </div>
  </div>
</template>
