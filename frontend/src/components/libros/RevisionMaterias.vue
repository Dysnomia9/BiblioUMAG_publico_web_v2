<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import api from '@/services/api'

/**
 * Debajo de «Materias» al catalogar: avisa cuando una materia ya existe en el catálogo
 * escrita de otra forma, para no crear repetidas («RAPANUI» y «RAPA NUI» serían dos
 * materias distintas y quien busque una no encuentra los libros de la otra).
 * - parecida (otros espacios o signos): al guardar se crearía otra; se ofrece usar la existente.
 * - igual (otras mayúsculas o tildes): al guardar ya se usa la existente; se avisa cómo quedará.
 * - nueva: se creará. Si su tema existe escrito de otra forma («RAPANUI -- LENGUAS» y el
 *   catálogo tiene «RAPA NUI»), se ofrece escribirla con ese tema; si trae minúsculas,
 *   pasarla a mayúsculas como el resto del catálogo.
 */
type Revision = {
  nombre: string
  tipo: 'exacta' | 'igual' | 'parecida' | 'nueva'
  existente: { id: number; nombre: string; libros: number } | null
  // Nueva entera, pero con el tema escrito distinto al del catálogo («RAPANUI -- …»).
  sugerencia: { tema: string; tema_existente: string; nombre: string } | null
}

const materias = defineModel<string[]>({ required: true })
const revisiones = ref<Revision[]>([])

let temporizador: ReturnType<typeof setTimeout> | undefined
let consulta = 0
watch(
  () => materias.value.slice(),
  (lista) => {
    clearTimeout(temporizador)
    if (!lista.length) {
      revisiones.value = []
      return
    }
    temporizador = setTimeout(async () => {
      const id = ++consulta
      try {
        const { data } = await api.post<Revision[]>('/categorias/revisar', { nombres: lista })
        if (id === consulta) revisiones.value = data
      } catch {
        // Es solo un aviso: sin él se cataloga igual.
      }
    }, 300)
  },
  { immediate: true },
)
onBeforeUnmount(() => clearTimeout(temporizador))

const presentes = computed(() => new Set(materias.value))
const avisos = computed(() => revisiones.value.filter((r) => r.tipo !== 'exacta' && presentes.value.has(r.nombre)))
const parecidas = computed(() => avisos.value.filter((r) => r.tipo === 'parecida'))
const iguales = computed(() => avisos.value.filter((r) => r.tipo === 'igual'))
const temasDistintos = computed(() => avisos.value.filter((r) => r.tipo === 'nueva' && r.sugerencia))
const nuevas = computed(() => avisos.value.filter((r) => r.tipo === 'nueva' && !r.sugerencia))
// Solo materias nuevas bien escritas: es informativo, no hay nada que corregir.
const soloInforma = computed(() => avisos.value.every((r) => r.tipo === 'nueva' && !r.sugerencia && !conMinusculas(r.nombre)))
const conMinusculas = (texto: string) => /\p{Ll}/u.test(texto)

function reemplazar(actual: string, por: string) {
  const lista = materias.value.map((m) => (m === actual ? por : m))
  materias.value = [...new Set(lista)]
}

function usarTodasLasExistentes() {
  let lista = materias.value
  for (const r of [...parecidas.value, ...iguales.value]) lista = lista.map((m) => (m === r.nombre ? r.existente!.nombre : m))
  for (const r of temasDistintos.value) lista = lista.map((m) => (m === r.nombre ? r.sugerencia!.nombre : m))
  materias.value = [...new Set(lista)]
}

const libros = (n: number) => (n === 1 ? '1 libro' : `${n.toLocaleString('es-CL')} libros`)
</script>

<template>
  <div
    v-if="avisos.length"
    class="mt-2 overflow-hidden rounded-lg border text-xs"
    :class="soloInforma ? 'border-gray-200 bg-gray-50' : 'border-amber-200 bg-amber-50/60'"
    role="status"
  >
    <div class="flex items-center justify-between gap-2 border-b px-3 py-1.5" :class="soloInforma ? 'border-gray-200' : 'border-amber-200'">
      <p v-if="soloInforma" class="font-semibold text-gray-700">{{ avisos.length === 1 ? 'Una materia nueva' : `${avisos.length} materias nuevas` }}: se {{ avisos.length === 1 ? 'creará' : 'crearán' }} al guardar</p>
      <p v-else class="font-semibold text-amber-900">Revise {{ avisos.length === 1 ? 'esta materia' : `estas ${avisos.length} materias` }} antes de guardar</p>
      <button
        v-if="parecidas.length + iguales.length + temasDistintos.length > 1"
        type="button"
        class="rounded-md border border-amber-300 bg-white px-2 py-0.5 font-semibold text-amber-900 hover:bg-amber-100"
        @click="usarTodasLasExistentes"
      >
        Usar todas las del catálogo
      </button>
    </div>
    <ul class="divide-y" :class="soloInforma ? 'divide-gray-100' : 'divide-amber-100'">
      <li v-for="r in parecidas" :key="r.nombre" class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-1.5">
        <span class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-red-800">Repetida</span>
        <span class="min-w-0 flex-1 text-gray-800">
          <strong class="font-semibold">{{ r.nombre }}</strong> ya existe como <strong class="font-semibold">{{ r.existente!.nombre }}</strong>
          <span class="text-gray-500">· {{ libros(r.existente!.libros) }}</span>. Si la deja así, se crea otra materia aparte.
        </span>
        <button type="button" class="rounded-md bg-amber-600 px-2 py-0.5 font-semibold text-white hover:bg-amber-700" @click="reemplazar(r.nombre, r.existente!.nombre)">
          Usar «{{ r.existente!.nombre }}»
        </button>
      </li>
      <li v-for="r in temasDistintos" :key="r.nombre" class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-1.5">
        <span class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-red-800">Tema distinto</span>
        <span class="min-w-0 flex-1 text-gray-800">
          <strong class="font-semibold">{{ r.nombre }}</strong>: el tema <strong class="font-semibold">{{ r.sugerencia!.tema }}</strong> está en el catálogo como
          <strong class="font-semibold">{{ r.sugerencia!.tema_existente }}</strong>.
        </span>
        <button type="button" class="rounded-md bg-amber-600 px-2 py-0.5 font-semibold text-white hover:bg-amber-700" @click="reemplazar(r.nombre, r.sugerencia!.nombre)">
          Usar «{{ r.sugerencia!.nombre }}»
        </button>
      </li>
      <li v-for="r in iguales" :key="r.nombre" class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-1.5">
        <span class="rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-sky-800">Se unifica</span>
        <span class="min-w-0 flex-1 text-gray-800">
          <strong class="font-semibold">{{ r.nombre }}</strong> se guardará como <strong class="font-semibold">{{ r.existente!.nombre }}</strong>
          <span class="text-gray-500">· {{ libros(r.existente!.libros) }}</span>
        </span>
        <button type="button" class="rounded-md border border-sky-300 bg-white px-2 py-0.5 font-semibold text-sky-800 hover:bg-sky-50" @click="reemplazar(r.nombre, r.existente!.nombre)">
          Escribirla así
        </button>
      </li>
      <li v-for="r in nuevas" :key="r.nombre" class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-1.5">
        <span class="rounded bg-gray-200 px-1.5 py-0.5 text-[10px] font-bold uppercase text-gray-700">Nueva</span>
        <span class="min-w-0 flex-1 text-gray-800">
          <strong class="font-semibold">{{ r.nombre }}</strong> no existe en el catálogo: se creará al guardar.
          <template v-if="conMinusculas(r.nombre)"> El catálogo las escribe en mayúsculas.</template>
        </span>
        <button
          v-if="conMinusculas(r.nombre)"
          type="button"
          class="rounded-md border border-gray-300 bg-white px-2 py-0.5 font-semibold text-gray-800 hover:bg-gray-100"
          @click="reemplazar(r.nombre, r.nombre.toLocaleUpperCase('es-CL'))"
        >
          Pasar a mayúsculas
        </button>
      </li>
    </ul>
  </div>
</template>
