<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { STAFF_SHORTCUT_MAP } from '@/composables/useStaffShortcuts'
import TopBar from './TopBar.vue'

const router = useRouter()

function onKeyDown(event: KeyboardEvent) {
  const destino = STAFF_SHORTCUT_MAP[event.key]
  if (destino) {
    event.preventDefault()
    router.push({ name: destino })
  }
}

onMounted(() => window.addEventListener('keydown', onKeyDown))
onUnmounted(() => window.removeEventListener('keydown', onKeyDown))
</script>

<template>
  <div class="flex flex-col min-h-screen bg-gradient-to-br from-indigo-50/60 via-slate-50 to-purple-50/40">
    <TopBar />
    <main class="flex-1 p-6">
      <slot />
    </main>
  </div>
</template>
