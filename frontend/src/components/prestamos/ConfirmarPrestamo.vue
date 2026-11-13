<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { fechaCorta } from '@/utils/prestamos'

/**
 * Confirmación del préstamo de un libro en el mesón: qué copia sale, a quién y hasta cuándo.
 * El préstamo se registra recién al confirmar. Para el uso en sala, la devolución es hoy.
 */
const props = defineProps<{
  pendiente: { titulo: string; copia: string | null; codigoBarras: string; fechaDevolucion: string; enSala: boolean } | null
  usuario: { nombre: string; apellido?: string | null; rut?: string | null } | null
  cargando?: boolean
}>()
const emit = defineEmits<{ confirmar: []; cancelar: [] }>()

const boton = ref<HTMLButtonElement | null>(null)

/** Días desde hoy hasta la devolución (fechas «AAAA-MM-DD», sin la hora). */
const dias = computed(() => {
  if (!props.pendiente) return 0
  const [a, m, d] = props.pendiente.fechaDevolucion.split('-').map(Number)
  const hoy = new Date()
  return Math.round((Date.UTC(a, m - 1, d) - Date.UTC(hoy.getFullYear(), hoy.getMonth(), hoy.getDate())) / 86_400_000)
})
const textoDias = computed(() => (dias.value <= 0 ? 'hoy' : dias.value === 1 ? 'mañana' : `en ${dias.value} días`))
const nombre = computed(() => [props.usuario?.nombre, props.usuario?.apellido].filter(Boolean).join(' '))

function cerrar() {
  if (!props.cargando) emit('cancelar')
}
function teclas(e: KeyboardEvent) {
  if (e.key === 'Escape') cerrar()
}
watch(
  () => !!props.pendiente,
  async (abierto) => {
    if (abierto) {
      window.addEventListener('keydown', teclas)
      await nextTick()
      boton.value?.focus()
    } else window.removeEventListener('keydown', teclas)
  },
)
onBeforeUnmount(() => window.removeEventListener('keydown', teclas))
</script>

<template>
  <Teleport to="body">
    <div v-if="pendiente" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm" @click.self="cerrar">
      <div
        class="w-full max-w-md overflow-hidden rounded-2xl border-t-[3px] border-t-indigo-600 bg-white shadow-2xl ring-1 ring-indigo-100"
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirmar-prestamo-titulo"
      >
        <!-- Cabecera: qué sale, con el libro abierto que se va -->
        <div class="relative overflow-hidden border-b border-gray-100 bg-gradient-to-b from-indigo-50/80 to-white px-5 pb-4 pt-4">
          <svg class="libro pointer-events-none absolute -right-1 top-2 h-[5.5rem] w-[7.5rem]" viewBox="0 0 120 88" fill="none" aria-hidden="true">
            <!-- sombra -->
            <ellipse cx="54" cy="80" rx="40" ry="3.5" fill="#6366f1" opacity="0.10" />
            <!-- tapas -->
            <path d="M14 24c14-5 28-5 40 2v52c-12-7-26-7-40-2V24z" fill="#c7d2fe" />
            <path d="M94 24c-14-5-28-5-40 2v52c12-7 26-7 40-2V24z" fill="#a5b4fc" />
            <!-- hojas -->
            <path d="M17 21c13-4 26-3 37 4v50c-11-7-24-8-37-4V21z" fill="#f5f7ff" stroke="#a5b4fc" stroke-width="1.2" />
            <path d="M91 21c-13-4-26-3-37 4v50c11-7 24-8 37-4V21z" fill="#fff" stroke="#a5b4fc" stroke-width="1.2" />
            <!-- canto de las hojas -->
            <path d="M20 73c11-3 23-2 34 4M88 73c-11-3-23-2-34 4" stroke="#c7d2fe" stroke-width="1" />
            <!-- líneas de texto -->
            <g stroke="#c7d2fe" stroke-width="1.6" stroke-linecap="round">
              <path d="M24 32c8-2 16-1 23 2M24 39c8-2 16-1 23 2M24 46c8-2 16-1 23 2M24 53c6-1.5 12-1 17 1" />
              <path d="M61 34c7-3 15-4 23-2M61 41c7-3 15-4 23-2M61 48c7-3 15-4 23-2M61 55c5-2 11-3 17-2" />
            </g>
            <!-- lomo -->
            <path d="M54 25v52" stroke="#818cf8" stroke-width="1.4" />
            <!-- marcapáginas -->
            <path d="M72 20v16l3.5-3 3.5 3V19.5" fill="#f59e0b" />
            <!-- flecha: sale del mesón -->
            <g class="flecha" stroke="#10b981" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
              <path d="M84 9h24m-6-5 6 5-6 5" />
              <path d="M78 9h1.5M72 9h1.5" opacity="0.5" />
            </g>
          </svg>

          <p class="text-[11px] font-bold uppercase tracking-widest text-indigo-600">{{ pendiente.enSala ? 'Uso en sala' : 'Se prestará' }}</p>
          <h3 id="confirmar-prestamo-titulo" class="mt-1 line-clamp-2 pr-24 font-serif text-2xl font-bold leading-tight text-gray-900">{{ pendiente.titulo }}</h3>
          <div class="mt-2 flex flex-wrap items-center gap-1.5">
            <span v-if="pendiente.copia" class="rounded-md bg-indigo-100/80 px-2 py-0.5 text-xs font-semibold text-indigo-800 ring-1 ring-inset ring-indigo-200">{{ pendiente.copia }}</span>
            <span class="rounded-md border border-gray-200 bg-white px-2 py-0.5 font-mono text-xs text-gray-700">{{ pendiente.codigoBarras }}</span>
          </div>
          <p class="mt-3 text-sm leading-relaxed text-gray-600">
            <template v-if="pendiente.enSala">
              <strong class="text-gray-900">{{ nombre }}</strong> leerá <strong class="text-gray-900">{{ pendiente.titulo }}</strong> en la biblioteca y lo devuelve
              <strong class="text-gray-900">hoy, antes del cierre</strong>.
            </template>
            <template v-else>
              Se prestará <strong class="text-gray-900">{{ pendiente.titulo }}<template v-if="pendiente.copia"> ({{ pendiente.copia }})</template></strong> a
              <strong class="text-gray-900">{{ nombre }}</strong>, con devolución acordada para el <strong class="whitespace-nowrap text-gray-900">{{ fechaCorta(`${pendiente.fechaDevolucion}T12:00:00`) }}</strong>.
            </template>
          </p>
        </div>

        <div class="space-y-3 px-5 py-4">
          <!-- A quién -->
          <div class="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-100/70 text-indigo-600" aria-hidden="true">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
            </span>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Se presta a</p>
              <p class="truncate text-sm font-bold text-gray-900">{{ nombre }}</p>
              <p v-if="usuario?.rut" class="font-mono text-xs text-gray-500">{{ usuario.rut }}</p>
            </div>
          </div>

          <!-- Hasta cuándo -->
          <div class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-gradient-to-r from-emerald-50 to-emerald-50/40 px-3.5 py-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-emerald-200 bg-white text-emerald-600" aria-hidden="true">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
            </span>
            <div class="min-w-0 flex-1">
              <p class="text-[10px] font-bold uppercase tracking-widest text-emerald-800">{{ pendiente.enSala ? 'Se devuelve' : 'Devolución acordada' }}</p>
              <p class="font-serif text-xl font-bold leading-tight text-emerald-900">
                {{ pendiente.enSala ? 'Hoy, antes del cierre' : fechaCorta(`${pendiente.fechaDevolucion}T12:00:00`) }}
              </p>
            </div>
            <span v-if="!pendiente.enSala" class="shrink-0 rounded-full border border-emerald-200 bg-white px-2.5 py-0.5 text-xs font-bold text-emerald-800">{{ textoDias }}</span>
          </div>

          <p class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50/70 px-3.5 py-2.5 text-xs leading-relaxed text-amber-900">
            <svg class="mt-px h-4 w-4 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
            <span>El {{ pendiente.enSala ? 'uso en sala' : 'préstamo' }} se registra recién al pulsar <strong>«Sí, prestar»</strong>. Si cancela, no se presta nada.</span>
          </p>
        </div>

        <div class="flex gap-3 border-t border-gray-100 bg-gray-50/70 px-5 py-3">
          <button
            type="button"
            class="flex-1 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-100"
            :disabled="cargando"
            @click="cerrar"
          >
            Cancelar
          </button>
          <button
            ref="boton"
            type="button"
            class="inline-flex flex-[1.4] items-center justify-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] transition-colors hover:bg-indigo-700 disabled:opacity-60"
            :disabled="cargando"
            @click="emit('confirmar')"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
            {{ cargando ? 'Confirmando…' : 'Sí, prestar' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
/* El libro flota apenas y la flecha sale y vuelve: solo si el sistema no pide menos movimiento. */
@media (prefers-reduced-motion: no-preference) {
  .libro {
    animation: flotar 4s ease-in-out infinite;
  }
  .flecha {
    animation: salir 2.2s ease-in-out infinite;
  }
}
@keyframes flotar {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
}
@keyframes salir {
  0%, 100% { transform: translateX(0); opacity: 1; }
  50% { transform: translateX(4px); opacity: 0.75; }
}
</style>
