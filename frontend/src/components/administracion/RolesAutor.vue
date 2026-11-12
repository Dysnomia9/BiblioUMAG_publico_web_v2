<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'

/**
 * Papeles de autor (MARC 700 $e): traductor, editor, profesor patrocinante… Se eligen al
 * lado de cada autor en Catalogación y la ficha agrupa a las personas por papel. Uno
 * desactivado deja de ofrecerse, pero los libros que ya lo tienen lo conservan.
 */
type Rol = { id: number; clave: string; nombre: string; abreviatura: string; plural: string; activo: boolean; en_uso: number }

const toast = useToast()
const roles = ref<Rol[]>([])
const cargando = ref(false)

async function cargar() {
  cargando.value = true
  try {
    roles.value = (await api.get<Rol[]>('/roles-autor', { params: { todos: 1 } })).data
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudieron cargar los papeles de autor'))
  } finally {
    cargando.value = false
  }
}
onMounted(cargar)

const ficha = reactive({ abierto: false, rol: null as Rol | null, nombre: '', abreviatura: '', plural: '', guardando: false })

function abrir(rol: Rol | null) {
  Object.assign(ficha, { abierto: true, rol, nombre: rol?.nombre ?? '', abreviatura: rol?.abreviatura ?? '', plural: rol?.plural ?? '', guardando: false })
}

/** Al crear uno, el plural se sugiere: «Asesor» → «Asesores». */
function sugerirPlural() {
  if (ficha.rol) return
  const n = ficha.nombre.trim()
  ficha.plural = !n ? '' : /[aeiouáéíóú]$/i.test(n) ? `${n}s` : `${n}es`
}

async function guardar() {
  ficha.guardando = true
  const datos = { nombre: ficha.nombre.trim(), abreviatura: ficha.abreviatura.trim(), plural: ficha.plural.trim() }
  try {
    if (ficha.rol) await api.patch(`/roles-autor/${ficha.rol.id}`, datos)
    else await api.post('/roles-autor', datos)
    toast.success(ficha.rol ? `Papel «${datos.nombre}» actualizado` : `Papel «${datos.nombre}» creado: ya se puede elegir al catalogar`)
    ficha.abierto = false
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo guardar el papel'))
  } finally {
    ficha.guardando = false
  }
}

async function cambiarActivo(rol: Rol) {
  try {
    await api.patch(`/roles-autor/${rol.id}`, { activo: !rol.activo })
    toast.success(rol.activo ? `«${rol.nombre}» ya no se ofrece al catalogar` : `«${rol.nombre}» vuelve a ofrecerse al catalogar`)
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo cambiar el papel'))
  }
}

const aEliminar = ref<Rol | null>(null)
const eliminando = ref(false)

async function eliminar() {
  if (!aEliminar.value) return
  eliminando.value = true
  try {
    await api.delete(`/roles-autor/${aEliminar.value.id}`)
    toast.success(`Papel «${aEliminar.value.nombre}» eliminado`)
    aEliminar.value = null
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo eliminar el papel'))
  } finally {
    eliminando.value = false
  }
}

const CAMPO = 'h-10 w-full rounded-lg border border-gray-300 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100'
</script>

<template>
  <section class="overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
    <header class="flex flex-col gap-3 border-b border-gray-100 bg-indigo-50/40 px-5 py-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h3 class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
          <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
          Papeles de autor
        </h3>
        <p class="mt-0.5 max-w-3xl pl-3 text-xs text-gray-500">
          Lo que hace cada persona en una obra (700 $e): traductor, editor, profesor patrocinante… Se elige al lado de cada autor en
          <strong>Catalogación</strong>, y la ficha muestra una fila por papel («Traductores», «Asesores»…). Uno <strong>desactivado</strong> deja de
          ofrecerse, pero los libros que ya lo tienen lo conservan; solo se <strong>elimina</strong> uno que ningún libro usa.
        </p>
      </div>
      <button type="button" class="shrink-0 self-start rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700" @click="abrir(null)">
        + Nuevo papel
      </button>
    </header>

    <div class="overflow-x-auto">
      <table class="w-full">
        <thead>
          <tr class="border-b border-gray-200 bg-gray-50 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
            <th class="px-4 py-2.5">Papel</th>
            <th class="hidden px-4 py-2.5 md:table-cell">Abreviatura</th>
            <th class="hidden px-4 py-2.5 md:table-cell">Fila en la ficha</th>
            <th class="px-4 py-2.5 text-right">Libros</th>
            <th class="px-4 py-2.5 text-right">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="r in roles" :key="r.id" :class="r.activo ? '' : 'bg-gray-50/70'">
            <td class="px-4 py-2 text-sm">
              <span :class="r.activo ? 'font-medium text-gray-900' : 'text-gray-400 line-through'">{{ r.nombre }}</span>
              <span v-if="!r.activo" class="ml-2 rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-semibold text-gray-600">Desactivado</span>
            </td>
            <td class="hidden px-4 py-2 font-mono text-xs text-gray-600 md:table-cell">{{ r.abreviatura }}</td>
            <td class="hidden px-4 py-2 text-sm text-gray-600 md:table-cell">{{ r.plural }}</td>
            <td class="px-4 py-2 text-right text-sm tabular-nums" :class="r.en_uso ? 'text-gray-700' : 'text-gray-400'">{{ r.en_uso.toLocaleString('es-CL') }}</td>
            <td class="whitespace-nowrap px-4 py-2 text-right">
              <button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50" @click="abrir(r)">Editar</button>
              <button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-100" @click="cambiarActivo(r)">{{ r.activo ? 'Desactivar' : 'Activar' }}</button>
              <button
                type="button"
                class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:text-gray-300 disabled:hover:bg-transparent"
                :disabled="r.en_uso > 0"
                :title="r.en_uso > 0 ? 'Está en uso: desactívalo en vez de eliminarlo' : undefined"
                @click="aEliminar = r"
              >
                Eliminar
              </button>
            </td>
          </tr>
          <tr v-if="!cargando && !roles.length">
            <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-400">Sin papeles todavía.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="ficha.abierto" :titulo="ficha.rol ? 'Editar papel' : 'Nuevo papel'" :bloqueado="ficha.guardando" @cerrar="ficha.abierto = false">
      <div class="space-y-3 text-sm">
        <div>
          <label for="rol-nombre" class="mb-1 block font-semibold text-gray-800">Nombre</label>
          <input id="rol-nombre" v-model="ficha.nombre" autocomplete="off" placeholder="Ej: Asesor metodológico" :class="CAMPO" @input="sugerirPlural" />
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label for="rol-abreviatura" class="mb-1 block font-semibold text-gray-800">Abreviatura</label>
            <input id="rol-abreviatura" v-model="ficha.abreviatura" autocomplete="off" placeholder="Ej: ases. metod." :class="CAMPO" />
          </div>
          <div>
            <label for="rol-plural" class="mb-1 block font-semibold text-gray-800">Fila en la ficha</label>
            <input id="rol-plural" v-model="ficha.plural" autocomplete="off" placeholder="Ej: Asesores metodológicos" :class="CAMPO" />
          </div>
        </div>
        <p class="text-[11px] text-gray-500">La ficha agrupa a las personas con este papel en una fila con el nombre de «Fila en la ficha».</p>
        <p v-if="ficha.rol?.en_uso" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
          Lo tienen {{ ficha.rol.en_uso }} {{ ficha.rol.en_uso === 1 ? 'libro' : 'libros' }}: el cambio se verá en todas sus fichas.
        </p>
      </div>
      <template #pie>
        <button type="button" class="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100" :disabled="ficha.guardando" @click="ficha.abierto = false">Cancelar</button>
        <button
          type="button"
          class="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
          :disabled="ficha.guardando || !ficha.nombre.trim() || !ficha.abreviatura.trim() || !ficha.plural.trim()"
          @click="guardar"
        >
          {{ ficha.guardando ? 'Guardando…' : 'Guardar' }}
        </button>
      </template>
    </Modal>

    <DialogoConfirmacion :abierto="!!aEliminar" titulo="Eliminar papel" confirmar="Sí, eliminar" tono="peligro" :cargando="eliminando" @confirmar="eliminar" @cancelar="aEliminar = null">
      ¿Eliminar el papel «{{ aEliminar?.nombre }}»? Ningún libro lo usa.
    </DialogoConfirmacion>
  </section>
</template>
