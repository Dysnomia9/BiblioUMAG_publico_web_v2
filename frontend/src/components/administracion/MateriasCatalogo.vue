<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import Paginador from '@/components/ui/Paginador.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'

/**
 * Materias del catálogo (650): renombrarlas, unir dos que son la misma y eliminar las que
 * ningún libro usa. Los libros están enlazados a la materia, no a su nombre: al renombrar
 * «TESIS ELECTRONICA» todos sus libros la siguen teniendo con el nombre nuevo.
 */
type Materia = { id: number; nombre: string; libros_count: number }

const toast = useToast()
const materias = ref<Materia[]>([])
const busqueda = ref('')
const orden = ref<'nombre' | 'libros'>('libros')
const soloSinLibros = ref(false)
const pagina = ref(1)
const paginas = ref(1)
const total = ref(0)
const desde = ref<number | null>(null)
const hasta = ref<number | null>(null)
const cargando = ref(false)
let temporizador: ReturnType<typeof setTimeout> | undefined

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get('/materias', {
      params: { q: busqueda.value || undefined, orden: orden.value, sin_libros: soloSinLibros.value ? 1 : undefined, page: pagina.value, por_pagina: 50 },
    })
    materias.value = data.data
    paginas.value = data.last_page
    total.value = data.total
    desde.value = data.from
    hasta.value = data.to
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudieron cargar las materias'))
  } finally {
    cargando.value = false
  }
}

function alBuscar() {
  clearTimeout(temporizador)
  temporizador = setTimeout(() => {
    pagina.value = 1
    cargar()
  }, 250)
}

function irAPagina(p: number) {
  pagina.value = p
  cargar()
}

onMounted(cargar)

// ---------- Renombrar (y unir si el nombre ya existe) ----------
const edicion = reactive({ materia: null as Materia | null, nombre: '', guardando: false, existente: null as Materia | null })

function abrirEdicion(m: Materia) {
  Object.assign(edicion, { materia: m, nombre: m.nombre, guardando: false, existente: null })
}

async function guardar(unir = false) {
  if (!edicion.materia) return
  const nombre = edicion.nombre.replace(/\s+/g, ' ').trim()
  if (!nombre || (nombre === edicion.materia.nombre && !unir)) {
    edicion.materia = null
    return
  }
  edicion.guardando = true
  try {
    const { data } = await api.patch(`/materias/${edicion.materia.id}`, { nombre, unir })
    toast.success(data.unida_con
      ? `«${edicion.materia.nombre}» se unió con «${data.unida_con.nombre}» (ahora ${data.unida_con.libros_count} libros)`
      : `Materia renombrada a «${data.nombre}»: sus ${data.libros_count} libros la siguen teniendo`)
    edicion.materia = null
    edicion.existente = null
    await cargar()
  } catch (e: any) {
    if (e?.response?.status === 409 && e.response.data?.existente) {
      edicion.existente = e.response.data.existente
    } else {
      toast.error(mensajeDeError(e, 'No se pudo guardar la materia'))
    }
  } finally {
    edicion.guardando = false
  }
}

// ---------- Eliminar (solo sin libros) ----------
const aEliminar = ref<Materia | null>(null)
const eliminando = ref(false)

async function eliminar() {
  if (!aEliminar.value) return
  eliminando.value = true
  try {
    await api.delete(`/materias/${aEliminar.value.id}`)
    toast.success(`Materia «${aEliminar.value.nombre}» eliminada`)
    aEliminar.value = null
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo eliminar la materia'))
  } finally {
    eliminando.value = false
  }
}
</script>

<template>
  <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
    <header class="border-b border-gray-100 bg-indigo-50/40 px-5 py-3">
      <h3 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
        <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
        Materias
      </h3>
      <p class="mt-0.5 max-w-3xl pl-3 text-xs text-gray-500">
        Las materias (650) que se asignan al catalogar. Al <strong>renombrar</strong> una, todos sus libros la siguen teniendo con el nombre nuevo.
        Si el nombre nuevo ya existe (aunque cambien tildes, mayúsculas o espacios), se ofrece <strong>unirlas</strong>: los libros pasan a la existente.
        Solo se pueden <strong>eliminar</strong> las que ningún libro usa.
      </p>
    </header>

    <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-3 sm:flex-row sm:items-center">
      <input
        v-model="busqueda"
        type="search"
        placeholder="Buscar materia (ej. tesis electrónica)"
        aria-label="Buscar materia"
        class="h-10 flex-1 rounded-lg border border-gray-300 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
        @input="alBuscar"
      />
      <select v-model="orden" aria-label="Ordenar" class="h-10 rounded-lg border border-gray-300 px-3 text-sm" @change="irAPagina(1)">
        <option value="libros">Más usadas primero</option>
        <option value="nombre">Alfabético</option>
      </select>
      <label class="flex items-center gap-2 text-sm text-gray-700">
        <input v-model="soloSinLibros" type="checkbox" class="rounded border-gray-300" @change="irAPagina(1)" />
        Solo sin libros
      </label>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full">
        <thead>
          <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
            <th class="px-4 py-2.5">Materia</th>
            <th class="w-28 px-4 py-2.5 text-right">Libros</th>
            <th class="w-48 px-4 py-2.5 text-right">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="m in materias" :key="m.id" class="hover:bg-gray-50/60">
            <td class="px-4 py-2 text-sm">
              <router-link
                :to="{ name: 'listado-libros', query: { categoria_id: m.id, categoria: m.nombre } }"
                class="text-gray-900 hover:text-indigo-700 hover:underline"
                :title="`Ver los ${m.libros_count} libros de esta materia en el Listado de material`"
              >{{ m.nombre }}</router-link>
            </td>
            <td class="px-4 py-2 text-right text-sm tabular-nums" :class="m.libros_count ? 'text-gray-700' : 'text-gray-400'">{{ m.libros_count.toLocaleString('es-CL') }}</td>
            <td class="px-4 py-2 text-right">
              <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50" @click="abrirEdicion(m)">Renombrar</button>
              <button
                type="button"
                class="rounded-lg px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:text-gray-300 disabled:hover:bg-transparent"
                :disabled="m.libros_count > 0"
                :title="m.libros_count > 0 ? 'Tiene libros: renómbrala o únela con otra' : undefined"
                @click="aEliminar = m"
              >
                Eliminar
              </button>
            </td>
          </tr>
          <tr v-if="!cargando && !materias.length">
            <td colspan="3" class="px-4 py-6 text-center text-sm text-gray-400">No hay materias que coincidan.</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="border-t border-gray-100 px-5 py-3">
      <Paginador :pagina="pagina" :paginas="paginas" :total="total" :desde="desde" :hasta="hasta" unidad="materias" :cargando="cargando" @update:pagina="irAPagina" />
    </div>

    <Modal :abierto="!!edicion.materia" titulo="Renombrar materia" :bloqueado="edicion.guardando" destacado ancho="md" @cerrar="edicion.materia = null">
      <div v-if="edicion.materia" class="space-y-4 text-sm">
        <div class="flex items-center gap-4 rounded-xl border border-indigo-100 bg-indigo-50/50 px-4 py-3.5">
          <span class="font-serif text-3xl font-bold tabular-nums text-indigo-900">{{ edicion.materia.libros_count.toLocaleString('es-CL') }}</span>
          <p class="text-[15px] leading-snug text-gray-600">
            {{ edicion.materia.libros_count === 1 ? 'libro tiene esta materia;' : 'libros tienen esta materia;' }}<br />
            {{ edicion.materia.libros_count === 1 ? 'tendrá' : 'todos tendrán' }} el nombre nuevo.
          </p>
        </div>
        <div>
          <label for="materia-nombre" class="mb-1.5 block text-[15px] font-semibold text-gray-800">Nombre</label>
          <input
            id="materia-nombre"
            v-model="edicion.nombre"
            class="h-12 w-full rounded-xl border-2 border-gray-300 px-4 text-base outline-none focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100"
            @input="edicion.existente = null"
            @keydown.enter="guardar(false)"
          />
          <p class="mt-2 flex flex-wrap items-center gap-2 text-[13px] text-gray-500">
            Subdivisiones con <span class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 font-mono text-gray-700">--</span>
            ej. <span class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 font-mono text-gray-700">TESIS -- RECURSO ELECTRONICO</span>
          </p>
        </div>
        <div v-if="edicion.existente" class="rounded-xl border border-amber-300 bg-amber-50 p-3.5 text-amber-900" role="alert">
          Ya existe <strong>«{{ edicion.existente.nombre }}»</strong> ({{ edicion.existente.libros_count.toLocaleString('es-CL') }} libros).
          ¿Unir «{{ edicion.materia.nombre }}» con ella? Sus libros pasarán a «{{ edicion.existente.nombre }}» y esta materia desaparecerá.
        </div>
      </div>
      <template #pie>
        <button type="button" class="flex-1 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-100" :disabled="edicion.guardando" @click="edicion.materia = null">Cancelar</button>
        <button
          v-if="edicion.existente"
          type="button"
          class="flex-1 rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] hover:bg-amber-700 disabled:opacity-60"
          :disabled="edicion.guardando"
          @click="guardar(true)"
        >
          {{ edicion.guardando ? 'Uniendo…' : 'Sí, unir' }}
        </button>
        <button
          v-else
          type="button"
          class="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] hover:bg-indigo-700 disabled:opacity-60"
          :disabled="edicion.guardando || !edicion.nombre.trim()"
          @click="guardar(false)"
        >
          {{ edicion.guardando ? 'Guardando…' : 'Guardar' }}
        </button>
      </template>
    </Modal>

    <DialogoConfirmacion
      :abierto="!!aEliminar"
      titulo="Eliminar materia"
      confirmar="Sí, eliminar"
      tono="peligro"
      :cargando="eliminando"
      @confirmar="eliminar"
      @cancelar="aEliminar = null"
    >
      ¿Eliminar «{{ aEliminar?.nombre }}»? Ningún libro la usa.
    </DialogoConfirmacion>
  </section>
</template>
