<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { categoriaDe, COLORES_RECURSO, ICONOS_RECURSO, useCategoriasRecurso } from '@/utils/recursos'
import { fechaCorta } from '@/utils/prestamos'
import type { Equipo } from '@/types'

const toast = useToast()
const auth = useAuthStore()

// Las categorías (notebook, bicicleta…) se administran en Administración → Catálogos.
const { categorias, cargar: cargarCategorias } = useCategoriasRecurso()
const categoriasActivas = computed(() => categorias.value.filter((c) => c.activo))
const filtroCategoria = ref('')
const visibles = computed(() => (filtroCategoria.value ? equipos.value.filter((e) => e.tipo === filtroCategoria.value) : equipos.value))

const equipos = ref<Equipo[]>([])
const cargando = ref(true)
const apiError = ref(false)

// Registro de varios recursos a la vez: una fila por recurso (nombre, código y categoría).
// Las filas vacías no cuentan; con el lector, el Enter del código salta a la fila siguiente.
type FilaNueva = { id: number; nombre: string; codigo: string; tipo: string }
type Problema = { campo: 'nombre' | 'codigo' | 'tipo'; mensaje: string }
const FILAS_INICIALES = 5
const MAXIMO_FILAS = 50
let siguienteFila = 0

/** La categoría que se está mirando o, si no, la primera activa. */
function tipoPorDefecto() {
  if (filtroCategoria.value && categoriasActivas.value.some((c) => c.clave === filtroCategoria.value)) return filtroCategoria.value
  return categoriasActivas.value[0]?.clave ?? ''
}
const filaVacia = (tipo = tipoPorDefecto()): FilaNueva => ({ id: siguienteFila++, nombre: '', codigo: '', tipo })
const filasIniciales = () => Array.from({ length: FILAS_INICIALES }, () => filaVacia())
const estaVacia = (f: FilaNueva) => !f.nombre.trim() && !f.codigo.trim()

const filas = ref<FilaNueva[]>(filasIniciales())
const llenas = computed(() => filas.value.map((f, i) => ({ ...f, i })).filter((f) => !estaVacia(f)))
const grilla = ref<HTMLElement | null>(null)

// Las filas sin escribir siguen a la categoría que se está mirando.
watch([categoriasActivas, filtroCategoria], () => {
  const tipo = tipoPorDefecto()
  for (const f of filas.value) {
    if (estaVacia(f) || !categoriasActivas.value.some((c) => c.clave === f.tipo)) f.tipo = tipo
  }
})

/** Al cambiar la categoría de una fila, las vacías de más abajo la siguen (cinco notebooks de una vez). */
function alCambiarCategoria(i: number) {
  for (const f of filas.value.slice(i + 1)) if (estaVacia(f)) f.tipo = filas.value[i].tipo
}

function agregarFila() {
  if (filas.value.length < MAXIMO_FILAS) filas.value.push(filaVacia(filas.value[filas.value.length - 1]?.tipo))
}

function quitarFila(i: number) {
  filas.value.splice(i, 1)
  if (!filas.value.length) filas.value.push(filaVacia())
}

function enfocar(i: number, campo: 'nombre' | 'codigo') {
  grilla.value?.querySelector<HTMLInputElement>(`[data-fila="${i}"][data-campo="${campo}"]`)?.focus()
}

/** Enter: del nombre al código y del código a la fila siguiente (si era la última, agrega una). */
async function siguienteCampo(i: number, campo: 'nombre' | 'codigo') {
  if (campo === 'nombre') return enfocar(i, 'codigo')
  if (i === filas.value.length - 1) {
    agregarFila()
    await nextTick()
  }
  enfocar(i + 1, 'nombre')
}

async function agregarYEnfocar() {
  agregarFila()
  await nextTick()
  enfocar(filas.value.length - 1, 'nombre')
}

const intentoRegistrar = ref(false)
const erroresServidor = ref<Record<number, Problema>>({})
watch(filas, () => (erroresServidor.value = {}), { deep: true })

/** Lo que impide registrar cada fila (por id). Lo repetido se avisa al tiro; lo que falta, al registrar. */
const problemas = computed(() => {
  const resultado: Record<number, Problema & { siempre: boolean }> = {}
  const porNombre = new Map(equipos.value.map((e) => [e.codigo_inventario, e]))
  const porCodigo = new Map(equipos.value.map((e) => [e.codigo_barras, e]))
  const nombresVistos = new Map<string, number>()
  const codigosVistos = new Map<string, number>()
  for (const f of llenas.value) {
    const nombre = f.nombre.trim()
    const codigo = f.codigo.trim()
    let p: (Problema & { siempre: boolean }) | null = null
    if (codigo && porCodigo.has(codigo)) p = { campo: 'codigo', mensaje: `Ese código de barras ya es de ${porCodigo.get(codigo)!.codigo_inventario}`, siempre: true }
    else if (codigo && codigosVistos.has(codigo)) p = { campo: 'codigo', mensaje: `Código repetido: ya está en la fila ${codigosVistos.get(codigo)! + 1}`, siempre: true }
    else if (nombre && porNombre.has(nombre)) p = { campo: 'nombre', mensaje: 'Ya hay un recurso con ese nombre', siempre: true }
    else if (nombre && nombresVistos.has(nombre)) p = { campo: 'nombre', mensaje: `Nombre repetido: ya está en la fila ${nombresVistos.get(nombre)! + 1}`, siempre: true }
    else if (!nombre) p = { campo: 'nombre', mensaje: 'Falta el nombre', siempre: false }
    else if (!codigo) p = { campo: 'codigo', mensaje: 'Falta el código de barras', siempre: false }
    else if (!f.tipo) p = { campo: 'tipo', mensaje: 'Elige la categoría', siempre: false }
    if (p) resultado[f.id] = p
    if (nombre && !nombresVistos.has(nombre)) nombresVistos.set(nombre, f.i)
    if (codigo && !codigosVistos.has(codigo)) codigosVistos.set(codigo, f.i)
  }
  return resultado
})

function problemaDe(f: FilaNueva): Problema | null {
  const p = problemas.value[f.id]
  if (p && (p.siempre || intentoRegistrar.value)) return p
  return erroresServidor.value[f.id] ?? null
}
const bordeDe = (f: FilaNueva, campo: Problema['campo']) => (problemaDe(f)?.campo === campo ? 'border-red-400 ring-1 ring-red-300' : 'border-gray-300')

const textoRegistrar = computed(() => {
  const n = llenas.value.length
  return n === 0 ? 'Registrar' : n === 1 ? 'Registrar 1 recurso' : `Registrar ${n} recursos`
})

const creando = ref(false)
const confirmandoCrear = ref(false)

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<Equipo[]>('/equipos')
    equipos.value = data
    apiError.value = false
  } catch {
    apiError.value = true
    equipos.value = []
  } finally {
    cargando.value = false
  }
}

onMounted(() => {
  cargar()
  cargarCategorias()
})

function pedirConfirmacionCrear() {
  intentoRegistrar.value = true
  if (!llenas.value.length) {
    toast.error('Escriba al menos un recurso: su nombre y su código de barras')
    enfocar(0, 'nombre')
    return
  }
  if (Object.keys(problemas.value).length) {
    toast.error('Revise las filas marcadas en rojo')
    return
  }
  confirmandoCrear.value = true
}

async function crearEquipos() {
  const enviadas = llenas.value
  creando.value = true
  try {
    await api.post('/equipos/lote', {
      recursos: enviadas.map((f) => ({ codigo_inventario: f.nombre.trim(), codigo_barras: f.codigo.trim(), tipo: f.tipo })),
    })
    toast.success(enviadas.length === 1 ? 'Recurso registrado' : `${enviadas.length} recursos registrados`)
    filas.value = filasIniciales()
    intentoRegistrar.value = false
    await cargar()
  } catch (e: any) {
    // Los errores vienen por posición en la lista enviada («recursos.2.codigo_barras»): se marcan en
    // su fila de la pantalla, sin el «Fila N» del servidor, que no cuenta las filas vacías.
    const porFila: Record<number, Problema> = {}
    const CAMPOS = { codigo_inventario: 'nombre', codigo_barras: 'codigo', tipo: 'tipo' } as const
    for (const [clave, mensajes] of Object.entries<string[]>(e?.response?.data?.errors ?? {})) {
      const m = clave.match(/^recursos\.(\d+)\.(codigo_inventario|codigo_barras|tipo)$/)
      const fila = m ? enviadas[Number(m[1])] : undefined
      if (!m || !fila || porFila[fila.id]) continue
      const mensaje = mensajes[0].replace(/^Fila \d+: /, '').replace(/\.$/, '')
      porFila[fila.id] = { campo: CAMPOS[m[2] as keyof typeof CAMPOS], mensaje: mensaje.charAt(0).toUpperCase() + mensaje.slice(1) }
    }
    erroresServidor.value = porFila
    toast.error(Object.keys(porFila).length ? 'Revise las filas marcadas en rojo' : mensajeDeError(e, 'No se pudieron registrar los recursos'))
  } finally {
    creando.value = false
    confirmandoCrear.value = false
  }
}

const equipoACambiar = ref<Equipo | null>(null)
const cambiandoActivo = ref(false)

// Dar de baja pide el motivo: se ve en el inventario y queda en la bitácora.
const MOTIVOS_FRECUENTES = ['Dañado, sin reparación', 'Extraviado', 'Robado', 'Obsoleto o fuera de uso']
const motivoBaja = ref('')
const campoMotivo = ref<HTMLTextAreaElement | null>(null)

function pedirCambioActivo(equipo: Equipo) {
  motivoBaja.value = ''
  equipoACambiar.value = equipo
}

/** Un motivo frecuente queda escrito y el cursor al final, por si se quiere agregar el detalle. */
async function elegirMotivo(motivo: string) {
  motivoBaja.value = motivo
  await nextTick()
  campoMotivo.value?.focus()
  campoMotivo.value?.setSelectionRange(motivo.length, motivo.length)
}

async function cambiarActivo() {
  const equipo = equipoACambiar.value
  if (!equipo) return
  cambiandoActivo.value = true
  try {
    await api.patch(`/equipos/${equipo.id}/activo`, equipo.activo ? { activo: false, motivo: motivoBaja.value.trim() } : { activo: true })
    toast.success(equipo.activo ? 'Recurso dado de baja' : 'Recurso reactivado')
    await cargar()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo actualizar el recurso')
  } finally {
    cambiandoActivo.value = false
    equipoACambiar.value = null
  }
}

const nombreCategoria = (clave: string) => categoriaDe(clave)?.nombre ?? clave
const fondoDe = (clave: string) => (COLORES_RECURSO[categoriaDe(clave)?.color ?? 'slate'] ?? COLORES_RECURSO.slate).fondo
const iconoDe = (clave: string) => (ICONOS_RECURSO[categoriaDe(clave)?.icono ?? 'caja'] ?? ICONOS_RECURSO.caja).trazo
const cuantos = (clave: string) => equipos.value.filter((e) => e.tipo === clave && e.activo).length
</script>

<template>
  <StaffLayout>
    <div class="max-w-4xl mx-auto">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Recursos de apoyo</h1>
          <p class="text-sm text-white/60 mt-1">Inventario de recursos no bibliográficos para préstamo: notebooks, audífonos, cargadores, bicicletas…</p>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-md p-6 mb-6">
        <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
          <h4 class="text-sm font-semibold text-gray-700">Registrar recursos nuevos</h4>
          <router-link
            v-if="auth.puede('catalogos.gestionar')"
            :to="{ name: 'administracion', query: { seccion: 'catalogos' } }"
            class="text-xs font-semibold text-indigo-700 hover:underline"
          >¿Falta una categoría? Créala en Administración →</router-link>
        </div>
        <p class="mb-4 text-xs text-gray-500">
          Una fila por recurso; las que quedan vacías no se registran. Con el lector, al escanear el código se pasa solo a la fila siguiente.
        </p>

        <div ref="grilla">
          <div class="hidden sm:flex items-end gap-2 pb-1.5 text-xs font-medium text-gray-600">
            <span class="w-5 shrink-0"></span>
            <div class="grid flex-1 grid-cols-[minmax(0,1fr)_minmax(0,1fr)_11rem] gap-2">
              <span>Nombre / identificador</span>
              <span>Código de barras</span>
              <span>Categoría</span>
            </div>
            <span class="w-8 shrink-0"></span>
          </div>
          <div class="space-y-3 sm:space-y-2">
            <div v-for="(f, i) in filas" :key="f.id" :class="i ? 'border-t border-gray-100 pt-3 sm:border-0 sm:pt-0' : ''">
              <div class="flex items-start gap-2">
                <span class="mt-3 w-5 shrink-0 text-right text-xs font-semibold tabular-nums text-gray-400">{{ i + 1 }}</span>
                <div class="grid min-w-0 flex-1 grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_11rem]">
                  <input
                    v-model="f.nombre"
                    :data-fila="i"
                    data-campo="nombre"
                    :aria-label="`Fila ${i + 1}: nombre o identificador`"
                    :placeholder="i ? 'Nombre' : 'Ej: Notebook 06'"
                    maxlength="255"
                    class="w-full rounded-lg border px-3 py-2 font-mono text-sm outline-none placeholder:font-sans focus:ring-2 focus:ring-indigo-500"
                    :class="bordeDe(f, 'nombre')"
                    @keydown.enter.prevent="siguienteCampo(i, 'nombre')"
                  />
                  <input
                    v-model="f.codigo"
                    :data-fila="i"
                    data-campo="codigo"
                    :aria-label="`Fila ${i + 1}: código de barras`"
                    :placeholder="i ? 'Código de barras' : 'Escanee o escriba el código'"
                    maxlength="255"
                    class="w-full rounded-lg border px-3 py-2 font-mono text-sm outline-none placeholder:font-sans focus:ring-2 focus:ring-indigo-500"
                    :class="bordeDe(f, 'codigo')"
                    @keydown.enter.prevent="siguienteCampo(i, 'codigo')"
                  />
                  <select
                    v-model="f.tipo"
                    :aria-label="`Fila ${i + 1}: categoría`"
                    class="w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                    :class="bordeDe(f, 'tipo')"
                    @change="alCambiarCategoria(i)"
                  >
                    <option v-for="c in categoriasActivas" :key="c.clave" :value="c.clave">{{ c.nombre }}</option>
                  </select>
                </div>
                <button
                  type="button"
                  class="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700"
                  :aria-label="`Quitar la fila ${i + 1}`"
                  title="Quitar esta fila"
                  @click="quitarFila(i)"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
              <p v-if="problemaDe(f)" class="ml-7 mt-1 text-xs font-medium text-red-600">{{ problemaDe(f)!.mensaje }}</p>
            </div>
          </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
          <button
            type="button"
            class="text-sm font-semibold text-indigo-700 hover:underline disabled:cursor-not-allowed disabled:opacity-40 disabled:no-underline"
            :disabled="filas.length >= MAXIMO_FILAS"
            :title="filas.length >= MAXIMO_FILAS ? `Hasta ${MAXIMO_FILAS} recursos de una vez` : undefined"
            @click="agregarYEnfocar"
          >
            + Agregar otra fila
          </button>
          <button
            @click="pedirConfirmacionCrear"
            :disabled="creando || !llenas.length"
            class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium disabled:opacity-60 disabled:cursor-not-allowed"
          >
            {{ textoRegistrar }}
          </button>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="mb-3 flex flex-wrap gap-1.5" role="group" aria-label="Filtrar por categoría">
        <button
          type="button"
          class="rounded-full px-3 py-1 text-xs font-medium ring-1 transition"
          :class="!filtroCategoria ? 'bg-indigo-600 text-white ring-transparent' : 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50'"
          @click="filtroCategoria = ''"
        >
          Todas ({{ equipos.filter((e) => e.activo).length }})
        </button>
        <button
          v-for="c in categorias"
          :key="c.clave"
          type="button"
          class="rounded-full px-3 py-1 text-xs font-medium ring-1 transition"
          :class="filtroCategoria === c.clave ? 'bg-indigo-600 text-white ring-transparent' : 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50'"
          @click="filtroCategoria = c.clave"
        >
          {{ c.nombre_plural }} ({{ cuantos(c.clave) }}){{ c.activo ? '' : ' · inactiva' }}
        </button>
      </div>

      <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="bg-gray-100 border-b-2 border-gray-200">
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Nombre</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Código de barras</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Categoría</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Disponible</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Estado</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              <tr v-for="(e, idx) in visibles" :key="e.id" :class="idx % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                <td class="px-6 py-3 text-sm font-mono text-gray-900">{{ e.codigo_inventario }}</td>
                <td class="px-6 py-3 text-sm font-mono text-gray-500">{{ e.codigo_barras }}</td>
                <td class="px-6 py-3 text-sm text-gray-600">
                  <span class="inline-flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-md" :class="fondoDe(e.tipo)">
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" :d="iconoDe(e.tipo)" /></svg>
                    </span>
                    {{ nombreCategoria(e.tipo) }}
                  </span>
                </td>
                <td class="px-6 py-3">
                  <span
                    class="text-xs px-2.5 py-1 rounded-full font-medium"
                    :class="e.disponible ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'"
                  >
                    {{ e.disponible ? 'Sí' : 'No' }}
                  </span>
                </td>
                <td class="px-6 py-3">
                  <span
                    class="text-xs px-2.5 py-1 rounded-full font-medium whitespace-nowrap"
                    :class="e.activo ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-700'"
                  >
                    {{ e.activo ? 'Activo' : 'De baja' }}
                  </span>
                  <template v-if="!e.activo && (e.motivo_baja || e.dado_de_baja_el)">
                    <p v-if="e.motivo_baja" class="mt-1.5 max-w-[16rem] text-xs text-gray-600 line-clamp-2" :title="e.motivo_baja">{{ e.motivo_baja }}</p>
                    <p v-if="e.dado_de_baja_el" class="mt-0.5 text-[11px] text-gray-400">Desde el {{ fechaCorta(e.dado_de_baja_el) }}</p>
                  </template>
                </td>
                <td class="px-6 py-3">
                  <button
                    @click="pedirCambioActivo(e)"
                    :disabled="e.activo && !e.disponible"
                    :title="e.activo && !e.disponible ? 'No se puede dar de baja un recurso que está prestado' : ''"
                    class="text-sm font-medium disabled:opacity-40 disabled:cursor-not-allowed"
                    :class="e.activo ? 'text-red-600 hover:text-red-700' : 'text-indigo-700 hover:text-indigo-800'"
                  >
                    {{ e.activo ? 'Dar de baja' : 'Reactivar' }}
                  </button>
                </td>
              </tr>
              <tr v-if="!cargando && !visibles.length">
                <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-400">Sin recursos registrados{{ filtroCategoria ? ' en esta categoría' : '' }}.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <DialogoConfirmacion
      :abierto="confirmandoCrear"
      :titulo="llenas.length === 1 ? '¿Registrar este recurso?' : `¿Registrar estos ${llenas.length} recursos?`"
      confirmar="Sí, registrar"
      :cargando="creando"
      @confirmar="crearEquipos"
      @cancelar="confirmandoCrear = false"
    >
      <p>{{ llenas.length === 1 ? 'Se agregará al inventario y quedará disponible' : 'Se agregarán al inventario y quedarán disponibles' }} para préstamo:</p>
      <ul class="mt-3 max-h-64 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200">
        <li v-for="f in llenas" :key="f.id" class="flex items-center justify-between gap-3 px-3 py-2">
          <span class="min-w-0">
            <span class="block truncate font-mono font-medium text-gray-900">{{ f.nombre.trim() }}</span>
            <span class="block text-xs text-gray-500">{{ nombreCategoria(f.tipo) }}</span>
          </span>
          <span class="shrink-0 font-mono text-xs text-gray-600">{{ f.codigo.trim() }}</span>
        </li>
      </ul>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="!!equipoACambiar"
      :titulo="equipoACambiar?.activo ? '¿Dar de baja este recurso?' : '¿Reactivar este recurso?'"
      :confirmar="equipoACambiar?.activo ? 'Sí, dar de baja' : 'Sí, reactivar'"
      :tono="equipoACambiar?.activo ? 'peligro' : 'exito'"
      :cargando="cambiandoActivo"
      :deshabilitado="!!equipoACambiar?.activo && !motivoBaja.trim()"
      @confirmar="cambiarActivo"
      @cancelar="equipoACambiar = null"
    >
      <template v-if="equipoACambiar?.activo">
        <p>
          <strong class="text-gray-900">{{ equipoACambiar.codigo_inventario }}</strong> ({{ nombreCategoria(equipoACambiar.tipo).toLowerCase() }})
          dejará de ofrecerse para préstamo. Su historial se conserva y puedes reactivarlo cuando quieras.
        </p>
        <p class="mt-2">
          Estado: <span class="line-through">Activo</span> → <strong class="text-gray-900">De baja</strong>
        </p>
        <label for="motivo-baja" class="mt-4 mb-1.5 block font-medium text-gray-700">Motivo de la baja</label>
        <div class="mb-2 flex flex-wrap gap-1.5">
          <button
            v-for="m in MOTIVOS_FRECUENTES"
            :key="m"
            type="button"
            class="rounded-full px-2.5 py-1 text-xs font-medium ring-1 transition"
            :class="motivoBaja === m ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50'"
            @click="elegirMotivo(m)"
          >
            {{ m }}
          </button>
        </div>
        <textarea
          id="motivo-baja"
          ref="campoMotivo"
          v-model="motivoBaja"
          rows="3"
          maxlength="500"
          placeholder="Ej: la pantalla se trizó y el servicio técnico dijo que no tiene arreglo"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm text-gray-900"
        />
        <p class="mt-1 text-xs text-gray-400">Se verá en el inventario y queda en la bitácora con tu nombre.</p>
      </template>
      <template v-else-if="equipoACambiar">
        <p><strong class="text-gray-900">{{ equipoACambiar.codigo_inventario }}</strong> volverá a estar disponible para préstamo.</p>
        <p v-if="equipoACambiar.motivo_baja" class="mt-2">Se había dado de baja por: «{{ equipoACambiar.motivo_baja }}».</p>
      </template>
    </DialogoConfirmacion>
  </StaffLayout>
</template>
