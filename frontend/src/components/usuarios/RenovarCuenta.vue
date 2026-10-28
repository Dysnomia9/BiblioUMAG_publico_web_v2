<script setup lang="ts">
import { ref } from 'vue'
import DialogoConfirmacion from '@/components/ui/DialogoConfirmacion.vue'
import api from '@/services/api'
import { mensajeDeError, useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { fechaVigencia, proximoVencimiento, textoInactividad } from '@/utils/vigencia'
import type { Usuario } from '@/types'

/**
 * Aviso «la cuenta no sirve» con el botón para renovarla en el momento, sin ir al
 * listado de Usuarios: se usa donde alguien se encuentra con una cuenta vencida
 * (Préstamos, Entradas). Solo aparece el botón a quien puede renovar.
 */
const props = defineProps<{
  usuario: Pick<Usuario, 'id' | 'nombre' | 'apellido'> & Partial<Pick<Usuario, 'vigente_hasta' | 'inactividad'>>
}>()

const emit = defineEmits<{ renovada: [usuario: Usuario] }>()

const toast = useToast()
const auth = useAuthStore()

const confirmando = ref(false)
const guardando = ref(false)

async function renovar() {
  guardando.value = true
  try {
    const { data } = await api.patch<Usuario>(`/usuarios/${props.usuario.id}/habilitar`)
    toast.success(`Cuenta renovada hasta el ${fechaVigencia(data.vigente_hasta)}`)
    confirmando.value = false
    emit('renovada', data)
  } catch (e) {
    toast.error(mensajeDeError(e, 'No se pudo renovar la cuenta'))
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <div>
  <div class="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between">
    <p>
      <strong>{{ textoInactividad(usuario) }}.</strong>
      Mientras no se renueve no puede marcar entrada, pedir préstamos ni entrar al portal (sí puede devolver libros y pagar multas).
    </p>
    <button
      v-if="auth.puede('usuarios.habilitar')"
      type="button"
      class="shrink-0 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
      @click="confirmando = true"
    >
      Renovar cuenta
    </button>
  </div>

  <DialogoConfirmacion
    :abierto="confirmando"
    titulo="¿Renovar esta cuenta?"
    confirmar="Sí, renovar"
    tono="exito"
    :cargando="guardando"
    @confirmar="renovar"
    @cancelar="confirmando = false"
  >
    <p>
      <strong class="text-gray-900">{{ usuario.nombre }} {{ usuario.apellido }}</strong> quedará vigente hasta el
      <strong class="text-gray-900">{{ fechaVigencia(proximoVencimiento()) }}</strong>. Podrá marcar su entrada, pedir préstamos y reservas, y entrar
      al portal. Si tiene multas pendientes o libros vencidos, los préstamos y reservas siguen bloqueados hasta que se resuelvan.
    </p>
  </DialogoConfirmacion>
  </div>
</template>
