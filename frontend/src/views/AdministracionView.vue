<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import GestionEstudiantes from '@/components/administracion/GestionEstudiantes.vue'
import CategoriasRecurso from '@/components/administracion/CategoriasRecurso.vue'
import ReglasPrestamo from '@/components/administracion/ReglasPrestamo.vue'
import DiasSinAtencion from '@/components/administracion/DiasSinAtencion.vue'
import FondoAdministracion from '@/components/administracion/FondoAdministracion.vue'
import CopiasDadasDeBaja from '@/components/administracion/CopiasDadasDeBaja.vue'
import PoliticasVigencia from '@/components/administracion/PoliticasVigencia.vue'
import MantenedorSalas from '@/components/administracion/MantenedorSalas.vue'
import TiempoPresentarseSalas from '@/components/administracion/TiempoPresentarseSalas.vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import type { ConfiguracionInstitucional, EstadoLibroPersonalizado, PlantillaFichaClave, TipoMaterial, Ubicacion } from '@/types'

const toast = useToast()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const apiError = ref(false)
const cargando = ref(true)

// Cada sección exige su propio permiso: alguien puede administrar los catálogos
// (ubicaciones, tipos de material…) sin poder cambiar quién firma la constancia.
type Seccion = 'institucion' | 'catalogos' | 'circulacion' | 'salas' | 'estudiantes' | 'vigencia' | 'eliminados'
const SECCIONES: { clave: Seccion; label: string; detalle?: string; permiso: string }[] = [
  { clave: 'institucion', label: 'Configuración', detalle: 'institucional', permiso: 'configuracion.editar' },
  { clave: 'catalogos', label: 'Catálogos', detalle: 'estados, ubicaciones, tipos y recursos', permiso: 'catalogos.gestionar' },
  { clave: 'circulacion', label: 'Reglas de préstamo', detalle: 'y días sin atención', permiso: 'catalogos.gestionar' },
  { clave: 'salas', label: 'Salas', permiso: 'catalogos.gestionar' },
  { clave: 'estudiantes', label: 'Usuarios', permiso: 'usuarios.gestionar' },
  { clave: 'vigencia', label: 'Vigencia', detalle: 'de cuentas', permiso: 'vigencia.gestionar' },
  { clave: 'eliminados', label: 'Eliminados', permiso: 'libros.estado' },
]
const secciones = computed(() => SECCIONES.filter((s) => auth.puede(s.permiso)))
const seccion = ref<Seccion>('institucion')

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}

function elegirSeccion(clave: Seccion) {
  seccion.value = clave
  router.replace({ query: { ...route.query, seccion: clave } })
}

const configuracion = reactive({ jefe_unidad_nombre: '', jefe_unidad_cargo: '' })
const configuracionOriginal = reactive({ jefe_unidad_nombre: '', jefe_unidad_cargo: '' })
const guardandoConfig = ref(false)
const confirmandoConfig = ref(false)

const estados = ref<EstadoLibroPersonalizado[]>([])
const nuevoEstado = reactive({ nombre: '', descripcion: '' })
const creandoEstado = ref(false)
const confirmandoCrearEstado = ref(false)
const estadoADesactivar = ref<EstadoLibroPersonalizado | null>(null)
const desactivandoEstado = ref(false)

const ubicaciones = ref<Ubicacion[]>([])
const nuevaUbicacion = reactive({ nombre: '' })
const creandoUbicacion = ref(false)
const confirmandoCrearUbicacion = ref(false)

const tiposMaterial = ref<TipoMaterial[]>([])
const nuevoTipoMaterial = reactive({ nombre: '', plantilla: 'libro' as PlantillaFichaClave })

// Qué ficha bibliográfica usa cada tipo: qué etiquetas MARC lleva y cuáles ve el usuario.
const PLANTILLAS: Record<PlantillaFichaClave, string> = {
  libro: 'Libro',
  trabajo_titulacion: 'Trabajo de titulación',
  juego_educativo: 'Juego educativo',
  publicacion_seriada: 'Publicación seriada (revista)',
}
const creandoTipoMaterial = ref(false)
const confirmandoCrearTipoMaterial = ref(false)

async function cargar() {
  cargando.value = true
  try {
    const [configRes, estadosRes, ubicacionesRes, tiposMaterialRes] = await Promise.all([
      api.get<ConfiguracionInstitucional>('/configuracion'),
      api.get<EstadoLibroPersonalizado[]>('/estados-libro-personalizados'),
      api.get<Ubicacion[]>('/ubicaciones', { params: { con_copias: 1 } }),
      api.get<TipoMaterial[]>('/tipos-material'),
    ])
    Object.assign(configuracion, configRes.data)
    Object.assign(configuracionOriginal, configRes.data)
    estados.value = estadosRes.data
    ubicaciones.value = ubicacionesRes.data
    tiposMaterial.value = tiposMaterialRes.data
    apiError.value = false
  } catch {
    apiError.value = true
  } finally {
    cargando.value = false
  }
}

onMounted(() => {
  const pedida = route.query.seccion as Seccion | undefined
  seccion.value = secciones.value.find((s) => s.clave === pedida)?.clave ?? secciones.value[0]?.clave ?? 'institucion'
  cargar()
})

function pedirConfirmacionConfig() {
  if (!configuracion.jefe_unidad_nombre.trim() || !configuracion.jefe_unidad_cargo.trim()) {
    toast.error('Completa nombre y cargo')
    return
  }
  confirmandoConfig.value = true
}

async function guardarConfiguracion() {
  guardandoConfig.value = true
  try {
    await api.put('/configuracion', configuracion)
    Object.assign(configuracionOriginal, configuracion)
    toast.success('Configuración institucional actualizada')
    confirmandoConfig.value = false
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo guardar'))
  } finally {
    guardandoConfig.value = false
  }
}

function pedirConfirmacionCrearEstado() {
  if (!nuevoEstado.nombre.trim()) {
    toast.error('Ingresa un nombre para el estado personalizado')
    return
  }
  confirmandoCrearEstado.value = true
}

async function crearEstado() {
  creandoEstado.value = true
  try {
    const { data } = await api.post<EstadoLibroPersonalizado>('/estados-libro-personalizados', {
      nombre: nuevoEstado.nombre.trim(),
      descripcion: nuevoEstado.descripcion.trim() || null,
    })
    estados.value.push(data)
    nuevoEstado.nombre = ''
    nuevoEstado.descripcion = ''
    confirmandoCrearEstado.value = false
    toast.success('Estado personalizado creado')
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo crear el estado'))
  } finally {
    creandoEstado.value = false
  }
}

function pedirConfirmacionCrearUbicacion() {
  if (!nuevaUbicacion.nombre.trim()) {
    toast.error('Ingresa un nombre para la ubicación')
    return
  }
  confirmandoCrearUbicacion.value = true
}

async function crearUbicacion() {
  creandoUbicacion.value = true
  try {
    const { data } = await api.post<Ubicacion>('/ubicaciones', { nombre: nuevaUbicacion.nombre.trim() })
    ubicaciones.value.push({ ...data, copias: 0 })
    ubicaciones.value.sort((a, b) => a.nombre.localeCompare(b.nombre))
    nuevaUbicacion.nombre = ''
    confirmandoCrearUbicacion.value = false
    toast.success('Ubicación creada')
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo crear la ubicación'))
  } finally {
    creandoUbicacion.value = false
  }
}

// Editar el nombre de una ubicación: sus copias la siguen teniendo.
const edicionUbicacion = reactive({ ubicacion: null as Ubicacion | null, nombre: '', guardando: false })

function abrirEdicionUbicacion(u: Ubicacion) {
  Object.assign(edicionUbicacion, { ubicacion: u, nombre: u.nombre, guardando: false })
}

async function guardarUbicacion() {
  const u = edicionUbicacion.ubicacion
  const nombre = edicionUbicacion.nombre.trim()
  if (!u) return
  if (!nombre) {
    toast.error('Ingresa un nombre para la ubicación')
    return
  }
  if (nombre === u.nombre) {
    edicionUbicacion.ubicacion = null
    return
  }
  edicionUbicacion.guardando = true
  try {
    const { data } = await api.patch<Ubicacion>(`/ubicaciones/${u.id}`, { nombre })
    u.nombre = data.nombre
    ubicaciones.value.sort((a, b) => a.nombre.localeCompare(b.nombre))
    edicionUbicacion.ubicacion = null
    toast.success('Ubicación actualizada')
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo cambiar el nombre de la ubicación'))
  } finally {
    edicionUbicacion.guardando = false
  }
}

// Eliminar: si tiene copias, primero se elige a qué ubicación pasan.
const eliminacionUbicacion = reactive({ ubicacion: null as Ubicacion | null, moverA: '' as number | '', eliminando: false })
const otrasUbicaciones = computed(() => ubicaciones.value.filter((u) => u.id !== eliminacionUbicacion.ubicacion?.id))

function pedirEliminarUbicacion(u: Ubicacion) {
  Object.assign(eliminacionUbicacion, { ubicacion: u, moverA: '', eliminando: false })
}

async function eliminarUbicacion() {
  const u = eliminacionUbicacion.ubicacion
  if (!u) return
  eliminacionUbicacion.eliminando = true
  try {
    await api.delete(`/ubicaciones/${u.id}`, { data: u.copias ? { mover_a: eliminacionUbicacion.moverA } : {} })
    const destino = ubicaciones.value.find((x) => x.id === eliminacionUbicacion.moverA)
    if (destino && u.copias) destino.copias = (destino.copias ?? 0) + u.copias
    ubicaciones.value = ubicaciones.value.filter((x) => x.id !== u.id)
    eliminacionUbicacion.ubicacion = null
    toast.success(destino && u.copias ? `Ubicación eliminada: sus copias pasaron a «${destino.nombre}»` : 'Ubicación eliminada')
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo eliminar la ubicación'))
  } finally {
    eliminacionUbicacion.eliminando = false
  }
}

function pedirConfirmacionCrearTipoMaterial() {
  if (!nuevoTipoMaterial.nombre.trim()) {
    toast.error('Ingresa un nombre para el tipo de material')
    return
  }
  confirmandoCrearTipoMaterial.value = true
}

async function crearTipoMaterial() {
  creandoTipoMaterial.value = true
  try {
    const { data } = await api.post<TipoMaterial>('/tipos-material', {
      nombre: nuevoTipoMaterial.nombre.trim(),
      plantilla: nuevoTipoMaterial.plantilla,
    })
    tiposMaterial.value.push(data)
    tiposMaterial.value.sort((a, b) => a.nombre.localeCompare(b.nombre))
    Object.assign(nuevoTipoMaterial, { nombre: '', plantilla: 'libro' })
    confirmandoCrearTipoMaterial.value = false
    toast.success('Tipo de material creado')
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo crear el tipo de material'))
  } finally {
    creandoTipoMaterial.value = false
  }
}

// El select vuelve a la ficha actual hasta que se confirme el cambio.
const plantillaPendiente = ref<{ tipo: TipoMaterial; plantilla: PlantillaFichaClave } | null>(null)
const cambiandoPlantilla = ref(false)

function pedirCambioPlantilla(tipo: TipoMaterial, evento: Event) {
  const select = evento.target as HTMLSelectElement
  const plantilla = select.value as PlantillaFichaClave
  select.value = tipo.plantilla
  plantillaPendiente.value = { tipo, plantilla }
}

async function confirmarCambioPlantilla() {
  const pendiente = plantillaPendiente.value
  if (!pendiente) return
  cambiandoPlantilla.value = true
  try {
    await cambiarPlantilla(pendiente.tipo, pendiente.plantilla)
  } finally {
    cambiandoPlantilla.value = false
    plantillaPendiente.value = null
  }
}

async function cambiarPlantilla(tipo: TipoMaterial, plantilla: PlantillaFichaClave) {
  const anterior = tipo.plantilla
  tipo.plantilla = plantilla
  try {
    await api.patch(`/tipos-material/${tipo.id}`, { plantilla })
    toast.success(`«${tipo.nombre}» usa ahora la ficha de ${PLANTILLAS[plantilla]}`)
  } catch (e) {
    tipo.plantilla = anterior
    toast.error(mensajeDeError(e, 'No se pudo cambiar la ficha del tipo de material'))
  }
}

async function aplicarCambioActivo(estado: EstadoLibroPersonalizado, activo: boolean) {
  try {
    const { data } = await api.patch<EstadoLibroPersonalizado>(`/estados-libro-personalizados/${estado.id}/activo`, { activo })
    const idx = estados.value.findIndex((e) => e.id === estado.id)
    if (idx !== -1) estados.value[idx] = data
  } catch {
    toast.error('No se pudo cambiar el estado')
  }
}

// Desactivar pide confirmación (deja de ofrecerse para libros nuevos); reactivar es
// la dirección "segura" y no la necesita.
function toggleActivo(estado: EstadoLibroPersonalizado) {
  if (estado.activo) {
    estadoADesactivar.value = estado
    return
  }
  aplicarCambioActivo(estado, true)
}

async function confirmarDesactivar() {
  if (!estadoADesactivar.value) return
  desactivandoEstado.value = true
  try {
    await aplicarCambioActivo(estadoADesactivar.value, false)
    estadoADesactivar.value = null
  } finally {
    desactivandoEstado.value = false
  }
}
</script>

<template>
  <StaffLayout>
    <div class="max-w-6xl mx-auto">
      <!-- La caja de título con motivo propio: el frontis de la universidad. -->
      <div
        class="relative mb-5 overflow-hidden rounded-2xl shadow-md"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <FondoAdministracion />
        <div class="relative px-6 py-4">
          <nav class="text-xs text-white/75" aria-label="Ruta">
            <button type="button" class="font-medium text-white hover:underline" @click="abrirMenuGestion">Gestión</button>
            <span class="mx-1.5">›</span>
            <span>Administración del sistema</span>
          </nav>
          <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Administración</h1>
          <p class="mt-0.5 max-w-lg text-sm text-white/75">Configuración institucional, catálogos administrables y estudiantes</p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <!-- Pestañas de carpeta: la elegida queda blanca, con el borde índigo arriba. -->
      <div v-if="secciones.length > 1" class="mb-4 flex gap-1 overflow-x-auto border-b border-gray-200" role="tablist">
        <button
          v-for="s in secciones"
          :key="s.clave"
          type="button"
          role="tab"
          :aria-selected="seccion === s.clave"
          @click="elegirSeccion(s.clave)"
          class="relative -mb-px min-w-[6.5rem] flex-1 rounded-t-xl border border-gray-200 px-3 py-2 text-center transition-colors"
          :class="seccion === s.clave
            ? 'border-t-2 border-b-white border-t-indigo-600 bg-white text-indigo-900'
            : 'mt-1 bg-indigo-50/60 text-gray-600 hover:bg-indigo-50 hover:text-gray-900'"
        >
          <span class="block text-sm font-semibold leading-tight">{{ s.label }}</span>
          <span v-if="s.detalle" :title="s.detalle" class="mt-0.5 hidden truncate text-[11px] leading-tight sm:block" :class="seccion === s.clave ? 'text-gray-500' : 'text-gray-400'">{{ s.detalle }}</span>
        </button>
      </div>

      <div v-if="!cargando" class="space-y-5">
        <GestionEstudiantes v-if="seccion === 'estudiantes'" />
        <PoliticasVigencia v-if="seccion === 'vigencia'" />
        <template v-if="seccion === 'salas'">
          <MantenedorSalas />
          <TiempoPresentarseSalas />
        </template>
        <CopiasDadasDeBaja v-if="seccion === 'eliminados'" />

        <section v-if="seccion === 'institucion'" class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
          <header class="border-b border-gray-100 bg-indigo-50/40 px-5 py-3">
            <h3 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
              <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
              Configuración institucional
            </h3>
            <p class="mt-0.5 pl-3 text-xs text-gray-500">
              Nombre y cargo de quien firma la Constancia de No Multa (Usuarios → Constancia y portal de autoservicio).
            </p>
          </header>
          <div class="grid grid-cols-1 gap-4 p-5 lg:grid-cols-[minmax(0,1fr)_19rem]">
            <div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                <div>
                  <label for="config-nombre" class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
                  <input id="config-nombre" v-model="configuracion.jefe_unidad_nombre" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
                </div>
                <div>
                  <label for="config-cargo" class="block text-xs font-medium text-gray-600 mb-1">Cargo</label>
                  <input id="config-cargo" v-model="configuracion.jefe_unidad_cargo" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
                </div>
              </div>
              <button
                @click="pedirConfirmacionConfig"
                :disabled="guardandoConfig"
                class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
              >
                {{ guardandoConfig ? 'Guardando…' : 'Guardar' }}
              </button>
            </div>

            <!-- Vista previa de la firma tal como la imprime la constancia: línea, nombre y cargo. -->
            <aside class="rounded-xl border border-amber-200/80 bg-amber-50/50 px-4 py-3" aria-label="Vista previa de la firma">
              <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-800">Así aparece en la constancia</p>
              <div class="mx-auto mt-5 w-4/5 border-t border-amber-900/40"></div>
              <p class="mt-1.5 break-words text-center font-serif text-sm font-bold text-gray-900">{{ configuracion.jefe_unidad_nombre.trim() || 'Nombre' }}</p>
              <p class="break-words text-center text-xs text-amber-900">{{ configuracion.jefe_unidad_cargo.trim() || 'Cargo' }}</p>
            </aside>
          </div>
        </section>

        <template v-if="seccion === 'catalogos'">
          <!-- Mismo formato que la tabla de Usuarios: encabezado con barrita, alta en la franja y tabla. -->
          <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
            <header class="space-y-3 border-b border-gray-100 bg-indigo-50/40 px-5 py-3">
              <div>
                <h3 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
                  <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
                  Estados personalizados de libro
                </h3>
                <p class="mt-0.5 pl-3 text-xs text-gray-500">
                  Aparecen como opción "Personalizado" en Estado de ejemplares y Cambio Masivo. Un estado ya usado no se puede
                  borrar, solo desactivar (deja de ofrecerse en el selector).
                </p>
              </div>
              <div class="flex flex-col sm:flex-row gap-2">
                <input v-model="nuevoEstado.nombre" placeholder="Nombre (ej: Restauración)" aria-label="Nombre del estado nuevo" class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500" />
                <input v-model="nuevoEstado.descripcion" placeholder="Descripción (opcional)" aria-label="Descripción del estado nuevo" class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500" />
                <button
                  @click="pedirConfirmacionCrearEstado"
                  :disabled="creandoEstado"
                  class="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 disabled:opacity-60"
                >
                  + Crear
                </button>
              </div>
            </header>
            <table class="w-full">
              <thead>
                <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
                  <th class="px-4 py-2.5">Estado</th>
                  <th class="hidden px-4 py-2.5 sm:table-cell">Descripción</th>
                  <th class="px-4 py-2.5 text-right">Situación</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="e in estados" :key="e.id" class="even:bg-gray-50/50">
                  <td class="px-4 py-2.5">
                    <span class="block text-sm font-semibold text-gray-900">{{ e.nombre }}</span>
                    <span v-if="e.descripcion" class="block text-xs text-gray-500 sm:hidden">{{ e.descripcion }}</span>
                  </td>
                  <td class="hidden px-4 py-2.5 text-sm text-gray-700 sm:table-cell">{{ e.descripcion ?? '—' }}</td>
                  <td class="px-4 py-2.5 text-right">
                    <button
                      @click="toggleActivo(e)"
                      class="inline-block rounded border px-1.5 py-px text-[10px] font-bold uppercase tracking-widest transition-colors"
                      :class="e.activo ? 'border-emerald-300 bg-emerald-50/60 text-emerald-700 hover:bg-emerald-100' : 'border-gray-300 bg-gray-50 text-gray-500 hover:bg-gray-100'"
                      :title="e.activo ? 'Desactivar' : 'Reactivar'"
                    >
                      {{ e.activo ? 'Activo' : 'Inactivo' }}
                    </button>
                  </td>
                </tr>
                <tr v-if="!estados.length">
                  <td colspan="3" class="px-4 py-6 text-center text-sm text-gray-400">Sin estados personalizados todavía.</td>
                </tr>
              </tbody>
            </table>
          </section>

          <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
            <header class="space-y-3 border-b border-gray-100 bg-indigo-50/40 px-5 py-3">
              <div>
                <h3 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
                  <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
                  Ubicaciones
                </h3>
                <p class="mt-0.5 pl-3 text-xs text-gray-500">
                  Sedes y otras ubicaciones físicas donde puede estar un ejemplar — se elige al catalogar un libro
                  y sirve para filtrar en Cambio Masivo de Estado. Agrega acá cualquier sede nueva, no hay límite. Al eliminar
                  una que tiene copias, se elige a qué ubicación pasan.
                </p>
              </div>
              <div class="flex flex-col sm:flex-row gap-2">
                <input v-model="nuevaUbicacion.nombre" placeholder="Nombre (ej: Biblioteca Central, Sede Puerto Natales)" aria-label="Nombre de la ubicación nueva" class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500" @keydown.enter="pedirConfirmacionCrearUbicacion" />
                <button
                  @click="pedirConfirmacionCrearUbicacion"
                  :disabled="creandoUbicacion"
                  class="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 disabled:opacity-60"
                >
                  + Crear
                </button>
              </div>
            </header>
            <table class="w-full">
              <thead>
                <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
                  <th class="px-4 py-2.5">Ubicación</th>
                  <th class="px-4 py-2.5">Copias</th>
                  <th class="px-4 py-2.5 text-right">Acciones</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="u in ubicaciones" :key="u.id" class="even:bg-gray-50/50">
                  <td class="px-4 py-2.5">
                    <span class="text-sm font-bold text-gray-900">{{ u.nombre }}</span>
                  </td>
                  <td class="px-4 py-2.5 whitespace-nowrap">
                    <span class="font-mono text-xs text-gray-800">{{ (u.copias ?? 0).toLocaleString('es-CL') }}</span>
                    <span class="ml-1 text-xs text-gray-500">{{ u.copias === 1 ? 'copia' : 'copias' }}</span>
                  </td>
                  <td class="px-4 py-2.5">
                    <div class="flex items-center justify-end gap-1.5">
                      <button type="button" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-800 shadow-sm transition-colors hover:bg-gray-50" @click="abrirEdicionUbicacion(u)">
                        <svg class="h-3.5 w-3.5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.820a4.500 4.500 0 01-1.897 1.130l-2.685.8.8-2.685a4.500 4.500 0 011.130-1.897L16.863 4.487z" /></svg>
                        Editar
                      </button>
                      <button type="button" class="inline-flex w-[6.25rem] items-center justify-center whitespace-nowrap rounded-lg border border-red-200 bg-white px-2.5 py-1 text-xs font-semibold text-red-700 shadow-sm transition-colors hover:bg-red-50" @click="pedirEliminarUbicacion(u)">
                        Eliminar
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="!ubicaciones.length">
                  <td colspan="3" class="px-4 py-6 text-center text-sm text-gray-400">Sin ubicaciones todavía.</td>
                </tr>
              </tbody>
            </table>
          </section>

          <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
            <header class="space-y-3 border-b border-gray-100 bg-indigo-50/40 px-5 py-3">
              <div>
                <h3 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
                  <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
                  Tipos de material
                </h3>
                <p class="mt-0.5 pl-3 text-xs text-gray-500">
                  Se eligen al catalogar un libro y sirven para filtrar en Listado de material y Cambio Masivo de Estado.
                  Cada tipo usa una <strong>ficha</strong>: qué etiquetas MARC se catalogan y cuáles ve el usuario en el portal.
                </p>
              </div>
              <div class="flex flex-col sm:flex-row gap-2">
                <input v-model="nuevoTipoMaterial.nombre" placeholder="Nombre (ej: Mapa)" aria-label="Nombre del tipo nuevo" class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500" @keydown.enter="pedirConfirmacionCrearTipoMaterial" />
                <select v-model="nuevoTipoMaterial.plantilla" aria-label="Ficha del tipo nuevo" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
                  <option v-for="(nombre, clave) in PLANTILLAS" :key="clave" :value="clave">Ficha de {{ nombre }}</option>
                </select>
                <button
                  @click="pedirConfirmacionCrearTipoMaterial"
                  :disabled="creandoTipoMaterial"
                  class="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 disabled:opacity-60"
                >
                  + Crear
                </button>
              </div>
            </header>
            <table class="w-full">
              <thead>
                <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
                  <th class="px-4 py-2.5">Tipo</th>
                  <th class="px-4 py-2.5 text-right">Ficha</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="t in tiposMaterial" :key="t.id" class="even:bg-gray-50/50">
                  <td class="px-4 py-2.5 text-sm font-semibold text-gray-900">{{ t.nombre }}</td>
                  <td class="px-4 py-2.5 text-right">
                    <select
                      :value="t.plantilla"
                      :aria-label="`Ficha de ${t.nombre}`"
                      class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs outline-none focus:ring-2 focus:ring-indigo-500"
                      @change="pedirCambioPlantilla(t, $event)"
                    >
                      <option v-for="(nombre, clave) in PLANTILLAS" :key="clave" :value="clave">Ficha de {{ nombre }}</option>
                    </select>
                  </td>
                </tr>
                <tr v-if="!tiposMaterial.length">
                  <td colspan="2" class="px-4 py-6 text-center text-sm text-gray-400">Sin tipos de material todavía.</td>
                </tr>
              </tbody>
            </table>
          </section>

          <CategoriasRecurso />
        </template>

        <template v-if="seccion === 'circulacion'">
          <ReglasPrestamo />
          <DiasSinAtencion />
        </template>
      </div>

      <!-- Ventanas en formato de tarjeta: resumen con borde índigo a la izquierda y rótulo en mayúsculas. -->
      <DialogoConfirmacion
        :abierto="confirmandoConfig"
        titulo="¿Confirmar cambio de datos institucionales?"
        confirmar="Sí, guardar"
        destacado
        :cargando="guardandoConfig"
        @confirmar="guardarConfiguracion"
        @cancelar="confirmandoConfig = false"
      >
        <p>Este nombre y cargo aparecen firmando la Constancia de No Multa que se le entrega a los usuarios.</p>
        <div class="mt-3 space-y-1 rounded-lg border border-l-[3px] border-gray-200 border-l-indigo-600 bg-indigo-50/30 px-4 py-3 text-gray-700">
          <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Cambios</p>
          <p v-if="configuracionOriginal.jefe_unidad_nombre !== configuracion.jefe_unidad_nombre">
            Nombre: <span class="text-gray-400 line-through">{{ configuracionOriginal.jefe_unidad_nombre }}</span>
            → <strong class="text-gray-900">{{ configuracion.jefe_unidad_nombre }}</strong>
          </p>
          <p v-if="configuracionOriginal.jefe_unidad_cargo !== configuracion.jefe_unidad_cargo">
            Cargo: <span class="text-gray-400 line-through">{{ configuracionOriginal.jefe_unidad_cargo }}</span>
            → <strong class="text-gray-900">{{ configuracion.jefe_unidad_cargo }}</strong>
          </p>
          <p v-if="configuracionOriginal.jefe_unidad_nombre === configuracion.jefe_unidad_nombre && configuracionOriginal.jefe_unidad_cargo === configuracion.jefe_unidad_cargo" class="text-gray-500">
            Sin cambios respecto a lo guardado actualmente.
          </p>
        </div>
      </DialogoConfirmacion>

      <DialogoConfirmacion
        :abierto="confirmandoCrearEstado"
        titulo="¿Crear este estado personalizado?"
        confirmar="Sí, crear"
        destacado
        :cargando="creandoEstado"
        @confirmar="crearEstado"
        @cancelar="confirmandoCrearEstado = false"
      >
        <p>Quedará disponible como opción "Personalizado" en Estado de ejemplares y Cambio Masivo para todo el staff.</p>
        <div class="mt-3 rounded-lg border border-l-[3px] border-gray-200 border-l-indigo-600 bg-indigo-50/30 px-4 py-3">
          <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Nuevo estado</p>
          <p class="mt-0.5 font-bold text-gray-900">{{ nuevoEstado.nombre }}</p>
          <p v-if="nuevoEstado.descripcion" class="text-gray-500">{{ nuevoEstado.descripcion }}</p>
        </div>
      </DialogoConfirmacion>

      <DialogoConfirmacion
        :abierto="confirmandoCrearUbicacion"
        titulo="¿Crear esta ubicación?"
        confirmar="Sí, crear"
        destacado
        :cargando="creandoUbicacion"
        @confirmar="crearUbicacion"
        @cancelar="confirmandoCrearUbicacion = false"
      >
        <p>Quedará disponible para elegir al catalogar un libro y como filtro en Cambio Masivo de Estado.</p>
        <div class="mt-3 rounded-lg border border-l-[3px] border-gray-200 border-l-indigo-600 bg-indigo-50/30 px-4 py-3">
          <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Nueva ubicación</p>
          <p class="mt-0.5 font-bold text-gray-900">{{ nuevaUbicacion.nombre }}</p>
        </div>
      </DialogoConfirmacion>

      <Modal
        :abierto="!!edicionUbicacion.ubicacion"
        titulo="Editar ubicación"
        descripcion="Sus copias, reglas de préstamo y días sin atención la siguen teniendo con el nombre nuevo."
        destacado
        :bloqueado="edicionUbicacion.guardando"
        @cerrar="edicionUbicacion.ubicacion = null"
      >
        <label for="ubicacion-editar-nombre" class="mb-1 block text-xs font-medium text-gray-600">Nombre</label>
        <input
          id="ubicacion-editar-nombre"
          v-model="edicionUbicacion.nombre"
          maxlength="255"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm"
          @keydown.enter="guardarUbicacion"
        />
        <template #pie>
          <button
            type="button"
            class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg bg-white text-gray-700 hover:bg-gray-100 transition-colors font-semibold text-sm"
            :disabled="edicionUbicacion.guardando"
            @click="edicionUbicacion.ubicacion = null"
          >
            Cancelar
          </button>
          <button
            type="button"
            class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-semibold text-sm shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] disabled:opacity-60"
            :disabled="edicionUbicacion.guardando"
            @click="guardarUbicacion"
          >
            {{ edicionUbicacion.guardando ? 'Guardando…' : 'Guardar' }}
          </button>
        </template>
      </Modal>

      <DialogoConfirmacion
        :abierto="!!eliminacionUbicacion.ubicacion"
        titulo="¿Eliminar esta ubicación?"
        confirmar="Sí, eliminar"
        tono="peligro"
        destacado
        :cargando="eliminacionUbicacion.eliminando"
        :deshabilitado="!!eliminacionUbicacion.ubicacion?.copias && !eliminacionUbicacion.moverA"
        @confirmar="eliminarUbicacion"
        @cancelar="eliminacionUbicacion.ubicacion = null"
      >
        <p>
          <strong class="text-gray-900">{{ eliminacionUbicacion.ubicacion?.nombre }}</strong> dejará de ofrecerse al catalogar y en los filtros.
        </p>
        <div v-if="eliminacionUbicacion.ubicacion?.copias" class="mt-3 rounded-lg bg-gray-50 px-3 py-2.5">
          <p>
            Tiene <strong class="text-gray-900">{{ eliminacionUbicacion.ubicacion.copias.toLocaleString('es-CL') }}
            {{ eliminacionUbicacion.ubicacion.copias === 1 ? 'copia' : 'copias' }}</strong>: elige a qué ubicación pasan.
          </p>
          <label for="ubicacion-mover-a" class="mt-2 mb-1 block text-xs font-medium text-gray-600">Pasar sus copias a</label>
          <select
            id="ubicacion-mover-a"
            v-model="eliminacionUbicacion.moverA"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-indigo-500 outline-none text-sm text-gray-900"
          >
            <option value="" disabled>Elige una ubicación</option>
            <option v-for="o in otrasUbicaciones" :key="o.id" :value="o.id">{{ o.nombre }}</option>
          </select>
        </div>
        <p v-else class="mt-3 rounded-lg bg-gray-50 px-3 py-2.5">No tiene copias.</p>
      </DialogoConfirmacion>

      <DialogoConfirmacion
        :abierto="confirmandoCrearTipoMaterial"
        titulo="¿Crear este tipo de material?"
        confirmar="Sí, crear"
        destacado
        :cargando="creandoTipoMaterial"
        @confirmar="crearTipoMaterial"
        @cancelar="confirmandoCrearTipoMaterial = false"
      >
        <p>Quedará disponible para elegir al catalogar un libro y como filtro en Listado de material y Cambio Masivo de Estado.</p>
        <div class="mt-3 rounded-lg border border-l-[3px] border-gray-200 border-l-indigo-600 bg-indigo-50/30 px-4 py-3">
          <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Nuevo tipo</p>
          <p class="mt-0.5 font-bold text-gray-900">{{ nuevoTipoMaterial.nombre }}</p>
          <span class="mt-1 inline-block rounded border border-indigo-200 bg-white px-1.5 py-px text-xs font-semibold text-indigo-700">Ficha de {{ PLANTILLAS[nuevoTipoMaterial.plantilla] }}</span>
        </div>
      </DialogoConfirmacion>

      <DialogoConfirmacion
        :abierto="!!plantillaPendiente"
        titulo="¿Cambiar la ficha de este tipo?"
        confirmar="Sí, cambiar"
        destacado
        :cargando="cambiandoPlantilla"
        @confirmar="confirmarCambioPlantilla"
        @cancelar="plantillaPendiente = null"
      >
        <template v-if="plantillaPendiente">
          <p>
            Al catalogar <strong class="text-gray-900">{{ plantillaPendiente.tipo.nombre }}</strong>, el formulario mostrará los campos de la ficha nueva.
            Lo que ya está catalogado conserva sus datos.
          </p>
          <div class="mt-3 rounded-lg border border-l-[3px] border-gray-200 border-l-indigo-600 bg-indigo-50/30 px-4 py-3">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Ficha</p>
            <p class="mt-0.5 text-gray-400 line-through">Ficha de {{ PLANTILLAS[plantillaPendiente.tipo.plantilla] }}</p>
            <p class="font-bold text-gray-900">Ficha de {{ PLANTILLAS[plantillaPendiente.plantilla] }}</p>
          </div>
        </template>
      </DialogoConfirmacion>

      <DialogoConfirmacion
        :abierto="!!estadoADesactivar"
        titulo="¿Desactivar este estado?"
        confirmar="Sí, desactivar"
        tono="peligro"
        destacado
        :cargando="desactivandoEstado"
        @confirmar="confirmarDesactivar"
        @cancelar="estadoADesactivar = null"
      >
        <p>
          <strong class="text-gray-900">{{ estadoADesactivar?.nombre }}</strong> dejará de aparecer como opción en Estado de ejemplares y Cambio Masivo.
        </p>
        <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2.5 text-xs">
          Los ejemplares que ya lo tienen asignado no se ven afectados, y se puede reactivar cuando quieras.
        </p>
      </DialogoConfirmacion>
    </div>
  </StaffLayout>
</template>
