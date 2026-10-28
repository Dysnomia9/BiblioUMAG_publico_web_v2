<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { fechaVigencia, useVigencia } from '@/utils/vigencia'
import type { ModoVigencia, PoliticaVigencia } from '@/types'

/**
 * Administración › Vigencia de cuentas: cuánto dura la cuenta de cada tipo de usuario
 * al renovarla. Los docentes y funcionarios rotan menos: pueden vencer más lejos o no vencer.
 * Cambiar esto no toca las cuentas que ya existen, solo lo que reciben al renovarse.
 */
const toast = useToast()
const vigencia = useVigencia()

const NOMBRES: Record<string, string> = { estudiante: 'Estudiantes', docente: 'Docentes', funcionario: 'Funcionarios' }
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
const MODOS: { id: ModoVigencia; texto: string; ayuda: string }[] = [
  { id: 'fecha_fija', texto: 'Fecha fija del año', ayuda: 'Vence ese día del año siguiente (ej. 31 de marzo).' },
  { id: 'duracion', texto: 'Cantidad de años', ayuda: 'Vence tantos años después de renovar.' },
  { id: 'sin_vencimiento', texto: 'No vence', ayuda: 'La cuenta sigue activa hasta que alguien la desactive.' },
]

type Fila = { tipo: string; modo: ModoVigencia; mes: number; dia: number; anios: number; proximo: string | null; cuentas_vigentes: number; por_vencer_60_dias: number }

const filas = ref<Fila[]>([])
const original = ref('')
const cargando = ref(true)
const apiError = ref(false)
const errores = reactive<Record<string, string>>({})
const confirmando = ref(false)
const guardando = ref(false)

const aFila = (p: PoliticaVigencia): Fila => ({
  tipo: p.tipo, modo: p.modo, mes: p.mes ?? 3, dia: p.dia ?? 31, anios: p.anios ?? 3,
  proximo: p.proximo, cuentas_vigentes: p.cuentas_vigentes, por_vencer_60_dias: p.por_vencer_60_dias,
})
const firma = (f: Fila[]) => JSON.stringify(f.map(({ tipo, modo, mes, dia, anios }) => [tipo, modo, modo === 'fecha_fija' ? [mes, dia] : null, modo === 'duracion' ? anios : null]))
const hayCambios = computed(() => firma(filas.value) !== original.value)

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get<PoliticaVigencia[]>('/vigencia')
    filas.value = data.map(aFila)
    original.value = firma(filas.value)
    apiError.value = false
  } catch {
    apiError.value = true
  } finally {
    cargando.value = false
  }
}

/** Cómo quedaría el tipo, en palabras, para mostrar junto a los controles. */
function resumen(f: Fila): string {
  if (f.modo === 'sin_vencimiento') return 'No vence'
  if (f.modo === 'duracion') return f.anios === 1 ? 'Vence un año después de renovar' : `Vence ${f.anios} años después de renovar`
  return `Vence el ${f.dia} de ${MESES[f.mes - 1]} del año siguiente`
}

function pedirConfirmacion() {
  Object.keys(errores).forEach((k) => delete errores[k])
  filas.value.forEach((f, i) => {
    if (f.modo === 'fecha_fija' && new Date(2028, f.mes - 1, f.dia).getMonth() !== f.mes - 1) errores[`politicas.${i}.dia`] = 'Ese día no existe en ese mes.'
    if (f.modo === 'duracion' && !(f.anios >= 1 && f.anios <= 10)) errores[`politicas.${i}.anios`] = 'Entre 1 y 10 años.'
  })
  if (Object.keys(errores).length) return
  confirmando.value = true
}

async function guardar() {
  guardando.value = true
  try {
    const { data } = await api.put<PoliticaVigencia[]>('/vigencia', {
      politicas: filas.value.map((f) => ({
        tipo: f.tipo,
        modo: f.modo,
        mes: f.modo === 'fecha_fija' ? f.mes : null,
        dia: f.modo === 'fecha_fija' ? f.dia : null,
        anios: f.modo === 'duracion' ? f.anios : null,
      })),
      confirmado: true,
    })
    filas.value = data.map(aFila)
    original.value = firma(filas.value)
    await vigencia.cargar(true)
    toast.success('Vigencia de las cuentas actualizada')
    confirmando.value = false
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo guardar la vigencia'))
    confirmando.value = false
  } finally {
    guardando.value = false
  }
}

onMounted(cargar)

const CAMPO = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30'
</script>

<template>
  <section class="rounded-xl bg-white p-6 shadow-md">
    <h2 class="text-lg font-semibold text-gray-900">Vigencia de las cuentas</h2>
    <p class="mt-1 text-sm text-gray-500">
      Cuánto dura la cuenta de cada tipo de usuario al renovarla. Los docentes y funcionarios rotan menos que los estudiantes: pueden vencer más lejos o no
      vencer. Cambiar esto <strong>no toca las cuentas que ya existen</strong>: solo la fecha que reciben de ahora en adelante al renovarse (a mano o en una
      carga masiva).
    </p>

    <ApiErrorBanner v-if="apiError" class="mt-4" />

    <div v-if="!cargando && filas.length" class="mt-5 space-y-4">
      <div v-for="(f, i) in filas" :key="f.tipo" class="rounded-xl border border-gray-200 p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h3 class="font-semibold text-gray-900">{{ NOMBRES[f.tipo] }}</h3>
          <p class="text-xs text-gray-500">
            {{ f.cuentas_vigentes.toLocaleString('es-CL') }} cuentas vigentes<template v-if="f.por_vencer_60_dias"> · {{ f.por_vencer_60_dias.toLocaleString('es-CL') }} vencen en 60 días</template>
          </p>
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
          <div>
            <label :for="`modo-${f.tipo}`" class="mb-1 block text-xs font-medium text-gray-600">Cómo vence</label>
            <select :id="`modo-${f.tipo}`" v-model="f.modo" :class="CAMPO">
              <option v-for="m in MODOS" :key="m.id" :value="m.id">{{ m.texto }}</option>
            </select>
            <p class="mt-1 text-xs text-gray-500">{{ MODOS.find((m) => m.id === f.modo)?.ayuda }}</p>
          </div>

          <div v-if="f.modo === 'fecha_fija'" class="flex gap-2">
            <div>
              <label :for="`dia-${f.tipo}`" class="mb-1 block text-xs font-medium text-gray-600">Día</label>
              <input :id="`dia-${f.tipo}`" v-model.number="f.dia" type="number" min="1" max="31" class="w-20 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30" />
            </div>
            <div>
              <label :for="`mes-${f.tipo}`" class="mb-1 block text-xs font-medium text-gray-600">Mes</label>
              <select :id="`mes-${f.tipo}`" v-model.number="f.mes" :class="CAMPO">
                <option v-for="(m, k) in MESES" :key="m" :value="k + 1">{{ m }}</option>
              </select>
            </div>
          </div>
          <div v-else-if="f.modo === 'duracion'">
            <label :for="`anios-${f.tipo}`" class="mb-1 block text-xs font-medium text-gray-600">Años</label>
            <input :id="`anios-${f.tipo}`" v-model.number="f.anios" type="number" min="1" max="10" class="w-24 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30" />
          </div>
        </div>

        <p v-if="errores[`politicas.${i}.dia`] || errores[`politicas.${i}.anios`]" class="mt-2 text-xs text-red-600">
          {{ errores[`politicas.${i}.dia`] || errores[`politicas.${i}.anios`] }}
        </p>
        <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700">
          {{ resumen(f) }}.
          <span v-if="!hayCambios">
            Si se renueva hoy: <strong>{{ f.proximo ? fechaVigencia(f.proximo) : 'sin vencimiento' }}</strong>.
          </span>
        </p>
      </div>

      <div class="flex justify-end">
        <button
          type="button"
          class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="!hayCambios"
          @click="pedirConfirmacion"
        >
          Guardar cambios
        </button>
      </div>
    </div>

    <DialogoConfirmacion
      :abierto="confirmando"
      titulo="¿Cambiar la vigencia de las cuentas?"
      confirmar="Sí, guardar"
      :cargando="guardando"
      @confirmar="guardar"
      @cancelar="confirmando = false"
    >
      <ul class="space-y-1">
        <li v-for="f in filas" :key="f.tipo"><strong class="text-gray-900">{{ NOMBRES[f.tipo] }}:</strong> {{ resumen(f) }}</li>
      </ul>
      <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-amber-800">
        No cambia las cuentas que ya existen: aplica a las renovaciones y cargas masivas que se hagan desde ahora. Queda registrado en la bitácora.
      </p>
    </DialogoConfirmacion>
  </section>
</template>
