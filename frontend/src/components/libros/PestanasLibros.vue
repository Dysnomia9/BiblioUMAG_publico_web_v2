<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { itemPorRuta } from '@/navegacion'

/** Pestañas del módulo de catálogo; solo las que el rol puede abrir. En pantallas angostas se deslizan. */
const props = defineProps<{
  actual: 'catalogacion-libros' | 'listado-libros' | 'historial-prestamos-libro' | 'estado-libro' | 'historial-estado-libro'
}>()

const auth = useAuthStore()

const PESTANAS = [
  { name: 'catalogacion-libros', label: 'Catalogar' },
  { name: 'listado-libros', label: 'Listado' },
  { name: 'historial-prestamos-libro', label: 'Historial' },
  { name: 'estado-libro', label: 'Estado' },
  { name: 'historial-estado-libro', label: 'Historial de estado' },
] as const

const visibles = computed(() => PESTANAS.filter((p) => p.name === props.actual || auth.puedeAlguno(itemPorRuta(p.name)?.permiso)))

// En el teléfono las pestañas se deslizan: la activa queda a la vista.
const barra = ref<HTMLElement | null>(null)
onMounted(() => {
  const activa = barra.value?.querySelector<HTMLElement>('[aria-current="page"]')
  if (!barra.value || !activa) return
  const desfase = activa.getBoundingClientRect().left - barra.value.getBoundingClientRect().left
  barra.value.scrollLeft += desfase - (barra.value.clientWidth - activa.offsetWidth) / 2
})
</script>

<template>
  <nav ref="barra" class="max-w-full overflow-x-auto" aria-label="Módulo de catálogo">
    <div class="flex w-max rounded-xl bg-gray-200/60 p-1">
      <router-link
        v-for="p in visibles"
        :key="p.name"
        :to="{ name: p.name }"
        class="whitespace-nowrap rounded-lg px-4 py-2 text-sm font-medium transition-colors"
        :class="p.name === actual ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
        :aria-current="p.name === actual ? 'page' : undefined"
      >
        {{ p.label }}
      </router-link>
    </div>
  </nav>
</template>
