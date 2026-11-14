<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import api from '@/services/api'
import apiUsuario from '@/services/apiUsuario'
import { useAuthStore } from '@/stores/auth'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import { useFondo } from '@/composables/useFondo'
import { useTema } from '@/composables/useTema'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { FONDO_PORTAL, FONDO_PREDETERMINADO, FONDOS } from '@/utils/fondos'
import Modal from '@/components/ui/Modal.vue'

/**
 * Aspecto visual (menú de la cuenta): el fondo del panel en modo día o, con `portal`, el
 * del portal de usuarios. Se ve apenas se elige y se guarda en la cuenta (PATCH /auth/fondo
 * o /auth/usuario/fondo), así que sigue a la persona en
 * cualquier computador. Cada miniatura lleva su propio data-fondo: muestra el fondo de
 * verdad, con sus tarjetas y bordes, aunque esté puesto otro o el modo noche.
 */
const props = defineProps<{ abierto: boolean; portal?: boolean }>()
const emit = defineEmits<{ cerrar: [] }>()

const auth = useAuthStore()
const authUsuario = useUsuarioAuthStore()
const toast = useToast()
const { actual, ponerDelPersonal, ponerDelPortal } = useFondo()

// El panel y el portal guardan el fondo cada uno en su cuenta.
const poner = (clave: string | null) => (props.portal ? ponerDelPortal(clave) : ponerDelPersonal(clave))
const guardado = () => (props.portal ? authUsuario.usuario?.fondo ?? null : auth.staff?.fondo ?? null)
const predeterminado = props.portal ? FONDO_PORTAL : FONDO_PREDETERMINADO
const { oscuro, alternar } = useTema()

const DEGRADADO = 'linear-gradient(135deg, #2D1B69 0%, #3B28A3 30%, #4338CA 60%, #4F46E5 100%)'

// Se guarda un momento después del último cambio: con las flechas se recorren los fondos
// de a uno y basta guardar el último.
let pendiente: ReturnType<typeof setTimeout> | undefined

function elegir(clave: string) {
  poner(clave)
  clearTimeout(pendiente)
  pendiente = setTimeout(guardar, 400)
}

async function guardar() {
  clearTimeout(pendiente)
  pendiente = undefined
  const clave = actual.value
  if (props.portal ? !authUsuario.usuario : !auth.staff) return
  if (guardado() === clave) return
  try {
    if (props.portal) {
      const { data } = await apiUsuario.patch<{ fondo: string }>('/auth/usuario/fondo', { fondo: clave })
      if (authUsuario.usuario) authUsuario.usuario.fondo = data.fondo
    } else {
      const { data } = await api.patch<{ fondo: string }>('/auth/fondo', { fondo: clave })
      if (auth.staff) auth.staff.fondo = data.fondo
    }
  } catch (e) {
    // Vuelve al que tiene guardado la cuenta, salvo que ya se haya elegido otro.
    if (actual.value === clave) poner(guardado())
    toast.error(mensajeDeError(e, 'No se pudo guardar el fondo'))
  }
}

// Al cerrar no se espera: lo elegido se guarda de inmediato.
watch(() => props.abierto, (abierto) => {
  if (!abierto && pendiente) guardar()
})
onBeforeUnmount(() => {
  if (pendiente) guardar()
})
</script>

<template>
  <Modal
    :abierto="abierto"
    titulo="Aspecto visual"
:descripcion="`El fondo del ${portal ? 'portal' : 'panel'} en modo día. Queda guardado en tu cuenta, así que te acompaña en cualquier computador.`"
    ancho="xl"
    @cerrar="emit('cerrar')"
  >
    <div v-if="oscuro" class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
      <span>Estás en modo noche: el fondo que elijas se ve en modo día.</span>
      <button type="button" class="font-semibold text-indigo-700 underline-offset-2 hover:underline" @click="alternar">Cambiar a modo día</button>
    </div>

    <fieldset>
      <legend class="sr-only">Fondo del modo día</legend>
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <label
          v-for="f in FONDOS"
          :key="f.clave"
          class="flex cursor-pointer flex-col gap-2 rounded-xl border p-2 transition-colors has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-indigo-500"
          :class="f.clave === actual ? 'border-indigo-500 ring-2 ring-indigo-500/30' : 'border-gray-200 hover:border-indigo-300'"
        >
          <input
            type="radio"
            name="fondo"
            class="sr-only"
            :value="f.clave"
            :checked="f.clave === actual"
            @change="elegir(f.clave)"
          />
          <!-- Miniatura: barra morada, encabezado y tres tarjetas sobre el fondo. -->
          <span class="tema-claro block overflow-hidden rounded-lg border border-gray-200" aria-hidden="true">
            <span :data-fondo="f.clave" class="miniatura-fondo block h-24">
              <span class="block h-3" :style="{ background: DEGRADADO }"></span>
              <span class="flex flex-col gap-1.5 p-2">
                <span class="block h-5 rounded-[5px] border border-gray-200 bg-white shadow-sm"></span>
                <span class="grid grid-cols-3 gap-1.5">
                  <span v-for="n in 3" :key="n" class="block h-9 rounded-[5px] border border-gray-200 bg-white shadow-sm"></span>
                </span>
              </span>
            </span>
          </span>
          <span class="flex items-center justify-between gap-1 px-0.5">
            <span class="text-sm font-semibold text-gray-900">{{ f.nombre }}</span>
            <svg v-if="f.clave === actual" class="h-4 w-4 shrink-0 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
          </span>
          <span class="px-0.5 text-xs leading-snug text-gray-500">
            {{ f.descripcion }}
            <span v-if="f.clave === predeterminado" class="mt-1 block font-medium text-gray-400">Predeterminado</span>
          </span>
        </label>
      </div>
    </fieldset>

    <template #pie>
      <p class="flex-1 self-center text-xs text-gray-500">Se guarda al elegirlo. En modo noche se usa siempre el fondo oscuro.</p>
      <button type="button" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700" @click="emit('cerrar')">
        Listo
      </button>
    </template>
  </Modal>
</template>
