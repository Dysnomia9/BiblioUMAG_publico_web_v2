<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import type { Ejemplar, EstadoFasciculo, Fasciculo } from '@/types'

/**
 * Fascículos de una revista (plantilla «Publicación seriada»): qué números llegaron y
 * cuáles faltan. Quien cataloga registra la recepción de un número esperado o
 * reclamado, y agrega los que vienen.
 */
const props = defineProps<{ libroId: number; copias: Ejemplar[]; puedeEditar: boolean }>()

const toast = useToast()
const fasciculos = ref<Fasciculo[]>([])
const conteos = ref<Record<EstadoFasciculo, number>>({ recibido: 0, esperado: 0, reclamado: 0, faltante: 0 })
const cargando = ref(true)
const filtro = ref<EstadoFasciculo | ''>('')

const ESTADOS: Record<EstadoFasciculo, { texto: string; badge: string }> = {
  recibido: { texto: 'Recibido', badge: 'bg-emerald-50 text-emerald-700' },
  esperado: { texto: 'Esperado', badge: 'bg-sky-50 text-sky-700' },
  reclamado: { texto: 'Reclamado', badge: 'bg-amber-50 text-amber-800' },
  faltante: { texto: 'Faltante', badge: 'bg-red-50 text-red-700' },
}

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<{ fasciculos: Fasciculo[]; conteos: Record<EstadoFasciculo, number> }>(`/libros/${props.libroId}/fasciculos`)
    fasciculos.value = data.fasciculos
    conteos.value = data.conteos
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudieron cargar los fascículos'))
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

const visibles = computed(() => (filtro.value ? fasciculos.value.filter((f) => f.estado === filtro.value) : fasciculos.value))
const fecha = (iso: string | null) => (iso ? new Date(`${iso}T12:00:00`).toLocaleDateString('es-CL') : '')
const nombre = (f: Pick<Fasciculo, 'enumeracion' | 'cronologia'>) => [f.enumeracion, f.cronologia ? `(${f.cronologia})` : ''].filter(Boolean).join(' ') || 'Fascículo sin numerar'

function detalle(f: Fasciculo) {
  if (f.estado === 'recibido') return f.fecha_recepcion ? `Recibido el ${fecha(f.fecha_recepcion)}` : 'Recibido'
  if (f.fecha_esperada) return `Se esperaba el ${fecha(f.fecha_esperada)}`
  return ''
}

// ---------- Registrar la recepción ----------
const hoy = new Date().toLocaleDateString('sv-SE')
const recepcion = reactive({ fasciculo: null as Fasciculo | null, fecha: hoy, guardando: false })

async function recibir() {
  const f = recepcion.fasciculo
  if (!f) return
  recepcion.guardando = true
  try {
    await api.patch(`/fasciculos/${f.id}/recibir`, { fecha_recepcion: recepcion.fecha || null })
    toast.success(`Fascículo ${nombre(f)} recibido`)
    recepcion.fasciculo = null
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo registrar la recepción'))
  } finally {
    recepcion.guardando = false
  }
}

function pedirRecepcion(f: Fasciculo) {
  Object.assign(recepcion, { fasciculo: f, fecha: hoy })
}

// ---------- Agregar un fascículo ----------
const nuevoVacio = () => ({ enumeracion: '', cronologia: '', fecha_publicacion: '', fecha_esperada: '', estado: 'esperado' as EstadoFasciculo, ejemplar_id: '' as number | '', nota: '' })
const nuevo = reactive({ abierto: false, guardando: false, datos: nuevoVacio() })

function abrirNuevo() {
  Object.assign(nuevo, { abierto: true, datos: nuevoVacio() })
}

async function agregar() {
  const d = nuevo.datos
  if (!d.enumeracion.trim() && !d.cronologia.trim()) {
    toast.error('Indica el número (ej. Vol. 84, n.º 5) o la fecha (ej. mayo 2019)')
    return
  }
  nuevo.guardando = true
  try {
    await api.post(`/libros/${props.libroId}/fasciculos`, {
      enumeracion: d.enumeracion.trim() || null,
      cronologia: d.cronologia.trim() || null,
      fecha_publicacion: d.fecha_publicacion || null,
      fecha_esperada: d.fecha_esperada || null,
      estado: d.estado,
      ejemplar_id: d.ejemplar_id === '' ? null : d.ejemplar_id,
      nota: d.nota.trim() || null,
    })
    toast.success('Fascículo agregado')
    nuevo.abierto = false
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo agregar el fascículo'))
  } finally {
    nuevo.guardando = false
  }
}

const CAMPO = 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500'
</script>

<template>
  <div class="overflow-hidden rounded-xl border border-sky-100 bg-white">
    <div class="flex flex-wrap items-center justify-between gap-2 bg-sky-50 px-4 py-2.5">
      <p class="text-[11px] font-bold uppercase tracking-wider text-sky-800">Fascículos <span class="ml-1">{{ fasciculos.length }}</span></p>
      <button v-if="puedeEditar" type="button" class="rounded-lg border border-dashed border-sky-300 px-2.5 py-1 text-xs font-semibold text-sky-800 transition-colors hover:bg-white" @click="abrirNuevo">
        + Agregar fascículo
      </button>
    </div>
    <div class="flex flex-wrap gap-1.5 border-b border-gray-100 px-4 py-2.5">
      <button
        type="button"
        class="rounded-full px-2.5 py-1 text-xs font-medium"
        :class="filtro === '' ? 'bg-sky-700 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
        @click="filtro = ''"
      >
        Todos {{ fasciculos.length }}
      </button>
      <template v-for="(e, clave) in ESTADOS" :key="clave">
        <button
          v-if="conteos[clave]"
          type="button"
          class="rounded-full px-2.5 py-1 text-xs font-medium"
          :class="filtro === clave ? 'bg-sky-700 text-white' : `${e.badge} hover:brightness-95`"
          @click="filtro = clave"
        >
          {{ e.texto }} {{ conteos[clave] }}
        </button>
      </template>
    </div>
    <ul class="max-h-80 divide-y divide-gray-100 overflow-y-auto">
      <li v-for="f in visibles" :key="f.id" class="flex items-center justify-between gap-3 px-4 py-2.5">
        <div class="min-w-0">
          <p class="text-sm font-semibold text-gray-900">{{ nombre(f) }}</p>
          <p class="text-xs text-gray-500">
            {{ detalle(f) }}<template v-if="f.ejemplar"> · copia {{ f.ejemplar.numero_copia }} (<span class="font-mono">{{ f.ejemplar.codigo_barras }}</span>)</template
            ><template v-if="f.nota"> · {{ f.nota }}</template>
          </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="ESTADOS[f.estado].badge">{{ ESTADOS[f.estado].texto }}</span>
          <button
            v-if="puedeEditar && f.estado !== 'recibido'"
            type="button"
            class="rounded-lg border border-emerald-300 px-2.5 py-1 text-xs font-semibold text-emerald-700 transition-colors hover:bg-emerald-50"
            @click="pedirRecepcion(f)"
          >
            Recibir
          </button>
        </div>
      </li>
      <li v-if="!cargando && !visibles.length" class="px-4 py-3 text-xs text-gray-400">Sin fascículos registrados.</li>
    </ul>

    <DialogoConfirmacion
      :abierto="!!recepcion.fasciculo"
      titulo="¿Registrar la recepción?"
      confirmar="Sí, recibido"
      tono="exito"
      :cargando="recepcion.guardando"
      @confirmar="recibir"
      @cancelar="recepcion.fasciculo = null"
    >
      <p v-if="recepcion.fasciculo">El fascículo <strong class="text-gray-900">{{ nombre(recepcion.fasciculo) }}</strong> quedará como recibido.</p>
      <label for="fasciculo-fecha-recepcion" class="mb-1 mt-3 block text-xs font-medium text-gray-600">Fecha de recepción</label>
      <input id="fasciculo-fecha-recepcion" v-model="recepcion.fecha" type="date" :max="hoy" :class="CAMPO" />
    </DialogoConfirmacion>

    <Modal :abierto="nuevo.abierto" titulo="Agregar fascículo" descripcion="Un número de esta revista: el que llegó o el que se espera." ancho="lg" pie-fijo :bloqueado="nuevo.guardando" @cerrar="nuevo.abierto = false">
      <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <div>
          <label for="fasciculo-enumeracion" class="mb-1 block text-xs font-medium text-gray-600">Número</label>
          <input id="fasciculo-enumeracion" v-model="nuevo.datos.enumeracion" maxlength="255" placeholder="Vol. 84, n.º 5" :class="CAMPO" />
        </div>
        <div>
          <label for="fasciculo-cronologia" class="mb-1 block text-xs font-medium text-gray-600">Fecha que trae la revista</label>
          <input id="fasciculo-cronologia" v-model="nuevo.datos.cronologia" maxlength="255" placeholder="mayo 2019" :class="CAMPO" />
        </div>
        <div>
          <label for="fasciculo-estado" class="mb-1 block text-xs font-medium text-gray-600">Estado</label>
          <select id="fasciculo-estado" v-model="nuevo.datos.estado" :class="CAMPO">
            <option v-for="(e, clave) in ESTADOS" :key="clave" :value="clave">{{ e.texto }}</option>
          </select>
          <p v-if="nuevo.datos.estado === 'recibido'" class="mt-1 text-[11px] text-gray-500">Queda recibido hoy.</p>
        </div>
        <div>
          <label for="fasciculo-copia" class="mb-1 block text-xs font-medium text-gray-600">Copia prestable (opcional)</label>
          <select id="fasciculo-copia" v-model="nuevo.datos.ejemplar_id" :class="CAMPO">
            <option value="">Ninguna</option>
            <option v-for="c in copias" :key="c.id" :value="c.id">Copia {{ c.numero_copia }} · {{ c.codigo_barras }}</option>
          </select>
        </div>
        <div>
          <label for="fasciculo-publicacion" class="mb-1 block text-xs font-medium text-gray-600">Fecha de publicación</label>
          <input id="fasciculo-publicacion" v-model="nuevo.datos.fecha_publicacion" type="date" :class="CAMPO" />
        </div>
        <div>
          <label for="fasciculo-esperada" class="mb-1 block text-xs font-medium text-gray-600">Fecha esperada</label>
          <input id="fasciculo-esperada" v-model="nuevo.datos.fecha_esperada" type="date" :class="CAMPO" />
        </div>
        <div class="sm:col-span-2">
          <label for="fasciculo-nota" class="mb-1 block text-xs font-medium text-gray-600">Nota</label>
          <textarea id="fasciculo-nota" v-model="nuevo.datos.nota" rows="2" placeholder="Opcional" :class="CAMPO"></textarea>
        </div>
      </div>
      <template #pie>
        <button type="button" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm" :disabled="nuevo.guardando" @click="nuevo.abierto = false">
          Cancelar
        </button>
        <button type="button" class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60" :disabled="nuevo.guardando" @click="agregar">
          {{ nuevo.guardando ? 'Guardando…' : 'Agregar' }}
        </button>
      </template>
    </Modal>
  </div>
</template>
