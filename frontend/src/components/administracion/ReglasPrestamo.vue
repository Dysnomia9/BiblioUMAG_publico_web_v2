<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import type { ReglaPrestamo, TipoMaterial, TipoUsuario, Ubicacion } from '@/types'

/**
 * Reglas de préstamo: plazo, cuántos libros a la vez y multa, por tipo de usuario,
 * tipo de material y ubicación (vacío = todos). Al prestar se usa la más específica
 * que calce; si ninguna calza, lo de siempre (un libro a la vez y la tarifa general).
 */
const toast = useToast()

const reglas = ref<ReglaPrestamo[]>([])
const sinRegla = ref<{ max_ejemplares: number; multa_por_dia: number; tope_multa: number | null } | null>(null)
const tiposMaterial = ref<TipoMaterial[]>([])
const ubicaciones = ref<Ubicacion[]>([])

const TIPOS_USUARIO: Record<TipoUsuario, string> = { estudiante: 'Estudiantes', docente: 'Docentes', funcionario: 'Funcionarios' }

async function cargar() {
  try {
    const [r, t, u] = await Promise.all([
      api.get<{ reglas: ReglaPrestamo[]; sin_regla: typeof sinRegla.value }>('/reglas-prestamo'),
      api.get<TipoMaterial[]>('/tipos-material'),
      api.get<Ubicacion[]>('/ubicaciones'),
    ])
    reglas.value = r.data.reglas
    sinRegla.value = r.data.sin_regla
    tiposMaterial.value = t.data
    ubicaciones.value = u.data
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudieron cargar las reglas de préstamo'))
  }
}

onMounted(cargar)

const pesos = (monto: number) => new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP' }).format(monto)

/** «Estudiantes · Revista · Hemeroteca», con «todos» donde la regla no distingue. */
function aQuien(r: Pick<ReglaPrestamo, 'tipo_usuario' | 'tipo_material_id' | 'ubicacion_id'>) {
  return [
    r.tipo_usuario ? TIPOS_USUARIO[r.tipo_usuario] : 'Todos los usuarios',
    tiposMaterial.value.find((t) => t.id === r.tipo_material_id)?.nombre ?? 'todo material',
    ubicaciones.value.find((u) => u.id === r.ubicacion_id)?.nombre ?? 'toda ubicación',
  ].join(' · ')
}

function condiciones(r: ReglaPrestamo) {
  if (!r.prestable) return 'No se presta a domicilio'
  const partes = [
    r.dias_prestamo !== null ? `${r.dias_prestamo} ${r.dias_prestamo === 1 ? 'día' : 'días'}` : 'plazo a elección del mesón',
    `${r.max_ejemplares ?? sinRegla.value?.max_ejemplares ?? 1} a la vez`,
    `multa ${pesos(r.multa_por_dia ?? sinRegla.value?.multa_por_dia ?? 0)} por día`,
  ]
  const tope = r.tope_multa ?? sinRegla.value?.tope_multa
  partes.push(tope ? `tope ${pesos(tope)}` : 'sin tope')
  return partes.join(' · ')
}

type Ficha = {
  tipo_usuario: TipoUsuario | ''
  tipo_material_id: number | ''
  ubicacion_id: number | ''
  dias_prestamo: string
  max_ejemplares: string
  multa_por_dia: string
  tope_multa: string
  prestable: boolean
}
const fichaVacia = (): Ficha => ({ tipo_usuario: '', tipo_material_id: '', ubicacion_id: '', dias_prestamo: '', max_ejemplares: '', multa_por_dia: '', tope_multa: '', prestable: true })
const texto = (n: number | null) => (n === null ? '' : String(n))

const form = reactive({ abierto: false, regla: null as ReglaPrestamo | null, ficha: fichaVacia(), confirmando: false, guardando: false })

function abrirNueva() {
  Object.assign(form, { abierto: true, regla: null, ficha: fichaVacia(), confirmando: false })
}

function abrirEdicion(r: ReglaPrestamo) {
  Object.assign(form, {
    abierto: true,
    regla: r,
    ficha: {
      tipo_usuario: r.tipo_usuario ?? '',
      tipo_material_id: r.tipo_material_id ?? '',
      ubicacion_id: r.ubicacion_id ?? '',
      dias_prestamo: texto(r.dias_prestamo),
      max_ejemplares: texto(r.max_ejemplares),
      multa_por_dia: texto(r.multa_por_dia),
      tope_multa: texto(r.tope_multa),
      prestable: r.prestable,
    },
    confirmando: false,
  })
}

const numero = (v: string) => (v.trim() === '' ? null : Number(v))

const datos = computed(() => ({
  tipo_usuario: form.ficha.tipo_usuario || null,
  tipo_material_id: form.ficha.tipo_material_id === '' ? null : form.ficha.tipo_material_id,
  ubicacion_id: form.ficha.ubicacion_id === '' ? null : form.ficha.ubicacion_id,
  dias_prestamo: numero(form.ficha.dias_prestamo),
  max_ejemplares: numero(form.ficha.max_ejemplares),
  multa_por_dia: numero(form.ficha.multa_por_dia),
  tope_multa: numero(form.ficha.tope_multa),
  prestable: form.ficha.prestable,
}))

function pedirConfirmacion() {
  const f = form.ficha
  const malos = (['dias_prestamo', 'max_ejemplares', 'multa_por_dia', 'tope_multa'] as const).filter((c) => f[c].trim() !== '' && !/^\d+$/.test(f[c].trim()))
  if (malos.length) {
    toast.error('Plazo, máximo, multa y tope son números enteros (o déjalos vacíos para usar lo general)')
    return
  }
  form.confirmando = true
}

async function guardar() {
  form.guardando = true
  try {
    if (form.regla) {
      await api.put(`/reglas-prestamo/${form.regla.id}`, datos.value)
      toast.success('Regla de préstamo actualizada')
    } else {
      await api.post('/reglas-prestamo', datos.value)
      toast.success('Regla de préstamo creada')
    }
    form.confirmando = false
    form.abierto = false
    await cargar()
  } catch (e) {
    form.confirmando = false
    toast.error(mensajeDeError(e, 'No se pudo guardar la regla'))
  } finally {
    form.guardando = false
  }
}

const aEliminar = ref<ReglaPrestamo | null>(null)
const eliminando = ref(false)

async function eliminar() {
  if (!aEliminar.value) return
  eliminando.value = true
  try {
    await api.delete(`/reglas-prestamo/${aEliminar.value.id}`)
    toast.success('Regla eliminada')
    aEliminar.value = null
    await cargar()
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo eliminar la regla'))
  } finally {
    eliminando.value = false
  }
}

const CAMPO = 'w-full rounded-lg border border-gray-300 px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-500'
</script>

<template>
  <div class="bg-white rounded-xl shadow-md p-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between mb-4">
      <div>
        <h3 class="font-semibold text-gray-900 mb-1">Reglas de préstamo</h3>
        <p class="text-xs text-gray-500 max-w-xl">
          Plazo, cuántos libros a la vez y multa según quién pide, qué material y de dónde. Un campo vacío vale para todos. Al prestar se usa la regla
          <strong>más específica</strong> que calce (la que fija más criterios; si empatan, manda el tipo de material, luego la ubicación y luego el
          tipo de usuario). Si ninguna calza:
          <template v-if="sinRegla">un libro a la vez, {{ pesos(sinRegla.multa_por_dia) }} por día de atraso{{ sinRegla.tope_multa ? `, tope ${pesos(sinRegla.tope_multa)}` : '' }}, y la fecha la elige el mesón.</template>
        </p>
      </div>
      <button type="button" class="shrink-0 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm" @click="abrirNueva">
        + Nueva regla
      </button>
    </div>

    <div class="rounded-lg border border-gray-200 divide-y divide-gray-100">
      <div v-for="r in reglas" :key="r.id" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" :class="r.prestable ? '' : 'bg-gray-50'">
        <div class="min-w-0">
          <p class="text-sm font-medium text-gray-900">{{ aQuien(r) }}</p>
          <p class="text-xs" :class="r.prestable ? 'text-gray-500' : 'text-red-600'">{{ condiciones(r) }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-3 text-sm">
          <button type="button" class="font-medium text-indigo-700 hover:text-indigo-800" @click="abrirEdicion(r)">Editar</button>
          <button type="button" class="font-medium text-red-600 hover:text-red-700" @click="aEliminar = r">Eliminar</button>
        </div>
      </div>
      <p v-if="!reglas.length" class="px-4 py-6 text-center text-sm text-gray-400">Sin reglas: todos los préstamos siguen la regla general.</p>
    </div>

    <Modal
      :abierto="form.abierto"
      :titulo="form.regla ? 'Editar regla de préstamo' : 'Nueva regla de préstamo'"
      descripcion="Deja vacío lo que no quieras distinguir: vale para todos."
      ancho="lg"
      pie-fijo
      :bloqueado="form.guardando"
      @cerrar="form.abierto = false"
    >
      <div class="space-y-4 text-sm">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div>
            <label for="regla-usuario" class="mb-1 block text-xs font-medium text-gray-600">Tipo de usuario</label>
            <select id="regla-usuario" v-model="form.ficha.tipo_usuario" :class="CAMPO">
              <option value="">Todos</option>
              <option v-for="(nombre, clave) in TIPOS_USUARIO" :key="clave" :value="clave">{{ nombre }}</option>
            </select>
          </div>
          <div>
            <label for="regla-material" class="mb-1 block text-xs font-medium text-gray-600">Tipo de material</label>
            <select id="regla-material" v-model="form.ficha.tipo_material_id" :class="CAMPO">
              <option value="">Todos</option>
              <option v-for="t in tiposMaterial" :key="t.id" :value="t.id">{{ t.nombre }}</option>
            </select>
          </div>
          <div>
            <label for="regla-ubicacion" class="mb-1 block text-xs font-medium text-gray-600">Ubicación</label>
            <select id="regla-ubicacion" v-model="form.ficha.ubicacion_id" :class="CAMPO">
              <option value="">Todas</option>
              <option v-for="u in ubicaciones" :key="u.id" :value="u.id">{{ u.nombre }}</option>
            </select>
          </div>
        </div>

        <label class="flex items-start gap-2.5 rounded-lg border border-gray-200 px-3 py-2.5">
          <input v-model="form.ficha.prestable" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
          <span>
            <span class="font-medium text-gray-900">Se presta a domicilio</span>
            <span class="block text-xs text-gray-500">Si lo desmarcas, el mesón no podrá prestar este material (por ejemplo, obras de referencia).</span>
          </span>
        </label>

        <div v-if="form.ficha.prestable" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label for="regla-dias" class="mb-1 block text-xs font-medium text-gray-600">Días de préstamo</label>
            <input id="regla-dias" v-model="form.ficha.dias_prestamo" inputmode="numeric" maxlength="3" placeholder="El mesón elige la fecha" :class="CAMPO" />
            <p class="mt-1 text-[11px] text-gray-500">Si la fecha cae en un día sin atención, se corre al siguiente.</p>
          </div>
          <div>
            <label for="regla-max" class="mb-1 block text-xs font-medium text-gray-600">Libros a la vez</label>
            <input id="regla-max" v-model="form.ficha.max_ejemplares" inputmode="numeric" maxlength="3" :placeholder="`${sinRegla?.max_ejemplares ?? 1} (general)`" :class="CAMPO" />
          </div>
          <div>
            <label for="regla-multa" class="mb-1 block text-xs font-medium text-gray-600">Multa por día de atraso (CLP)</label>
            <input id="regla-multa" v-model="form.ficha.multa_por_dia" inputmode="numeric" maxlength="7" :placeholder="sinRegla ? `${sinRegla.multa_por_dia} (general)` : ''" :class="CAMPO" />
          </div>
          <div>
            <label for="regla-tope" class="mb-1 block text-xs font-medium text-gray-600">Tope de la multa (CLP)</label>
            <input id="regla-tope" v-model="form.ficha.tope_multa" inputmode="numeric" maxlength="8" :placeholder="sinRegla?.tope_multa ? `${sinRegla.tope_multa} (general)` : 'Sin tope'" :class="CAMPO" />
          </div>
        </div>
        <p v-if="form.ficha.prestable" class="text-xs text-gray-500">Los días que la biblioteca no atiende no se cobran como atraso.</p>
      </div>
      <template #pie>
        <button
          type="button"
          class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
          :disabled="form.guardando"
          @click="form.abierto = false"
        >
          Cancelar
        </button>
        <button
          type="button"
          class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60"
          :disabled="form.guardando"
          @click="pedirConfirmacion"
        >
          {{ form.regla ? 'Revisar cambios' : 'Revisar y crear' }}
        </button>
      </template>
    </Modal>

    <DialogoConfirmacion
      :abierto="form.confirmando"
      :titulo="form.regla ? '¿Guardar los cambios de la regla?' : '¿Crear esta regla?'"
      :confirmar="form.regla ? 'Sí, guardar' : 'Sí, crear'"
      :cargando="form.guardando"
      @confirmar="guardar"
      @cancelar="form.confirmando = false"
    >
      <p><strong class="text-gray-900">{{ aQuien(datos) }}</strong></p>
      <p class="mt-1">{{ condiciones({ ...datos, id: 0 } as ReglaPrestamo) }}</p>
      <p class="mt-2">Se aplica a los préstamos que se hagan desde ahora y a las multas de las devoluciones que vienen.</p>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="!!aEliminar"
      titulo="¿Eliminar esta regla?"
      confirmar="Sí, eliminar"
      tono="peligro"
      :cargando="eliminando"
      @confirmar="eliminar"
      @cancelar="aEliminar = null"
    >
      <p v-if="aEliminar">
        <strong class="text-gray-900">{{ aQuien(aEliminar) }}</strong>: esos préstamos pasarán a la siguiente regla que calce o, si no hay, a la general.
      </p>
    </DialogoConfirmacion>
  </div>
</template>
