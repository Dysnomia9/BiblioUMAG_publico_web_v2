<script setup lang="ts">
import { nextTick, ref } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import CabeceraDegradado from '@/components/ui/CabeceraDegradado.vue'
import SelectorDocumento from '@/components/ui/SelectorDocumento.vue'
import FichaUsuario from '@/components/usuarios/FichaUsuario.vue'
import api from '@/services/api'
import { mensajeDeError } from '@/composables/useToast'
import { useModoDocumento } from '@/composables/useModoDocumento'
import { describirDocumento, formatearSegunModo, largoSegunModo, modoSegunValor, placeholderSegunModo } from '@/utils/documento'
import type { Usuario } from '@/types'

/**
 * Crear o corregir un usuario desde el mesón (Préstamos y Entradas): se busca por su
 * RUT o documento; si existe se abre su ficha para corregirla (por ejemplo, un cambio
 * de nombre), y si no, se registra con ese mismo documento ya escrito.
 */
const emit = defineEmits<{ guardado: [usuario: Usuario, nuevo: boolean] }>()

const TIPOS = ['estudiante', 'docente', 'funcionario'] as const
const TIPO_LABEL: Record<Usuario['tipo'], string> = { estudiante: 'Estudiante', docente: 'Docente', funcionario: 'Funcionario' }
// Cada tipo con su ícono y su color, para las tarjetas de «Registre a alguien nuevo».
const TARJETAS: Record<Usuario['tipo'], { tono: string; icono: string }> = {
  estudiante: { tono: 'bg-indigo-50 text-indigo-600', icono: 'M4.260 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.410 60.460 60.460 0 00-.491-6.347m-15.482 0a50.570 50.570 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.840c-.896.248-1.783.520-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.740-3.342' },
  docente: { tono: 'bg-sky-50 text-sky-600', icono: 'M3.750 3v11.250A2.250 2.250 0 006 16.500h2.250M3.750 3h-1.500m1.500 0h16.500m0 0h1.500m-1.500 0v11.250A2.250 2.250 0 0118 16.500h-2.250m-7.500 0h7.500m-7.500 0l-1 3m8.500-3l1 3m0 0l.500 1.500m-.500-1.500h-9.500m0 0l-.500 1.500' },
  funcionario: { tono: 'bg-amber-50 text-amber-700', icono: 'M20.250 14.150v4.250c0 1.094-.787 2.036-1.872 2.180-2.087.277-4.216.420-6.378.420s-4.291-.143-6.378-.420c-1.085-.144-1.872-1.086-1.872-2.180v-4.250m16.500 0a2.180 2.180 0 00.750-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.500 8.006c-.194.165-.420.295-.673.380A23.978 23.978 0 0112 15.750c-2.648 0-5.195-.429-7.577-1.220a2.016 2.016 0 01-.673-.380m0 0A2.180 2.180 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.500 0V5.250A2.250 2.250 0 0013.500 3h-3a2.250 2.250 0 00-2.250 2.250v.894m7.500 0a48.667 48.667 0 00-7.500 0' },
}

const abierto = ref(false)
const documento = ref('')
const { modo, escribir, error: errorDelDocumento } = useModoDocumento(documento)
const input = ref<HTMLInputElement | null>(null)
const buscando = ref(false)
const error = ref('')
/** null: aún no se busca; false: no hay nadie con ese documento. */
const encontrado = ref<Usuario | false | null>(null)
const ficha = ref<InstanceType<typeof FichaUsuario> | null>(null)

/** Abre el panel; con `inicial` (lo que se escribió en el mesón) busca de inmediato. */
function abrir(inicial = '') {
  abierto.value = true
  error.value = ''
  encontrado.value = null
  documento.value = ''
  if (inicial.trim()) {
    modo.value = modoSegunValor(inicial)
    nextTick(() => {
      documento.value = formatearSegunModo(inicial, modo.value)
      buscar()
    })
  } else {
    modo.value = 'rut'
    nextTick(() => input.value?.focus())
  }
}

/** Directo a la ficha de alguien ya buscado (la que se está atendiendo en Préstamos). */
function editar(u: Usuario) {
  encontrado.value = u
  abierto.value = false
  ficha.value?.abrirEdicion(u)
}

defineExpose({ abrir, editar })

async function buscar() {
  const e = errorDelDocumento()
  if (e) {
    error.value = e
    return
  }
  buscando.value = true
  error.value = ''
  try {
    const { data } = await api.get<Usuario>(`/usuarios/rut/${encodeURIComponent(documento.value)}`)
    encontrado.value = data
  } catch (e: any) {
    if (e?.response?.status === 404) encontrado.value = false
    else error.value = mensajeDeError(e, 'No se pudo buscar el usuario')
  } finally {
    buscando.value = false
  }
}

/**
 * Si se buscó y no había nadie, la ficha parte con ese documento ya escrito (un pasaporte
 * o DNI parte como pasaporte y se puede cambiar); si no, en blanco.
 */
function registrar(tipo: Usuario['tipo']) {
  const conDocumento = encontrado.value === false && !!documento.value
  abierto.value = false
  ficha.value?.abrirNuevo(tipo, conDocumento ? { rut: documento.value, tipo_documento: modo.value === 'rut' ? 'rut' : 'pasaporte' } : undefined)
}

function alGuardar(u: Usuario) {
  emit('guardado', u, !(encontrado.value && encontrado.value.id === u.id))
}

function estadoDe(u: Usuario) {
  if (u.activo) return { texto: 'Activo', clase: 'border-emerald-300 bg-emerald-50/60 text-emerald-700' }
  if (u.inactividad === 'vencida') return { texto: 'Vencida', clase: 'border-amber-300 bg-amber-50/60 text-amber-800' }
  return { texto: 'Inactivo', clase: 'border-red-300 bg-red-50/60 text-red-700' }
}
</script>

<template>
  <Modal :abierto="abierto" titulo="Crear o corregir un usuario" ancho="md" destacado degradado @cerrar="abierto = false">
    <template #cabecera>
      <CabeceraDegradado
        etiqueta="Usuarios"
        titulo="Crear o corregir un usuario"
        descripcion="Busque por RUT o documento para corregir sus datos, o registre a quien todavía no está."
        @cerrar="abierto = false"
      />
    </template>

    <div class="space-y-3">
      <div>
        <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
          <label for="meson-documento" class="text-xs font-semibold text-gray-700">Buscar por {{ modo === 'rut' ? 'RUT' : 'pasaporte o DNI' }}</label>
          <SelectorDocumento v-model="modo" compacto />
        </div>
        <div class="flex gap-2">
          <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.350-4.350M11 19a8 8 0 100-16 8 8 0 000 16z" /></svg>
            <input
              id="meson-documento"
              ref="input"
              :value="documento"
              :placeholder="placeholderSegunModo(modo)"
              :maxlength="largoSegunModo(modo)"
              autocomplete="off"
              class="w-full rounded-lg border bg-white py-2 pl-9 pr-3 font-mono text-sm outline-none transition focus:ring-2"
              :class="error ? 'border-red-400 focus:ring-red-200' : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-100'"
              @input="escribir($event); error = ''; encontrado = null"
              @keydown.enter.prevent="buscar"
            />
          </div>
          <button
            type="button"
            class="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-[inset_0_-2px_0_rgb(0_0_0/0.2)] transition-colors hover:bg-indigo-700 disabled:opacity-60"
            :disabled="buscando"
            @click="buscar"
          >
            {{ buscando ? 'Buscando…' : 'Buscar' }}
          </button>
        </div>
        <p v-if="error" class="mt-1 text-xs text-red-600" role="alert">{{ error }}</p>
      </div>

      <!-- Ya existe: su ficha, para corregirla -->
      <div v-if="encontrado" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50/50 px-3 py-2.5">
        <div class="flex min-w-0 items-center gap-2.5">
          <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="TARJETAS[encontrado.tipo].tono" aria-hidden="true">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.750"><path stroke-linecap="round" stroke-linejoin="round" :d="TARJETAS[encontrado.tipo].icono" /></svg>
          </span>
          <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
              <span class="text-sm font-bold text-gray-900">{{ encontrado.nombre }} {{ encontrado.apellido }}</span>
              <span class="rounded border px-1.5 py-px text-[10px] font-bold uppercase tracking-widest" :class="estadoDe(encontrado).clase">{{ estadoDe(encontrado).texto }}</span>
            </p>
            <p class="mt-0.5 truncate text-xs text-gray-500">
              {{ describirDocumento(encontrado.rut, encontrado.tipo_documento, encontrado.pais) }} · {{ TIPO_LABEL[encontrado.tipo] }}<template v-if="encontrado.email"> · {{ encontrado.email }}</template>
            </p>
          </div>
        </div>
        <button type="button" class="shrink-0 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-indigo-700 transition-colors hover:bg-gray-50" @click="editar(encontrado)">
          Editar datos
        </button>
      </div>

      <!-- No existe: se registra con ese documento eligiendo abajo qué es -->
      <p v-else-if="encontrado === false" class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
        No hay nadie registrado con ese {{ modo === 'rut' ? 'RUT' : 'pasaporte o DNI' }}. Si es de la universidad, elija abajo qué es y quedará con ese documento ya escrito.
      </p>

      <div class="flex items-center gap-3 pt-1" aria-hidden="true">
        <span class="h-px flex-1 bg-gray-200"></span>
        <span class="text-[10px] font-bold uppercase tracking-[0.14em] text-gray-400">{{ encontrado === false ? 'Regístrelo como' : 'O registre a alguien nuevo' }}</span>
        <span class="h-px flex-1 bg-gray-200"></span>
      </div>
      <div class="grid grid-cols-3 gap-2">
        <button
          v-for="t in TIPOS"
          :key="t"
          type="button"
          class="flex flex-col items-start gap-2 rounded-xl border border-gray-200 bg-white p-2.5 text-left shadow-sm transition-colors hover:border-indigo-300 hover:bg-indigo-50/40"
          :class="encontrado === false ? 'border-indigo-200' : ''"
          @click="registrar(t)"
        >
          <span class="flex h-7 w-7 items-center justify-center rounded-lg" :class="TARJETAS[t].tono" aria-hidden="true">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.750"><path stroke-linecap="round" stroke-linejoin="round" :d="TARJETAS[t].icono" /></svg>
          </span>
          <span class="text-sm font-bold text-gray-900">+ {{ TIPO_LABEL[t] }}</span>
        </button>
      </div>
    </div>
  </Modal>

  <FichaUsuario ref="ficha" @guardado="alGuardar" />
</template>
