<script setup lang="ts">
import { computed } from 'vue'

type Tarea = 'nuevo' | 'editar' | 'ejemplares' | 'editar_ejemplar' | 'listado'
const props = defineProps<{
  seccion: 'ficha' | 'ejemplares' | 'listado'
  tarea: Tarea
  listado: 'prestamos' | 'ejemplares'
  deshabilitado: boolean
}>()
const emit = defineEmits<{ tarea: [tarea: Tarea]; listado: [vista: 'prestamos' | 'ejemplares'] }>()
const ICONOS = {
  ficha: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H6.75A2.25 2.25 0 004.5 4.5v15a2.25 2.25 0 002.25 2.25h10.5a2.25 2.25 0 002.25-2.25v-5.25zM13.5 2.25a9 9 0 016 6M8.25 12h7.5m-7.5 3h7.5m-7.5 3h4.5',
  ejemplar: 'M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75M15.75 3.75H9.375c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V8.25L15.75 3.75zM15.75 3.75v4.5h4.5',
  listado: 'M8.25 6.75h12m-12 5.25h12m-12 5.25h12M3.75 6.75h.008v.008H3.75V6.75zm0 5.25h.008v.008H3.75V12zm0 5.25h.008v.008H3.75v-.008z',
  agregar: 'M12 4.5v15m7.5-7.5h-15',
  editar: 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.862 4.487zm0 0l2.651 2.651',
  devolver: 'M9 10.5H4.5m0 0L9 6m-4.5 4.5h9a6 6 0 010 12H9',
}
const secciones = [
  { id: 'ficha', tarea: 'nuevo', texto: 'Ficha del préstamo', icono: ICONOS.ficha },
  { id: 'ejemplares', tarea: 'ejemplares', texto: 'Ejemplar del préstamo', icono: ICONOS.ejemplar },
  { id: 'listado', tarea: 'listado', texto: 'Listado de préstamos', icono: ICONOS.listado },
] as const
const acciones = computed(() => props.seccion === 'ficha'
  ? [{ id: 'nuevo', texto: 'Nueva ficha', icono: ICONOS.agregar }, { id: 'editar', texto: 'Editar ficha', icono: ICONOS.editar }] as const
  : [{ id: 'ejemplares', texto: 'Agregar ejemplar', icono: ICONOS.agregar }, { id: 'editar_ejemplar', texto: 'Editar ejemplar', icono: ICONOS.editar }] as const)
const listados = [
  { id: 'prestamos', texto: 'Préstamos por ficha', icono: ICONOS.ficha },
  { id: 'ejemplares', texto: 'Ejemplares y devoluciones', icono: ICONOS.devolver },
] as const
</script>

<template>
  <div class="mt-4">
    <div class="flex flex-wrap gap-3" role="tablist" aria-label="Secciones de préstamo interbibliotecario">
      <button
        v-for="s in secciones" :key="s.id" type="button" role="tab" :aria-selected="seccion === s.id"
        class="inline-flex min-h-12 items-center gap-3 rounded-xl border px-4 py-2 text-left text-sm font-semibold transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 motion-reduce:transition-none"
        :class="seccion === s.id ? 'border-indigo-600 bg-gradient-to-r from-indigo-800 to-indigo-600 text-white shadow-md shadow-indigo-900/15' : 'border-indigo-200 bg-white text-indigo-950 shadow-sm hover:border-indigo-400 hover:bg-indigo-50'"
        :disabled="deshabilitado" @click="seccion !== s.id && emit('tarea', s.tarea)"
      >
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="seccion === s.id ? 'bg-white/15 text-white' : 'bg-indigo-50 text-indigo-600'">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="s.icono" /></svg>
        </span>
        <span>{{ s.texto }}</span>
        <svg class="ml-1 h-4 w-4 shrink-0 text-amber-200" :class="seccion === s.id ? 'opacity-100' : 'opacity-0'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
      </button>
    </div>

    <div class="mt-4 border-t border-indigo-100 pt-3">
      <div v-if="seccion !== 'listado'" class="flex flex-wrap items-center gap-3" role="tablist" :aria-label="seccion === 'ficha' ? 'Acciones de la ficha interbibliotecaria' : 'Acciones del ejemplar interbibliotecario'">
        <button v-for="a in acciones" :key="a.id" type="button" role="tab" :aria-selected="tarea === a.id" class="inline-flex min-h-10 items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50" :class="tarea === a.id ? 'border-indigo-300 bg-indigo-100 text-indigo-950 shadow-sm ring-1 ring-inset ring-indigo-200' : 'border-gray-200 bg-white text-gray-600 hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-900'" :disabled="deshabilitado" @click="emit('tarea', a.id)">
          <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="a.icono" /></svg>
          {{ a.texto }}
        </button>
      </div>
      <div v-else class="flex flex-wrap items-center gap-3" role="tablist" aria-label="Listados interbibliotecarios">
        <button v-for="v in listados" :key="v.id" type="button" role="tab" :aria-selected="listado === v.id" class="inline-flex min-h-10 items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50" :class="listado === v.id ? 'border-indigo-300 bg-indigo-100 text-indigo-950 shadow-sm ring-1 ring-inset ring-indigo-200' : 'border-gray-200 bg-white text-gray-600 hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-900'" :disabled="deshabilitado" @click="emit('listado', v.id)">
          <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="v.icono" /></svg>
          {{ v.texto }}
        </button>
      </div>
      <p v-if="seccion === 'listado'" class="mt-3 text-xs text-gray-500">Consulta la biblioteca propietaria, los plazos, los ejemplares pendientes y el historial de devoluciones.</p>
    </div>
  </div>
</template>
