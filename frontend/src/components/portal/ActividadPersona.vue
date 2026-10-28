<script setup lang="ts">
import type { ActividadPortal } from '@/types'

defineProps<{
  actividad: ActividadPortal | null
  error: boolean
}>()

function fechaLarga(fecha: string) {
  return new Date(`${fecha}T12:00:00`).toLocaleDateString('es-CL', { day: 'numeric', month: 'long' })
}

function diasHasta(fecha: string) {
  const hoy = new Date()
  hoy.setHours(12, 0, 0, 0)
  return Math.round((new Date(`${fecha}T12:00:00`).getTime() - hoy.getTime()) / 86400000)
}

/** Aviso de vencimiento: solo si está atrasado o vence en los próximos 3 días. */
function vencimiento(fecha: string | null) {
  if (!fecha) return null
  const dias = diasHasta(fecha)
  const plural = (n: number) => `${n} ${n === 1 ? 'día' : 'días'}`
  if (dias < 0) return { texto: `Atrasado ${plural(-dias)}`, cls: 'bg-red-50 text-red-700 border-red-200' }
  if (dias === 0) return { texto: 'Vence hoy', cls: 'bg-amber-50 text-amber-800 border-amber-200' }
  if (dias <= 3) return { texto: `Vence en ${plural(dias)}`, cls: 'bg-amber-50 text-amber-800 border-amber-200' }
  return null
}
</script>

<template>
  <section class="rounded-2xl border border-gray-200 bg-white px-5 pt-5 pb-2 shadow-sm">
    <h2 class="font-sans text-base font-bold text-gray-900">Tu actividad</h2>

    <p v-if="error" class="py-6 text-sm text-gray-500">No se pudo cargar tu actividad. Intenta recargar la página.</p>
    <p v-else-if="!actividad" class="py-6 text-sm text-gray-400">Cargando tu actividad…</p>
    <ul v-else class="mt-2 divide-y divide-gray-100">
      <li v-for="prestamo in actividad.prestamos" :key="`p${prestamo.id}`" class="flex items-center gap-4 py-3.5">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
          <svg v-if="prestamo.tipo_item === 'libro'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
          </svg>
          <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" />
          </svg>
        </span>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-semibold text-gray-900">{{ prestamo.titulo }}</p>
          <p class="text-xs text-gray-500">
            <template v-if="prestamo.en_sala">Uso en sala · se devuelve en el mesón antes del cierre</template>
            <template v-else>{{ prestamo.tipo_item === 'libro' ? 'Préstamo' : 'Recurso de apoyo' }}<template v-if="prestamo.fecha_devolucion"> · vence el {{ fechaLarga(prestamo.fecha_devolucion) }}</template></template>
          </p>
        </div>
        <span
          v-if="vencimiento(prestamo.fecha_devolucion)"
          class="shrink-0 rounded-full border px-2.5 py-1 text-xs font-medium"
          :class="vencimiento(prestamo.fecha_devolucion)?.cls"
        >
          {{ vencimiento(prestamo.fecha_devolucion)?.texto }}
        </span>
      </li>

      <li v-for="reserva in actividad.reservas_libro" :key="`l${reserva.id}`" class="flex items-center gap-4 py-3.5">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
          </svg>
        </span>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-semibold text-gray-900">{{ reserva.titulo }}</p>
          <p class="text-xs text-gray-500">
            <template v-if="reserva.estado === 'pendiente'">
              Reserva lista para retirar<template v-if="reserva.fecha_retiro"> · hasta el {{ fechaLarga(reserva.fecha_retiro) }}</template>
            </template>
            <template v-else>En lista de espera<template v-if="reserva.posicion"> · lugar #{{ reserva.posicion }}</template></template>
          </p>
        </div>
        <router-link
          :to="{ name: 'portal-catalogo' }"
          class="shrink-0 rounded-lg border border-gray-300 px-3.5 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition-colors"
        >
          Ver
        </router-link>
      </li>

      <li v-for="reserva in actividad.reservas_sala" :key="`s${reserva.id}`" class="flex items-center gap-4 py-3.5">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
          </svg>
        </span>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-semibold text-gray-900">{{ reserva.sala }} · hoy</p>
          <p class="text-xs text-gray-500">Reserva · {{ reserva.hora_inicio }} a {{ reserva.hora_fin }}</p>
        </div>
        <router-link
          :to="{ name: 'portal-salas' }"
          class="shrink-0 rounded-lg border border-gray-300 px-3.5 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition-colors"
        >
          Gestionar
        </router-link>
      </li>

      <li
        v-if="!actividad.prestamos.length && !actividad.reservas_libro.length && !actividad.reservas_sala.length"
        class="flex items-center gap-4 py-3.5"
      >
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-gray-500">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
          </svg>
        </span>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-gray-900">Sin préstamos ni reservas activas</p>
          <p class="text-xs text-gray-500">Cuando pidas un libro o reserves una sala, aparecerá aquí.</p>
        </div>
      </li>

      <li class="flex items-center gap-4 py-3.5">
        <template v-if="actividad.multas.cantidad === 0">
          <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
            </svg>
          </span>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-gray-900">Sin multas pendientes</p>
            <p class="text-xs text-gray-500">Puedes descargar tu constancia desde Accesos rápidos.</p>
          </div>
        </template>
        <template v-else>
          <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
            </svg>
          </span>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-gray-900">
              {{ actividad.multas.cantidad === 1 ? '1 multa pendiente' : `${actividad.multas.cantidad} multas pendientes` }}
              por ${{ actividad.multas.monto_total.toLocaleString('es-CL') }}
            </p>
            <p class="text-xs text-gray-500">Págala en la biblioteca para volver a pedir o reservar material.</p>
          </div>
        </template>
      </li>
    </ul>
  </section>
</template>
