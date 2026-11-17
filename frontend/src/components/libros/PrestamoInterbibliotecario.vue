<script setup lang="ts">
import { nombreDeCopia } from '@/utils/tomo'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import Paginador from '@/components/ui/Paginador.vue'
import CerrarPrestamoInterbibliotecario from '@/components/libros/CerrarPrestamoInterbibliotecario.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { enPuntaArenas } from '@/utils/horario'
import { ESTADOS_EJEMPLAR } from '@/utils/estadosEjemplar'
import { useAuthStore } from '@/stores/auth'
import type { Paginado, PrestamoInterbibliotecario } from '@/types'

/** Historial y gestión del material recibido. La ficha y los ejemplares se catalogan en el formulario compartido. */
const emit = defineEmits<{ editarFicha: [id: number]; editarEjemplar: [codigo: string]; estadoEjemplar: [codigo: string] }>()
const toast = useToast()
const auth = useAuthStore()
const hoy = enPuntaArenas().fecha
const CAMPO = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30'
const fecha = (iso: string | null | undefined) => iso ? new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('es-CL') : '—'

// ---------- Lo que está en la biblioteca y lo devuelto ----------
type Vista = 'en_biblioteca' | 'devueltos' | 'todos'
const VISTAS: { id: Vista; label: string }[] = [
  { id: 'en_biblioteca', label: 'En la biblioteca' },
  { id: 'devueltos', label: 'Devueltos' },
  { id: 'todos', label: 'Todos' },
]
const vista = ref<Vista>('en_biblioteca')
const pagina = ref(1)
const busqueda = ref('')
const filas = ref<PrestamoInterbibliotecario[]>([])
const meta = ref<Omit<Paginado<PrestamoInterbibliotecario>, 'data'>>({ current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null })
const conteos = ref<Record<Vista, number>>({ en_biblioteca: 0, devueltos: 0, todos: 0 })
const bibliotecas = ref<string[]>([])
const cargando = ref(true)
const error = ref(false)

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<Paginado<PrestamoInterbibliotecario> & { conteos: Record<Vista, number>; bibliotecas: string[] }>('/prestamos-interbibliotecarios', {
      params: { vista: vista.value, q: busqueda.value.trim() || undefined, page: pagina.value, por_pagina: 10 },
    })
    const { data: lista, conteos: c, bibliotecas: b, ...resto } = data
    filas.value = lista
    meta.value = resto
    conteos.value = c
    bibliotecas.value = b
    error.value = false
  } catch {
    error.value = true
    filas.value = []
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)
function recargarIngresos() { vista.value = 'en_biblioteca'; pagina.value = 1; return cargar() }
defineExpose({ recargarIngresos })

function cambiarVista(v: Vista) {
  vista.value = v
  pagina.value = 1
  cargar()
}

let buscarTimer: ReturnType<typeof setTimeout> | undefined
function onBuscar() {
  clearTimeout(buscarTimer)
  buscarTimer = setTimeout(() => {
    pagina.value = 1
    cargar()
  }, 250)
}

function irAPagina(p: number) {
  pagina.value = p
  cargar()
}

/** Cómo está cada copia: devuelta, por devolver, prestada, todavía sin colocar o disponible. */
function situacion(p: PrestamoInterbibliotecario) {
  if (p.devuelto_el) return { texto: `Devuelto el ${fecha(p.devuelto_el)}`, clase: 'bg-slate-100 text-slate-700' }
  if (p.prestamo_activo) {
    return { texto: `Prestado hasta el ${fecha(p.prestamo_activo.fecha_devolucion)}`, clase: p.vencido ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' }
  }
  if (p.vencido) return { texto: 'Plazo cumplido: devolver', clase: 'bg-red-100 text-red-700' }
  const estado = p.ejemplar.estado_proceso
  if (estado !== 'en_estante') return { texto: `${ESTADOS_EJEMPLAR[estado]?.label ?? estado}: aún no se presta`, clase: ESTADOS_EJEMPLAR[estado]?.badge ?? 'bg-gray-100 text-gray-700' }
  if (p.fecha_tope === hoy) return { texto: 'Último día para prestarlo', clase: 'bg-amber-100 text-amber-800' }
  return { texto: p.ejemplar.disponible ? 'Disponible' : 'Apartado para una reserva', clase: p.ejemplar.disponible ? 'bg-emerald-100 text-emerald-700' : 'bg-violet-100 text-violet-700' }
}

// ---------- Devolver a su biblioteca ----------
const aDevolver = ref<PrestamoInterbibliotecario | null>(null)
const notaDevolucion = ref('')
const devolviendo = ref(false)

function pedirDevolver(p: PrestamoInterbibliotecario) {
  notaDevolucion.value = ''
  aDevolver.value = p
}

async function devolver() {
  const p = aDevolver.value
  if (!p) return
  devolviendo.value = true
  try {
    const { data } = await api.post<PrestamoInterbibliotecario & { reservas_canceladas: number }>(`/prestamos-interbibliotecarios/${p.id}/devolver`, {
      nota: notaDevolucion.value.trim() || null,
    })
    toast.success(
      `«${p.ejemplar.libro.titulo}» devuelto a ${p.biblioteca}` +
        (data.reservas_canceladas ? ` · ${data.reservas_canceladas} ${data.reservas_canceladas === 1 ? 'reserva en espera cancelada' : 'reservas en espera canceladas'}` : ''),
    )
    aDevolver.value = null
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo devolver'))
  } finally {
    devolviendo.value = false
  }
}

// ---------- Corregir la biblioteca, la fecha límite o la nota ----------
const aEditar = ref<PrestamoInterbibliotecario | null>(null)
const edicion = reactive({ biblioteca: '', fecha_limite: '', nota: '' })
const topeEdicion = ref<string | null>(null)
const editando = ref(false)

function pedirEditar(p: PrestamoInterbibliotecario) {
  if (p.ficha_interbibliotecaria_id) {
    emit('editarFicha', p.ficha_interbibliotecaria_id)
    return
  }
  Object.assign(edicion, { biblioteca: p.biblioteca, fecha_limite: p.fecha_limite, nota: p.nota ?? '' })
  topeEdicion.value = p.fecha_tope
  aEditar.value = p
}

watch(
  () => edicion.fecha_limite,
  async (limite) => {
    const p = aEditar.value
    if (!p || !limite) return
    if (limite === p.fecha_limite) {
      topeEdicion.value = p.fecha_tope
      return
    }
    try {
      const { data } = await api.get<{ fecha_tope: string }>('/prestamos-interbibliotecarios/fecha-tope', {
        params: { fecha_limite: limite, ubicacion_id: p.ejemplar.ubicacion_id ?? undefined },
      })
      if (limite === edicion.fecha_limite) topeEdicion.value = data.fecha_tope
    } catch {
      topeEdicion.value = null
    }
  },
)

/** Lo que cambia, como [qué, antes, después]. */
const cambiosEdicion = computed(() => {
  const p = aEditar.value
  if (!p) return []
  const lista: [string, string, string][] = []
  if (edicion.biblioteca.trim() !== p.biblioteca) lista.push(['Biblioteca', p.biblioteca, edicion.biblioteca.trim()])
  if (edicion.fecha_limite !== p.fecha_limite) {
    lista.push(['Vuelve a su biblioteca', fecha(p.fecha_limite), fecha(edicion.fecha_limite)])
    lista.push(['Se presta a usuarios hasta', fecha(p.fecha_tope), topeEdicion.value ? fecha(topeEdicion.value) : '…'])
  }
  if (edicion.nota.trim() !== (p.nota ?? '')) lista.push(['Nota', p.nota || '(sin nota)', edicion.nota.trim() || '(sin nota)'])
  return lista
})
const edicionValida = computed(() => !!aEditar.value && !!edicion.biblioteca.trim() && edicion.fecha_limite > aEditar.value.fecha_ingreso && cambiosEdicion.value.length > 0)
// Si el plazo se acorta, un préstamo ya hecho puede quedar después del nuevo tope: no se mueve solo.
const prestamoFueraDelTope = computed(() => {
  const activo = aEditar.value?.prestamo_activo
  return !!activo?.fecha_devolucion && !!topeEdicion.value && activo.fecha_devolucion.slice(0, 10) > topeEdicion.value
})

async function guardarEdicion() {
  const p = aEditar.value
  if (!p || !edicionValida.value) return
  editando.value = true
  try {
    await api.patch(`/prestamos-interbibliotecarios/${p.id}`, {
      biblioteca: edicion.biblioteca.trim(),
      fecha_limite: edicion.fecha_limite,
      nota: edicion.nota.trim() || null,
    })
    toast.success('Préstamo interbibliotecario actualizado')
    aEditar.value = null
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo guardar'))
  } finally {
    editando.value = false
  }
}
</script>

<template>
  <div>
    <datalist id="pib-bibliotecas"><option v-for="b in bibliotecas" :key="b" :value="b" /></datalist>
    <!-- Lo que está en la biblioteca y lo devuelto -->
    <section class="mt-8 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
      <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h2 class="text-[15px] font-semibold text-gray-900">Ejemplares de préstamos interbibliotecarios</h2>
          <p class="text-xs text-gray-500">Lo que vence primero, arriba. «Devolver» lo saca del catálogo sin borrar sus préstamos.</p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
          <div class="flex max-w-full flex-wrap gap-2" role="tablist" aria-label="Qué mostrar">
            <button
              v-for="v in VISTAS"
              :key="v.id"
              type="button"
              role="tab"
              :aria-selected="vista === v.id"
              class="inline-flex min-h-9 items-center gap-2 whitespace-nowrap rounded-lg border px-3 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 focus-visible:ring-offset-2"
              :class="vista === v.id ? 'border-indigo-600 bg-indigo-700 text-white shadow-sm' : 'border-indigo-100 bg-indigo-50/60 text-indigo-900 hover:border-indigo-300 hover:bg-indigo-100'"
              @click="cambiarVista(v.id)"
            >
              {{ v.label }} <span class="min-w-5 rounded-full px-1.5 py-0.5 text-center text-[11px] tabular-nums" :class="vista === v.id ? 'bg-white/15 text-white' : 'bg-white text-indigo-700'">{{ conteos[v.id] }}</span>
            </button>
          </div>
          <input autocomplete="off"
            v-model="busqueda"
            type="text"
            placeholder="N.º de ficha, título, código o biblioteca"
            :class="[CAMPO, 'sm:w-64']"
            aria-label="Buscar material de otras bibliotecas"
            @input="onBuscar"
          />
        </div>
      </div>

      <p v-if="error" class="px-5 py-6 text-sm text-red-700">No se pudo cargar el material de otras bibliotecas.</p>
      <!-- En el teléfono, una tarjeta por copia: la situación y «Devolver» quedan a la vista sin deslizar la tabla. -->
      <ul v-if="!error" class="divide-y divide-gray-100 md:hidden">
        <li v-for="p in filas" :key="p.id" class="px-4 py-4" :class="p.vencido ? 'bg-red-50/40' : ''">
          <p class="text-sm font-semibold text-gray-900">{{ p.ejemplar.libro.titulo }}</p>
          <p class="mt-1 font-mono text-xs font-semibold text-indigo-800">Ficha N.º {{ p.ejemplar.libro.numero_ficha }}</p>
          <p class="mt-0.5 text-xs text-gray-500">{{ p.biblioteca }} · <span class="font-mono">{{ p.ejemplar.codigo_barras }}</span></p>
          <span class="mt-2 inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="situacion(p).clase">{{ situacion(p).texto }}</span>
          <p v-if="p.prestamo_activo" class="mt-1 text-xs text-gray-600">{{ p.prestamo_activo.usuario }}</p>
          <dl class="mt-2 grid grid-cols-3 gap-2 text-xs">
            <div>
              <dt class="text-gray-500">Llegó</dt>
              <dd class="text-gray-800">{{ fecha(p.fecha_ingreso) }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Usuarios hasta</dt>
              <dd :class="p.vencido ? 'font-semibold text-red-700' : 'text-gray-800'">{{ fecha(p.fecha_tope) }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Vuelve</dt>
              <dd class="font-semibold text-gray-900">{{ fecha(p.fecha_limite) }}</dd>
            </div>
          </dl>
          <p v-if="p.nota" class="mt-2 whitespace-pre-line text-xs text-gray-500">{{ p.nota }}</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <button type="button" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-950" v-if="p.ficha_interbibliotecaria_id" @click="emit('editarFicha', p.ficha_interbibliotecaria_id)">Editar ficha</button>
            <button type="button" class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-1 text-xs font-semibold text-violet-950" @click="emit('editarEjemplar', p.ejemplar.codigo_barras)">Editar ejemplar</button>
            <button v-if="!p.devuelto_el && auth.puede('libros.estado')" type="button" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-950" @click="emit('estadoEjemplar', p.ejemplar.codigo_barras)">Cambiar estado</button>
          </div>
          <div v-if="!p.devuelto_el" class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50" @click="pedirDevolver(p)">
              Devolver a su biblioteca
            </button>
            <button type="button" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50" @click="pedirEditar(p)">
              Cambiar plazo o nota
            </button>
          </div>
          <p v-else class="mt-2 text-xs text-gray-500">{{ p.total_prestamos ?? 0 }} {{ p.total_prestamos === 1 ? 'préstamo' : 'préstamos' }} a usuarios · devuelto por {{ p.devuelto_por?.nombre ?? 'sin registro' }}</p>
        </li>
        <li v-if="!cargando && !filas.length" class="px-4 py-8 text-center text-sm text-gray-400">
          {{ busqueda.trim() ? 'Nada coincide con la búsqueda.' : vista === 'devueltos' ? 'Todavía no se ha devuelto nada.' : 'No hay material de otras bibliotecas.' }}
        </li>
      </ul>
      <div v-if="!error" class="hidden overflow-x-auto md:block">
        <table class="w-full min-w-[56rem]">
          <thead>
            <tr class="border-b border-indigo-200 bg-indigo-100/70">
              <th class="px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Título</th>
              <th class="px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Biblioteca</th>
              <th class="px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Plazos</th>
              <th class="px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Situación</th>
              <th class="px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Acción</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(p, i) in filas" :key="p.id" class="align-top transition-colors hover:bg-indigo-50/40" :class="p.vencido ? 'bg-red-50/60' : i % 2 ? 'bg-indigo-50/50' : 'bg-amber-50/40'">
              <td class="px-3 py-2 text-xs text-gray-900">
                <p class="font-mono text-xs font-semibold text-indigo-800">Ficha N.º {{ p.ejemplar.libro.numero_ficha }}</p>
                {{ p.ejemplar.libro.titulo }}
                <p v-if="p.ejemplar.libro.autores?.length" class="text-xs text-gray-500">{{ p.ejemplar.libro.autores.map((a) => a.nombre).join(', ') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                  <button type="button" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-950" v-if="p.ficha_interbibliotecaria_id" @click="emit('editarFicha', p.ficha_interbibliotecaria_id)">Editar ficha</button>
                  <button type="button" class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-1 text-xs font-semibold text-violet-950" @click="emit('editarEjemplar', p.ejemplar.codigo_barras)">Editar ejemplar</button>
                  <button v-if="!p.devuelto_el && auth.puede('libros.estado')" type="button" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-950" @click="emit('estadoEjemplar', p.ejemplar.codigo_barras)">Cambiar estado</button>
                </div>

                <p class="mt-0.5 font-mono text-[11px] text-gray-400">{{ p.ejemplar.etiqueta_copia ? `${p.ejemplar.etiqueta_copia} · ` : '' }}{{ p.ejemplar.codigo_barras }}</p>
              </td>
              <td class="px-3 py-2 text-xs text-gray-700">
                {{ p.biblioteca }}
                <p v-if="p.nota" class="mt-0.5 max-w-[16rem] whitespace-pre-line text-xs text-gray-500">{{ p.nota }}</p>
              </td>
              <td class="whitespace-nowrap px-3 py-2 text-xs text-gray-600">
                <p>Llegó: {{ fecha(p.fecha_ingreso) }}</p>
                <p :class="p.vencido ? 'font-semibold text-red-700' : ''">Usuarios hasta: {{ fecha(p.fecha_tope) }}</p>
                <p class="font-semibold text-gray-800">Vuelve: {{ fecha(p.fecha_limite) }}</p>
              </td>
              <td class="px-3 py-2 text-xs">
                <span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="situacion(p).clase">{{ situacion(p).texto }}</span>
                <p v-if="p.prestamo_activo" class="mt-1 text-xs text-gray-600">{{ p.prestamo_activo.usuario }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ p.total_prestamos ?? 0 }} {{ p.total_prestamos === 1 ? 'préstamo' : 'préstamos' }} a usuarios</p>
              </td>
              <td class="px-3 py-2">
                <div v-if="!p.devuelto_el" class="flex flex-col items-start gap-2">
                  <button type="button" class="min-h-8 rounded-lg border border-red-200 bg-red-50 px-3 py-1 text-xs font-semibold text-red-800 hover:bg-red-100" @click="pedirDevolver(p)">Devolver a su biblioteca</button>
                  <button type="button" class="min-h-8 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-800 hover:bg-indigo-100" @click="pedirEditar(p)">Cambiar plazo o nota</button>
                </div>
                <p v-else class="text-xs text-gray-500">Por {{ p.devuelto_por?.nombre ?? 'sin registro' }}</p>
              </td>
            </tr>
            <tr v-if="!cargando && !filas.length">
              <td colspan="5" class="px-6 py-8 text-center text-xs text-gray-400">
                {{ busqueda.trim() ? 'Nada coincide con la búsqueda.' : vista === 'devueltos' ? 'Todavía no se ha devuelto nada.' : 'No hay material de otras bibliotecas.' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Paginador
        class="border-t border-gray-100 px-5 py-3"
        :pagina="meta.current_page"
        :paginas="meta.last_page"
        :total="meta.total"
        :desde="meta.from"
        :hasta="meta.to"
        unidad="copias"
        :cargando="cargando"
        @update:pagina="irAPagina"
      />
    </section>

    <!-- Si figura prestada, se cierra ese préstamo con el motivo y escribiendo CONFIRMAR. -->
    <CerrarPrestamoInterbibliotecario
      :abierto="!!aDevolver?.prestamo_activo"
      :id="aDevolver?.id ?? null"
      :biblioteca="aDevolver?.biblioteca ?? ''"
      :titulo="aDevolver?.ejemplar.libro.titulo ?? ''"
      :usuario="aDevolver?.prestamo_activo?.usuario ?? null"
      :fecha-devolucion="aDevolver?.prestamo_activo?.fecha_devolucion ?? null"
      @listo="aDevolver = null; cargar()"
      @cancelar="aDevolver = null"
    />

    <DialogoConfirmacion
      :abierto="!!aDevolver && !aDevolver.prestamo_activo"
      :titulo="`¿Devolver a ${aDevolver?.biblioteca ?? 'su biblioteca'}?`"
      confirmar="Sí, devolver"
      tono="peligro"
      :cargando="devolviendo"
      @confirmar="devolver"
      @cancelar="aDevolver = null"
    >
      <p>
        <strong class="text-gray-900">{{ aDevolver?.ejemplar.libro.titulo }}</strong> (<template v-if="nombreDeCopia(aDevolver?.ejemplar)">{{ nombreDeCopia(aDevolver?.ejemplar)!.toLowerCase() }} · </template>
        <span class="font-mono">{{ aDevolver?.ejemplar.codigo_barras }}</span>) vuelve a su biblioteca y deja de verse en el catálogo y el portal.
        <template v-if="!aDevolver?.total_prestamos">No se borra: queda registrado que estuvo aquí.</template>
        <template v-else-if="aDevolver.total_prestamos === 1">No se borra: su préstamo sigue en el historial y en los reportes.</template>
        <template v-else>No se borra: sus {{ aDevolver.total_prestamos }} préstamos siguen en el historial y en los reportes.</template>
      </p>
      <p v-if="aDevolver && !aDevolver.vencido" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
        Todavía se puede prestar hasta el {{ fecha(aDevolver.fecha_tope) }}: se devuelve antes de tiempo.
      </p>
      <div class="mt-3">
        <label for="pib-nota-devolucion" class="mb-1.5 block text-xs font-semibold text-gray-700">Nota (opcional)</label>
        <input autocomplete="off"
          id="pib-nota-devolucion"
          v-model="notaDevolucion"
          type="text"
          maxlength="500"
          placeholder="Ej: enviado por correo, guía n.º 5544"
          class="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm outline-none placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
        />
      </div>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="!!aEditar"
      titulo="¿Cambiar el préstamo interbibliotecario?"
      confirmar="Guardar cambios"
      :cargando="editando"
      :deshabilitado="!edicionValida"
      @confirmar="guardarEdicion"
      @cancelar="aEditar = null"
    >
      <p class="mb-3"><strong class="text-gray-900">{{ aEditar?.ejemplar.libro.titulo }}</strong> · <span class="font-mono">{{ aEditar?.ejemplar.codigo_barras }}</span></p>
      <div class="space-y-3">
        <div>
          <label for="pib-editar-biblioteca" class="mb-1.5 block text-xs font-semibold text-gray-700">Biblioteca propietaria</label>
          <input autocomplete="off" id="pib-editar-biblioteca" v-model="edicion.biblioteca" list="pib-bibliotecas" :class="CAMPO" />
        </div>
        <div>
          <label for="pib-editar-limite" class="mb-1.5 block text-xs font-semibold text-gray-700">Vuelve a su biblioteca el</label>
          <input autocomplete="off" id="pib-editar-limite" v-model="edicion.fecha_limite" type="date" :min="aEditar?.fecha_ingreso" :class="CAMPO" />
          <p class="mt-1 text-[11px] text-gray-500">Si la biblioteca propietaria dio más plazo.</p>
        </div>
        <div>
          <label for="pib-editar-nota" class="mb-1.5 block text-xs font-semibold text-gray-700">Nota interna</label>
          <textarea id="pib-editar-nota" v-model="edicion.nota" rows="2" :class="CAMPO"></textarea>
        </div>
      </div>
      <ul v-if="cambiosEdicion.length" class="mt-3 space-y-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-xs">
        <li v-for="[que, antes, despues] in cambiosEdicion" :key="que">
          {{ que }}: <span class="text-gray-400 line-through">{{ antes }}</span> → <strong class="text-gray-900">{{ despues }}</strong>
        </li>
      </ul>
      <p v-if="prestamoFueraDelTope" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
        {{ aEditar?.prestamo_activo?.usuario }} lo tiene hasta el {{ fecha(aEditar?.prestamo_activo?.fecha_devolucion) }}, después del nuevo tope: avísale que lo devuelva antes.
      </p>
    </DialogoConfirmacion>
  </div>
</template>
