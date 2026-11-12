<script setup lang="ts">
import StaffLayout from '@/components/layout/StaffLayout.vue'
import PestanasLibros from '@/components/libros/PestanasLibros.vue'
import FondoEstante from '@/components/libros/FondoEstante.vue'
import MateriasCatalogo from '@/components/administracion/MateriasCatalogo.vue'

function abrirMenuGestion() {
  window.dispatchEvent(new Event('abrir-menu-gestion'))
}
</script>

<template>
  <StaffLayout>
    <div class="mx-auto max-w-[88rem]">
      <!-- La misma caja de título que Catalogar, Listado e Historial; debajo, el mantenedor. -->
      <section class="mb-5 rounded-2xl border border-gray-200 bg-white shadow-md">
        <div class="relative rounded-t-2xl" style="background: linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%);">
          <FondoEstante />
          <div class="relative min-w-0 px-6 pt-4">
            <nav class="text-xs text-white/75" aria-label="Ruta">
              <button type="button" class="font-medium text-white hover:underline" @click="abrirMenuGestion">Gestión</button>
              <span class="mx-1.5">›</span>
              <span>Catálogo</span>
            </nav>
            <h1 class="font-serif text-2xl font-bold tracking-tight text-white">Materias</h1>
            <p class="mt-0.5 text-sm text-white/75">Renombrar, unir y eliminar las materias que se asignan al catalogar</p>
          </div>
          <PestanasLibros actual="materias-catalogo" en-banda class="relative mt-3 px-5" />
        </div>
        <div class="h-3 rounded-b-2xl"></div>
      </section>

      <MateriasCatalogo />
    </div>
  </StaffLayout>
</template>
