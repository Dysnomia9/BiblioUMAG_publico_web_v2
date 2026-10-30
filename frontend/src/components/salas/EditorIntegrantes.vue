<script setup lang="ts">
import { nextTick, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import SelectorDocumento from '@/components/ui/SelectorDocumento.vue'
import { convertirAlModo, errorSegunModo, escribirIdentificador, largoSegunModo, modoSegunValor, type ModoDocumento } from '@/utils/documento'
import { PERSONAS_MAXIMAS_SALA as MAXIMO, PERSONAS_MINIMAS_SALA as MINIMO } from '@/utils/reservas'
import type { IntegrantesReserva, TramoReserva } from '@/types'

/**
 * Lista editable de los integrantes de una reserva de sala: quitar a quien no vino,
 * sumar a otra persona o corregir un RUT. El servidor vuelve a revisar todo al
 * guardar (de 3 a 5 personas registradas, sin otra sala a esa hora, cuota diaria).
 */
const props = defineProps<{
  reservaId: number
  personas: TramoReserva['personas']
}>()

const emit = defineEmits<{
  guardado: [resultado: IntegrantesReserva]
  cancelar: []
}>()

// Cada integrante con su RUT o, si es extranjero, su pasaporte/DNI.
type Fila = { clave: number; rut: string; nombre: string | null; modo: ModoDocumento }

const toast = useToast()
let siguienteClave = 0
const filas = ref<Fila[]>(props.personas.map((p) => ({ clave: siguienteClave++, rut: p.rut, nombre: p.nombre, modo: modoSegunValor(p.rut) })))
while (filas.value.length < MINIMO) filas.value.push({ clave: siguienteClave++, rut: '', nombre: null, modo: 'rut' })
const errores = ref<Record<number, string>>({})
const guardando = ref(false)
const lista = ref<HTMLElement | null>(null)

function escribir(fila: Fila, idx: number, event: Event) {
  const resultado = escribirIdentificador(event, fila.modo)
  fila.rut = resultado.valor
  fila.modo = resultado.modo
  // Lo escrito ya no es necesariamente la persona que estaba: su nombre sale al guardar.
  fila.nombre = null
  limpiarError(idx)
}

function cambiarModo(fila: Fila, idx: number, modo: ModoDocumento) {
  fila.modo = modo
  fila.rut = convertirAlModo(fila.rut, modo)
  fila.nombre = null
  limpiarError(idx)
}

function limpiarError(idx: number) {
  if (errores.value[idx]) {
    const restantes = { ...errores.value }
    delete restantes[idx]
    errores.value = restantes
  }
}

function quitar(idx: number) {
  filas.value.splice(idx, 1)
  errores.value = {}
}

async function agregar() {
  filas.value.push({ clave: siguienteClave++, rut: '', nombre: null, modo: 'rut' })
  await nextTick()
  lista.value?.querySelectorAll('input')[filas.value.length - 1]?.focus()
}

async function guardar() {
  if (filas.value.some((f) => !f.rut.trim())) {
    toast.error('Complete el RUT (o pasaporte/DNI) de cada persona, o quite la fila vacía')
    return
  }
  const malas = Object.fromEntries(filas.value.map((f, i) => [i, errorSegunModo(f.rut, f.modo)] as const).filter(([, error]) => error))
  if (Object.keys(malas).length) {
    errores.value = malas
    toast.error(Object.keys(malas).length === 1 ? 'Revisa el RUT o documento marcado en rojo' : 'Revisa los RUT o documentos marcados en rojo')
    return
  }
  guardando.value = true
  errores.value = {}
  try {
    const { data } = await api.put<IntegrantesReserva>(`/reservas/${props.reservaId}/integrantes`, {
      ruts: filas.value.map((f) => f.rut),
    })
    emit('guardado', data)
  } catch (e: any) {
    const porCampo = Object.entries((e?.response?.data?.errors ?? {}) as Record<string, string[]>)
    const porFila = porCampo.filter(([campo]) => /^ruts\.\d+$/.test(campo))
    if (porFila.length) {
      errores.value = Object.fromEntries(porFila.map(([campo, mensajes]) => [Number(campo.split('.')[1]), mensajes[0]]))
      toast.error(porFila.length === 1 ? 'Revisa el RUT marcado en rojo' : 'Revisa los RUT marcados en rojo')
    } else {
      toast.error(porCampo[0]?.[1]?.[0] ?? e?.response?.data?.message ?? 'No se pudieron guardar los integrantes')
    }
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <div>
    <div ref="lista" class="space-y-2">
      <div v-for="(fila, idx) in filas" :key="fila.clave">
        <div class="flex items-center gap-2">
          <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold text-indigo-700">
            {{ idx + 1 }}
          </span>
          <input
            :value="fila.rut"
            @input="escribir(fila, idx, $event)"
            @keydown.enter="guardar"
            type="text"
            :maxlength="largoSegunModo(fila.modo)"
            :placeholder="`${fila.modo === 'rut' ? 'RUT' : 'Pasaporte o DNI'} persona ${idx + 1}`"
            :aria-label="`${fila.modo === 'rut' ? 'RUT' : 'Pasaporte o DNI'} de la persona ${idx + 1}`"
            :disabled="guardando"
            class="min-w-0 flex-1 px-3 py-2 border rounded-lg font-mono text-sm focus:ring-2 outline-none"
            :class="errores[idx] ? 'border-red-400 ring-1 ring-red-200 focus:ring-red-400' : 'border-gray-300 focus:ring-indigo-500'"
          />
          <SelectorDocumento
            :model-value="fila.modo"
            compacto
            :etiqueta="`Documento de la persona ${idx + 1}`"
            @update:model-value="cambiarModo(fila, idx, $event)"
          />
          <button
            type="button"
            @click="quitar(idx)"
            :disabled="filas.length <= MINIMO || guardando"
            :title="filas.length <= MINIMO ? `La reserva necesita al menos ${MINIMO} personas` : 'Quitar a esta persona'"
            :aria-label="`Quitar a la persona ${idx + 1}`"
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-red-50 hover:text-red-600 disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-gray-400"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <p v-if="fila.nombre" class="ml-9 mt-0.5 truncate text-xs text-gray-500">{{ fila.nombre }}</p>
        <p v-if="errores[idx]" class="ml-9 mt-1 text-xs text-red-600">{{ errores[idx] }}</p>
      </div>
    </div>

    <button
      v-if="filas.length < MAXIMO"
      type="button"
      @click="agregar"
      :disabled="guardando"
      class="mt-2 w-full rounded-lg border border-dashed border-indigo-300 py-2 text-xs font-semibold text-indigo-700 transition-colors hover:bg-indigo-50 disabled:opacity-60"
    >
      + Agregar persona
    </button>
    <p v-else class="mt-2 text-xs text-gray-400">Máximo {{ MAXIMO }} personas por reserva.</p>

    <div class="mt-3 flex gap-2">
      <button
        type="button"
        @click="emit('cancelar')"
        :disabled="guardando"
        class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-xs"
      >
        Descartar cambios
      </button>
      <button
        type="button"
        @click="guardar"
        :disabled="guardando"
        class="flex-1 px-3 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-xs disabled:opacity-60"
      >
        {{ guardando ? 'Guardando…' : 'Guardar integrantes' }}
      </button>
    </div>
  </div>
</template>
