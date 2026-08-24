<script setup lang="ts">
import loginFoto from '@/assets/login-foto.webp'

defineProps<{ subtitulo?: string }>()
</script>

<template>
  <div class="relative hidden md:flex md:flex-col md:justify-between overflow-hidden px-10 py-12 text-white">
    <img :src="loginFoto" alt="" class="absolute inset-0 h-full w-full object-cover" />

    <!-- Velo de marca sobre la foto: mismo gradiente pero semitransparente para que se vea la foto de fondo -->
    <div
      class="absolute inset-0"
      style="background: linear-gradient(135deg, rgba(45,27,105,0.90) 0%, rgba(59,40,163,0.87) 30%, rgba(67,56,202,0.85) 60%, rgba(79,70,229,0.82) 100%);"
    ></div>

    <!-- Decoración: manchas suaves para dar profundidad -->
    <div class="pointer-events-none absolute -top-16 -right-16 h-64 w-64 rounded-full bg-white/10 blur-3xl"></div>
    <div class="pointer-events-none absolute bottom-10 -left-10 h-48 w-48 rounded-full bg-white/5 blur-3xl"></div>

    <!-- Decoración: grilla de puntos, esquina inferior izquierda -->
    <div
      class="pointer-events-none absolute bottom-8 left-8 h-28 w-28 opacity-40"
      style="background-image: radial-gradient(rgba(255,255,255,0.55) 1.2px, transparent 1.2px); background-size: 14px 14px;"
    ></div>

    <div class="relative flex-1 flex flex-col items-center justify-center text-center">
      <div class="mb-6 flex h-16 w-16 items-center justify-center rounded-2xl border border-white/15 bg-white/10">
        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
        </svg>
      </div>

      <h1 class="text-3xl font-serif font-bold">Biblioteca UMAG</h1>

      <div class="my-4 h-px w-16 bg-white/25"></div>

      <p class="text-sm text-white/70 leading-relaxed">
        {{ subtitulo ?? 'Sistema de gestión bibliotecaria' }}<br />
        Universidad de Magallanes
      </p>
    </div>
  </div>
</template>
