<script setup lang="ts">
/**
 * Pasos de "cómo se usa esta pantalla" (panel del personal y portal). Cada vez que
 * se abre la pantalla parte oculta (solo se ve el botón); se despliega al pulsarlo.
 * Con `:con-boton="false"` y `v-model:abierta`, la pantalla pone su propio botón.
 */
withDefaults(
  defineProps<{
    pasos: string[]
    titulo?: string
    consejo?: string
    conBoton?: boolean
  }>(),
  { titulo: '¿Cómo se usa esta pantalla?', conBoton: true },
)

const abierta = defineModel<boolean>('abierta', { default: false })
</script>

<template>
  <div v-if="conBoton || abierta" class="mb-6">
    <button
      v-if="!abierta"
      class="inline-flex items-center gap-1.5 rounded-full border border-indigo-200 bg-white px-3.5 py-1.5 text-xs font-medium text-indigo-700 shadow-sm hover:bg-indigo-50 transition-colors"
      @click="abierta = true"
    >
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
      </svg>
      {{ titulo }}
    </button>

    <div v-else class="rounded-xl border border-indigo-100 bg-indigo-50/70 p-5">
      <div class="flex items-start justify-between gap-3 mb-3">
        <h3 class="text-sm font-semibold text-indigo-900">{{ titulo }}</h3>
        <button class="text-xs font-medium text-indigo-600 hover:text-indigo-800 shrink-0" @click="abierta = false">Ocultar</button>
      </div>
      <ol class="grid gap-2.5 sm:grid-cols-2">
        <li v-for="(paso, i) in pasos" :key="i" class="flex gap-2.5 text-sm text-gray-700">
          <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">{{ i + 1 }}</span>
          <span v-html="paso" />
        </li>
      </ol>
      <p v-if="consejo" class="mt-3 border-t border-indigo-100 pt-3 text-xs text-indigo-800" v-html="consejo" />
    </div>
  </div>
</template>
