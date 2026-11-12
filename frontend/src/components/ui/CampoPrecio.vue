<script setup lang="ts">
import { ref, watch } from 'vue'

/**
 * Precio en pesos, escrito como en Chile: los puntos de miles se ponen solos mientras se
 * escribe («15000» → «15.000») y el punto no se toma como decimal («15.000» son quince mil,
 * no 15). La coma es el decimal (algunos precios de Horizon traen centavos: «279,65»).
 * El valor (v-model) es el número, o '' si está vacío.
 */
const props = defineProps<{ modelValue: number | string | null | ''; id?: string; clase?: string }>()
const emit = defineEmits<{ 'update:modelValue': [valor: number | ''] }>()

/** 15000 → «15.000»; 279.65 → «279,65». */
function formatear(valor: number | string | null | ''): string {
  if (valor === '' || valor === null) return ''
  const n = Number(valor)
  return Number.isFinite(n) ? n.toLocaleString('es-CL', { maximumFractionDigits: 2 }) : ''
}

/** «15.000» → 15000; «279,65» → 279.65; «» → ''. */
function leer(texto: string): number | '' {
  const limpio = texto.replace(/\./g, '').replace(/\s/g, '').replace(',', '.').replace(/[^0-9.]/g, '')
  if (!limpio) return ''
  const n = Number(limpio)
  return Number.isFinite(n) ? n : ''
}

const texto = ref(formatear(props.modelValue))
// Si el valor cambia desde afuera (se carga otra copia, se limpia el formulario), se muestra ese
watch(
  () => props.modelValue,
  (valor) => {
    const actual = leer(texto.value)
    if (actual !== (valor === '' || valor === null ? '' : Number(valor))) texto.value = formatear(valor)
  },
)

function alEscribir(evento: Event) {
  const escrito = (evento.target as HTMLInputElement).value
  const [entero, decimales] = escrito.replace(/\./g, '').split(',')
  const digitos = entero.replace(/\D/g, '').replace(/^0+(?=\d)/, '')
  texto.value = (digitos ? Number(digitos).toLocaleString('es-CL') : '') + (decimales !== undefined ? `,${decimales.replace(/\D/g, '').slice(0, 2)}` : '')
  ;(evento.target as HTMLInputElement).value = texto.value
  emit('update:modelValue', leer(texto.value))
}
</script>

<template>
  <div class="relative">
    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
    <input :id="id" :value="texto" autocomplete="off" inputmode="decimal" placeholder="0" :class="[clase, 'pl-7']" @input="alEscribir" />
  </div>
</template>
