<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { STAFF_SHORTCUT_MAP } from '@/composables/useStaffShortcuts'
import { useAuthStore } from '@/stores/auth'
import { itemPorRuta } from '@/navegacion'
import TopBar from './TopBar.vue'

const router = useRouter()
const auth = useAuthStore()

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
  <div class="flex flex-col min-h-screen bg-gradient-to-br from-indigo-50/60 via-slate-50 to-purple-50/40 dark:from-[#10132b] dark:via-[#0e1124] dark:to-[#151230]">
    <TopBar />
    <main class="flex-1 p-4 sm:p-6">
      <slot />
    </main>
  </div>
</template>
