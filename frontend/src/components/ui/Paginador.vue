<script setup lang="ts">
import { computed } from 'vue'

/**
 * Pie de un listado por páginas: «51–100 de 3.240 libros», Anterior / Siguiente y
 * hasta cinco números de página alrededor de la actual.
 */
const props = withDefaults(
  defineProps<{
    pagina: number
    paginas: number
    total: number
    desde?: number | null
    hasta?: number | null
    /** Qué se cuenta, en plural: «libros», «préstamos»… */
    unidad?: string
    cargando?: boolean
    /** Para columnas angostas: el conteo arriba, botones más chicos y tres números. */
    compacto?: boolean
  }>(),
  { desde: null, hasta: null, unidad: 'resultados', cargando: false, compacto: false },
)

const emit = defineEmits<{ 'update:pagina': [pagina: number] }>()

const numeros = computed(() => {
  const cuantos = props.compacto ? 3 : 5
  const inicio = Math.max(1, Math.min(props.pagina - Math.floor(cuantos / 2), props.paginas - cuantos + 1))
  return Array.from({ length: Math.min(cuantos, props.paginas) }, (_, i) => inicio + i)
})

const formato = (n: number) => n.toLocaleString('es-CL')

function ir(pagina: number) {
  if (pagina < 1 || pagina > props.paginas || pagina === props.pagina || props.cargando) return
  emit('update:pagina', pagina)
}
</script>

<template>
  <div class="flex flex-col" :class="compacto ? 'gap-2 text-xs' : 'gap-3 text-sm sm:flex-row sm:items-center sm:justify-between'">
    <p class="text-gray-500">
      <template v-if="total && desde && hasta">{{ formato(desde) }}–{{ formato(hasta) }} de {{ formato(total) }} {{ unidad }}</template>
      <template v-else>{{ formato(total) }} {{ unidad }}</template>
    </p>
    <nav v-if="paginas > 1" class="flex items-center gap-1" aria-label="Páginas">
      <button
        type="button"
        class="rounded-lg border border-gray-300 font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-40"
        :class="compacto ? 'px-2 py-1' : 'px-3 py-1.5'"
        :disabled="pagina === 1 || cargando"
        @click="ir(pagina - 1)"
      >
        Anterior
      </button>
      <button
        v-for="n in numeros"
        :key="n"
        type="button"
        class="rounded-lg px-2 font-medium transition-colors"
        :class="[n === pagina ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100', compacto ? 'min-w-[1.75rem] py-1' : 'min-w-[2.25rem] py-1.5']"
        :aria-current="n === pagina ? 'page' : undefined"
        :disabled="cargando"
        @click="ir(n)"
      >
        {{ n }}
      </button>
      <button
        type="button"
        class="rounded-lg border border-gray-300 font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-40"
        :class="compacto ? 'px-2 py-1' : 'px-3 py-1.5'"
        :disabled="pagina === paginas || cargando"
        @click="ir(pagina + 1)"
      >
        Siguiente
      </button>
    </nav>
  </div>
</template>
