<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { separarClasificacion, unirClasificacion, type PartesClasificacion } from '@/utils/clasificacion'

const props = defineProps<{ modelValue: string; id: string; placeholder: string; claseCampo: string; guiaId: string; plantilla?: string }>()
const emit = defineEmits<{ 'update:modelValue': [valor: string] }>()
const modo = ref<'automatica' | 'secciones'>('automatica')
const ultimaEmision = ref<string | null>(null)
const lectura = computed(() => props.plantilla === 'publicacion_seriada' ? null : separarClasificacion(props.modelValue))
const campos = reactive<PartesClasificacion>({ clase: '', cutter: '', anio: '', complemento: '' })
const rotuloClase = computed(() => props.plantilla === 'trabajo_titulacion' ? 'Prefijo' : props.plantilla === 'juego_educativo' ? 'Código' : 'Clase Dewey')
const ejemplo = computed(() => separarClasificacion(props.placeholder.replace(/^Ej:\s*/, '')))
const valores = computed(() => {
  const p = modo.value === 'secciones' ? campos : lectura.value
  return p ? [{ nombre: rotuloClase.value, valor: p.clase }, { nombre: 'Cutter', valor: p.cutter }, { nombre: 'Año', valor: p.anio }, { nombre: 'Complemento', valor: p.complemento }].filter((v) => v.valor) : []
})

watch(() => props.modelValue, (valor) => {
  if (valor === ultimaEmision.value || modo.value !== 'secciones') return
  const partes = props.plantilla === 'publicacion_seriada' ? null : separarClasificacion(valor)
  if (partes) Object.assign(campos, partes)
  else modo.value = 'automatica'
})
watch(() => props.plantilla, () => { modo.value = 'automatica' })

function cambiar(destino: 'automatica' | 'secciones') {
  if (destino === 'secciones') {
    if (!lectura.value) return
    Object.assign(campos, lectura.value)
  }
  // Cambiar de presentación no reescribe ni normaliza lo que ya estaba guardado.
  modo.value = destino
  void nextTick(() => document.getElementById(props.id)?.focus())
}
function actualizar() {
  ultimaEmision.value = unirClasificacion(campos)
  emit('update:modelValue', ultimaEmision.value)
}
</script>

<template>
  <div>
    <div class="mb-2 inline-flex rounded-lg bg-indigo-50 p-1" role="group" aria-label="Cómo editar la clasificación">
      <button v-for="opcion in [{ id: 'automatica' as const, texto: 'Separación automática' }, { id: 'secciones' as const, texto: 'Por secciones' }]" :key="opcion.id" type="button" class="min-h-8 rounded-md px-3 py-1 text-xs font-semibold transition-colors disabled:opacity-50" :class="modo === opcion.id ? 'bg-white text-indigo-900 shadow-sm' : 'text-gray-600 hover:text-indigo-900'" :aria-pressed="modo === opcion.id" :disabled="opcion.id === 'secciones' && !lectura" @click="cambiar(opcion.id)">{{ opcion.texto }}</button>
    </div>
    <input v-if="modo === 'automatica'" :id="id" :value="modelValue" autocomplete="off" :placeholder="placeholder" :class="[claseCampo, 'font-mono']" :aria-describedby="`${guiaId} ${id}-ayuda`" @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)" @keydown.enter.prevent="cambiar('secciones')" />
    <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-3">
      <label class="block text-xs font-medium text-gray-700">{{ rotuloClase }}<input :id="id" v-model="campos.clase" :placeholder="ejemplo?.clase || '813.54'" autocomplete="off" :class="[claseCampo, 'mt-1 font-mono']" @input="actualizar" /></label>
      <label class="block text-xs font-medium text-gray-700">Cutter<input v-model="campos.cutter" :placeholder="ejemplo?.cutter || 'G216n'" autocomplete="off" :class="[claseCampo, 'mt-1 font-mono']" @input="actualizar" /></label>
      <label class="block text-xs font-medium text-gray-700">Año<input v-model="campos.anio" :placeholder="ejemplo?.anio || '2020'" inputmode="numeric" autocomplete="off" :class="[claseCampo, 'mt-1 font-mono']" @input="actualizar" /></label>
      <label class="col-span-2 block text-xs font-medium text-gray-700 sm:col-span-3">Complemento (opcional)<input v-model="campos.complemento" placeholder="Ej: t.1 c.2" autocomplete="off" :class="[claseCampo, 'mt-1 font-mono']" @input="actualizar" /></label>
    </div>
    <p :id="`${id}-ayuda`" class="mt-1 text-xs text-gray-500">{{ modo === 'secciones' ? 'Edita cualquier parte directamente. Tab pasa a la siguiente casilla.' : lectura ? 'Escribe o pega la clasificación completa: las partes se identifican debajo. Enter abre las secciones.' : 'Formato local: puedes editar el texto completo sin alterar sus datos.' }}</p>
    <div v-if="valores.length" class="mt-2 flex flex-wrap gap-2" aria-label="Partes de la clasificación">
      <span v-for="parte in valores" :key="parte.nombre" class="rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-950"><span class="block text-[10px] text-indigo-700">{{ parte.nombre }}</span><span class="font-mono font-semibold">{{ parte.valor }}</span></span>
    </div>
    <p v-if="modo === 'secciones' && modelValue" class="mt-2 text-xs text-gray-600">Clasificación completa: <span class="font-mono text-gray-900">{{ modelValue }}</span></p>
  </div>
</template>
