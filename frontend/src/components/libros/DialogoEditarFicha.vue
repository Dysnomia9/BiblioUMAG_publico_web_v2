<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'

/**
 * «Editar ficha» desde el Listado de material: avisa que se abre Catalogación con la ficha
 * cargada y que nada cambia hasta guardar allá, con el atajo para abrirla en otra pestaña.
 * Se cierra con Esc o con un clic afuera.
 */
const props = defineProps<{ titulo: string | null }>()
const emit = defineEmits<{ confirmar: []; cancelar: [] }>()

function alPresionarTecla(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('cancelar')
}
watch(
  () => props.titulo,
  (titulo) => {
    if (titulo) window.addEventListener('keydown', alPresionarTecla)
    else window.removeEventListener('keydown', alPresionarTecla)
  },
  { immediate: true },
)
onBeforeUnmount(() => window.removeEventListener('keydown', alPresionarTecla))
</script>

<template>
  <Teleport to="body">
    <div v-if="titulo" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm" @click.self="emit('cancelar')">
      <div class="w-full max-w-lg overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-indigo-100" role="dialog" aria-modal="true" :aria-label="`Editar la ficha ${titulo}`">
        <!-- Encabezado: el título del libro sobre la franja de la ficha, con lomos de libros de adorno -->
        <div class="relative overflow-hidden px-7 pb-6 pt-6 text-white" style="background: linear-gradient(120deg, #2D1B69 0%, #3B28A3 40%, #4F46E5 100%)">
          <div class="pointer-events-none absolute -right-2 top-4 flex items-end gap-1 opacity-30" aria-hidden="true">
            <span class="h-24 w-8 rounded-sm border border-white/60 bg-white/20"></span>
            <span class="relative h-28 w-9 rounded-sm border border-white/60 bg-white/25"><span class="absolute left-1.5 right-1.5 top-4 h-1.5 rounded-sm bg-white/50"></span></span>
            <span class="h-20 w-7 rounded-sm border border-white/60 bg-white/20"></span>
            <span class="relative h-24 w-9 rounded-sm border border-white/60 bg-white/25"><span class="absolute left-1.5 right-1.5 top-4 h-1.5 rounded-sm bg-white/50"></span></span>
          </div>
          <p class="relative inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-bold uppercase tracking-widest text-white/90">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
            Editar ficha
          </p>
          <h3 class="relative mt-3 pr-24 font-serif text-2xl font-bold leading-snug">{{ titulo }}</h3>
        </div>

        <div class="space-y-4 px-7 py-6">
          <!-- Adónde lleva -->
          <div class="flex items-center gap-4 rounded-2xl border border-indigo-100 bg-indigo-50/50 px-4 py-3.5">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-sm" aria-hidden="true">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
            </span>
            <div>
              <p class="text-[11px] font-bold uppercase tracking-widest text-gray-500">Se abre en</p>
              <p class="text-base font-bold text-indigo-950">Catalogación, con la ficha cargada</p>
            </div>
          </div>

          <!-- Nada se guarda todavía -->
          <p class="flex items-center gap-3 text-[15px] text-gray-600">
            <svg class="h-5 w-5 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
            <span>Nada cambia hasta que pulses <strong class="text-gray-900">«Guardar cambios»</strong> allá.</span>
          </p>

          <!-- Atajo -->
          <div class="rounded-2xl border border-dashed border-indigo-200 bg-gray-50/60 px-4 py-3 text-sm leading-loose text-gray-600">
            <strong class="text-indigo-900">Atajo:</strong>
            abrir en otra pestaña sin esta pregunta con
            <kbd class="rounded-md border border-gray-300 bg-white px-1.5 py-0.5 font-sans text-xs font-semibold text-gray-800 shadow-sm">Cmd/Ctrl</kbd>
            +
            <kbd class="rounded-md border border-gray-300 bg-white px-1.5 py-0.5 font-sans text-xs font-semibold text-gray-800 shadow-sm">clic</kbd>
            en «Editar», o «Editar en otra pestaña» en el menú
            <kbd class="rounded-md border border-gray-300 bg-white px-1.5 py-0.5 font-sans text-xs font-semibold text-gray-800 shadow-sm">⋯</kbd>
          </div>
        </div>

        <div class="flex flex-col-reverse gap-3 border-t border-gray-100 px-7 py-5 sm:flex-row">
          <button type="button" class="flex-1 rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm font-semibold text-gray-800 transition-colors hover:bg-gray-50" @click="emit('cancelar')">
            Cancelar
          </button>
          <button
            type="button"
            class="inline-flex flex-[1.4] items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-[inset_0_-3px_0_rgb(0_0_0/0.2)] transition-colors hover:bg-indigo-700"
            @click="emit('confirmar')"
          >
            Sí, editar
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
