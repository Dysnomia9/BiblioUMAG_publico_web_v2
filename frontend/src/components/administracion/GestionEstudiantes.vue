<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import FichaUsuario from '@/components/usuarios/FichaUsuario.vue'
import CargaMasivaEstudiantes from '@/components/administracion/CargaMasivaEstudiantes.vue'
import Paginacion from '@/components/ui/Paginacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { useListadoPaginado } from '@/composables/useListadoPaginado'
import type { OpcionesUsuarios, Paginado, Usuario } from '@/types'

const toast = useToast()

const opciones = ref<OpcionesUsuarios>({ carreras: [], tipos_documento: [], tipos: ['estudiante', 'docente', 'funcionario'], sexos: ['Femenino', 'Masculino', 'Otro'] })

const filtros = reactive({ q: '', tipo: 'estudiante', carrera: '' })

const TIPO_LABEL: Record<Usuario['tipo'], string> = { estudiante: 'Estudiante', docente: 'Docente', funcionario: 'Funcionario' }
const NOMBRE_TIPO: Record<Usuario['tipo'], string> = { estudiante: 'estudiante', docente: 'docente', funcionario: 'funcionario' }
const ETIQUETA_DOCUMENTO = { rut: 'RUT', pasaporte: 'Pasaporte', dni: 'DNI', otro: 'Otro' } as const

function estadoDe(u: Usuario) {
  if (u.activo) return { texto: 'Activo', clase: 'border-emerald-300 bg-emerald-50/60 text-emerald-700' }
  if (u.inactividad === 'vencida') return { texto: 'Vencida', clase: 'border-amber-300 bg-amber-50/60 text-amber-800' }
  return { texto: 'Inactivo', clase: 'border-red-300 bg-red-50/60 text-red-700' }
}

// El servidor entrega de a 25: con el reporte de matrícula son miles de estudiantes.
// Tras guardar o tras una carga masiva se recarga la misma página.
const { filas: usuarios, total, porPagina, pagina, cargando, cargar, reiniciar } = useListadoPaginado<Usuario>(async (p) => {
  try {
    const { data } = await api.get<Paginado<Usuario>>('/usuarios', {
      params: {
        q: filtros.q.trim() || undefined,
        tipo: filtros.tipo || undefined,
        carrera: filtros.carrera || undefined,
        page: p,
      },
    })
    return data
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo cargar el listado de estudiantes'))
    throw e
  }
})

async function cargarOpciones() {
  try {
    const { data } = await api.get<OpcionesUsuarios>('/usuarios/opciones')
    opciones.value = data
  } catch {
    // Sin opciones, la carrera se escribe a mano.
  }
}

let busquedaTimer: ReturnType<typeof setTimeout> | undefined
watch(filtros, () => {
  clearTimeout(busquedaTimer)
  busquedaTimer = setTimeout(reiniciar, 300)
}, { deep: true })

onMounted(() => {
  cargar()
  cargarOpciones()
})

const ficha = ref<InstanceType<typeof FichaUsuario> | null>(null)

// ---------- Carga masiva ----------
const cargaAbierta = ref(false)
</script>

<template>
  <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
    <header class="flex flex-col gap-3 border-b border-gray-100 bg-indigo-50/40 px-5 py-3 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <h3 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
          <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
          Usuarios: estudiantes, docentes y funcionarios
        </h3>
        <p class="mt-0.5 max-w-xl pl-3 text-xs text-gray-500">
          Registra estudiantes, docentes y funcionarios (con RUT o, si son extranjeros, con pasaporte o DNI), corrige sus datos o cárgalos en forma masiva
          desde una planilla Excel o CSV. Todo cambio pide confirmación y queda en el Registro absoluto.
        </p>
      </div>
      <div class="flex shrink-0 flex-wrap items-center gap-2">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-gray-50"
          @click="cargaAbierta = true"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.500v2.250A2.250 2.250 0 005.250 21h13.500A2.250 2.250 0 0021 18.750V16.500m-13.500-9L12 3m0 0l4.500 4.500M12 3v13.500" /></svg>
          Carga masiva (Excel/CSV)
        </button>
        <!-- Un solo bloque para crear: se elige el tipo de persona. -->
        <div class="inline-flex overflow-hidden rounded-lg bg-indigo-600 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)]" role="group" aria-label="Crear usuario nuevo">
          <span class="flex items-center whitespace-nowrap bg-indigo-700/60 px-2.5 text-xs" aria-hidden="true">+ Nuevo</span>
          <button v-for="t in (['estudiante', 'docente', 'funcionario'] as const)" :key="t" type="button" class="border-l border-white/20 px-3 py-2 transition-colors first-of-type:border-l-0 hover:bg-indigo-700" :aria-label="`Nuevo ${NOMBRE_TIPO[t]}`" @click="ficha?.abrirNuevo(t)">
            {{ TIPO_LABEL[t] }}
          </button>
        </div>
      </div>
    </header>

    <div class="grid grid-cols-1 gap-2 border-b border-gray-100 p-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
      <div class="relative">
        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.350-4.350M11 19a8 8 0 100-16 8 8 0 000 16z" /></svg>
        <input
          v-model="filtros.q"
          type="search"
          placeholder="Buscar por nombre, RUT, pasaporte o carrera"
          aria-label="Buscar estudiantes"
          class="w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-9 pr-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
        />
      </div>
      <select v-model="filtros.tipo" aria-label="Filtrar por tipo" class="rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
        <option value="">Todos los tipos</option>
        <option v-for="t in opciones.tipos" :key="t" :value="t">{{ TIPO_LABEL[t] }}s</option>
      </select>
      <select v-model="filtros.carrera" aria-label="Filtrar por carrera" class="rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
        <option value="">Todas las carreras</option>
        <option v-for="c in opciones.carreras" :key="c" :value="c">{{ c }}</option>
      </select>
    </div>

    <p class="border-b border-gray-100 px-4 py-2 text-xs text-gray-500">
      <template v-if="cargando">Cargando…</template>
      <template v-else><strong class="text-gray-900">{{ total.toLocaleString('es-CL') }}</strong> {{ total === 1 ? 'persona' : 'personas' }}</template>
    </p>

    <div class="overflow-x-auto">
      <table class="w-full">
        <thead>
          <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
            <th class="px-4 py-2.5">Documento</th>
            <th class="px-4 py-2.5">Nombre</th>
            <th class="hidden px-4 py-2.5 md:table-cell">Carrera</th>
            <th class="hidden px-4 py-2.5 sm:table-cell">Estado</th>
            <th class="px-4 py-2.5 text-right">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="u in usuarios" :key="u.id" class="even:bg-gray-50/50">
            <td class="px-4 py-2.5 whitespace-nowrap">
              <span class="mr-1.5 rounded px-1.5 py-0.5 text-[10px] font-bold uppercase" :class="u.tipo_documento && u.tipo_documento !== 'rut' ? 'bg-amber-50 text-amber-800' : 'bg-indigo-50 text-indigo-700'">{{ ETIQUETA_DOCUMENTO[u.tipo_documento ?? 'rut'] }}</span>
              <span class="font-mono text-xs text-gray-800">{{ u.rut }}</span>
              <span v-if="u.pais && u.tipo_documento !== 'rut'" class="mt-0.5 block text-[11px] text-gray-500">{{ u.pais }}</span>
            </td>
            <td class="px-4 py-2.5">
              <span class="block text-sm font-bold leading-snug text-gray-900">{{ u.nombre }} {{ u.apellido }}</span>
              <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs">
                <span class="font-semibold text-gray-600">{{ TIPO_LABEL[u.tipo] }}</span>
                <template v-if="u.email">
                  <span class="h-3 w-px bg-gray-300" aria-hidden="true"></span>
                  <span class="break-all text-indigo-700">{{ u.email }}</span>
                </template>
                <span v-if="u.correos?.length" class="rounded bg-indigo-50 px-1 text-indigo-700" :title="u.correos.map((c) => c.email).join(', ')">+{{ u.correos.length }}</span>
              </span>
            </td>
            <td class="hidden px-4 py-2.5 text-sm text-gray-700 md:table-cell">
              {{ u.carrera ?? u.unidad ?? '—' }}<span v-if="u.anio_ingreso" class="text-xs text-gray-400"> · {{ u.anio_ingreso }}</span>
            </td>
            <td class="hidden px-4 py-2.5 whitespace-nowrap sm:table-cell">
              <span class="inline-block rounded border px-1.5 py-px text-[10px] font-bold uppercase tracking-widest" :class="estadoDe(u).clase">{{ estadoDe(u).texto }}</span>
            </td>
            <td class="px-4 py-2.5 text-right">
              <button type="button" class="rounded-lg border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-gray-50" @click="ficha?.abrirEdicion(u)">Editar</button>
            </td>
          </tr>
          <tr v-if="!cargando && !usuarios.length">
            <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">Sin personas que coincidan con los filtros.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <Paginacion v-model="pagina" class="border-t border-gray-100 px-4 py-2" :total="total" :por-pagina="porPagina" singular="persona" plural="personas" />

    <FichaUsuario ref="ficha" @guardado="cargar(); cargarOpciones()" />

    <CargaMasivaEstudiantes v-model:abierto="cargaAbierta" @cargado="cargar(); cargarOpciones()" />
  </section>
</template>
