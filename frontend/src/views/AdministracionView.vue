<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import StaffLayout from '@/components/layout/StaffLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import GestionEstudiantes from '@/components/administracion/GestionEstudiantes.vue'
import CategoriasRecurso from '@/components/administracion/CategoriasRecurso.vue'
import ReglasPrestamo from '@/components/administracion/ReglasPrestamo.vue'
import DiasSinAtencion from '@/components/administracion/DiasSinAtencion.vue'
import CopiasDadasDeBaja from '@/components/administracion/CopiasDadasDeBaja.vue'
import PoliticasVigencia from '@/components/administracion/PoliticasVigencia.vue'
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
type Seccion = 'institucion' | 'catalogos' | 'circulacion' | 'estudiantes' | 'vigencia' | 'eliminados'
const SECCIONES: { clave: Seccion; label: string; permiso: string }[] = [
  { clave: 'institucion', label: 'Configuración institucional', permiso: 'configuracion.editar' },
  { clave: 'catalogos', label: 'Catálogos (estados, ubicaciones, tipos y recursos)', permiso: 'catalogos.gestionar' },
  { clave: 'circulacion', label: 'Reglas de préstamo y días sin atención', permiso: 'catalogos.gestionar' },
  { clave: 'estudiantes', label: 'Usuarios', permiso: 'usuarios.gestionar' },
  { clave: 'vigencia', label: 'Vigencia de cuentas', permiso: 'vigencia.gestionar' },
  { clave: 'eliminados', label: 'Eliminados', permiso: 'libros.estado' },
]
const secciones = computed(() => SECCIONES.filter((s) => auth.puede(s.permiso)))
const seccion = ref<Seccion>('institucion')

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
    <div class="max-w-4xl mx-auto">
      <div
        class="rounded-xl shadow-md mb-6 overflow-hidden"
        style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
      >
        <div class="px-6 py-5">
          <h1 class="text-2xl font-serif font-bold tracking-tight text-white">Administración</h1>
          <p class="text-sm text-white/60 mt-1">Configuración institucional, catálogos administrables y estudiantes</p>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div v-if="secciones.length > 1" class="flex flex-col sm:flex-row gap-1 mb-6 bg-white rounded-xl shadow-sm p-1">
        <button
          v-for="s in secciones"
          :key="s.clave"
          @click="elegirSeccion(s.clave)"
          class="flex-1 py-2.5 px-4 rounded-lg text-sm font-medium transition-colors"
          :class="seccion === s.clave ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100'"
        >
          {{ s.label }}
        </button>
      </div>

      <div v-if="!cargando" class="space-y-6">
        <GestionEstudiantes v-if="seccion === 'estudiantes'" />
        <PoliticasVigencia v-if="seccion === 'vigencia'" />
        <CopiasDadasDeBaja v-if="seccion === 'eliminados'" />

        <div v-if="seccion === 'institucion'" class="bg-white rounded-xl shadow-md p-6">
          <h3 class="font-semibold text-gray-900 mb-1">Configuración institucional</h3>
          <p class="text-xs text-gray-500 mb-4">
            Nombre y cargo de quien firma la Constancia de No Multa (Usuarios → Constancia y portal de autoservicio).
          </p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
              <input v-model="configuracion.jefe_unidad_nombre" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Cargo</label>
              <input v-model="configuracion.jefe_unidad_cargo" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
            </div>
          </div>
          <button
            @click="pedirConfirmacionConfig"
            :disabled="guardandoConfig"
            class="px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium disabled:opacity-60"
          >
            {{ guardandoConfig ? 'Guardando…' : 'Guardar' }}
          </button>
        </div>

        <template v-if="seccion === 'catalogos'">
          <div class="bg-white rounded-xl shadow-md p-6">
            <h3 class="font-semibold text-gray-900 mb-1">Estados personalizados de libro</h3>
            <p class="text-xs text-gray-500 mb-4">
              Aparecen como opción "Personalizado" en Estado de ejemplares y Cambio Masivo. Un estado ya usado no se puede
              borrar, solo desactivar (deja de ofrecerse en el selector).
            </p>

            <div class="flex flex-col sm:flex-row gap-2 mb-5">
              <input v-model="nuevoEstado.nombre" placeholder="Nombre (ej: Restauración)" class="flex-1 px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
              <input v-model="nuevoEstado.descripcion" placeholder="Descripción (opcional)" class="flex-1 px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
              <button
                @click="pedirConfirmacionCrearEstado"
                :disabled="creandoEstado"
                class="px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60 shrink-0"
              >
                + Crear
              </button>
            </div>

            <div class="rounded-lg border border-gray-200 divide-y divide-gray-100">
              <div v-for="e in estados" :key="e.id" class="flex items-center justify-between px-4 py-3">
                <div>
                  <p class="text-sm font-medium text-gray-900">{{ e.nombre }}</p>
                  <p v-if="e.descripcion" class="text-xs text-gray-500">{{ e.descripcion }}</p>
                </div>
                <button
                  @click="toggleActivo(e)"
                  class="text-xs px-3 py-1 rounded-full font-medium transition-colors"
                  :class="e.activo ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'"
                >
                  {{ e.activo ? 'Activo' : 'Inactivo' }}
                </button>
              </div>
              <p v-if="!estados.length" class="px-4 py-6 text-center text-sm text-gray-400">Sin estados personalizados todavía.</p>
            </div>
          </div>

          <div class="bg-white rounded-xl shadow-md p-6">
            <h3 class="font-semibold text-gray-900 mb-1">Ubicaciones</h3>
            <p class="text-xs text-gray-500 mb-4">
              Sedes y otras ubicaciones físicas donde puede estar un ejemplar — se elige al catalogar un libro
              y sirve para filtrar en Cambio Masivo de Estado. Agrega acá cualquier sede nueva, no hay límite. Al eliminar
              una que tiene copias, se elige a qué ubicación pasan.
            </p>

            <div class="flex flex-col sm:flex-row gap-2 mb-5">
              <input v-model="nuevaUbicacion.nombre" placeholder="Nombre (ej: Biblioteca Central, Sede Puerto Natales)" class="flex-1 px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" @keydown.enter="pedirConfirmacionCrearUbicacion" />
              <button
                @click="pedirConfirmacionCrearUbicacion"
                :disabled="creandoUbicacion"
                class="px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60 shrink-0"
              >
                + Crear
              </button>
            </div>

            <div class="rounded-lg border border-gray-200 divide-y divide-gray-100">
              <div v-for="u in ubicaciones" :key="u.id" class="flex items-center justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                  <p class="text-sm font-medium text-gray-900">{{ u.nombre }}</p>
                  <p class="text-xs text-gray-500">{{ (u.copias ?? 0).toLocaleString('es-CL') }} {{ u.copias === 1 ? 'copia' : 'copias' }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3 text-sm">
                  <button type="button" class="font-medium text-indigo-700 hover:text-indigo-800" @click="abrirEdicionUbicacion(u)">Editar</button>
                  <button type="button" class="font-medium text-red-600 hover:text-red-700" @click="pedirEliminarUbicacion(u)">Eliminar</button>
                </div>
              </div>
              <p v-if="!ubicaciones.length" class="px-4 py-6 text-center text-sm text-gray-400">Sin ubicaciones todavía.</p>
            </div>
          </div>

          <div class="bg-white rounded-xl shadow-md p-6">
            <h3 class="font-semibold text-gray-900 mb-1">Tipos de material</h3>
            <p class="text-xs text-gray-500 mb-4">
              Se eligen al catalogar un libro y sirven para filtrar en Listado de material y Cambio Masivo de Estado.
              Cada tipo usa una <strong>ficha</strong>: qué etiquetas MARC se catalogan y cuáles ve el usuario en el portal.
            </p>

            <div class="flex flex-col sm:flex-row gap-2 mb-5">
              <input v-model="nuevoTipoMaterial.nombre" placeholder="Nombre (ej: Mapa)" class="flex-1 px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm" @keydown.enter="pedirConfirmacionCrearTipoMaterial" />
              <select v-model="nuevoTipoMaterial.plantilla" aria-label="Ficha del tipo nuevo" class="px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
                <option v-for="(nombre, clave) in PLANTILLAS" :key="clave" :value="clave">Ficha de {{ nombre }}</option>
              </select>
              <button
                @click="pedirConfirmacionCrearTipoMaterial"
                :disabled="creandoTipoMaterial"
                class="px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60 shrink-0"
              >
                + Crear
              </button>
            </div>

            <div class="rounded-lg border border-gray-200 divide-y divide-gray-100">
              <div v-for="t in tiposMaterial" :key="t.id" class="px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <p class="text-sm font-medium text-gray-900">{{ t.nombre }}</p>
                <select
                  :value="t.plantilla"
                  :aria-label="`Ficha de ${t.nombre}`"
                  class="px-3 py-1.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-xs"
                  @change="pedirCambioPlantilla(t, $event)"
                >
                  <option v-for="(nombre, clave) in PLANTILLAS" :key="clave" :value="clave">Ficha de {{ nombre }}</option>
                </select>
              </div>
              <p v-if="!tiposMaterial.length" class="px-4 py-6 text-center text-sm text-gray-400">Sin tipos de material todavía.</p>
            </div>
          </div>

          <CategoriasRecurso />
        </template>

        <template v-if="seccion === 'circulacion'">
          <ReglasPrestamo />
          <DiasSinAtencion />
        </template>
      </div>

      <div
        v-if="confirmandoConfig"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="confirmandoConfig = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Confirmar cambio de datos institucionales?</h3>
          <p class="text-sm text-gray-500 mb-3">
            Este nombre y cargo aparecen firmando la Constancia de No Multa que se le entrega a los usuarios.
          </p>
          <div class="text-sm bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-6 space-y-1">
            <p v-if="configuracionOriginal.jefe_unidad_nombre !== configuracion.jefe_unidad_nombre">
              Nombre: <span class="text-gray-400 line-through">{{ configuracionOriginal.jefe_unidad_nombre }}</span>
              → <strong>{{ configuracion.jefe_unidad_nombre }}</strong>
            </p>
            <p v-if="configuracionOriginal.jefe_unidad_cargo !== configuracion.jefe_unidad_cargo">
              Cargo: <span class="text-gray-400 line-through">{{ configuracionOriginal.jefe_unidad_cargo }}</span>
              → <strong>{{ configuracion.jefe_unidad_cargo }}</strong>
            </p>
            <p v-if="configuracionOriginal.jefe_unidad_nombre === configuracion.jefe_unidad_nombre && configuracionOriginal.jefe_unidad_cargo === configuracion.jefe_unidad_cargo" class="text-gray-500">
              Sin cambios respecto a lo guardado actualmente.
            </p>
          </div>
          <div class="flex gap-3">
            <button
              @click="confirmandoConfig = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="guardarConfiguracion"
              :disabled="guardandoConfig"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ guardandoConfig ? 'Guardando…' : 'Sí, guardar' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="confirmandoCrearEstado"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="confirmandoCrearEstado = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Crear este estado personalizado?</h3>
          <p class="text-sm text-gray-500 mb-3">
            Quedará disponible como opción "Personalizado" en Estado de ejemplares y Cambio Masivo para todo el staff.
          </p>
          <div class="text-sm bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-6 space-y-1">
            <p><strong>{{ nuevoEstado.nombre }}</strong></p>
            <p v-if="nuevoEstado.descripcion" class="text-gray-500">{{ nuevoEstado.descripcion }}</p>
          </div>
          <div class="flex gap-3">
            <button
              @click="confirmandoCrearEstado = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="crearEstado"
              :disabled="creandoEstado"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ creandoEstado ? 'Creando…' : 'Sí, crear' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="confirmandoCrearUbicacion"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="confirmandoCrearUbicacion = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Crear esta ubicación?</h3>
          <p class="text-sm text-gray-500 mb-3">
            Quedará disponible para elegir al catalogar un libro y como filtro en Cambio Masivo de Estado.
          </p>
          <div class="text-sm bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-6">
            <p><strong>{{ nuevaUbicacion.nombre }}</strong></p>
          </div>
          <div class="flex gap-3">
            <button
              @click="confirmandoCrearUbicacion = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="crearUbicacion"
              :disabled="creandoUbicacion"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ creandoUbicacion ? 'Creando…' : 'Sí, crear' }}
            </button>
          </div>
        </div>
      </div>

      <Modal
        :abierto="!!edicionUbicacion.ubicacion"
        titulo="Editar ubicación"
        descripcion="Sus copias, reglas de préstamo y días sin atención la siguen teniendo con el nombre nuevo."
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
            class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            :disabled="edicionUbicacion.guardando"
            @click="edicionUbicacion.ubicacion = null"
          >
            Cancelar
          </button>
          <button
            type="button"
            class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
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
        :cargando="eliminacionUbicacion.eliminando"
        :deshabilitado="!!eliminacionUbicacion.ubicacion?.copias && !eliminacionUbicacion.moverA"
        @confirmar="eliminarUbicacion"
        @cancelar="eliminacionUbicacion.ubicacion = null"
      >
        <p>
          <strong class="text-gray-900">{{ eliminacionUbicacion.ubicacion?.nombre }}</strong> dejará de ofrecerse al catalogar y en los filtros.
        </p>
        <template v-if="eliminacionUbicacion.ubicacion?.copias">
          <p class="mt-2">
            Tiene <strong class="text-gray-900">{{ eliminacionUbicacion.ubicacion.copias.toLocaleString('es-CL') }}
            {{ eliminacionUbicacion.ubicacion.copias === 1 ? 'copia' : 'copias' }}</strong>: elige a qué ubicación pasan.
          </p>
          <label for="ubicacion-mover-a" class="mt-3 mb-1 block text-xs font-medium text-gray-600">Pasar sus copias a</label>
          <select
            id="ubicacion-mover-a"
            v-model="eliminacionUbicacion.moverA"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm text-gray-900"
          >
            <option value="" disabled>Elige una ubicación</option>
            <option v-for="o in otrasUbicaciones" :key="o.id" :value="o.id">{{ o.nombre }}</option>
          </select>
        </template>
        <p v-else class="mt-2">No tiene copias.</p>
      </DialogoConfirmacion>

      <div
        v-if="confirmandoCrearTipoMaterial"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="confirmandoCrearTipoMaterial = false"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Crear este tipo de material?</h3>
          <p class="text-sm text-gray-500 mb-3">
            Quedará disponible para elegir al catalogar un libro y como filtro en Listado de material y Cambio Masivo de Estado.
          </p>
          <div class="text-sm bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-6">
            <p><strong>{{ nuevoTipoMaterial.nombre }}</strong></p>
            <p class="text-gray-500 mt-0.5">Ficha de {{ PLANTILLAS[nuevoTipoMaterial.plantilla] }}</p>
          </div>
          <div class="flex gap-3">
            <button
              @click="confirmandoCrearTipoMaterial = false"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="crearTipoMaterial"
              :disabled="creandoTipoMaterial"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ creandoTipoMaterial ? 'Creando…' : 'Sí, crear' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="plantillaPendiente"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="!cambiandoPlantilla && (plantillaPendiente = null)"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Cambiar la ficha de este tipo?</h3>
          <p class="text-sm text-gray-500 mb-3">
            Al catalogar <strong>{{ plantillaPendiente.tipo.nombre }}</strong>, el formulario mostrará los campos de la ficha nueva.
            Lo que ya está catalogado conserva sus datos.
          </p>
          <div class="text-sm bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-6">
            <p class="text-gray-500"><span class="line-through">Ficha de {{ PLANTILLAS[plantillaPendiente.tipo.plantilla] }}</span></p>
            <p class="mt-0.5"><strong>Ficha de {{ PLANTILLAS[plantillaPendiente.plantilla] }}</strong></p>
          </div>
          <div class="flex gap-3">
            <button
              @click="plantillaPendiente = null"
              :disabled="cambiandoPlantilla"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="confirmarCambioPlantilla"
              :disabled="cambiandoPlantilla"
              class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ cambiandoPlantilla ? 'Guardando…' : 'Sí, cambiar' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="estadoADesactivar"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
        @click.self="estadoADesactivar = null"
      >
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
          <h3 class="text-lg font-bold text-gray-900 mb-1">¿Desactivar este estado?</h3>
          <p class="text-sm text-gray-500 mb-6">
            <strong>{{ estadoADesactivar.nombre }}</strong> dejará de aparecer como opción en Estado de ejemplares y Cambio
            Masivo. Los ejemplares que ya lo tienen asignado no se ven afectados, y se puede reactivar cuando quieras.
          </p>
          <div class="flex gap-3">
            <button
              @click="estadoADesactivar = null"
              class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
            >
              Cancelar
            </button>
            <button
              @click="confirmarDesactivar"
              :disabled="desactivandoEstado"
              class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium text-sm disabled:opacity-60"
            >
              {{ desactivandoEstado ? 'Desactivando…' : 'Sí, desactivar' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </StaffLayout>
</template>
