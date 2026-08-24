<script setup lang="ts">
import { onMounted, ref } from 'vue'
import PortalLayout from '@/components/layout/PortalLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import apiUsuario from '@/services/apiUsuario'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import { useToast } from '@/composables/useToast'
import { generarConstanciaNoMulta } from '@/utils/constancia'
import type { ConfiguracionInstitucional, EstadoPortal, Usuario } from '@/types'

const auth = useUsuarioAuthStore()
const toast = useToast()

const personasEnSala = ref(0)
const capacidad = ref(220)
const apiError = ref(false)
const generandoConstancia = ref(false)
const confirmandoConstancia = ref(false)

const acciones = [
  {
    to: 'portal-entrada',
    titulo: 'Marcar entrada',
    detalle: 'Registra tu ingreso con QR o RUT',
    icon: 'M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 3h3m3 0h-3m0 0v3m0-3v-3',
  },
  {
    to: 'portal-catalogo',
    titulo: 'Ver catálogo',
    detalle: 'Consulta la disponibilidad de libros',
    icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253',
  },
  {
    to: 'portal-salas',
    titulo: 'Reservar logia',
    detalle: 'Solicita un bloque de estudio y gestiona tus reservas',
    icon: 'M4 6h16M4 12h16M4 18h7',
  },
]

async function cargar() {
  try {
    const { data } = await apiUsuario.get<EstadoPortal>('/mi/estado')
    personasEnSala.value = data.personasEnSala
    capacidad.value = data.capacidad
    apiError.value = false
  } catch {
    apiError.value = true
    personasEnSala.value = 0
  }
}

onMounted(cargar)

async function confirmarConstancia() {
  confirmandoConstancia.value = false
  generandoConstancia.value = true
  try {
    const [{ data: usuario }, { data: configuracion }] = await Promise.all([
      apiUsuario.get<Usuario & { multas_pendientes: { cantidad: number; monto_total: number } }>('/mi/multas'),
      apiUsuario.get<ConfiguracionInstitucional>('/mi/configuracion'),
    ])
    if (usuario.multas_pendientes.cantidad > 0) {
      toast.error(`Tienes ${usuario.multas_pendientes.cantidad} multa(s) pendiente(s) — no se puede emitir la constancia`)
      return
    }
    await generarConstanciaNoMulta(usuario, configuracion)
    toast.success('Constancia generada')
  } catch {
    toast.error('No se pudo generar la constancia')
  } finally {
    generandoConstancia.value = false
  }
}
</script>

<template>
  <PortalLayout>
    <div class="max-w-lg mx-auto">
      <div class="relative overflow-hidden rounded-2xl bg-white border border-gray-100 shadow-sm p-5 mb-6">
        <div class="absolute -right-8 -top-10 w-32 h-32 rounded-full bg-indigo-50/70 pointer-events-none"></div>
        <div class="absolute -right-2 -bottom-12 w-20 h-20 rounded-full bg-purple-50/60 pointer-events-none"></div>

        <div class="relative flex items-center gap-3">
          <div
            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-white shadow-sm"
            style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
          >
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
            </svg>
          </div>
          <p class="text-[26px] font-bold text-gray-900 leading-tight truncate">¡Bienvenido/a, {{ auth.usuario?.nombre }}!</p>
        </div>

        <div class="relative mt-4">
          <span class="inline-flex items-center gap-1.5 bg-emerald-50 border border-emerald-100 rounded-full pl-2.5 pr-3 py-1.5">
            <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
            <span class="text-xs font-medium text-emerald-700">{{ personasEnSala }} personas en biblioteca</span>
          </span>
          <div class="mt-2 h-1.5 w-40 max-w-full bg-gray-100 rounded-full overflow-hidden">
            <div
              class="h-full bg-emerald-400 rounded-full transition-all"
              :style="{ width: `${Math.min(100, (personasEnSala / capacidad) * 100)}%` }"
            />
          </div>
        </div>
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="space-y-3">
        <router-link
          v-for="a in acciones"
          :key="a.to"
          :to="{ name: a.to }"
          class="w-full flex items-center gap-4 p-4 bg-white border border-gray-200 rounded-2xl shadow-sm hover:border-indigo-300 hover:shadow-md transition-all"
        >
          <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-50/80 text-indigo-600 shrink-0">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" :d="a.icon" />
            </svg>
          </div>
          <div class="text-left min-w-0">
            <p class="font-semibold text-gray-900">{{ a.titulo }}</p>
            <p class="text-xs text-gray-500 truncate mt-0.5">{{ a.detalle }}</p>
          </div>
          <svg class="ml-auto w-5 h-5 text-gray-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
          </svg>
        </router-link>

        <a
          href="https://umag.elogim.com/"
          target="_blank"
          rel="noopener"
          class="w-full flex items-center gap-4 p-4 bg-white border border-gray-200 rounded-2xl shadow-sm hover:border-indigo-300 hover:shadow-md transition-all"
        >
          <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-50/80 text-indigo-600 shrink-0">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
            </svg>
          </div>
          <div class="text-left min-w-0">
            <p class="font-semibold text-gray-900">Recursos Digitales</p>
            <p class="text-xs text-gray-500 truncate mt-0.5">Base de datos electrónica de la biblioteca</p>
          </div>
          <svg class="ml-auto w-5 h-5 text-gray-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
          </svg>
        </a>

        <button
          type="button"
          @click="confirmandoConstancia = true"
          :disabled="generandoConstancia"
          class="w-full flex items-center gap-4 p-4 bg-white border border-gray-200 rounded-2xl shadow-sm hover:border-indigo-300 hover:shadow-md transition-all disabled:opacity-60"
        >
          <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-50/80 text-indigo-600 shrink-0">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
          </div>
          <div class="text-left min-w-0">
            <p class="font-semibold text-gray-900">Constancia de No Multa</p>
            <p class="text-xs text-gray-500 truncate mt-0.5">
              {{ generandoConstancia ? 'Generando…' : 'Descarga tu constancia en PDF' }}
            </p>
          </div>
          <svg class="ml-auto w-5 h-5 text-gray-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
          </svg>
        </button>
      </div>
    </div>

    <div
      v-if="confirmandoConstancia"
      class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4"
      @click.self="confirmandoConstancia = false"
    >
      <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
        <h3 class="text-lg font-bold text-gray-900 mb-1">¿Generar Constancia de No Multa?</h3>
        <p class="text-sm text-gray-500 mb-6">Se descargará un PDF con tu constancia. ¿Continuar?</p>
        <div class="flex gap-3">
          <button
            @click="confirmandoConstancia = false"
            class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm"
          >
            Cancelar
          </button>
          <button
            @click="confirmarConstancia"
            class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm"
          >
            Sí, descargar
          </button>
        </div>
      </div>
    </div>
  </PortalLayout>
</template>
