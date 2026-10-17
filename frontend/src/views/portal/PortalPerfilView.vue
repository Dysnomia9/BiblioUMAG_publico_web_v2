<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import PortalLayout from '@/components/layout/PortalLayout.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import { useConstancia } from '@/composables/useConstancia'

const auth = useUsuarioAuthStore()
const router = useRouter()
const { confirmando: confirmandoConstancia, generando: generandoConstancia, generar: generarConstancia } = useConstancia()

const iniciales = computed(() => {
  const letras = `${auth.usuario?.nombre?.charAt(0) ?? ''}${auth.usuario?.apellido?.charAt(0) ?? ''}`
  return letras.toUpperCase() || 'U'
})

async function cerrarSesion() {
  await auth.logout()
  router.push({ name: 'portal-login' })
}
</script>

<template>
  <PortalLayout>
    <div class="max-w-2xl mx-auto">
      <div class="mb-5">
        <h1 class="text-2xl sm:text-3xl font-serif font-bold tracking-tight text-gray-900">Mi perfil</h1>
        <p class="mt-1 text-sm text-gray-500">Tus datos, tu constancia y tu historial de ingresos.</p>
      </div>

      <section class="flex items-center gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-[#b08d57] text-lg font-bold text-[#1a2430]">
          {{ iniciales }}
        </span>
        <div class="min-w-0">
          <p class="text-lg font-bold text-gray-900">{{ auth.usuario?.nombre }} {{ auth.usuario?.apellido }}</p>
          <p class="text-sm text-gray-500"><span class="capitalize">{{ auth.usuario?.tipo }}</span> · {{ auth.usuario?.rut }}</p>
          <p v-if="auth.usuario?.carrera" class="mt-0.5 text-xs text-gray-500">{{ auth.usuario.carrera }}</p>
          <p v-if="auth.usuario?.email" class="mt-0.5 text-xs text-gray-500 truncate">{{ auth.usuario.email }}</p>
        </div>
      </section>

      <div class="mt-4 space-y-3">
        <button
          type="button"
          :disabled="generandoConstancia"
          class="flex w-full items-center gap-4 rounded-2xl border border-gray-200 bg-white p-4 text-left shadow-sm hover:border-indigo-300 transition-colors disabled:opacity-60"
          @click="confirmandoConstancia = true"
        >
          <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
            </svg>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-semibold text-gray-900">Constancia de No Multa</span>
            <span class="block text-xs text-gray-500">{{ generandoConstancia ? 'Generando…' : 'Descárgala en PDF; se bloquea si tienes multas pendientes' }}</span>
          </span>
          <svg class="w-5 h-5 shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
          </svg>
        </button>

        <router-link
          :to="{ name: 'portal-ingresos' }"
          class="flex items-center gap-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm hover:border-indigo-300 transition-colors"
        >
          <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-700">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-semibold text-gray-900">Mis ingresos</span>
            <span class="block text-xs text-gray-500">Las veces que registraste tu entrada</span>
          </span>
          <svg class="w-5 h-5 shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
          </svg>
        </router-link>

        <button
          type="button"
          class="flex w-full items-center gap-4 rounded-2xl border border-red-100 bg-white p-4 text-left shadow-sm hover:bg-red-50 transition-colors"
          @click="cerrarSesion"
        >
          <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
          </span>
          <span class="text-sm font-semibold text-red-600">Cerrar sesión</span>
        </button>
      </div>
    </div>

    <DialogoConfirmacion
      :abierto="confirmandoConstancia"
      titulo="¿Generar Constancia de No Multa?"
      confirmar="Sí, descargar"
      @confirmar="generarConstancia"
      @cancelar="confirmandoConstancia = false"
    >
      <p>Se descargará un PDF con tu constancia. ¿Continuar?</p>
    </DialogoConfirmacion>
  </PortalLayout>
</template>
