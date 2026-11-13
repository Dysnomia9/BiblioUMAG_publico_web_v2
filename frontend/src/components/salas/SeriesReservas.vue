<script setup lang="ts">
import { onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'

/**
 * Panel de las reservas que se repiten semana a semana (permiso «Reservas especiales de
 * salas»): cada serie con reservas por delante, cuántas le quedan, y cancelar las que quedan.
 * Las series se crean desde Salas, con «Repetir cada semana» en la ventana de nueva reserva.
 */
type Serie = {
  serie: string
  sala: { id: number; nombre: string; codigo: string } | null
  a_cargo: { rut: string; nombre: string | null }
  cantidad_personas: number
  dias: number[]
  hora_inicio: string
  hora_fin: string
  desde: string
  hasta: string
  proxima: string | null
  total: number
  quedan: number
  canceladas: number
}

const emit = defineEmits<{ cambio: [] }>()
const toast = useToast()

const series = ref<Serie[]>([])
const cargando = ref(true)
const abierto = ref(true)
const porCancelar = ref<Serie | null>(null)
const cancelando = ref(false)

const DIAS = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábados', 'domingos']
/** [1, 3] → «lunes y miércoles». */
const losDias = (dias: number[]) => {
  const nombres = dias.map((d) => DIAS[d])
  return nombres.length > 1 ? `${nombres.slice(0, -1).join(', ')} y ${nombres[nombres.length - 1]}` : nombres[0]
}
const fecha = (f: string) => new Date(`${f}T12:00:00`).toLocaleDateString('es-CL', { day: 'numeric', month: 'short' })

async function cargar() {
  try {
    const { data } = await api.get<Serie[]>('/reservas/series')
    series.value = data
  } catch {
    series.value = []
  } finally {
    cargando.value = false
  }
}

async function cancelar() {
  const s = porCancelar.value
  if (!s) return
  cancelando.value = true
  try {
    const { data } = await api.delete<{ canceladas: number }>(`/reservas/serie/${s.serie}`)
    toast.success(`Se cancelaron ${data.canceladas} ${data.canceladas === 1 ? 'reserva' : 'reservas'} de ${s.sala?.nombre ?? 'la sala'}`)
    porCancelar.value = null
    await cargar()
    emit('cambio')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'No se pudo cancelar la serie')
  } finally {
    cancelando.value = false
  }
}

onMounted(cargar)
defineExpose({ cargar })
</script>

<template>
  <section class="mb-4 overflow-hidden rounded-2xl border border-t-2 border-gray-200 border-t-indigo-600 bg-white shadow-sm">
    <button type="button" class="flex w-full items-center justify-between gap-3 bg-indigo-50/40 px-5 py-3 text-left" :aria-expanded="abierto" @click="abierto = !abierto">
      <span>
        <span class="flex items-center gap-2 font-serif text-base font-bold text-gray-900">
          <span class="h-4 w-1 rounded-full bg-indigo-600" aria-hidden="true"></span>
          Reservas que se repiten
          <span v-if="series.length" class="rounded-full bg-indigo-100 px-2 text-xs font-bold tabular-nums text-indigo-700">{{ series.length }}</span>
        </span>
        <span class="mt-0.5 block text-xs text-gray-600">Se crean en Salas con «Repetir cada semana» al hacer una reserva. Aquí se ven las que siguen vigentes.</span>
      </span>
      <svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform" :class="abierto ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
    </button>

    <template v-if="abierto">
      <p v-if="cargando" class="px-5 py-4 text-sm text-gray-400">Cargando…</p>
      <p v-else-if="!series.length" class="px-5 py-4 text-sm text-gray-500">No hay reservas que se repitan por delante.</p>
      <ul v-else class="divide-y divide-gray-100">
        <li v-for="s in series" :key="s.serie" class="flex flex-wrap items-center gap-x-5 gap-y-2 px-5 py-3">
          <div class="min-w-[12rem] flex-1">
            <p class="text-sm font-semibold text-gray-900">
              <span class="mr-1 rounded bg-gray-100 px-1 font-mono text-xs text-gray-700">{{ s.sala?.codigo }}</span>{{ s.sala?.nombre }}
            </p>
            <p class="text-xs text-gray-600">{{ s.a_cargo.nombre ?? s.a_cargo.rut }} · {{ s.cantidad_personas }} {{ s.cantidad_personas === 1 ? 'persona' : 'personas' }}</p>
          </div>
          <div class="min-w-[12rem] text-sm text-gray-800">
            <p class="first-letter:uppercase">{{ losDias(s.dias) }}, {{ s.hora_inicio }} a {{ s.hora_fin }}</p>
            <p class="text-xs text-gray-500">Del {{ fecha(s.desde) }} al {{ fecha(s.hasta) }}</p>
          </div>
          <div class="text-xs text-gray-600">
            <p><strong class="text-gray-900 tabular-nums">{{ s.quedan }}</strong> por delante de {{ s.total }}<template v-if="s.canceladas"> · {{ s.canceladas }} canceladas</template></p>
            <p v-if="s.proxima">Próxima: {{ fecha(s.proxima) }}</p>
          </div>
          <button
            type="button"
            class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 transition-colors hover:bg-red-50"
            @click="porCancelar = s"
          >
            Cancelar las que quedan
          </button>
        </li>
      </ul>
    </template>

    <DialogoConfirmacion
      :abierto="!!porCancelar"
      titulo="¿Cancelar lo que queda de esta serie?"
      :cargando="cancelando"
      confirmar="Sí, cancelarlas"
      cancelar="No, mantener"
      tono="peligro"
      @confirmar="cancelar"
      @cancelar="porCancelar = null"
    >
      <p v-if="porCancelar" class="text-sm text-gray-600">
        Se cancelarán las <strong>{{ porCancelar.quedan }}</strong> reservas que quedan de {{ porCancelar.sala?.nombre }}
        ({{ losDias(porCancelar.dias) }}, {{ porCancelar.hora_inicio }} a {{ porCancelar.hora_fin }}). Las que ya pasaron quedan como registro.
      </p>
    </DialogoConfirmacion>
  </section>
</template>
