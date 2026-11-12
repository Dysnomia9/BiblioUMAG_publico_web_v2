<script setup lang="ts">
import { computed } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import { estadoDe, fechaYHora } from '@/utils/prestamos'
import { itemPrestamo } from '@/utils/recursos'
import type { Prestamo, Usuario } from '@/types'

/**
 * «¿Confirmar devolución?» de un libro o de un recurso de apoyo (notebook, cargador…):
 * qué se devuelve con su estado («Vence hoy», «Vencido»), a quién se prestó, el código,
 * y cuándo se prestó y cuándo vence, con «Hoy» y la hora cuando la tiene.
 */
const props = defineProps<{
  prestamo: Prestamo | null
  usuario: Pick<Usuario, 'nombre' | 'apellido' | 'rut'> | null
  cargando?: boolean
}>()
const emit = defineEmits<{ confirmar: []; cancelar: [] }>()

const item = computed(() => (props.prestamo ? itemPrestamo(props.prestamo.tipo_item) : null))
const estado = computed(() => (props.prestamo ? estadoDe(props.prestamo) : null))
const prestado = computed(() => (props.prestamo ? fechaYHora(props.prestamo.fecha_prestamo) : null))
const vence = computed(() => (props.prestamo?.fecha_devolucion ? fechaYHora(props.prestamo.fecha_devolucion) : null))
</script>

<template>
  <Modal :abierto="!!prestamo" titulo="¿Confirmar devolución?" :bloqueado="cargando" destacado tono="exito" ancho="md" @cerrar="emit('cancelar')">
    <div v-if="prestamo && item" class="space-y-4">
      <div class="flex items-center gap-3.5 rounded-xl border border-emerald-200 bg-emerald-50/70 p-4">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-gray-200 bg-white text-sky-600 shadow-sm" aria-hidden="true">
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" :d="item.icono" /></svg>
        </span>
        <div class="min-w-0 flex-1">
          <p class="line-clamp-2 text-base font-bold leading-snug text-gray-900">{{ prestamo.libro_titulo }}</p>
          <p class="mt-0.5 text-sm text-gray-600">Se registrará la devolución</p>
        </div>
        <span v-if="estado" class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold" :class="estado.clase">{{ estado.texto }}</span>
      </div>

      <dl class="overflow-hidden rounded-xl border border-gray-200 text-sm">
        <div v-if="usuario" class="grid grid-cols-[7rem_1fr] gap-3 border-b border-gray-100 px-4 py-2.5">
          <dt class="text-gray-500">Prestado a</dt>
          <dd class="font-semibold text-gray-900">{{ usuario.nombre }} {{ usuario.apellido }}</dd>
        </div>
        <div v-if="usuario" class="grid grid-cols-[7rem_1fr] gap-3 border-b border-gray-100 px-4 py-2.5">
          <dt class="text-gray-500">RUT</dt>
          <dd class="font-mono text-[15px] font-semibold text-gray-900">{{ usuario.rut }}</dd>
        </div>
        <div v-if="prestamo.codigo_barras" class="grid grid-cols-[7rem_1fr] gap-3 border-b border-gray-100 px-4 py-2.5">
          <dt class="text-gray-500">Código</dt>
          <dd class="font-mono text-[15px] font-semibold text-gray-900">{{ prestamo.codigo_barras }}</dd>
        </div>
        <div class="grid grid-cols-2 bg-gray-50/70">
          <div class="px-4 py-2.5">
            <dt class="text-gray-500">Prestado el</dt>
            <dd class="mt-0.5 font-semibold text-gray-900">{{ prestado?.dia }}<span v-if="prestado?.hora" class="font-normal text-gray-600"> · {{ prestado.hora }}</span></dd>
          </div>
          <div class="border-l border-gray-100 px-4 py-2.5">
            <dt class="text-gray-500">Vence el</dt>
            <dd class="mt-0.5 font-semibold text-gray-900">
              <template v-if="vence">{{ vence.dia }}<span v-if="vence.hora" class="font-normal text-gray-600"> · {{ vence.hora }}</span></template>
              <template v-else>{{ prestamo.en_sala ? 'Hoy, antes del cierre' : '—' }}</template>
            </dd>
          </div>
        </div>
      </dl>
    </div>
    <template #pie>
      <button
        type="button"
        class="flex-1 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-100"
        :disabled="cargando"
        @click="emit('cancelar')"
      >
        Cancelar
      </button>
      <button
        type="button"
        class="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] transition-colors hover:bg-emerald-700 disabled:opacity-60"
        :disabled="cargando"
        @click="emit('confirmar')"
      >
        {{ cargando ? 'Confirmando…' : 'Sí, devolver' }}
      </button>
    </template>
  </Modal>
</template>
