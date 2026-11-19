<script setup lang="ts">
// Las pestañas del mesón de salas (Piso 1, HCM, Administración…): crearlas, renombrarlas,
// ordenarlas, elegir sus salas y eliminarlas (con confirmación). Al eliminar una pestaña sus
// salas no se tocan: pasan a «Otras». Se abre desde Administración › Salas (catalogos.gestionar).
import { computed, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import type { PestanaSala } from '@/types'

/** Lo que hace falta de cada sala: con qué se muestra y en qué pestaña está. */
type SalaDePestana = { id: number; codigo: string; nombre: string; pestana_id?: number | null }

const props = defineProps<{ abierto: boolean; pestanas: PestanaSala[]; salas: SalaDePestana[] }>()
const emit = defineEmits<{ cerrar: []; actualizado: [] }>()
const toast = useToast()

const guardando = ref(false)
const nombres = ref<Record<number, string>>({})
const nuevaNombre = ref('')
const nuevaApartada = ref(false)
/** La pestaña cuyas salas se están eligiendo, y las elegidas. */
const editandoSalas = ref<number | null>(null)
const elegidas = ref<Set<number>>(new Set())
const porEliminar = ref<PestanaSala | null>(null)

watch(
  () => [props.abierto, props.pestanas] as const,
  () => {
    nombres.value = Object.fromEntries(props.pestanas.map((p) => [p.id, p.nombre]))
  },
  { immediate: true },
)
watch(
  () => props.abierto,
  (abierto) => {
    if (!abierto) {
      editandoSalas.value = null
      nuevaNombre.value = ''
      nuevaApartada.value = false
    }
  },
)

const nombrePestana = (id: number | null | undefined) => props.pestanas.find((p) => p.id === id)?.nombre ?? 'Otras'
const cuantasSalas = (id: number) => props.salas.filter((s) => s.pestana_id === id).length
const salasOrdenadas = computed(() => [...props.salas].sort((a, b) => a.codigo.localeCompare(b.codigo, 'es', { numeric: true })))

function mensajeError(e: any, porDefecto: string) {
  const errores = e?.response?.data?.errors
  return (errores && (Object.values(errores)[0] as string[])?.[0]) || e?.response?.data?.message || porDefecto
}

async function ejecutar(accion: () => Promise<unknown>, exito: string, error: string) {
  guardando.value = true
  try {
    await accion()
    toast.success(exito)
    emit('actualizado')
    return true
  } catch (e) {
    toast.error(mensajeError(e, error))
    return false
  } finally {
    guardando.value = false
  }
}

async function crear() {
  const nombre = nuevaNombre.value.trim()
  if (!nombre) return
  const ok = await ejecutar(() => api.post('/pestanas-salas', { nombre, apartada: nuevaApartada.value }), `Pestaña «${nombre}» creada`, 'No se pudo crear la pestaña')
  if (ok) {
    nuevaNombre.value = ''
    nuevaApartada.value = false
  }
}

async function renombrar(p: PestanaSala) {
  const nombre = (nombres.value[p.id] ?? '').trim()
  if (!nombre || nombre === p.nombre) {
    nombres.value[p.id] = p.nombre
    return
  }
  const ok = await ejecutar(() => api.put(`/pestanas-salas/${p.id}`, { nombre }), 'Nombre guardado', 'No se pudo renombrar la pestaña')
  if (!ok) nombres.value[p.id] = p.nombre
}

function cambiarSinRegistro(p: PestanaSala) {
  ejecutar(
    () => api.put(`/pestanas-salas/${p.id}`, { admite_sin_registro: !p.admite_sin_registro }),
    p.admite_sin_registro ? `En «${p.nombre}» vuelve a pedirse el RUT de un usuario registrado` : `En «${p.nombre}» se puede reservar con el nombre de quien no está registrado`,
    'No se pudo cambiar la pestaña',
  )
}

function cambiarApartada(p: PestanaSala) {
  ejecutar(
    () => api.put(`/pestanas-salas/${p.id}`, { apartada: !p.apartada }),
    p.apartada ? `«${p.nombre}» vuelve junto a las demás` : `«${p.nombre}» queda al final, apartada`,
    'No se pudo cambiar la pestaña',
  )
}

/** Sube o baja una pestaña dentro de su grupo (las de siempre o las apartadas). */
function mover(p: PestanaSala, paso: -1 | 1) {
  const grupo = props.pestanas.filter((x) => x.apartada === p.apartada)
  const i = grupo.findIndex((x) => x.id === p.id)
  const j = i + paso
  if (j < 0 || j >= grupo.length) return
  const ids = grupo.map((x) => x.id)
  ;[ids[i], ids[j]] = [ids[j], ids[i]]
  ejecutar(() => api.put('/pestanas-salas/orden', { ids }), 'Orden guardado', 'No se pudo cambiar el orden')
}

function abrirSalas(p: PestanaSala) {
  if (editandoSalas.value === p.id) {
    editandoSalas.value = null
    return
  }
  editandoSalas.value = p.id
  elegidas.value = new Set(props.salas.filter((s) => s.pestana_id === p.id).map((s) => s.id))
}

function alternar(id: number) {
  const nuevas = new Set(elegidas.value)
  if (nuevas.has(id)) nuevas.delete(id)
  else nuevas.add(id)
  elegidas.value = nuevas
}

async function guardarSalas(p: PestanaSala) {
  const ok = await ejecutar(() => api.put(`/pestanas-salas/${p.id}/salas`, { sala_ids: [...elegidas.value] }), `Salas de «${p.nombre}» guardadas`, 'No se pudieron guardar las salas')
  if (ok) editandoSalas.value = null
}

async function eliminar() {
  const p = porEliminar.value
  if (!p) return
  const ok = await ejecutar(() => api.delete(`/pestanas-salas/${p.id}`), `Pestaña «${p.nombre}» eliminada`, 'No se pudo eliminar la pestaña')
  if (ok) {
    if (editandoSalas.value === p.id) editandoSalas.value = null
    porEliminar.value = null
  }
}
</script>

<template>
  <Modal :abierto="abierto" titulo="Pestañas de salas" descripcion="Agrupan las salas en el mesón. Una sala va en una sola pestaña; la que no está en ninguna sale en «Otras»." ancho="lg" :bloqueado="guardando" pie-fijo @cerrar="emit('cerrar')">
    <ul class="divide-y divide-gray-100 rounded-xl border border-gray-200">
      <li v-for="p in pestanas" :key="p.id" class="px-3 py-2.5">
        <div class="flex flex-wrap items-center gap-2">
          <div class="flex flex-col">
            <button type="button" class="rounded px-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30" :disabled="guardando" :aria-label="`Subir ${p.nombre}`" @click="mover(p, -1)">▲</button>
            <button type="button" class="rounded px-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30" :disabled="guardando" :aria-label="`Bajar ${p.nombre}`" @click="mover(p, 1)">▼</button>
          </div>
          <input
            v-model="nombres[p.id]"
            type="text"
            maxlength="40"
            :aria-label="`Nombre de la pestaña ${p.nombre}`"
            class="min-w-[140px] flex-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-500"
            @keydown.enter.prevent="($event.target as HTMLInputElement).blur()"
            @blur="renombrar(p)"
          />
          <span class="text-xs tabular-nums text-gray-500">{{ cuantasSalas(p.id) }} salas</span>
          <label class="inline-flex items-center gap-1.5 text-xs text-gray-600" title="Va al final de la barra, separada de las demás (como Administración)">
            <input type="checkbox" :checked="p.apartada" :disabled="guardando" class="rounded border-gray-300 text-indigo-600" @change="cambiarApartada(p)" />
            Apartada
          </label>
          <label class="inline-flex items-center gap-1.5 text-xs text-gray-600" title="En sus salas, el mesón puede reservar escribiendo el nombre de quien no está registrado en el sistema (académicos o docentes externos)">
            <input type="checkbox" :checked="!!p.admite_sin_registro" :disabled="guardando" class="rounded border-gray-300 text-indigo-600" @change="cambiarSinRegistro(p)" />
            Sin registro
          </label>
          <button
            type="button"
            class="rounded-lg border px-2.5 py-1 text-xs font-semibold transition-colors"
            :class="editandoSalas === p.id ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-100'"
            :aria-expanded="editandoSalas === p.id"
            @click="abrirSalas(p)"
          >
            Salas
          </button>
          <button type="button" class="rounded-lg border border-red-200 px-2.5 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" :disabled="guardando" @click="porEliminar = p">Eliminar</button>
        </div>

        <div v-if="editandoSalas === p.id" class="mt-2.5 rounded-lg bg-gray-50 p-2.5">
          <p class="mb-2 text-xs text-gray-500">Marque las salas que van en «{{ p.nombre }}». Una sala marcada que estaba en otra pestaña pasa a esta; la que se desmarca queda en «Otras».</p>
          <div class="grid max-h-64 grid-cols-2 gap-1 overflow-y-auto sm:grid-cols-3">
            <label v-for="s in salasOrdenadas" :key="s.id" class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1 text-sm hover:bg-white">
              <input type="checkbox" :checked="elegidas.has(s.id)" class="rounded border-gray-300 text-indigo-600" @change="alternar(s.id)" />
              <span class="min-w-0">
                <span class="font-semibold text-gray-800">{{ s.codigo }}</span>
                <span v-if="s.pestana_id !== p.id" class="block truncate text-[11px] text-gray-400">en {{ nombrePestana(s.pestana_id) }}</span>
              </span>
            </label>
          </div>
          <div class="mt-2 flex justify-end gap-2">
            <button type="button" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-white" @click="editandoSalas = null">Cancelar</button>
            <button type="button" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-60" :disabled="guardando" @click="guardarSalas(p)">Guardar salas</button>
          </div>
        </div>
      </li>
      <li v-if="!pestanas.length" class="px-3 py-4 text-center text-sm text-gray-500">No hay pestañas: todas las salas salen en «Otras».</li>
    </ul>

    <form class="mt-3 flex flex-wrap items-center gap-2" @submit.prevent="crear">
      <input
        v-model="nuevaNombre"
        type="text"
        maxlength="40"
        placeholder="Nueva pestaña (ej. Subterráneo)"
        aria-label="Nombre de la nueva pestaña"
        class="min-w-[180px] flex-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
      />
      <label class="inline-flex items-center gap-1.5 text-xs text-gray-600">
        <input v-model="nuevaApartada" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
        Apartada
      </label>
      <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60" :disabled="guardando || !nuevaNombre.trim()">Agregar pestaña</button>
    </form>

    <template #pie>
      <button type="button" class="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100" @click="emit('cerrar')">Listo</button>
    </template>
  </Modal>

  <DialogoConfirmacion
    :abierto="!!porEliminar"
    :titulo="`¿Eliminar la pestaña «${porEliminar?.nombre ?? ''}»?`"
    confirmar="Eliminar pestaña"
    tono="peligro"
    :cargando="guardando"
    @confirmar="eliminar"
    @cancelar="porEliminar = null"
  >
    <template v-if="porEliminar && cuantasSalas(porEliminar.id)">
      Sus {{ cuantasSalas(porEliminar.id) }} salas no se eliminan: pasan a la pestaña «Otras», con sus reservas e historial.
    </template>
    <template v-else>La pestaña no tiene salas.</template>
  </DialogoConfirmacion>
</template>
