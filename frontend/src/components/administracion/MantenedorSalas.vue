<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import Modal from '@/components/ui/Modal.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { CODIGOS_EN_EL_MAPA } from '@/utils/mapaSalas'
import type { SalaAdministrada } from '@/types'

/**
 * Las salas de la biblioteca: crearlas, ajustarlas, darlas de baja y eliminarlas. Una sala
 * con reservas en su historial no se elimina, se da de baja: deja de ofrecerse en el mesón y
 * en el portal, y sus reservas y reportes se conservan.
 */
const toast = useToast()

const salas = ref<SalaAdministrada[]>([])
type Rango = { minimo: number; maximo: number }
// Personas por reserva de grupo: en el mesón el rango es más amplio que en el portal (config/salas.php).
const personas = ref<{ meson: Rango; portal: Rango }>({ meson: { minimo: 1, maximo: 7 }, portal: { minimo: 3, maximo: 5 } })
const apiError = ref(false)
const cargando = ref(true)

async function cargar() {
  try {
    const { data } = await api.get<{ salas: SalaAdministrada[]; personas: { meson: Rango; portal: Rango } }>('/mantenedor-salas')
    salas.value = data.salas
    personas.value = data.personas
    apiError.value = false
  } catch {
    apiError.value = true
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

const deBaja = computed(() => salas.value.filter((s) => !s.activa).length)

// Son unas 30: por defecto se ven las primeras y el resto con «Ver todas».
const VISIBLES = 8
const verTodas = ref(false)
const salasVisibles = computed(() => (verTodas.value ? salas.value : salas.value.slice(0, VISIBLES)))

const TIPOS: Record<string, string> = { logia: 'Logia', sala: 'Sala' }
const rango = (r: Rango) => `de ${r.minimo} a ${r.maximo}`
/** Una sala que no está en el portal se reserva solo en el mesón: ahí no rige el rango del portal. */
const textoReserva = (soloResponsable: boolean, soloAdministracion: boolean) => {
  if (soloResponsable) return 'A nombre de una persona'
  const { meson, portal } = personas.value
  return soloAdministracion ? `Grupo ${rango(meson)} personas` : `Grupo ${rango(meson)} personas en el mesón, ${rango(portal)} en el portal`
}
const textoAcceso = (soloAdministracion: boolean) => (soloAdministracion ? 'Solo administración' : 'Mesón y portal')
const textoCapacidad = (capacidad: number) => `${capacidad} ${capacidad === 1 ? 'persona' : 'personas'}`
const textoReservas = (cantidad: number) => `${cantidad} ${cantidad === 1 ? 'reserva activa' : 'reservas activas'}`

// ---------- Crear y editar ----------
const form = reactive({
  abierto: false,
  confirmando: false,
  guardando: false,
  sala: null as SalaAdministrada | null,
  nombre: '',
  codigo: '',
  tipo: 'logia',
  capacidad: 3 as number | '',
  solo_administracion: false,
  solo_responsable: false,
})

function nueva() {
  Object.assign(form, { abierto: true, confirmando: false, sala: null, nombre: '', codigo: '', tipo: 'logia', capacidad: personas.value.portal.minimo, solo_administracion: false, solo_responsable: false })
}

function editar(sala: SalaAdministrada) {
  Object.assign(form, {
    abierto: true,
    confirmando: false,
    sala,
    nombre: sala.nombre,
    codigo: sala.codigo,
    tipo: sala.tipo,
    capacidad: sala.capacidad,
    solo_administracion: sala.solo_administracion,
    solo_responsable: sala.solo_responsable,
  })
}

/** Como lo guardará el servidor (Sala::normalizarCodigo()): «l4» y «4» son L04. */
const codigoFinal = computed(() => {
  const codigo = form.codigo.replace(/\s+/g, '').toUpperCase()
  const logia = codigo.match(/^(?:L|LOGIA)?0*(\d{1,2})$/)
  return logia ? `L${logia[1].padStart(2, '0')}` : codigo
})

const avisoPortal = computed(() => {
  if (form.solo_administracion) return 'No aparecerá en el portal.'
  if (!codigoFinal.value) return null
  return CODIGOS_EN_EL_MAPA.has(codigoFinal.value) ? 'Con este código ocupa su lugar en el plano del portal.' : 'Este código no está en el plano del portal: la sala aparecerá debajo del mapa.'
})

const cambios = computed(() => {
  const o = form.sala
  if (!o) return []
  const lista: { campo: string; antes: string; despues: string }[] = []
  const comparar = (campo: string, antes: string, despues: string) => antes !== despues && lista.push({ campo, antes, despues })
  comparar('Nombre', o.nombre, form.nombre.trim())
  comparar('Código', o.codigo, codigoFinal.value)
  comparar('Tipo', TIPOS[o.tipo] ?? o.tipo, TIPOS[form.tipo] ?? form.tipo)
  comparar('Capacidad', textoCapacidad(o.capacidad), textoCapacidad(Number(form.capacidad)))
  comparar('Se reserva', textoReserva(o.solo_responsable, o.solo_administracion), textoReserva(form.solo_responsable, form.solo_administracion))
  comparar('Acceso', textoAcceso(o.solo_administracion), textoAcceso(form.solo_administracion))
  return lista
})

function pedirConfirmacion() {
  if (!form.nombre.trim() || !form.codigo.trim()) {
    toast.error('Completa el nombre y el código')
    return
  }
  if (form.sala && !cambios.value.length) {
    form.abierto = false
    toast.info('No hubo cambios que guardar')
    return
  }
  form.confirmando = true
}

async function guardar() {
  form.guardando = true
  const datos = {
    nombre: form.nombre.trim(),
    codigo: form.codigo,
    tipo: form.tipo,
    capacidad: form.capacidad,
    solo_administracion: form.solo_administracion,
    solo_responsable: form.solo_responsable,
  }
  try {
    if (form.sala) await api.put(`/mantenedor-salas/${form.sala.id}`, datos)
    else await api.post('/mantenedor-salas', datos)
    toast.success(form.sala ? 'Sala actualizada' : 'Sala creada. Ya se puede reservar.')
    form.abierto = false
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo guardar la sala'))
  } finally {
    form.guardando = false
    form.confirmando = false
  }
}

// ---------- Dar de baja, reactivar y eliminar ----------
const aCambiar = ref<SalaAdministrada | null>(null)
const cambiando = ref(false)

async function cambiarActiva() {
  if (!aCambiar.value) return
  const activa = !aCambiar.value.activa
  cambiando.value = true
  try {
    await api.patch(`/mantenedor-salas/${aCambiar.value.id}/activa`, { activa })
    toast.success(activa ? 'Sala reactivada' : 'Sala dada de baja. Su historial se conserva.')
    aCambiar.value = null
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo cambiar el estado de la sala'))
  } finally {
    cambiando.value = false
  }
}

const aEliminar = ref<SalaAdministrada | null>(null)
const eliminando = ref(false)

async function eliminar() {
  if (!aEliminar.value) return
  eliminando.value = true
  try {
    await api.delete(`/mantenedor-salas/${aEliminar.value.id}`)
    toast.success('Sala eliminada')
    aEliminar.value = null
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo eliminar la sala'))
    aEliminar.value = null
    await cargar()
  } finally {
    eliminando.value = false
  }
}

const CAMPO = 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500'
</script>

<template>
  <ApiErrorBanner v-if="apiError" />

  <section v-else-if="!cargando" class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
    <header class="flex flex-col gap-3 border-b border-gray-100 bg-indigo-50/40 px-5 py-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h3 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
          <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
          Salas
          <span class="rounded border border-indigo-200 bg-white px-1.5 font-sans text-[11px] font-bold text-indigo-700">{{ salas.length }}</span>
        </h3>
        <p class="mt-0.5 max-w-3xl pl-3 text-xs text-gray-500">
          {{ salas.length }} {{ salas.length === 1 ? 'sala' : 'salas' }}<template v-if="deBaja">, {{ deBaja }} de baja</template>. Una sala con reservas en su historial no se elimina: se da de baja, deja de
          ofrecerse y conserva sus reservas y reportes.
        </p>
      </div>
      <button type="button" class="shrink-0 self-start rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] transition-colors hover:bg-indigo-700" @click="nueva">+ Nueva sala</button>
    </header>

    <div class="overflow-x-auto">
      <table class="w-full min-w-[46rem]">
        <thead>
          <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
            <th class="px-4 py-2.5">Sala</th>
            <th class="px-4 py-2.5">Capacidad</th>
            <th class="px-4 py-2.5">Cómo se reserva</th>
            <th class="px-4 py-2.5">Estado</th>
            <th class="px-4 py-2.5" aria-label="Acciones"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="s in salasVisibles" :key="s.id" :class="s.activa ? 'even:bg-gray-50/50' : 'bg-gray-50 text-gray-500'">
            <td class="px-4 py-2.5 whitespace-nowrap">
              <span class="rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 font-mono text-xs text-gray-700">{{ s.codigo }}</span>
              <span class="ml-2.5 text-sm font-bold" :class="s.activa ? 'text-gray-900' : 'text-gray-500'">{{ s.nombre }}</span>
            </td>
            <td class="px-4 py-2.5 whitespace-nowrap text-sm text-gray-700"><strong class="text-gray-900">{{ s.capacidad }}</strong> {{ s.capacidad === 1 ? 'persona' : 'personas' }}</td>
            <td class="px-4 py-2.5 text-sm text-gray-700">
              <p>{{ textoReserva(s.solo_responsable, s.solo_administracion) }}</p>
              <p class="text-xs text-gray-500">{{ textoAcceso(s.solo_administracion) }}</p>
            </td>
            <td class="px-4 py-2.5 whitespace-nowrap">
              <span class="inline-block rounded border px-1.5 py-px text-[10px] font-bold uppercase tracking-widest" :class="s.activa ? 'border-emerald-300 bg-emerald-50/60 text-emerald-700' : 'border-gray-300 bg-gray-50 text-gray-500'">
                {{ s.activa ? 'Activa' : 'De baja' }}
              </span>
              <p v-if="s.reservas_activas_count" class="mt-0.5 text-[11px] text-gray-500">{{ textoReservas(s.reservas_activas_count) }}</p>
            </td>
            <td class="px-4 py-2.5 whitespace-nowrap">
              <div class="flex items-center justify-end gap-4">
                <button type="button" class="rounded-lg border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-gray-50" @click="editar(s)">Editar</button>
                <button type="button" class="text-xs font-semibold hover:underline" :class="s.activa ? 'text-red-700' : 'text-emerald-700'" @click="aCambiar = s">
                  {{ s.activa ? 'Dar de baja' : 'Reactivar' }}
                </button>
                <button v-if="!s.reservas_count" type="button" class="text-xs font-semibold text-red-700 hover:underline" @click="aEliminar = s">Eliminar</button>
                <span v-else class="invisible text-xs font-semibold" aria-hidden="true">Eliminar</span>
              </div>
            </td>
          </tr>
          <tr v-if="!salas.length">
            <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-400">Sin salas. Crea la primera con «+ Nueva sala».</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="salas.length > VISIBLES" class="border-t border-gray-100 bg-gray-50/60 px-4 py-2 text-center">
      <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-indigo-700 hover:underline" :aria-expanded="verTodas" @click="verTodas = !verTodas">
        {{ verTodas ? 'Ver menos' : `Ver todas las salas (${salas.length - VISIBLES} más)` }}
        <svg class="h-3.5 w-3.5 transition-transform" :class="verTodas ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
      </button>
    </div>

    <Modal
      :abierto="form.abierto"
      :titulo="form.sala ? `Editar ${form.sala.nombre}` : 'Nueva sala'"
      ancho="md"
      destacado
      :bloqueado="form.guardando"
      :cerrar-al-clic-afuera="false"
      @cerrar="form.abierto = false"
    >
      <div class="space-y-3">
        <div>
          <label for="sala-nombre" class="mb-1 block text-xs font-medium text-gray-600">Nombre</label>
          <input id="sala-nombre" v-model="form.nombre" maxlength="255" placeholder="Ej: Logia 16" :class="CAMPO" />
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div>
            <label for="sala-codigo" class="mb-1 block text-xs font-medium text-gray-600">Código</label>
            <input id="sala-codigo" v-model="form.codigo" maxlength="20" placeholder="Ej: L16" :class="CAMPO" class="bg-gray-50 font-mono" />
          </div>
          <div>
            <label for="sala-tipo" class="mb-1 block text-xs font-medium text-gray-600">Tipo</label>
            <select id="sala-tipo" v-model="form.tipo" :class="CAMPO">
              <option v-for="(texto, clave) in TIPOS" :key="clave" :value="clave">{{ texto }}</option>
            </select>
          </div>
          <div>
            <label for="sala-capacidad" class="mb-1 block text-xs font-medium text-gray-600">Capacidad</label>
            <input id="sala-capacidad" v-model.number="form.capacidad" type="number" min="1" max="500" :class="CAMPO" />
          </div>
        </div>
        <p class="text-xs text-gray-500">
          El código es el que se escribe en el mesón para entregar o recibir la llave.
          <template v-if="avisoPortal">{{ avisoPortal }}</template>
        </p>
        <p class="pt-1 text-[10px] font-bold uppercase tracking-widest text-indigo-700">Opciones</p>
        <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-gray-200 px-3 py-2.5 transition-colors hover:bg-gray-50" :class="form.solo_responsable ? 'border-indigo-300 bg-indigo-50/40' : ''">
          <input v-model="form.solo_responsable" type="checkbox" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
          <span>
            <span class="block text-sm font-semibold text-gray-900">Se reserva a nombre de una persona</span>
            <span class="block text-xs text-gray-500">Para el docente o funcionario a cargo, sin anotar a cada asistente (los seminarios). Si no, se reserva en grupo: {{ rango(personas.meson) }} personas en el mesón y {{ rango(personas.portal) }} en el portal.</span>
          </span>
        </label>
        <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-gray-200 px-3 py-2.5 transition-colors hover:bg-gray-50" :class="form.solo_administracion ? 'border-indigo-300 bg-indigo-50/40' : ''">
          <input v-model="form.solo_administracion" type="checkbox" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
          <span>
            <span class="block text-sm font-semibold text-gray-900">Solo la gestiona la administración</span>
            <span class="block text-xs text-gray-500">No se presta a estudiantes ni aparece en el portal: la reserva quien tiene el permiso «Salas de la administración».</span>
          </span>
        </label>
      </div>
      <template #pie>
        <button type="button" :disabled="form.guardando" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg bg-white text-gray-700 hover:bg-gray-100 transition-colors font-semibold text-sm" @click="form.abierto = false">Cancelar</button>
        <button type="button" :disabled="form.guardando" class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-semibold text-sm shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] disabled:opacity-60" @click="pedirConfirmacion">
          {{ form.sala ? 'Guardar cambios' : 'Crear sala' }}
        </button>
      </template>
    </Modal>

    <DialogoConfirmacion
      :abierto="form.confirmando"
      :titulo="form.sala ? '¿Guardar los cambios de la sala?' : '¿Crear esta sala?'"
      :confirmar="form.sala ? 'Sí, guardar' : 'Sí, crear'"
      destacado
      :cargando="form.guardando"
      @confirmar="guardar"
      @cancelar="form.confirmando = false"
    >
      <template v-if="form.sala">
        <div class="rounded-lg border border-l-[3px] border-gray-200 border-l-indigo-600 bg-indigo-50/30 px-4 py-3">
          <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Cambios</p>
          <ul class="mt-1 space-y-1 text-gray-700">
            <li v-for="c in cambios" :key="c.campo">
              {{ c.campo }}: <span class="text-gray-400 line-through">{{ c.antes }}</span> → <strong class="text-gray-900">{{ c.despues }}</strong>
            </li>
          </ul>
        </div>
        <p class="mt-3">Las reservas ya hechas siguen en esta sala, con los datos nuevos.</p>
      </template>
      <template v-else>
        <div class="rounded-lg border border-l-[3px] border-gray-200 border-l-indigo-600 bg-indigo-50/30 px-4 py-3">
          <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Nueva sala</p>
          <p class="mt-0.5 font-bold text-gray-900">{{ form.nombre.trim() }} <span class="ml-1 rounded border border-gray-200 bg-white px-1.5 py-px font-mono text-xs font-normal text-gray-700">{{ codigoFinal }}</span></p>
          <p class="mt-0.5 text-gray-600">{{ textoCapacidad(Number(form.capacidad)) }} · {{ textoReserva(form.solo_responsable, form.solo_administracion) }} · {{ textoAcceso(form.solo_administracion) }}</p>
        </div>
        <p class="mt-3">Se podrá reservar de inmediato.</p>
      </template>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="!!aCambiar"
      :titulo="aCambiar?.activa ? '¿Dar de baja esta sala?' : '¿Reactivar esta sala?'"
      :confirmar="aCambiar?.activa ? 'Sí, dar de baja' : 'Sí, reactivar'"
      :tono="aCambiar?.activa ? 'peligro' : 'exito'"
      destacado
      :cargando="cambiando"
      :deshabilitado="!!aCambiar?.activa && !!aCambiar?.reservas_activas_count"
      @confirmar="cambiarActiva"
      @cancelar="aCambiar = null"
    >
      <template v-if="aCambiar?.activa">
        <p v-if="aCambiar.reservas_activas_count" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
          {{ aCambiar.nombre }} tiene {{ textoReservas(aCambiar.reservas_activas_count) }}. {{ aCambiar.reservas_activas_count === 1 ? 'Cancélala o muévela' : 'Cancélalas o muévelas' }} a otra sala desde el Listado de reservas antes de darla de baja.
        </p>
        <template v-else>
          <p><strong class="text-gray-900">{{ aCambiar.nombre }}</strong> dejará de aparecer en el mesón y en el portal, y no se podrá reservar.</p>
          <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2.5 text-xs">Sus reservas y reportes se conservan, y puedes reactivarla cuando quieras.</p>
        </template>
      </template>
      <p v-else-if="aCambiar"><strong class="text-gray-900">{{ aCambiar.nombre }}</strong> volverá a aparecer en el mesón y en el portal, y se podrá reservar.</p>
    </DialogoConfirmacion>

    <DialogoConfirmacion :abierto="!!aEliminar" titulo="¿Eliminar esta sala?" confirmar="Sí, eliminar" tono="peligro" destacado :cargando="eliminando" @confirmar="eliminar" @cancelar="aEliminar = null">
      <template v-if="aEliminar">
        <p><strong class="text-gray-900">{{ aEliminar.nombre }}</strong> <span class="rounded border border-gray-200 bg-gray-50 px-1.5 py-px font-mono text-xs text-gray-700">{{ aEliminar.codigo }}</span> se eliminará.</p>
        <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2.5 text-xs">No tiene reservas, así que no se pierde ningún historial.</p>
      </template>
    </DialogoConfirmacion>
  </section>
</template>
