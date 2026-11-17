<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue'
import api from '@/services/api'
import { fechaCorta } from '@/utils/prestamos'
import { mensajeDeError, useToast } from '@/composables/useToast'
import Paginador from '@/components/ui/Paginador.vue'
import type { FichaInterbibliotecaria, Paginado } from '@/types'

const emit = defineEmits<{ elegir: [ficha: FichaInterbibliotecaria, tarea: 'editar' | 'ejemplares'] }>()
withDefaults(defineProps<{ listadoPrestamos?: boolean }>(), { listadoPrestamos: false })
const toast = useToast()
const filas = ref<FichaInterbibliotecaria[]>([])
const busqueda = ref('')
const pagina = ref(1)
const cargando = ref(false)
const error = ref(false)
const meta = ref({ current_page: 1, last_page: 1, total: 0, from: null as number | null, to: null as number | null })
let solicitud = 0
let timer: ReturnType<typeof setTimeout> | undefined
async function cargar() {
  const actual = ++solicitud
  cargando.value = true
  try {
    const { data } = await api.get<Paginado<FichaInterbibliotecaria>>('/prestamos-interbibliotecarios/fichas', {
      params: { q: busqueda.value.trim() || undefined, page: pagina.value, por_pagina: 10 },
    })
    if (actual !== solicitud) return
    const { data: lista, ...resto } = data
    filas.value = lista
    meta.value = resto
    error.value = false
  } catch (e) {
    if (actual !== solicitud) return
    error.value = true
    toast.error(mensajeDeError(e, 'No se pudieron cargar las fichas interbibliotecarias'))
  } finally { if (actual === solicitud) cargando.value = false }
}
function buscar() { clearTimeout(timer); timer = setTimeout(() => { pagina.value = 1; void cargar() }, 250) }
function recargar() { pagina.value = 1; return cargar() }
onMounted(cargar)
onBeforeUnmount(() => { clearTimeout(timer); ++solicitud })
defineExpose({ recargar })
</script>

<template>
  <section class="mb-5 overflow-hidden rounded-xl border border-amber-200 bg-amber-50/60 shadow-sm" aria-label="Fichas interbibliotecarias guardadas">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-200 bg-amber-100/70 px-3 py-3">
      <div><h3 class="font-semibold text-amber-950">{{ listadoPrestamos ? 'Listado de préstamos interbibliotecarios' : 'Fichas interbibliotecarias guardadas' }}</h3><p class="mt-0.5 text-xs text-gray-600">{{ listadoPrestamos ? 'Un registro por ficha de préstamo, con su biblioteca propietaria, devolución y ejemplares.' : 'Incluye las fichas que todavía no tienen ejemplares.' }}</p></div>
      <input v-model="busqueda" type="search" aria-label="Buscar fichas interbibliotecarias" placeholder="Título, ficha o biblioteca…" class="w-full rounded-full border border-amber-300 bg-white px-4 py-2 text-sm sm:w-72" @input="buscar" />
    </div>
    <p v-if="error" class="px-3 py-3 text-sm text-red-800">No se pudo actualizar el listado. <button type="button" class="underline" @click="cargar">Reintentar</button></p>
    <p v-else-if="!filas.length" class="px-3 py-4 text-sm text-gray-600" role="status">{{ cargando ? 'Cargando fichas…' : 'No hay fichas para esta búsqueda.' }}</p>
    <div v-else class="overflow-x-auto" :aria-busy="cargando">
      <table class="w-full text-left text-xs">
        <thead class="border-b border-amber-200 bg-amber-100/40 text-amber-950"><tr><th class="px-3 py-2">Ficha y título</th><th class="px-3 py-2">Biblioteca propietaria</th><th class="whitespace-nowrap px-3 py-2">Devolver el</th><th class="px-3 py-2">Ejemplares</th><th class="px-3 py-2">Acciones</th></tr></thead>
        <tbody class="divide-y divide-amber-100">
          <tr v-for="(f, i) in filas" :key="f.id" :class="i % 2 ? 'bg-indigo-50/50' : 'bg-amber-50/50'">
            <td class="max-w-64 px-3 py-2"><span class="font-mono font-semibold text-indigo-800">N.º {{ f.libro.numero_ficha }}</span><p class="mt-0.5 line-clamp-2 font-medium text-gray-900">{{ f.libro.titulo }}</p></td>
            <td class="max-w-48 px-3 py-2 text-gray-700">{{ f.biblioteca }}</td>
            <td class="whitespace-nowrap px-3 py-2 font-semibold text-amber-950">{{ fechaCorta(f.fecha_limite) }}</td>
            <td class="px-3 py-2 text-gray-700">{{ f.ingresos_count ? `${f.pendientes_count} por devolver · ${f.ingresos_count} ingresados` : 'Sin ejemplares' }}</td>
            <td class="px-3 py-2"><div class="flex items-center gap-2 whitespace-nowrap">
              <button type="button" class="inline-flex min-h-8 items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1 font-semibold text-amber-950 shadow-sm transition-colors hover:border-amber-400 hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2 disabled:opacity-50" :disabled="cargando" @click="emit('elegir', f, 'editar')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.862 4.487z" /></svg>Editar ficha</button>
              <button type="button" class="inline-flex min-h-8 items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1 font-semibold text-emerald-900 shadow-sm transition-colors hover:border-emerald-400 hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 disabled:opacity-50" :disabled="cargando" @click="emit('elegir', f, 'ejemplares')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14m7-7H5" /></svg>Agregar ejemplares</button>
            </div></td>
          </tr>
        </tbody>
      </table>
    </div>
    <Paginador class="border-t border-amber-200 bg-amber-100/40 px-3 py-2" :pagina="meta.current_page" :paginas="meta.last_page" :total="meta.total" :desde="meta.from" :hasta="meta.to" unidad="fichas" :cargando="cargando" @update:pagina="pagina = $event; cargar()" />
  </section>
</template>
