<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import type { Ejemplar, Ubicacion } from '@/types'

/**
 * Edita los datos de una copia ya catalogada: ubicación, volumen, colección y notas.
 * La colección vacía vale la del libro; la nota pública se ve en el portal y la
 * interna solo la ve el personal. El estado se cambia en Estado de ejemplares.
 */
const props = defineProps<{ copia: Ejemplar | null; coleccionLibro: string | null; ubicaciones: Ubicacion[] }>()
const emit = defineEmits<{ cerrar: []; guardada: [copia: Ejemplar] }>()

const toast = useToast()
const form = reactive({ ubicacion_id: '' as number | '', volumen: '', coleccion: '', nota_publica: '', nota_interna: '', guardando: false })

watch(
  () => props.copia,
  (c) => {
    if (!c) return
    Object.assign(form, {
      ubicacion_id: c.ubicacion_id ?? '',
      volumen: c.volumen ?? '',
      coleccion: c.coleccion ?? '',
      nota_publica: c.nota_publica ?? '',
      nota_interna: c.nota_interna ?? '',
    })
  },
  { immediate: true },
)

const titulo = computed(() => (props.copia ? `Copia ${props.copia.numero_copia} · ${props.copia.codigo_barras}` : ''))

async function guardar() {
  if (!props.copia) return
  form.guardando = true
  try {
    const { data } = await api.patch<Ejemplar>(`/ejemplares/${props.copia.id}`, {
      ubicacion_id: form.ubicacion_id === '' ? null : form.ubicacion_id,
      volumen: form.volumen.trim() || null,
      coleccion: form.coleccion.trim() || null,
      nota_publica: form.nota_publica.trim() || null,
      nota_interna: form.nota_interna.trim() || null,
    })
    toast.success('Copia actualizada')
    emit('guardada', data)
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo guardar la copia'))
  } finally {
    form.guardando = false
  }
}

const CAMPO = 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500'
</script>

<template>
  <Modal :abierto="!!copia" :titulo="titulo" descripcion="Datos de esta copia física. El estado se cambia en Estado de ejemplares." ancho="lg" pie-fijo :bloqueado="form.guardando" @cerrar="emit('cerrar')">
    <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
      <div>
        <label for="copia-editar-ubicacion" class="mb-1 block text-xs font-medium text-gray-600">Ubicación física</label>
        <select id="copia-editar-ubicacion" v-model="form.ubicacion_id" :class="CAMPO">
          <option value="">Sin especificar</option>
          <option v-for="u in ubicaciones" :key="u.id" :value="u.id">{{ u.nombre }}</option>
        </select>
      </div>
      <div>
        <label for="copia-editar-volumen" class="mb-1 block text-xs font-medium text-gray-600">Volumen</label>
        <input id="copia-editar-volumen" v-model="form.volumen" maxlength="100" placeholder="Tomo I" :class="CAMPO" />
      </div>
      <div class="sm:col-span-2">
        <label for="copia-editar-coleccion" class="mb-1 block text-xs font-medium text-gray-600">Colección de la copia</label>
        <input id="copia-editar-coleccion" v-model="form.coleccion" maxlength="255" :placeholder="coleccionLibro ? `${coleccionLibro} (la del libro)` : 'La del libro'" :class="CAMPO" />
        <p class="mt-1 text-[11px] text-gray-500">Vacía, vale la colección del libro<template v-if="coleccionLibro"> («{{ coleccionLibro }}»)</template>.</p>
      </div>
      <div class="sm:col-span-2">
        <label for="copia-editar-publica" class="mb-1 block text-xs font-medium text-gray-600">Nota pública</label>
        <textarea id="copia-editar-publica" v-model="form.nota_publica" rows="2" placeholder="Se ve en el portal. Ej: Incluye CD" :class="CAMPO"></textarea>
      </div>
      <div class="sm:col-span-2">
        <label for="copia-editar-interna" class="mb-1 block text-xs font-medium text-gray-600">Nota interna</label>
        <textarea id="copia-editar-interna" v-model="form.nota_interna" rows="2" placeholder="Solo la ve el personal" :class="CAMPO"></textarea>
      </div>
    </div>
    <template #pie>
      <button type="button" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm" :disabled="form.guardando" @click="emit('cerrar')">
        Cancelar
      </button>
      <button type="button" class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm disabled:opacity-60" :disabled="form.guardando" @click="guardar">
        {{ form.guardando ? 'Guardando…' : 'Guardar copia' }}
      </button>
    </template>
  </Modal>
</template>
