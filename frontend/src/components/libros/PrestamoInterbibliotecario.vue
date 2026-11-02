<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import Paginador from '@/components/ui/Paginador.vue'
import MultiSelectCombobox from '@/components/libros/MultiSelectCombobox.vue'
import SeccionFicha from '@/components/libros/SeccionFicha.vue'
import { TONOS_FICHA } from '@/components/libros/tonosFicha'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import type { Autor, Libro, Paginado, PrestamoInterbibliotecario, TipoMaterial, Ubicacion } from '@/types'

/**
 * Catalogación › Préstamo interbibliotecario: el material que otra biblioteca (la
 * propietaria) le presta a la nuestra. Arriba se registra lo que llega; abajo, lo que
 * está en la biblioteca y lo ya devuelto. A los usuarios se les presta hasta un día
 * hábil antes de la fecha límite; después se devuelve a su biblioteca y la copia sale
 * del catálogo, sin borrarse: sus préstamos siguen en los reportes.
 */
const props = defineProps<{ ubicaciones: Ubicacion[]; tiposMaterial: TipoMaterial[] }>()

const toast = useToast()
const hoy = new Date().toLocaleDateString('sv-SE')

const CAMPO = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none transition-shadow placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30'
const ETIQUETA = 'mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-gray-700'

/** «vie 13 nov 2026». */
const fecha = (iso: string | null | undefined) =>
  iso ? new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('es-CL', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : '—'

// ---------- Registrar lo que llega ----------
const formVacio = () => ({
  titulo: '',
  autores: [] as string[],
  isbn: '',
  editorial: '',
  anio_publicacion: '' as number | '',
  tipo_material_id: '' as number | '',
  codigo_barras: '',
  ubicacion_id: '' as number | '',
  biblioteca: '',
  fecha_ingreso: hoy,
  fecha_limite: '',
  nota: '',
})
const form = reactive(formVacio())
// El título ya está en el catálogo (se le agrega una copia) o se crea con lo básico.
const ORIGENES = [
  { id: 'nuevo' as const, label: 'Título nuevo' },
  { id: 'catalogo' as const, label: 'Ya está en el catálogo' },
]
const origen = ref<'nuevo' | 'catalogo'>('nuevo')
const libroElegido = ref<Libro | null>(null)
const busquedaLibro = ref('')
const resultadosLibro = ref<Libro[]>([])

async function buscarAutores(texto: string) {
  const { data } = await api.get<Autor[]>('/autores', { params: { q: texto || undefined } })
  return data
}

let buscarLibroTimer: ReturnType<typeof setTimeout> | undefined
function onBuscarLibro() {
  clearTimeout(buscarLibroTimer)
  buscarLibroTimer = setTimeout(async () => {
    const texto = busquedaLibro.value.trim()
    if (!texto) {
      resultadosLibro.value = []
      return
    }
    try {
      const { data } = await api.get<Paginado<Libro>>('/libros', { params: { q: texto, por_pagina: 10 } })
      if (texto === busquedaLibro.value.trim()) resultadosLibro.value = data.data
    } catch {
      resultadosLibro.value = []
    }
  }, 250)
}

function elegirLibro(libro: Libro) {
  clearTimeout(buscarLibroTimer)
  libroElegido.value = libro
  resultadosLibro.value = []
  busquedaLibro.value = libro.titulo
}

function cambiarLibro() {
  libroElegido.value = null
  busquedaLibro.value = ''
}

async function generarCodigo() {
  try {
    const { data } = await api.get<{ codigo_barras: string }>('/ejemplares/siguiente-codigo-barras')
    form.codigo_barras = data.codigo_barras
  } catch {
    toast.error('No se pudo generar un código de barras')
  }
}

// Hasta cuándo se le presta a los usuarios: lo calcula el servidor (días hábiles y días sin atención).
const topePrevisto = ref<string | null>(null)
watch(
  () => [form.fecha_limite, form.ubicacion_id],
  async () => {
    topePrevisto.value = null
    if (!form.fecha_limite) return
    const pedida = form.fecha_limite
    try {
      const { data } = await api.get<{ fecha_tope: string }>('/prestamos-interbibliotecarios/fecha-tope', {
        params: { fecha_limite: pedida, ubicacion_id: form.ubicacion_id || undefined },
      })
      if (pedida === form.fecha_limite) topePrevisto.value = data.fecha_tope
    } catch {
      topePrevisto.value = null
    }
  },
)
const topeYaPaso = computed(() => !!topePrevisto.value && topePrevisto.value < hoy)

const obligatorios = computed(() => [
  { texto: origen.value === 'catalogo' ? 'Título elegido' : 'Título', listo: origen.value === 'catalogo' ? !!libroElegido.value : !!form.titulo.trim() },
  { texto: 'Código de barras', listo: !!form.codigo_barras.trim() },
  { texto: 'Biblioteca propietaria', listo: !!form.biblioteca.trim() },
  { texto: 'Fecha de ingreso', listo: !!form.fecha_ingreso && form.fecha_ingreso <= hoy },
  { texto: 'Fecha límite (después del ingreso)', listo: !!form.fecha_limite && form.fecha_limite > form.fecha_ingreso },
])

const tituloRegistro = computed(() => (origen.value === 'catalogo' ? libroElegido.value?.titulo ?? '' : form.titulo.trim()))
const ubicacionElegida = computed(() => props.ubicaciones.find((u) => u.id === form.ubicacion_id)?.nombre ?? null)

const confirmandoRegistro = ref(false)
const guardando = ref(false)

function pedirRegistro() {
  const falta = obligatorios.value.filter((o) => !o.listo)
  if (falta.length) {
    toast.error(`Falta completar: ${falta.map((o) => o.texto).join(', ')}`)
    return
  }
  confirmandoRegistro.value = true
}

async function registrar() {
  guardando.value = true
  try {
    await api.post('/prestamos-interbibliotecarios', {
      ...(origen.value === 'catalogo'
        ? { libro_id: libroElegido.value?.id }
        : {
            titulo: form.titulo.trim(),
            autores: form.autores,
            isbn: form.isbn.trim() || null,
            editorial: form.editorial.trim() || null,
            anio_publicacion: form.anio_publicacion === '' ? null : Number(form.anio_publicacion),
            tipo_material_id: form.tipo_material_id === '' ? null : Number(form.tipo_material_id),
          }),
      codigo_barras: form.codigo_barras.trim(),
      ubicacion_id: form.ubicacion_id === '' ? null : Number(form.ubicacion_id),
      biblioteca: form.biblioteca.trim(),
      fecha_ingreso: form.fecha_ingreso,
      fecha_limite: form.fecha_limite,
      nota: form.nota.trim() || null,
    })
    toast.success(`Préstamo interbibliotecario registrado: «${tituloRegistro.value}»`)
    confirmandoRegistro.value = false
    Object.assign(form, formVacio())
    cambiarLibro()
    vista.value = 'en_biblioteca'
    pagina.value = 1
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo registrar el préstamo interbibliotecario'))
    confirmandoRegistro.value = false
  } finally {
    guardando.value = false
  }
}

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
const meta = ref<Omit<Paginado<PrestamoInterbibliotecario>, 'data'>>({ current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null })
const conteos = ref<Record<Vista, number>>({ en_biblioteca: 0, devueltos: 0, todos: 0 })
const bibliotecas = ref<string[]>([])
const cargando = ref(true)
const error = ref(false)

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<Paginado<PrestamoInterbibliotecario> & { conteos: Record<Vista, number>; bibliotecas: string[] }>('/prestamos-interbibliotecarios', {
      params: { vista: vista.value, q: busqueda.value.trim() || undefined, page: pagina.value },
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

/** Cómo está cada copia: devuelta, por devolver, prestada o disponible. */
function situacion(p: PrestamoInterbibliotecario) {
  if (p.devuelto_el) return { texto: `Devuelto el ${fecha(p.devuelto_el)}`, clase: 'bg-slate-100 text-slate-700' }
  if (p.prestamo_activo) {
    return { texto: `Prestado hasta el ${fecha(p.prestamo_activo.fecha_devolucion)}`, clase: p.vencido ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' }
  }
  if (p.vencido) return { texto: 'Plazo cumplido: devolver', clase: 'bg-red-100 text-red-700' }
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
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
      <div class="min-w-0 space-y-5">
        <p class="rounded-2xl border border-indigo-100 bg-indigo-50/60 px-4 py-3 text-sm leading-relaxed text-indigo-900">
          Material que otra biblioteca le presta a la nuestra. Se presta a los usuarios hasta <strong>un día hábil antes</strong> de la fecha en que tiene
          que volver a su biblioteca (de lunes a viernes, sin feriados ni días sin atención). Cuando se le devuelve, la copia sale del catálogo y del portal,
          pero no se borra: sus préstamos siguen en el historial y en los reportes.
        </p>

        <!-- 1. Obra -->
        <SeccionFicha id="pib-obra" :numero="1" titulo="Obra" detalle="El título que llegó" tono="indigo">
          <div class="mb-4 flex w-max max-w-full overflow-x-auto rounded-xl bg-gray-100 p-1" role="tablist" aria-label="De dónde sale el título">
            <button
              v-for="o in ORIGENES"
              :key="o.id"
              type="button"
              role="tab"
              :aria-selected="origen === o.id"
              class="whitespace-nowrap rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors"
              :class="origen === o.id ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
              @click="origen = o.id"
            >
              {{ o.label }}
            </button>
          </div>

          <div v-if="origen === 'nuevo'" class="grid grid-cols-1 gap-4 sm:grid-cols-6">
            <div class="sm:col-span-6">
              <label for="pib-titulo" :class="ETIQUETA">Título <span class="text-red-600">*</span></label>
              <input id="pib-titulo" v-model="form.titulo" placeholder="Título de la obra" :class="CAMPO" />
            </div>
            <div class="sm:col-span-6">
              <p :class="ETIQUETA">Autores</p>
              <MultiSelectCombobox v-model="form.autores" :buscar="buscarAutores" placeholder="Apellido, Nombre: escribe para buscar o crear" :clase-ficha="TONOS_FICHA.indigo.chip" />
            </div>
            <div class="sm:col-span-2">
              <label for="pib-isbn" :class="ETIQUETA">ISBN</label>
              <input id="pib-isbn" v-model="form.isbn" placeholder="978..." :class="[CAMPO, 'font-mono']" />
            </div>
            <div class="sm:col-span-2">
              <label for="pib-editorial" :class="ETIQUETA">Editorial</label>
              <input id="pib-editorial" v-model="form.editorial" placeholder="Editorial" :class="CAMPO" />
            </div>
            <div class="sm:col-span-2">
              <label for="pib-anio" :class="ETIQUETA">Año</label>
              <input id="pib-anio" v-model="form.anio_publicacion" type="number" placeholder="2020" :class="CAMPO" />
            </div>
            <div class="sm:col-span-3">
              <label for="pib-tipo" :class="ETIQUETA">Tipo de material</label>
              <select id="pib-tipo" v-model="form.tipo_material_id" :class="CAMPO">
                <option value="">Sin especificar (libro)</option>
                <option v-for="t in tiposMaterial" :key="t.id" :value="t.id">{{ t.nombre }}</option>
              </select>
            </div>
            <p class="self-end text-[11px] leading-relaxed text-gray-500 sm:col-span-3">Lo demás de la ficha se completa después en «Editar ficha», si hace falta.</p>
          </div>

          <template v-else>
            <label for="pib-buscar" :class="ETIQUETA">Título, autor, ISBN o código de barras</label>
            <div class="relative">
              <input id="pib-buscar" v-model="busquedaLibro" type="text" placeholder="Ej: Fundamentos de bases de datos" :class="CAMPO" @input="onBuscarLibro" />
              <div v-if="resultadosLibro.length" class="absolute z-10 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                <button
                  v-for="l in resultadosLibro"
                  :key="l.id"
                  type="button"
                  class="w-full border-b border-gray-100 px-3 py-2.5 text-left text-sm last:border-0 hover:bg-indigo-50"
                  @click="elegirLibro(l)"
                >
                  <p class="font-medium text-gray-900">{{ l.titulo }}</p>
                  <p class="text-xs text-gray-500">
                    <template v-if="l.autores?.length">{{ l.autores.map((a) => a.nombre).join(', ') }} · </template>{{ l.ejemplares_count ?? l.ejemplares?.length ?? 0 }} copia(s)
                  </p>
                </button>
              </div>
            </div>
            <div v-if="libroElegido" class="mt-3 flex items-start justify-between gap-3 rounded-xl border border-indigo-100 bg-indigo-50/60 p-3">
              <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900">{{ libroElegido.titulo }}</p>
                <p class="text-xs text-gray-500">Se le agrega una copia más, la de la otra biblioteca.</p>
              </div>
              <button type="button" class="shrink-0 text-xs font-medium text-indigo-700 hover:underline" @click="cambiarLibro">Cambiar</button>
            </div>
          </template>
        </SeccionFicha>

        <!-- 2. Copia -->
        <SeccionFicha id="pib-copia" :numero="2" titulo="Copia" detalle="La etiqueta y dónde queda mientras esté aquí" tono="violeta">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
              <label for="pib-codigo" :class="ETIQUETA">Código de barras <span class="text-red-600">*</span></label>
              <div class="flex gap-2">
                <input id="pib-codigo" v-model="form.codigo_barras" placeholder="30000003227566" :class="[CAMPO, 'font-mono']" />
                <button
                  type="button"
                  class="shrink-0 rounded-lg border border-gray-300 bg-white px-3 text-gray-600 transition-colors hover:bg-gray-50"
                  title="Generar el siguiente código"
                  aria-label="Generar el siguiente código de barras"
                  @click="generarCodigo"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                </button>
              </div>
              <p class="mt-1 text-[11px] text-gray-500">El de la etiqueta que se le pone aquí; no puede repetir el de otra copia.</p>
            </div>
            <div class="sm:col-span-3">
              <label for="pib-ubicacion" :class="ETIQUETA">Ubicación física</label>
              <select id="pib-ubicacion" v-model="form.ubicacion_id" :class="CAMPO">
                <option value="">Sin especificar</option>
                <option v-for="u in ubicaciones" :key="u.id" :value="u.id">{{ u.nombre }}</option>
              </select>
            </div>
          </div>
        </SeccionFicha>

        <!-- 3. Préstamo -->
        <SeccionFicha id="pib-prestamo" :numero="3" titulo="Préstamo interbibliotecario" detalle="De quién es y hasta cuándo" tono="dorado">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
            <div class="sm:col-span-6">
              <label for="pib-biblioteca" :class="ETIQUETA">Biblioteca propietaria <span class="text-red-600">*</span></label>
              <input id="pib-biblioteca" v-model="form.biblioteca" list="pib-bibliotecas" placeholder="Ej: Biblioteca Central, Universidad de Chile" :class="CAMPO" />
              <datalist id="pib-bibliotecas">
                <option v-for="b in bibliotecas" :key="b" :value="b" />
              </datalist>
            </div>
            <div class="sm:col-span-3">
              <label for="pib-ingreso" :class="ETIQUETA">Fecha de ingreso <span class="text-red-600">*</span></label>
              <input id="pib-ingreso" v-model="form.fecha_ingreso" type="date" :max="hoy" :class="CAMPO" />
              <p class="mt-1 text-[11px] text-gray-500">Cuándo llegó a la biblioteca.</p>
            </div>
            <div class="sm:col-span-3">
              <label for="pib-limite" :class="ETIQUETA">Vuelve a su biblioteca el <span class="text-red-600">*</span></label>
              <input id="pib-limite" v-model="form.fecha_limite" type="date" :min="form.fecha_ingreso || hoy" :class="CAMPO" />
              <p class="mt-1 text-[11px] text-gray-500">La fecha límite del préstamo interbibliotecario.</p>
            </div>
            <div class="sm:col-span-6">
              <label for="pib-nota" :class="ETIQUETA">Nota interna</label>
              <textarea id="pib-nota" v-model="form.nota" rows="2" placeholder="Ej: lo pidió el Dr. Pérez para su curso · solicitud n.º 1234" :class="CAMPO"></textarea>
            </div>
          </div>
        </SeccionFicha>
      </div>

      <aside class="space-y-4 lg:sticky lg:top-6 lg:self-start">
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <span class="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-dorado-600">Plazos</span>
          <dl class="mt-3 space-y-2.5 text-sm">
            <div>
              <dt class="text-xs text-gray-500">Vuelve a su biblioteca</dt>
              <dd class="font-semibold text-gray-900">{{ form.fecha_limite ? fecha(form.fecha_limite) : '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500">Los usuarios lo devuelven a más tardar</dt>
              <dd class="font-semibold" :class="topeYaPaso ? 'text-red-700' : 'text-indigo-700'">{{ topePrevisto ? fecha(topePrevisto) : '—' }}</dd>
            </div>
          </dl>
          <p v-if="topeYaPaso" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Ese día ya pasó: se puede registrar, pero no se le presta a nadie. Queda para devolverlo.
          </p>
          <p v-else class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-[11px] leading-relaxed text-gray-500">
            La copia queda «En estante», lista para prestar. En Préstamos, la fecha de devolución no puede pasar de ese día.
          </p>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <span class="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-dorado-600">Obligatorios</span>
          <ul class="mt-3 space-y-2">
            <li v-for="o in obligatorios" :key="o.texto" class="flex items-center gap-2 text-sm" :class="o.listo ? 'text-gray-800' : 'text-gray-500'">
              <svg v-if="o.listo" class="h-4 w-4 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
              </svg>
              <span v-else class="h-4 w-4 shrink-0 rounded-full border-2 border-gray-300"></span>
              {{ o.texto }}
            </li>
          </ul>
        </div>

        <button
          type="button"
          class="w-full rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-md transition hover:brightness-110 disabled:opacity-60"
          style="background: linear-gradient(135deg, #3B28A3 0%, #4F46E5 100%);"
          :disabled="guardando"
          @click="pedirRegistro"
        >
          Registrar préstamo interbibliotecario
        </button>
      </aside>
    </div>

    <!-- Lo que está en la biblioteca y lo devuelto -->
    <section class="mt-8 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
      <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h2 class="text-[15px] font-semibold text-gray-900">Material de otras bibliotecas</h2>
          <p class="text-xs text-gray-500">Lo que vence primero, arriba. «Devolver» lo saca del catálogo sin borrar sus préstamos.</p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
          <div class="flex w-max max-w-full overflow-x-auto rounded-xl bg-gray-100 p-1" role="tablist" aria-label="Qué mostrar">
            <button
              v-for="v in VISTAS"
              :key="v.id"
              type="button"
              role="tab"
              :aria-selected="vista === v.id"
              class="whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
              :class="vista === v.id ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
              @click="cambiarVista(v.id)"
            >
              {{ v.label }} <span class="ml-0.5 tabular-nums text-gray-400">{{ conteos[v.id] }}</span>
            </button>
          </div>
          <input
            v-model="busqueda"
            type="text"
            placeholder="Título, código o biblioteca"
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
          <div v-if="!p.devuelto_el" class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50" @click="pedirDevolver(p)">
              Devolver a su biblioteca
            </button>
            <button type="button" class="rounded-lg border border-indigo-200 bg-white px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50" @click="pedirEditar(p)">
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
            <tr class="border-b border-gray-200 bg-gray-50">
              <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Título</th>
              <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Biblioteca</th>
              <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Plazos</th>
              <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Situación</th>
              <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Acción</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="p in filas" :key="p.id" class="align-top transition-colors hover:bg-indigo-50/40" :class="p.vencido ? 'bg-red-50/40' : ''">
              <td class="px-5 py-3 text-sm text-gray-900">
                {{ p.ejemplar.libro.titulo }}
                <p v-if="p.ejemplar.libro.autores?.length" class="text-xs text-gray-500">{{ p.ejemplar.libro.autores.map((a) => a.nombre).join(', ') }}</p>
                <p class="mt-0.5 font-mono text-[11px] text-gray-400">c.{{ p.ejemplar.numero_copia }} · {{ p.ejemplar.codigo_barras }}</p>
              </td>
              <td class="px-5 py-3 text-sm text-gray-700">
                {{ p.biblioteca }}
                <p v-if="p.nota" class="mt-0.5 max-w-[16rem] whitespace-pre-line text-xs text-gray-500">{{ p.nota }}</p>
              </td>
              <td class="whitespace-nowrap px-5 py-3 text-xs text-gray-600">
                <p>Llegó: {{ fecha(p.fecha_ingreso) }}</p>
                <p :class="p.vencido ? 'font-semibold text-red-700' : ''">Usuarios hasta: {{ fecha(p.fecha_tope) }}</p>
                <p class="font-semibold text-gray-800">Vuelve: {{ fecha(p.fecha_limite) }}</p>
              </td>
              <td class="px-5 py-3 text-sm">
                <span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="situacion(p).clase">{{ situacion(p).texto }}</span>
                <p v-if="p.prestamo_activo" class="mt-1 text-xs text-gray-600">{{ p.prestamo_activo.usuario }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ p.total_prestamos ?? 0 }} {{ p.total_prestamos === 1 ? 'préstamo' : 'préstamos' }} a usuarios</p>
              </td>
              <td class="px-5 py-3">
                <div v-if="!p.devuelto_el" class="flex flex-col items-start gap-1">
                  <button type="button" class="text-sm font-medium text-red-700 hover:text-red-800" @click="pedirDevolver(p)">Devolver a su biblioteca</button>
                  <button type="button" class="text-sm font-medium text-indigo-700 hover:text-indigo-800" @click="pedirEditar(p)">Cambiar plazo o nota</button>
                </div>
                <p v-else class="text-xs text-gray-500">Por {{ p.devuelto_por?.nombre ?? 'sin registro' }}</p>
              </td>
            </tr>
            <tr v-if="!cargando && !filas.length">
              <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-400">
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

    <DialogoConfirmacion
      :abierto="confirmandoRegistro"
      titulo="¿Registrar este préstamo interbibliotecario?"
      confirmar="Sí, registrar"
      :cargando="guardando"
      @confirmar="registrar"
      @cancelar="confirmandoRegistro = false"
    >
      <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5">
        <p class="font-semibold text-gray-900">{{ tituloRegistro }}</p>
        <p class="text-xs">
          {{ origen === 'catalogo' ? 'Copia nueva de un título del catálogo' : 'Título nuevo' }} · <span class="font-mono">{{ form.codigo_barras.trim() }}</span
          ><template v-if="ubicacionElegida"> · {{ ubicacionElegida }}</template>
        </p>
      </div>
      <ul class="mt-3 space-y-1">
        <li>De <strong class="text-gray-900">{{ form.biblioteca.trim() }}</strong>, llegó el {{ fecha(form.fecha_ingreso) }}.</li>
        <li>Vuelve a su biblioteca el <strong class="text-gray-900">{{ fecha(form.fecha_limite) }}</strong>.</li>
        <li>Se presta a los usuarios hasta el <strong class="text-gray-900">{{ topePrevisto ? fecha(topePrevisto) : '…' }}</strong>.</li>
      </ul>
      <p v-if="topeYaPaso" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">Ese día ya pasó: no se le va a poder prestar a nadie.</p>
      <p v-else class="mt-3">La copia queda «En estante», lista para prestar.</p>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="!!aDevolver"
      :titulo="`¿Devolver a ${aDevolver?.biblioteca ?? 'su biblioteca'}?`"
      confirmar="Sí, devolver"
      tono="peligro"
      :cargando="devolviendo"
      :deshabilitado="!!aDevolver?.prestamo_activo"
      @confirmar="devolver"
      @cancelar="aDevolver = null"
    >
      <p>
        <strong class="text-gray-900">{{ aDevolver?.ejemplar.libro.titulo }}</strong> (copia {{ aDevolver?.ejemplar.numero_copia }} ·
        <span class="font-mono">{{ aDevolver?.ejemplar.codigo_barras }}</span>) vuelve a su biblioteca y deja de verse en el catálogo y el portal.
        <template v-if="!aDevolver?.total_prestamos">No se borra: queda registrado que estuvo aquí.</template>
        <template v-else-if="aDevolver.total_prestamos === 1">No se borra: su préstamo sigue en el historial y en los reportes.</template>
        <template v-else>No se borra: sus {{ aDevolver.total_prestamos }} préstamos siguen en el historial y en los reportes.</template>
      </p>
      <p v-if="aDevolver?.prestamo_activo" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
        Ahora la tiene {{ aDevolver.prestamo_activo.usuario }} (hasta el {{ fecha(aDevolver.prestamo_activo.fecha_devolucion) }}): recíbela primero en Préstamos.
      </p>
      <p v-else-if="aDevolver && !aDevolver.vencido" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
        Todavía se puede prestar hasta el {{ fecha(aDevolver.fecha_tope) }}: se devuelve antes de tiempo.
      </p>
      <div class="mt-3">
        <label for="pib-nota-devolucion" class="mb-1.5 block text-xs font-semibold text-gray-700">Nota (opcional)</label>
        <input
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
          <input id="pib-editar-biblioteca" v-model="edicion.biblioteca" list="pib-bibliotecas" :class="CAMPO" />
        </div>
        <div>
          <label for="pib-editar-limite" class="mb-1.5 block text-xs font-semibold text-gray-700">Vuelve a su biblioteca el</label>
          <input id="pib-editar-limite" v-model="edicion.fecha_limite" type="date" :min="aEditar?.fecha_ingreso" :class="CAMPO" />
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
