<script setup lang="ts">
import { onMounted, onUnmounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { STAFF_SHORTCUT_MAP } from '@/composables/useStaffShortcuts'
import { useFondo } from '@/composables/useFondo'
import { useAuthStore } from '@/stores/auth'
import { itemPorRuta } from '@/navegacion'
import TopBar from './TopBar.vue'

const router = useRouter()
const auth = useAuthStore()
const { ponerDelPersonal } = useFondo()

// El fondo de la cuenta (lo cambia Aspecto visual, en el menú de la cuenta). Si la sesión
// no cargó (API caída), queda el último de este navegador.
watch(() => (auth.staff ? auth.staff.fondo : undefined), ponerDelPersonal, { immediate: true })

function onKeyDown(event: KeyboardEvent) {
  const destino = STAFF_SHORTCUT_MAP[event.key]
  // El atajo solo lleva a módulos que el rol puede abrir (si no, el router igual lo rebota).
  if (destino && auth.puedeAlguno(itemPorRuta(destino)?.permiso)) {
    event.preventDefault()
    router.push({ name: destino })
  }
}

onMounted(() => window.addEventListener('keydown', onKeyDown))
onUnmounted(() => window.removeEventListener('keydown', onKeyDown))
</script>

<template>
  <!-- En modo día, la página la dibuja .fondo-pagina con el fondo elegido (tailwind.config.js). -->
  <div class="fondo-pagina flex flex-col min-h-screen dark:bg-gradient-to-br dark:from-[#10132b] dark:via-[#0e1124] dark:to-[#151230]">
    <TopBar />
    <main class="flex-1 p-4 sm:p-6">
      <slot />
    </main>
  </div>
</template>
