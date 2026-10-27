<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import PortalLayout from '@/components/layout/PortalLayout.vue'
import ApiErrorBanner from '@/components/ApiErrorBanner.vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import ActividadPersona from '@/components/portal/ActividadPersona.vue'
import PildoraHorario from '@/components/portal/PildoraHorario.vue'
import apiUsuario from '@/services/apiUsuario'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import { useConstancia } from '@/composables/useConstancia'
import type { ActividadPortal, EstadoPortal, HorarioBiblioteca } from '@/types'

const auth = useUsuarioAuthStore()
const router = useRouter()
const { confirmando: confirmandoConstancia, generando: generandoConstancia, generar: generarConstancia } = useConstancia()

const personasEnSala = ref(0)
const capacidad = ref(220)
const horario = ref<HorarioBiblioteca | null>(null)
const apiError = ref(false)
const actividad = ref<ActividadPortal | null>(null)
const errorActividad = ref(false)
const busqueda = ref('')

const fechaHoy = (() => {
  const texto = new Date().toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long' })
  return texto.charAt(0).toUpperCase() + texto.slice(1)
})()

/** Accesos de "Explorar" en el teléfono, cada uno con su color. */
const explorar = [
  {
    titulo: 'Catálogo',
    to: 'portal-catalogo',
    tarjeta: 'bg-indigo-50 border-indigo-100',
    icono: 'bg-indigo-600',
    d: 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25',
  },
  {
    titulo: 'Reservar sala',
    to: 'portal-salas',
    tarjeta: 'bg-amber-50 border-amber-100',
    icono: 'bg-[#b08d57]',
    d: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
  },
  {
    titulo: 'Recursos digitales',
    href: 'https://umag.elogim.com/',
    tarjeta: 'bg-emerald-50 border-emerald-100',
    icono: 'bg-emerald-600',
    d: 'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125',
  },
  {
    titulo: 'Mis ingresos',
    to: 'portal-ingresos',
    tarjeta: 'bg-sky-50 border-sky-100',
    icono: 'bg-sky-800',
    d: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
  },
]

async function cargar() {
  try {
    const { data } = await apiUsuario.get<EstadoPortal>('/mi/estado')
    personasEnSala.value = data.personasEnSala
    capacidad.value = data.capacidad
    horario.value = data.horario
    apiError.value = false
  } catch {
    apiError.value = true
    personasEnSala.value = 0
  }
}

async function cargarActividad() {
  try {
    const { data } = await apiUsuario.get<ActividadPortal>('/mi/actividad')
    actividad.value = data
    errorActividad.value = false
  } catch {
    errorActividad.value = true
  }
}

onMounted(() => {
  cargar()
  cargarActividad()
})

function buscarEnCatalogo() {
  const q = busqueda.value.trim()
  router.push({ name: 'portal-catalogo', query: q ? { q } : {} })
}
</script>

<template>
  <PortalLayout>
    <template #cabecera>
      <div class="lg:hidden px-4 sm:px-8 pt-2 pb-16 text-white">
        <p class="text-xs text-indigo-100/90">{{ fechaHoy }}</p>
        <h1 class="mt-0.5 text-[28px] font-serif font-bold leading-tight">Hola, {{ auth.usuario?.nombre }}</h1>
        <div class="mt-3 flex flex-wrap items-center gap-2">
          <PildoraHorario v-if="horario" oscura :horario="horario" />
          <span class="inline-flex items-center rounded-full border border-white/20 bg-blanco/15 px-3 py-1.5 text-xs font-medium text-white">
            {{ personasEnSala }} {{ personasEnSala === 1 ? 'ingreso' : 'ingresos' }} hoy
          </span>
        </div>
      </div>
    </template>

    <!-- Teléfono y tablet -->
    <div class="relative z-10 -mt-16 max-w-lg mx-auto lg:hidden">
      <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-lg shadow-indigo-900/10">
        <p v-if="actividad" class="text-[11px] font-bold uppercase tracking-wider text-indigo-600">
          {{ actividad.entrada_hoy ? `Registraste tu ingreso hoy a las ${actividad.entrada_hoy}` : 'Aún no registras tu ingreso hoy' }}
        </p>
        <h2 class="mt-1 font-sans text-xl font-bold text-gray-900">
          {{ actividad?.entrada_hoy ? '¿Volviste a la biblioteca?' : '¿Llegaste a la biblioteca?' }}
        </h2>
        <div class="mt-4 grid grid-cols-2 gap-3">
          <router-link
            :to="{ name: 'portal-entrada', query: { metodo: 'qr' } }"
            class="flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-3 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition-colors"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M9 9h6v6H9V9z" />
            </svg>
            Escanear QR
          </router-link>
          <router-link
            :to="{ name: 'portal-entrada', query: { metodo: 'rut' } }"
            class="flex items-center justify-center rounded-xl border border-gray-300 bg-white px-3 py-3 text-sm font-semibold text-gray-800 hover:bg-gray-50 transition-colors"
          >
            Usar mi RUT
          </router-link>
        </div>
      </section>

      <ApiErrorBanner v-if="apiError" class="mt-4" />

      <form
        class="mt-4 flex items-center gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3 shadow-sm focus-within:ring-2 focus-within:ring-indigo-500"
        @submit.prevent="buscarEnCatalogo"
      >
        <svg class="w-5 h-5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
        </svg>
        <input
          v-model="busqueda"
          type="search"
          enterkeyhint="search"
          aria-label="Buscar en el catálogo"
          placeholder="Buscar libros, autores o ISBN"
          class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-0"
        />
      </form>

      <h2 class="mt-6 mb-3 font-sans text-base font-bold text-gray-900">Explorar</h2>
      <div class="grid grid-cols-2 gap-3">
        <component
          :is="acceso.href ? 'a' : 'router-link'"
          v-for="acceso in explorar"
          :key="acceso.titulo"
          v-bind="acceso.href ? { href: acceso.href, target: '_blank', rel: 'noopener' } : { to: { name: acceso.to } }"
          class="relative flex min-h-[7rem] flex-col justify-between rounded-2xl border p-4 transition-shadow hover:shadow-md"
          :class="acceso.tarjeta"
        >
          <span class="flex h-10 w-10 items-center justify-center rounded-xl text-white shadow-sm" :class="acceso.icono">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" :d="acceso.d" />
            </svg>
          </span>
          <svg v-if="acceso.href" class="absolute right-3.5 top-3.5 w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
          </svg>
          <span class="mt-3 text-sm font-semibold text-gray-900">{{ acceso.titulo }}</span>
        </component>
      </div>
    </div>

    <!-- Computador -->
    <div class="hidden lg:block max-w-[1100px] mx-auto">
      <div class="mb-6 flex items-end justify-between gap-6">
        <div class="min-w-0">
          <p class="text-sm text-gray-500">{{ fechaHoy }}</p>
          <h1 class="mt-0.5 text-3xl font-serif font-bold tracking-tight text-gray-900 truncate">Hola, {{ auth.usuario?.nombre }}</h1>
        </div>
        <PildoraHorario v-if="horario" :horario="horario" />
      </div>

      <ApiErrorBanner v-if="apiError" />

      <div class="grid grid-cols-[minmax(0,1fr)_19rem] gap-5">
        <section
          class="flex items-center justify-between gap-8 rounded-2xl px-7 py-6 text-white shadow-md"
          style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);"
        >
          <div class="min-w-0">
            <p v-if="actividad" class="text-[11px] font-bold uppercase tracking-wider text-indigo-200">
              {{ actividad.entrada_hoy ? `Registraste tu ingreso hoy a las ${actividad.entrada_hoy}` : 'Aún no registras tu ingreso hoy' }}
            </p>
            <h2 class="mt-2 font-sans text-2xl font-bold">
              {{ actividad?.entrada_hoy ? '¿Volviste a la biblioteca?' : '¿Llegaste a la biblioteca?' }}
            </h2>
            <p class="mt-1.5 text-sm text-indigo-100/90">Marca tu entrada en segundos con el código QR de la biblioteca o con tu RUT.</p>
          </div>
          <div class="flex w-44 shrink-0 flex-col gap-2.5">
            <router-link
              :to="{ name: 'portal-entrada', query: { metodo: 'qr' } }"
              class="flex items-center justify-center gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-indigo-700 shadow-sm hover:bg-indigo-50 transition-colors"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M9 9h6v6H9V9z" />
              </svg>
              Escanear QR
            </router-link>
            <router-link
              :to="{ name: 'portal-entrada', query: { metodo: 'rut' } }"
              class="flex items-center justify-center rounded-lg border border-white/50 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blanco/10 transition-colors"
            >
              Usar mi RUT
            </router-link>
          </div>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
          <p class="text-sm font-medium text-gray-500">Ingresos de hoy</p>
          <p class="mt-2 flex items-baseline gap-2">
            <span class="text-4xl font-serif font-bold text-gray-900">{{ personasEnSala }}</span>
            <span class="text-sm text-gray-500">de un aforo de {{ capacidad }}</span>
          </p>
          <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100">
            <div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: `${Math.min(100, (personasEnSala / capacidad) * 100)}%` }" />
          </div>
          <p class="mt-2.5 text-xs text-gray-500">Personas que marcaron su entrada hoy.</p>
        </section>
      </div>

      <form
        class="mt-5 flex items-center gap-3 rounded-2xl border border-gray-200 bg-white py-2 pl-4 pr-2 shadow-sm focus-within:ring-2 focus-within:ring-indigo-500"
        @submit.prevent="buscarEnCatalogo"
      >
        <svg class="w-5 h-5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
        </svg>
        <input
          v-model="busqueda"
          type="search"
          aria-label="Buscar en el catálogo"
          placeholder="Busca en el catálogo por título, autor o ISBN"
          class="flex-1 border-0 bg-transparent py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-0"
        />
        <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition-colors">
          Buscar
        </button>
      </form>

      <div class="mt-5 grid grid-cols-[minmax(0,1fr)_19rem] items-start gap-5">
        <ActividadPersona
          :actividad="actividad"
          :error="errorActividad"
        />

        <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
          <h2 class="font-sans text-base font-bold text-gray-900">Accesos rápidos</h2>
          <nav class="mt-2 -mx-2">
            <router-link
              :to="{ name: 'portal-salas' }"
              class="flex items-center gap-3 rounded-lg px-2 py-2.5 text-sm font-medium text-gray-800 hover:bg-gray-50 transition-colors"
            >
              <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
              </svg>
              Reservar sala de estudio
              <svg class="ml-auto w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
              </svg>
            </router-link>
            <router-link
              :to="{ name: 'portal-catalogo' }"
              class="flex items-center gap-3 rounded-lg px-2 py-2.5 text-sm font-medium text-gray-800 hover:bg-gray-50 transition-colors"
            >
              <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
              </svg>
              Explorar catálogo
              <svg class="ml-auto w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
              </svg>
            </router-link>
            <a
              href="https://umag.elogim.com/"
              target="_blank"
              rel="noopener"
              class="flex items-center gap-3 rounded-lg px-2 py-2.5 text-sm font-medium text-gray-800 hover:bg-gray-50 transition-colors"
            >
              <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
              </svg>
              Recursos digitales
              <svg class="ml-auto w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
              </svg>
            </a>
            <router-link
              :to="{ name: 'portal-ingresos' }"
              class="flex items-center gap-3 rounded-lg px-2 py-2.5 text-sm font-medium text-gray-800 hover:bg-gray-50 transition-colors"
            >
              <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              Mi historial de ingresos
              <svg class="ml-auto w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
              </svg>
            </router-link>
            <button
              type="button"
              :disabled="generandoConstancia || !!actividad?.multas.cantidad"
              :title="actividad?.multas.cantidad ? 'Disponible cuando no tengas multas pendientes' : undefined"
              class="flex w-full items-center gap-3 rounded-lg px-2 py-2.5 text-left text-sm font-medium text-gray-800 hover:bg-gray-50 transition-colors disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent"
              @click="confirmandoConstancia = true"
            >
              <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
              </svg>
              {{ generandoConstancia ? 'Generando constancia…' : 'Constancia de no multa' }}
              <svg class="ml-auto w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
              </svg>
            </button>
          </nav>
        </section>
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
