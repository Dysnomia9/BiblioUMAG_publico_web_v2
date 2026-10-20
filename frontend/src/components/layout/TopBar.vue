<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { itemPorRuta } from '@/navegacion'
import api from '@/services/api'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import logoUmag from '@/assets/logo-umag.png'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const toast = useToast()

type Enlace = { name: string; label: string; icon: string; shortcut?: string }

const ICONO_INICIO = 'M3 12l9-9 9 9M5 10v10a1 1 0 001 1h4a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1h4a1 1 0 001-1V10'

const moduleLinksTodos: Enlace[] = [
  { name: 'entrada', label: 'Entradas', icon: 'M11 16l-4-4m0 0l4-4m-4 4h14M5 5v14', shortcut: 'F1' },
  { name: 'prestamo', label: 'Préstamos', icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253', shortcut: 'F2' },
  { name: 'salas', label: 'Salas', icon: 'M4 6h16M4 12h16M4 18h7', shortcut: 'F3' },
  { name: 'reportes', label: 'Reportes', icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z', shortcut: 'F4' },
]

type AdminColor = 'indigo' | 'amber' | 'emerald' | 'violet' | 'sky' | 'rose' | 'teal' | 'red' | 'orange' | 'fuchsia' | 'slate'
type Tarjeta = { name: string; label: string; icon: string; color: AdminColor }

// El menú de gestión, separado por secciones. Cada tarjeta se muestra solo si el
// rol tiene el permiso del módulo (el mismo que exige su ruta, ver navegacion.ts).
const SECCIONES: { titulo: string; tarjetas: Tarjeta[] }[] = [
  {
    titulo: 'Atención de público',
    tarjetas: [
      { name: 'usuarios', label: 'Usuarios', icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1a4 4 0 100-8 4 4 0 000 8zm6 3a4 4 0 00-3-3.87M9 12a4 4 0 100-8 4 4 0 000 8z', color: 'indigo' },
      { name: 'multas', label: 'Multas', icon: 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-8.99 3.75h.008v.008h-.008v-.008z', color: 'red' },
    ],
  },
  {
    titulo: 'Colección',
    tarjetas: [
      { name: 'listado-libros', label: 'Listado Libros', icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253', color: 'emerald' },
      { name: 'catalogacion-libros', label: 'Catalogación de Libros', icon: 'M12 4.5v15m0-15c-2.485 0-4.5.672-6 2.25v13.5c1.5-1.578 3.515-2.25 6-2.25s4.5.672 6 2.25V6.75c-1.5-1.578-3.515-2.25-6-2.25z', color: 'violet' },
      { name: 'estado-libro', label: 'Estado de ejemplares', icon: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', color: 'sky' },
      { name: 'cambio-masivo-estado', label: 'Cambio Masivo de Estado', icon: 'M4 7h16M4 12h10m-10 5h16', color: 'orange' },
      { name: 'etiquetas', label: 'Etiquetas de Código', icon: 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z M6 6h.008v.008H6V6z', color: 'amber' },
      { name: 'equipos', label: 'Recursos de apoyo', icon: 'M20.25 7.5l-8.25 4.5L3.75 7.5M3.75 7.5l8.25-4.5 8.25 4.5M3.75 7.5v9l8.25 4.5m0-9v9m0-9l8.25-4.5m-8.25 4.5l8.25 4.5m0 0v-9', color: 'teal' },
    ],
  },
  {
    titulo: 'Consultas e historiales',
    tarjetas: [
      { name: 'listado-prestamos', label: 'Listado Préstamos', icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', color: 'amber' },
      { name: 'listado-reservas', label: 'Listado Reservas', icon: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5', color: 'sky' },
      { name: 'historial-estado-libro', label: 'Historial de Estado', icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', color: 'slate' },
      { name: 'historial-prestamos-libro', label: 'Historial de Libros', icon: 'M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25', color: 'fuchsia' },
    ],
  },
  {
    titulo: 'Administración del sistema',
    tarjetas: [
      { name: 'codigo-qr', label: 'Código QR', icon: 'M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 3h3m3 0h-3m0 0v3m0-3v-3', color: 'rose' },
      { name: 'administracion', label: 'Administración', icon: 'M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 011.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 01-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 01-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.107-1.204l-.527-.738a1.125 1.125 0 01.12-1.45l.773-.773a1.125 1.125 0 011.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z', color: 'slate' },
      { name: 'personal', label: 'Personal y Roles', icon: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z', color: 'violet' },
      { name: 'registro-absoluto', label: 'Registro Absoluto', icon: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01', color: 'indigo' },
    ],
  },
]

const ADMIN_COLORS: Record<AdminColor, { icon: string; iconHover: string; border: string; topBorder: string }> = {
  indigo: { icon: 'bg-indigo-50 text-indigo-600', iconHover: 'group-hover:bg-indigo-600', border: 'hover:border-indigo-300', topBorder: 'border-t-indigo-400' },
  amber: { icon: 'bg-amber-50 text-amber-600', iconHover: 'group-hover:bg-amber-600', border: 'hover:border-amber-300', topBorder: 'border-t-amber-400' },
  emerald: { icon: 'bg-emerald-50 text-emerald-600', iconHover: 'group-hover:bg-emerald-600', border: 'hover:border-emerald-300', topBorder: 'border-t-emerald-400' },
  violet: { icon: 'bg-violet-50 text-violet-600', iconHover: 'group-hover:bg-violet-600', border: 'hover:border-violet-300', topBorder: 'border-t-violet-400' },
  sky: { icon: 'bg-sky-50 text-sky-600', iconHover: 'group-hover:bg-sky-600', border: 'hover:border-sky-300', topBorder: 'border-t-sky-400' },
  rose: { icon: 'bg-rose-50 text-rose-600', iconHover: 'group-hover:bg-rose-600', border: 'hover:border-rose-300', topBorder: 'border-t-rose-400' },
  teal: { icon: 'bg-teal-50 text-teal-600', iconHover: 'group-hover:bg-teal-600', border: 'hover:border-teal-300', topBorder: 'border-t-teal-400' },
  red: { icon: 'bg-red-50 text-red-600', iconHover: 'group-hover:bg-red-600', border: 'hover:border-red-300', topBorder: 'border-t-red-400' },
  orange: { icon: 'bg-orange-50 text-orange-600', iconHover: 'group-hover:bg-orange-600', border: 'hover:border-orange-300', topBorder: 'border-t-orange-400' },
  fuchsia: { icon: 'bg-fuchsia-50 text-fuchsia-600', iconHover: 'group-hover:bg-fuchsia-600', border: 'hover:border-fuchsia-300', topBorder: 'border-t-fuchsia-400' },
  slate: { icon: 'bg-slate-100 text-slate-600', iconHover: 'group-hover:bg-slate-600', border: 'hover:border-slate-300', topBorder: 'border-t-slate-400' },
}

const permitido = (name: string) => auth.puedeAlguno(itemPorRuta(name)?.permiso)

const moduleLinks = computed(() => moduleLinksTodos.filter((l) => permitido(l.name)))
const secciones = computed(() =>
  SECCIONES.map((s) => ({ ...s, tarjetas: s.tarjetas.filter((t) => permitido(t.name)) })).filter((s) => s.tarjetas.length),
)
const adminActive = computed(() => SECCIONES.some((s) => s.tarjetas.some((t) => t.name === route.name)))

// El menú de gestión se muestra a pantalla completa (no un dropdown chico) —
// se bloquea el scroll del body mientras está abierto y se puede cerrar con Esc,
// con el botón de cerrar, o navegando a un módulo.
const menuGestionAbierto = ref(false)

function abrirMenuGestion() {
  menuGestionAbierto.value = true
  document.body.style.overflow = 'hidden'
}

function cerrarMenuGestion() {
  menuGestionAbierto.value = false
  document.body.style.overflow = ''
}

// Menú de la cuenta (burbuja con el nombre): se cierra al hacer clic afuera, con Esc
// o al cambiar de pantalla.
const menuCuentaAbierto = ref(false)
const menuCuenta = ref<HTMLElement | null>(null)

const iniciales = computed(() =>
  (auth.staff?.nombre ?? 'Personal')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p.charAt(0).toUpperCase())
    .join(''),
)
const nombreRol = computed(() => auth.staff?.rol_nombre ?? auth.staff?.rol ?? 'Personal')

watch(() => route.fullPath, () => {
  cerrarMenuGestion()
  menuCuentaAbierto.value = false
})

function onKeyDown(event: KeyboardEvent) {
  if (event.key !== 'Escape') return
  if (menuGestionAbierto.value) cerrarMenuGestion()
  if (cambioClave.abierto && !cambioClave.confirmando) cambioClave.abierto = false
  menuCuentaAbierto.value = false
}

function alHacerClic(event: MouseEvent) {
  if (menuCuentaAbierto.value && menuCuenta.value && !menuCuenta.value.contains(event.target as Node)) menuCuentaAbierto.value = false
}

onMounted(() => {
  document.addEventListener('keydown', onKeyDown)
  document.addEventListener('click', alHacerClic)
  // Las páginas de gestión abren este menú desde su ruta de navegación («Gestión › …»).
  window.addEventListener('abrir-menu-gestion', abrirMenuGestion)
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeyDown)
  document.removeEventListener('click', alHacerClic)
  window.removeEventListener('abrir-menu-gestion', abrirMenuGestion)
  document.body.style.overflow = ''
})

function pedirCambioClave() {
  menuCuentaAbierto.value = false
  abrirCambioClave()
}

function pedirSalida() {
  menuCuentaAbierto.value = false
  confirmandoSalida.value = true
}

const confirmandoSalida = ref(false)

async function onLogout() {
  confirmandoSalida.value = false
  await auth.logout()
  router.push({ name: 'login' })
}

// ---- Cambiar mi contraseña ----
// Mientras se confirma, el formulario se oculta (sin perder lo escrito) y queda el diálogo.
const cambioClave = reactive({ abierto: false, confirmando: false, guardando: false, actual: '', nueva: '', confirmacion: '' })

function abrirCambioClave() {
  Object.assign(cambioClave, { abierto: true, confirmando: false, actual: '', nueva: '', confirmacion: '' })
}

function revisarClave() {
  if (cambioClave.nueva.length < 8) {
    toast.error('La contraseña nueva debe tener al menos 8 caracteres')
    return
  }
  if (cambioClave.nueva !== cambioClave.confirmacion) {
    toast.error('La confirmación no coincide con la contraseña nueva')
    return
  }
  cambioClave.confirmando = true
}

async function guardarClave() {
  cambioClave.guardando = true
  try {
    await api.patch('/auth/password', {
      actual: cambioClave.actual,
      nueva: cambioClave.nueva,
      nueva_confirmation: cambioClave.confirmacion,
    })
    toast.success('Contraseña actualizada. Se cerraron tus otras sesiones abiertas.')
    Object.assign(cambioClave, { abierto: false, confirmando: false })
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo cambiar la contraseña'))
    cambioClave.confirmando = false
  } finally {
    cambioClave.guardando = false
  }
}
</script>

<template>
  <header
    class="h-16 sm:h-20 shrink-0 flex items-center px-3 sm:px-6 2xl:px-8 gap-2 sm:gap-3"
    style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%); border-bottom: 1px solid rgba(255,255,255,0.1);"
  >
    <!-- Logo -->
    <router-link :to="{ name: 'dashboard' }" class="flex items-center gap-2.5 shrink-0">
      <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 p-1">
        <img :src="logoUmag" alt="UMAG" class="h-full w-full rounded-lg object-cover" />
      </span>
      <span class="hidden sm:block font-serif text-[15px] font-bold leading-[1.1] text-white">Biblioteca<br />UMAG</span>
      <span class="hidden sm:inline-flex items-center rounded-full bg-[#b08d57] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-[#1a2430]">
        Admin
      </span>
    </router-link>

    <!-- Módulos principales (desde pantallas anchas) -->
    <nav class="hidden lg:flex flex-1 min-w-0 justify-center" aria-label="Módulos principales">
      <div class="flex items-center gap-0.5 rounded-xl bg-white/10 p-1">
        <router-link
          v-for="link in [{ name: 'dashboard', label: 'Inicio', shortcut: '' }, ...moduleLinks]"
          :key="link.name"
          :to="{ name: link.name }"
          class="flex h-8 items-center gap-1.5 rounded-lg px-3 text-[13px] font-medium whitespace-nowrap transition-colors"
          :class="route.name === link.name ? 'bg-[#b08d57] text-[#1a2430] shadow-sm' : 'text-white/85 hover:bg-white/10 hover:text-white'"
        >
          {{ link.label }}
          <kbd
            v-if="link.shortcut"
            class="rounded border px-1 py-px text-[10px] font-bold leading-none"
            :class="route.name === link.name ? 'border-[#1a2430]/30 bg-[#1a2430]/10 text-[#1a2430]' : 'border-white/25 bg-white/10 text-white/90'"
          >{{ link.shortcut }}</kbd>
        </router-link>
      </div>
    </nav>

    <button
      v-if="secciones.length"
      @click="abrirMenuGestion"
      class="hidden lg:flex h-9 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-[13px] font-medium transition-colors"
      :class="adminActive ? 'border-transparent bg-[#b08d57] text-[#1a2430]' : 'border-white/20 bg-white/10 text-white hover:bg-white/15'"
    >
      <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
      Gestión
      <svg class="h-3.5 w-3.5 shrink-0 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
      </svg>
    </button>

    <!-- Botón de menú (celular y tablet) -->
    <button
      @click="abrirMenuGestion"
      class="lg:hidden flex-1 min-w-0 flex items-center justify-center gap-2 h-10 px-3 rounded-lg text-sm font-medium text-white bg-white/10 hover:bg-white/15"
      aria-label="Abrir menú"
    >
      <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
      </svg>
      <span class="truncate">{{ route.name === 'dashboard' ? 'Menú' : (itemPorRuta(route.name)?.label ?? 'Menú') }}</span>
    </button>

    <Teleport to="body">
      <div
        v-if="menuGestionAbierto"
        class="fixed inset-0 z-[200] flex items-start justify-center overflow-y-auto bg-black/50 backdrop-blur-sm p-3 sm:p-8"
        @click.self="cerrarMenuGestion"
      >
        <!-- Marco con bisel: más claro arriba y más oscuro abajo, para que el panel no sea un cuadrado plano -->
        <div class="w-full max-w-3xl lg:max-w-4xl my-2 sm:my-6 rounded-[22px] p-[5px] bg-gradient-to-b from-white/85 via-indigo-200/70 to-indigo-400/70 shadow-2xl ring-1 ring-white/30">
        <div class="rounded-2xl border border-indigo-200/80 bg-white shadow-[inset_0_2px_0_rgba(255,255,255,1),inset_0_-2px_0_rgba(79,70,229,0.08)]">
          <div class="px-5 py-5 sm:px-7 sm:py-6">
            <div class="flex items-start justify-between gap-4 mb-5">
              <div class="flex items-center gap-2.5">
                <span class="w-1 h-6 rounded-full bg-indigo-600 shrink-0"></span>
                <div>
                  <h2 class="text-lg sm:text-xl font-serif font-bold text-gray-900">Menú de Gestión</h2>
                  <p class="text-xs text-gray-500 mt-0.5">Selecciona un módulo para continuar · se muestran solo los que tu rol puede usar</p>
                </div>
              </div>
              <button
                @click="cerrarMenuGestion"
                class="shrink-0 h-8 w-8 flex items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 hover:text-gray-800 hover:border-gray-300 shadow-sm transition-colors"
                aria-label="Cerrar menú de gestión"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <!-- En celular, el menú también trae los módulos de la barra superior -->
            <div class="lg:hidden mb-6">
              <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2">Módulos principales</p>
              <div class="grid grid-cols-2 gap-2">
                <router-link
                  :to="{ name: 'dashboard' }"
                  class="flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-3 text-sm font-semibold text-gray-800 shadow-sm"
                  :class="route.name === 'dashboard' ? 'ring-2 ring-indigo-400' : ''"
                >
                  <svg class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONO_INICIO" /></svg>
                  Inicio
                </router-link>
                <router-link
                  v-for="link in moduleLinks"
                  :key="link.name"
                  :to="{ name: link.name }"
                  class="flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-3 text-sm font-semibold text-gray-800 shadow-sm"
                  :class="route.name === link.name ? 'ring-2 ring-indigo-400' : ''"
                >
                  <svg class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="link.icon" /></svg>
                  {{ link.label }}
                </router-link>
              </div>
            </div>

            <div class="space-y-6">
              <section v-for="seccion in secciones" :key="seccion.titulo">
                <p class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2.5">
                  {{ seccion.titulo }}
                  <span class="h-px flex-1 bg-gray-100"></span>
                </p>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-3">
                  <router-link
                    v-for="link in seccion.tarjetas"
                    :key="link.name"
                    :to="{ name: link.name }"
                    @click="cerrarMenuGestion"
                    class="group flex flex-col items-center rounded-xl border border-t-[3px] border-gray-200 bg-white p-3.5 text-center shadow-sm transition-all hover:shadow-md active:scale-[0.98]"
                    :class="[ADMIN_COLORS[link.color].border, ADMIN_COLORS[link.color].topBorder, route.name === link.name ? 'ring-2 ring-indigo-400' : '']"
                  >
                    <div
                      class="mb-2 flex h-9 w-9 items-center justify-center rounded-xl transition-colors group-hover:text-white"
                      :class="[ADMIN_COLORS[link.color].icon, ADMIN_COLORS[link.color].iconHover]"
                    >
                      <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="link.icon" />
                      </svg>
                    </div>
                    <div class="text-xs font-semibold text-gray-900 leading-tight">{{ link.label }}</div>
                  </router-link>
                </div>
              </section>
            </div>
          </div>
        </div>
        </div>
      </div>
    </Teleport>

    <!-- Cuenta: nombre y rol; el menú tiene «Cambiar mi contraseña» y «Cerrar sesión» -->
    <div ref="menuCuenta" class="relative shrink-0">
      <button
        type="button"
        class="flex items-center gap-2 rounded-full p-0.5 transition-colors hover:bg-white/15 sm:border sm:border-white/20 sm:bg-white/10 sm:py-1 sm:pl-1 sm:pr-3"
        aria-haspopup="menu"
        :aria-expanded="menuCuentaAbierto"
        aria-label="Menú de tu cuenta"
        @click="menuCuentaAbierto = !menuCuentaAbierto"
      >
        <span class="flex h-9 w-9 sm:h-8 sm:w-8 items-center justify-center rounded-full bg-[#b08d57] text-xs font-bold text-[#1a2430]">
          {{ iniciales }}
        </span>
        <span class="hidden xl:block text-left leading-tight">
          <span class="block text-[13px] font-semibold text-white">{{ auth.staff?.nombre ?? 'Personal' }}</span>
          <span class="block text-[11px] text-indigo-200">{{ nombreRol }}</span>
        </span>
        <svg class="hidden sm:block h-4 w-4 text-indigo-200 transition-transform" :class="menuCuentaAbierto ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      <div
        v-if="menuCuentaAbierto"
        role="menu"
        class="absolute right-0 z-50 mt-2 w-64 rounded-xl border border-gray-200 bg-white p-1.5 shadow-lg"
      >
        <div class="px-3 py-2">
          <p class="text-sm font-semibold text-gray-900">{{ auth.staff?.nombre ?? 'Personal' }}</p>
          <p class="text-xs text-gray-500">{{ nombreRol }}</p>
          <p class="truncate text-xs text-gray-400">{{ auth.staff?.email }}</p>
        </div>
        <div class="my-1 border-t border-gray-100"></div>
        <button
          type="button"
          role="menuitem"
          class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
          @click="pedirCambioClave"
        >
          <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
          </svg>
          Cambiar mi contraseña
        </button>
        <div class="my-1 border-t border-gray-100"></div>
        <button
          type="button"
          role="menuitem"
          class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-red-600 transition-colors hover:bg-red-50"
          @click="pedirSalida"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
          </svg>
          Cerrar sesión
        </button>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="cambioClave.abierto && !cambioClave.confirmando"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[210] flex items-center justify-center p-4"
        @click.self="cambioClave.abierto = false"
      >
        <form class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm" @submit.prevent="revisarClave">
          <h3 class="text-lg font-bold text-gray-900 mb-1">Cambiar mi contraseña</h3>
          <p class="text-sm text-gray-500 mb-5">Al guardar se cierran tus otras sesiones abiertas (por ejemplo, en otro computador).</p>
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1" for="clave-actual">Contraseña actual</label>
              <input id="clave-actual" v-model="cambioClave.actual" type="password" autocomplete="current-password" required class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1" for="clave-nueva">Contraseña nueva <span class="text-gray-400 font-normal">(mínimo 8 caracteres)</span></label>
              <input id="clave-nueva" v-model="cambioClave.nueva" type="password" autocomplete="new-password" required minlength="8" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1" for="clave-confirmacion">Repite la contraseña nueva</label>
              <input id="clave-confirmacion" v-model="cambioClave.confirmacion" type="password" autocomplete="new-password" required class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" />
            </div>
          </div>
          <div class="flex gap-3 mt-6">
            <button type="button" @click="cambioClave.abierto = false" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors font-medium text-sm">
              Cancelar
            </button>
            <button type="submit" class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium text-sm">
              Guardar
            </button>
          </div>
        </form>
      </div>
    </Teleport>

    <DialogoConfirmacion
      :abierto="cambioClave.confirmando"
      titulo="¿Cambiar tu contraseña?"
      confirmar="Sí, cambiar"
      :cargando="cambioClave.guardando"
      @confirmar="guardarClave"
      @cancelar="cambioClave.confirmando = false"
    >
      <p>Desde ahora entrarás con la contraseña nueva y se cerrarán tus otras sesiones abiertas (por ejemplo, en otro computador).</p>
    </DialogoConfirmacion>

    <DialogoConfirmacion
      :abierto="confirmandoSalida"
      titulo="¿Cerrar sesión?"
      confirmar="Sí, cerrar sesión"
      @confirmar="onLogout"
      @cancelar="confirmandoSalida = false"
    >
      <p>Tendrás que volver a iniciar sesión para seguir usando el panel.</p>
    </DialogoConfirmacion>
  </header>
</template>
